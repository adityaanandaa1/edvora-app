<?php

namespace Tests\Unit;

use App\Services\AnalitikPengguna;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

// Fungsi murni saja; tidak memakai database.
class AnalitikPenggunaTest extends TestCase
{
    public function test_deret_harian_mengisi_hari_kosong_dengan_nol(): void
    {
        $dari = CarbonImmutable::parse('2026-11-01', 'Asia/Jakarta');
        $sampai = CarbonImmutable::parse('2026-11-03', 'Asia/Jakarta');

        $this->assertSame([
            ['tanggal' => '2026-11-01', 'daftar' => 2, 'aktif' => 0],
            ['tanggal' => '2026-11-02', 'daftar' => 0, 'aktif' => 0],
            ['tanggal' => '2026-11-03', 'daftar' => 0, 'aktif' => 5],
        ], AnalitikPengguna::deretHarian(['2026-11-01' => 2], ['2026-11-03' => 5], $dari, $sampai));
    }

    public function test_sebaran_streak_mengelompokkan_dan_sisanya_nol_hari(): void
    {
        // 10 siswa: 3 streak 1 hari, 1 streak 3 hari, 2 streak 5 hari, 1 streak 12 hari, sisanya (3) tanpa streak.
        $hasil = AnalitikPengguna::sebaranStreak([1 => 3, 3 => 1, 5 => 2, 12 => 1], 10);

        $this->assertSame([
            ['label' => '0 hari', 'jumlah' => 3, 'persen' => 30.0],
            ['label' => '1–3 hari', 'jumlah' => 4, 'persen' => 40.0],
            ['label' => '4–6 hari', 'jumlah' => 2, 'persen' => 20.0],
            ['label' => '7+ hari', 'jumlah' => 1, 'persen' => 10.0],
        ], $hasil);
    }

    public function test_sebaran_streak_tanpa_siswa_persennya_null(): void
    {
        $hasil = AnalitikPengguna::sebaranStreak([], 0);

        $this->assertSame([0, 0, 0, 0], array_column($hasil, 'jumlah'));
        $this->assertSame([null, null, null, null], array_column($hasil, 'persen'));
    }

    public function test_retensi_mengisi_minggu_kosong_dan_null_bila_belum_layak(): void
    {
        $awal = CarbonImmutable::parse('2026-11-02', 'Asia/Jakarta'); // Senin
        $perMinggu = [
            // 4 siswa, semuanya sudah 7 hari: 1 kembali (25%); belum ada yang 30 hari.
            '2026-11-02' => ['jumlah' => 4, 'layak_d7' => 4, 'kembali_d7' => 1, 'layak_d30' => 0, 'kembali_d30' => 0],
            // Minggu 9 Nov tidak ada pendaftar.
            // 3 siswa, baru 2 yang sudah 7 hari, keduanya kembali.
            '2026-11-16' => ['jumlah' => 3, 'layak_d7' => 2, 'kembali_d7' => 2, 'layak_d30' => 0, 'kembali_d30' => 0],
        ];

        $this->assertSame([
            ['minggu' => '2026-11-02', 'jumlah' => 4, 'd7' => 25.0, 'd30' => null],
            ['minggu' => '2026-11-09', 'jumlah' => 0, 'd7' => null, 'd30' => null],
            ['minggu' => '2026-11-16', 'jumlah' => 3, 'd7' => 100.0, 'd30' => null],
        ], AnalitikPengguna::retensi($perMinggu, $awal, 3));
    }

    public function test_jenis_kelamin_menambah_siswa_yang_belum_mengisi(): void
    {
        $hasil = AnalitikPengguna::sebaranJenisKelamin(['laki-laki' => 3, 'perempuan' => 5], 10);

        $this->assertSame([
            ['label' => 'Laki-laki', 'jumlah' => 3, 'persen' => 30.0],
            ['label' => 'Perempuan', 'jumlah' => 5, 'persen' => 50.0],
            ['label' => 'Belum diisi', 'jumlah' => 2, 'persen' => 20.0],
        ], $hasil);
    }

    public function test_jenjang_menambah_siswa_yang_belum_memilih(): void
    {
        $hasil = AnalitikPengguna::sebaranJenjang(['S1' => 6, 'D3' => 1], 8);

        $this->assertSame(['S1', 'D4', 'D3', 'Belum memilih'], array_column($hasil, 'label'));
        $this->assertSame([6, 0, 1, 1], array_column($hasil, 'jumlah'));
    }

    public function test_persen_satu_desimal_dan_null_bila_total_nol(): void
    {
        $this->assertSame(33.3, AnalitikPengguna::persen(1, 3));
        $this->assertNull(AnalitikPengguna::persen(0, 0));
    }
}
