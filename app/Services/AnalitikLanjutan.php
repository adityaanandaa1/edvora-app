<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Fungsi murni untuk halaman Analitik admin (Belajar, Try Out, Battle, Gamifikasi, Operasional) dan bagian
 * Kualitas Soal & Penguasaan di analitik editor. Query-nya ada di DataAnalitikAdmin dan DataAnalitikKualitas.
 */
class AnalitikLanjutan
{
    // Histogram skor Try Out: skala UTBK 0–1000 (EstimasiIrt) dibagi per 100.
    public const LEBAR_HISTOGRAM = 100;

    // Kelompok level siswa (batas bawah); mengikuti level pembuka avatar 5/10/15/20/30.
    public const KELOMPOK_LEVEL = ['1–4' => 1, '5–9' => 5, '10–14' => 10, '15–19' => 15, '20–29' => 20, '30+' => 30];

    // Level tertinggi yang dibedakan saat menghitung level di SQL; di atasnya ikut kelompok terakhir.
    public const MAKS_LEVEL = 60;

    // Rentang akurasi yang wajar untuk tiap label tingkat kesulitan (persen jawaban benar).
    public const AKURASI_WAJAR = ['mudah' => [60, 100], 'sedang' => [30, 85], 'sulit' => [0, 60]];

    /**
     * Deret harian beberapa seri sekaligus; hari tanpa data diisi 0.
     *
     * @param  array<string, array<string, int>>  $seri  nama seri => ('Y-m-d' => nilai)
     * @return array<int, array<string, int|string>> [{tanggal, <seri1>, <seri2>, ...}]
     */
    public static function deret(array $seri, CarbonImmutable $dari, CarbonImmutable $sampai): array
    {
        $hasil = [];
        for ($hari = $dari->startOfDay(); $hari->lte($sampai); $hari = $hari->addDay()) {
            $tanggal = $hari->format('Y-m-d');
            $baris = ['tanggal' => $tanggal];
            foreach ($seri as $nama => $nilai) {
                $baris[$nama] = $nilai[$tanggal] ?? 0;
            }
            $hasil[] = $baris;
        }

        return $hasil;
    }

    /**
     * Ringkasan sebaran skor. Semua null bila belum ada skor.
     *
     * @param  array<int, float>  $skor
     * @return array{peserta: int, rata: float|null, median: float|null, min: float|null, maks: float|null}
     */
    public static function ringkasSkor(array $skor): array
    {
        if ($skor === []) {
            return ['peserta' => 0, 'rata' => null, 'median' => null, 'min' => null, 'maks' => null];
        }

        sort($skor);
        $n = count($skor);
        $tengah = intdiv($n, 2);

        return [
            'peserta' => $n,
            'rata' => round(array_sum($skor) / $n, 1),
            'median' => round($n % 2 === 1 ? $skor[$tengah] : ($skor[$tengah - 1] + $skor[$tengah]) / 2, 1),
            'min' => round($skor[0], 1),
            'maks' => round($skor[$n - 1], 1),
        ];
    }

    /**
     * Histogram skor 0–1000 per LEBAR_HISTOGRAM. Skor 1000 masuk kelompok terakhir (900–1000).
     *
     * @param  array<int, float>  $skor
     * @return array<int, array{label: string, jumlah: int}>
     */
    public static function histogram(array $skor, int $min = EstimasiIrt::SKOR_MIN, int $maks = EstimasiIrt::SKOR_MAKS): array
    {
        $jumlahKelompok = (int) ceil(($maks - $min) / self::LEBAR_HISTOGRAM);
        $isi = array_fill(0, $jumlahKelompok, 0);
        foreach ($skor as $s) {
            $i = (int) floor(($s - $min) / self::LEBAR_HISTOGRAM);
            $isi[max(0, min($jumlahKelompok - 1, $i))]++;
        }

        $hasil = [];
        foreach ($isi as $i => $jumlah) {
            $dari = $min + $i * self::LEBAR_HISTOGRAM;
            $sampai = $i === $jumlahKelompok - 1 ? $maks : $dari + self::LEBAR_HISTOGRAM - 1;
            $hasil[] = ['label' => "{$dari}–{$sampai}", 'jumlah' => $jumlah];
        }

        return $hasil;
    }

