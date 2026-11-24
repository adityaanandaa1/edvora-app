<?php

namespace Tests\Unit;

use App\Services\AnalitikLanjutan;
use App\Services\LevelXp;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

// Fungsi murni saja; tidak memakai database.
class AnalitikLanjutanTest extends TestCase
{
    public function test_deret_beberapa_seri_mengisi_hari_kosong(): void
    {
        $dari = CarbonImmutable::parse('2026-11-01', 'Asia/Jakarta');
        $sampai = CarbonImmutable::parse('2026-11-02', 'Asia/Jakarta');

        $this->assertSame([
            ['tanggal' => '2026-11-01', 'a' => 1, 'b' => 0],
            ['tanggal' => '2026-11-02', 'a' => 0, 'b' => 4],
        ], AnalitikLanjutan::deret(['a' => ['2026-11-01' => 1], 'b' => ['2026-11-02' => 4]], $dari, $sampai));
    }

    public function test_ringkas_skor_ganjil_genap_dan_kosong(): void
    {
        $this->assertSame(['peserta' => 3, 'rata' => 500.0, 'median' => 400.0, 'min' => 300.0, 'maks' => 800.0], AnalitikLanjutan::ringkasSkor([800, 300, 400]));
        $this->assertSame(450.0, AnalitikLanjutan::ringkasSkor([300, 400, 500, 900])['median']);
        $this->assertSame(['peserta' => 0, 'rata' => null, 'median' => null, 'min' => null, 'maks' => null], AnalitikLanjutan::ringkasSkor([]));
    }

    public function test_histogram_skor_batas_bawah_dan_seribu_masuk_kelompok_terakhir(): void
    {
        $hasil = AnalitikLanjutan::histogram([0, 99.9, 100, 950, 1000]);

        $this->assertCount(10, $hasil);
        $this->assertSame(['label' => '0–99', 'jumlah' => 2], $hasil[0]);
        $this->assertSame(['label' => '100–199', 'jumlah' => 1], $hasil[1]);
        $this->assertSame(['label' => '900–1000', 'jumlah' => 2], $hasil[9]);
    }

    public function test_ambang_level_cocok_dengan_level_xp(): void
    {
        $ambang = AnalitikLanjutan::ambangLevel();

        // width_bucket(xp, ambang) + 1 = level: banyak ambang yang ≤ xp, ditambah 1.
        foreach ([0, 299, 300, 899, 900, 1800, 130500, 999999] as $xp) {
            $level = count(array_filter($ambang, fn ($a) => $a <= $xp)) + 1;
            $harapan = min(LevelXp::dariXp($xp)['level'], AnalitikLanjutan::MAKS_LEVEL);
            $this->assertSame($harapan, $level, "xp {$xp}");
        }
        $this->assertSame('{300,900,1800}', AnalitikLanjutan::arrayPostgres([300, 900, 1800]));
    }

    public function test_sebaran_level_dikelompokkan(): void
    {
        $hasil = AnalitikLanjutan::sebaranLevel([1 => 6, 4 => 2, 5 => 1, 31 => 1]);

        $this->assertSame(['Level 1–4', 'Level 5–9', 'Level 10–14', 'Level 15–19', 'Level 20–29', 'Level 30+'], array_column($hasil, 'label'));
        $this->assertSame([8, 1, 0, 0, 0, 1], array_column($hasil, 'jumlah'));
        $this->assertSame(80.0, $hasil[0]['persen']);
    }

    public function test_matriks_jam_senin_indeks_nol_dan_abaikan_nilai_aneh(): void
    {
        $matriks = AnalitikLanjutan::matriksJam([
            ['hari' => 1, 'jam' => 0, 'jumlah' => 3],
            ['hari' => 7, 'jam' => 23, 'jumlah' => 5],
            ['hari' => 8, 'jam' => 1, 'jumlah' => 9],
        ]);

        $this->assertSame(3, $matriks[0][0]);
        $this->assertSame(5, $matriks[6][23]);
        $this->assertSame(8, array_sum(array_map('array_sum', $matriks)));
    }

    public function test_selisih_label(): void
    {
        $this->assertSame(0.0, AnalitikLanjutan::selisihLabel('mudah', 75));
        $this->assertSame(-30.0, AnalitikLanjutan::selisihLabel('mudah', 30));
        $this->assertSame(30.0, AnalitikLanjutan::selisihLabel('sulit', 90));
    }

    public function test_label_tidak_cocok_paling_menyimpang_dulu(): void
    {
        $soal = [
            ['kodeSoal' => 'A', 'kodeSubtes' => 'PU', 'topik' => 'T', 'tingkat' => 'mudah', 'jawaban' => 10, 'benar' => 8],  // 80%, wajar
            ['kodeSoal' => 'B', 'kodeSubtes' => 'PU', 'topik' => 'T', 'tingkat' => 'mudah', 'jawaban' => 10, 'benar' => 5],  // 50%, kurang 10
            ['kodeSoal' => 'C', 'kodeSubtes' => 'PU', 'topik' => 'T', 'tingkat' => 'sulit', 'jawaban' => 20, 'benar' => 19], // 95%, lebih 35
        ];

        $hasil = AnalitikLanjutan::labelTidakCocok($soal, 10);

        $this->assertSame(['C', 'B'], array_column($hasil, 'kodeSoal'));
        $this->assertSame('Lebih mudah dari labelnya', $hasil[0]['saran']);
        $this->assertSame('Lebih sulit dari labelnya', $hasil[1]['saran']);
        $this->assertArrayNotHasKey('selisih', $hasil[0]);
    }

    public function test_akurasi_per_tingkat_tertimbang_jawaban(): void
    {
        $hasil = AnalitikLanjutan::akurasiPerTingkat([
            ['tingkat' => 'mudah', 'jawaban' => 10, 'benar' => 10],
            ['tingkat' => 'mudah', 'jawaban' => 30, 'benar' => 10],
        ]);

        $this->assertSame(['tingkat' => 'mudah', 'soal' => 2, 'akurasi' => 50.0], $hasil[0]);
        $this->assertSame(['tingkat' => 'sedang', 'soal' => 0, 'akurasi' => null], $hasil[1]);
    }

    public function test_pengecoh_kuat_hanya_bila_mengalahkan_kunci(): void
    {
        $opsi = fn (string $soal, string $label, bool $kunci, int $dipilih) => [
            'soalId' => $soal, 'kodeSoal' => $soal, 'kodeSubtes' => 'PK', 'label' => $label, 'kunci' => $kunci, 'dipilih' => $dipilih,
        ];
        $baris = [
            // S1: pengecoh B (6) > kunci A (3) => masuk.
            $opsi('S1', 'A', true, 3), $opsi('S1', 'B', false, 6), $opsi('S1', 'C', false, 1),
            // S2: kunci paling banyak dipilih => tidak masuk.
            $opsi('S2', 'A', true, 8), $opsi('S2', 'B', false, 2),
            // S3: jawabannya terlalu sedikit => tidak dinilai.
            $opsi('S3', 'A', true, 0), $opsi('S3', 'B', false, 3),
        ];

        $this->assertSame([[
            'kodeSoal' => 'S1',
            'kodeSubtes' => 'PK',
            'jawaban' => 10,
            'labelKunci' => 'A',
            'persenKunci' => 30.0,
            'labelPengecoh' => 'B',
            'persenPengecoh' => 60.0,
        ]], AnalitikLanjutan::pengecohKuat($baris, 10, 10));
    }
}