    /**
     * Batas bawah XP level 2..MAKS_LEVEL, untuk width_bucket() di SQL: hasilnya + 1 = level siswa.
     *
     * @return array<int, int>
     */
    public static function ambangLevel(): array
    {
        return array_map(fn (int $level) => LevelXp::xpMinimal($level), range(2, self::MAKS_LEVEL));
    }

    /** Array integer dalam format literal Postgres, mis. '{300,900,1800}'. */
    public static function arrayPostgres(array $angka): string
    {
        return '{'.implode(',', array_map('intval', $angka)).'}';
    }

    /**
     * @param  array<int, int>  $jumlahPerLevel  level => banyak siswa
     * @return array<int, array{label: string, jumlah: int, persen: float|null}>
     */
    public static function sebaranLevel(array $jumlahPerLevel): array
    {
        $kelompok = array_fill_keys(array_keys(self::KELOMPOK_LEVEL), 0);
        foreach ($jumlahPerLevel as $level => $jumlah) {
            $label = array_key_first(self::KELOMPOK_LEVEL);
            foreach (self::KELOMPOK_LEVEL as $nama => $batasBawah) {
                if ($level >= $batasBawah) {
                    $label = $nama;
                }
            }
            $kelompok[$label] += $jumlah;
        }

        $total = array_sum($kelompok);
        $hasil = [];
        foreach ($kelompok as $label => $jumlah) {
            $hasil[] = ['label' => "Level {$label}", 'jumlah' => $jumlah, 'persen' => AnalitikPengguna::persen($jumlah, $total)];
        }

        return $hasil;
    }

    /**
     * Matriks hari (Senin = indeks 0) × jam (0–23) dari baris {hari ISO 1–7, jam, jumlah}.
     *
     * @param  array<int, array{hari: int, jam: int, jumlah: int}>  $baris
     * @return array<int, array<int, int>>
     */
    public static function matriksJam(array $baris): array
    {
        $matriks = array_fill(0, 7, array_fill(0, 24, 0));
        foreach ($baris as $b) {
            if ($b['hari'] >= 1 && $b['hari'] <= 7 && $b['jam'] >= 0 && $b['jam'] <= 23) {
                $matriks[$b['hari'] - 1][$b['jam']] += $b['jumlah'];
            }
        }

        return $matriks;
    }

    /**
     * Seberapa jauh akurasi soal keluar dari rentang wajar labelnya (poin persen). 0 = masih wajar.
     * Positif = soal lebih mudah dari labelnya, negatif = lebih sulit.
     */
    public static function selisihLabel(string $tingkat, float $akurasi): float
    {
        [$bawah, $atas] = self::AKURASI_WAJAR[$tingkat] ?? [0, 100];
        if ($akurasi < $bawah) {
            return round($akurasi - $bawah, 1);
        }

        return $akurasi > $atas ? round($akurasi - $atas, 1) : 0.0;
    }

    /**
     * Soal yang akurasinya tidak sesuai label tingkat kesulitan, paling menyimpang dulu.
     *
     * @param  array<int, array{kodeSoal: string, kodeSubtes: string, topik: string, tingkat: string, jawaban: int, benar: int}>  $soal
     * @return array<int, array{kodeSoal: string, kodeSubtes: string, topik: string, tingkat: string, jawaban: int, akurasi: float, saran: string}>
     */
    public static function labelTidakCocok(array $soal, int $maks): array
    {
        $hasil = [];
        foreach ($soal as $s) {
            $akurasi = round($s['benar'] * 100 / max(1, $s['jawaban']), 1);
            $selisih = self::selisihLabel($s['tingkat'], $akurasi);
            if ($selisih === 0.0) {
                continue;
            }
            $hasil[] = [
                'kodeSoal' => $s['kodeSoal'],
                'kodeSubtes' => $s['kodeSubtes'],
                'topik' => $s['topik'],
                'tingkat' => $s['tingkat'],
                'jawaban' => $s['jawaban'],
                'akurasi' => $akurasi,
                'saran' => $selisih > 0 ? 'Lebih mudah dari labelnya' : 'Lebih sulit dari labelnya',
                'selisih' => abs($selisih),
            ];
        }

        usort($hasil, fn ($a, $b) => $b['selisih'] <=> $a['selisih'] ?: $b['jawaban'] <=> $a['jawaban']);

        return array_map(function ($h) {
            unset($h['selisih']);

            return $h;
        }, array_slice($hasil, 0, $maks));
    }

    /**
     * Akurasi rata-rata (tertimbang jumlah jawaban) per label tingkat kesulitan.
     *
     * @param  array<int, array{tingkat: string, jawaban: int, benar: int}>  $soal
     * @return array<int, array{tingkat: string, soal: int, akurasi: float|null}>
     */
    public static function akurasiPerTingkat(array $soal): array
    {
        $hasil = [];
        foreach (AnalitikSoal::TINGKAT as $tingkat) {
            $milik = array_filter($soal, fn ($s) => $s['tingkat'] === $tingkat);
            $hasil[] = [
                'tingkat' => $tingkat,
                'soal' => count($milik),
                'akurasi' => AnalitikPengguna::persen(array_sum(array_column($milik, 'benar')), array_sum(array_column($milik, 'jawaban'))),
            ];
        }

        return $hasil;
    }

    /**
     * Soal pilihan ganda yang satu pengecohnya dipilih lebih sering daripada kuncinya: tanda kunci mungkin keliru
     * atau soalnya menjebak. Hanya soal dengan jawaban minimal $minJawaban.
     *
     * @param  array<int, array{soalId: string, kodeSoal: string, kodeSubtes: string, label: string, kunci: bool, dipilih: int}>  $baris  satu baris per opsi yang pernah dipilih
     * @return array<int, array{kodeSoal: string, kodeSubtes: string, jawaban: int, labelKunci: string|null, persenKunci: float, labelPengecoh: string, persenPengecoh: float}>
     */
    public static function pengecohKuat(array $baris, int $minJawaban, int $maks): array
    {
        $perSoal = [];
        foreach ($baris as $b) {
            $perSoal[$b['soalId']][] = $b;
        }

        $hasil = [];
        foreach ($perSoal as $opsi) {
            $total = array_sum(array_column($opsi, 'dipilih'));
            if ($total < $minJawaban) {
                continue;
            }

            $kunci = array_values(array_filter($opsi, fn ($o) => $o['kunci']));
            $salah = array_values(array_filter($opsi, fn ($o) => ! $o['kunci']));
            if ($salah === []) {
                continue;
            }
            usort($salah, fn ($a, $b) => $b['dipilih'] <=> $a['dipilih']);
            $dipilihKunci = array_sum(array_column($kunci, 'dipilih'));
            if ($salah[0]['dipilih'] <= $dipilihKunci) {
                continue;
            }

            $hasil[] = [
                'kodeSoal' => $opsi[0]['kodeSoal'],
                'kodeSubtes' => $opsi[0]['kodeSubtes'],
                'jawaban' => $total,
                'labelKunci' => $kunci[0]['label'] ?? null,
                'persenKunci' => AnalitikPengguna::persen($dipilihKunci, $total),
                'labelPengecoh' => $salah[0]['label'],
                'persenPengecoh' => AnalitikPengguna::persen($salah[0]['dipilih'], $total),
            ];
        }

        usort($hasil, fn ($a, $b) => ($b['persenPengecoh'] - $b['persenKunci']) <=> ($a['persenPengecoh'] - $a['persenKunci']));

        return array_slice($hasil, 0, $maks);
    }
}
