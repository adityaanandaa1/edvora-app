<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Aturan Dashboard Admin bagian Pengguna & Keaktifan: deret harian, sebaran streak, retensi kohort, dan sebaran
 * demografi/tujuan. Fungsi murni; query-nya ada di DataAnalitikPengguna.
 *
 * "Aktif" = mengerjakan sesuatu pada hari itu (WIB): memulai/menyelesaikan pengerjaan atau ikut battle. Login saja
 * tidak dihitung. Streak dihitung dari hari-hari aktif itu, bukan dari kolom siswa.streak_saat_ini (belum diisi
 * aplikasi).
 */
class AnalitikPengguna
{
    // Panjang deret harian yang dikirim ke halaman; halaman menyaringnya menjadi 7/30/90 hari.
    public const HARI_TREN = 90;

    // Banyak minggu pendaftaran (kohort) di tabel retensi, termasuk minggu ini.
    public const MINGGU_KOHORT = 8;

    // Retensi D7/D30: siswa masih aktif pada hari ke-7/ke-30 setelah mendaftar atau sesudahnya.
    public const HARI_RETENSI = [7, 30];

    public const MAKS_TUJUAN = 10;

    // Batas bawah tiap kelompok streak (hari berturut-turut), urut naik.
    public const KELOMPOK_STREAK = ['1–3 hari' => 1, '4–6 hari' => 4, '7+ hari' => 7];

    public const LABEL_JENIS_KELAMIN = ['laki-laki' => 'Laki-laki', 'perempuan' => 'Perempuan'];

    public const JENJANG = ['S1', 'D4', 'D3'];

    /**
     * Deret harian dari $dari sampai $sampai (tanggal WIB). Hari tanpa data diisi 0 supaya grafik tidak bolong.
     *
     * @param  array<string, int>  $daftar  'Y-m-d' => siswa baru
     * @param  array<string, int>  $aktif  'Y-m-d' => siswa aktif
     * @return array<int, array{tanggal: string, daftar: int, aktif: int}>
     */
    public static function deretHarian(array $daftar, array $aktif, CarbonImmutable $dari, CarbonImmutable $sampai): array
    {
        $hasil = [];
        for ($hari = $dari->startOfDay(); $hari->lte($sampai); $hari = $hari->addDay()) {
            $tanggal = $hari->format('Y-m-d');
            $hasil[] = ['tanggal' => $tanggal, 'daftar' => $daftar[$tanggal] ?? 0, 'aktif' => $aktif[$tanggal] ?? 0];
        }

        return $hasil;
    }

    /**
     * Sebaran streak. Siswa tanpa streak berjalan (tidak aktif kemarin maupun hari ini) masuk kelompok "0 hari".
     *
     * @param  array<int, int>  $jumlahPerPanjang  panjang streak => banyak siswa
     * @return array<int, array{label: string, jumlah: int, persen: float|null}>
     */
    public static function sebaranStreak(array $jumlahPerPanjang, int $totalSiswa): array
    {
        $kelompok = array_fill_keys(array_keys(self::KELOMPOK_STREAK), 0);
        foreach ($jumlahPerPanjang as $panjang => $jumlah) {
            if ($panjang < 1) {
                continue;
            }
            $label = null;
            foreach (self::KELOMPOK_STREAK as $nama => $batasBawah) {
                if ($panjang >= $batasBawah) {
                    $label = $nama;
                }
            }
            $kelompok[$label] += $jumlah;
        }

        $tanpaStreak = max(0, $totalSiswa - array_sum($kelompok));
        $hasil = [['label' => '0 hari', 'jumlah' => $tanpaStreak, 'persen' => self::persen($tanpaStreak, $totalSiswa)]];
        foreach ($kelompok as $label => $jumlah) {
            $hasil[] = ['label' => $label, 'jumlah' => $jumlah, 'persen' => self::persen($jumlah, $totalSiswa)];
        }

        return $hasil;
    }

    /**
     * Tabel retensi per minggu pendaftaran, mulai $mingguAwal (Senin) sebanyak $jumlahMinggu. Persen dihitung dari
     * siswa yang sudah cukup lama terdaftar; null = belum ada siswa yang cukup lama (minggu terlalu baru).
     *
     * @param  array<string, array{jumlah: int, layak_d7: int, kembali_d7: int, layak_d30: int, kembali_d30: int}>  $perMinggu  'Y-m-d' (Senin) => hitungan
     * @return array<int, array{minggu: string, jumlah: int, d7: float|null, d30: float|null}>
     */
    public static function retensi(array $perMinggu, CarbonImmutable $mingguAwal, int $jumlahMinggu): array
    {
        $hasil = [];
        for ($i = 0; $i < $jumlahMinggu; $i++) {
            $minggu = $mingguAwal->addWeeks($i)->format('Y-m-d');
            $b = $perMinggu[$minggu] ?? ['jumlah' => 0, 'layak_d7' => 0, 'kembali_d7' => 0, 'layak_d30' => 0, 'kembali_d30' => 0];
            $hasil[] = [
                'minggu' => $minggu,
                'jumlah' => $b['jumlah'],
                'd7' => self::persen($b['kembali_d7'], $b['layak_d7']),
                'd30' => self::persen($b['kembali_d30'], $b['layak_d30']),
            ];
        }

        return $hasil;
    }

    /**
     * Sebaran jenis kelamin, termasuk siswa yang belum mengisi biodata.
     *
     * @param  array<string, int>  $jumlahPerNilai  nilai enum ('' = belum diisi) => banyak siswa
     * @return array<int, array{label: string, jumlah: int, persen: float|null}>
     */
    public static function sebaranJenisKelamin(array $jumlahPerNilai, int $totalSiswa): array
    {
        $hasil = [];
        foreach (self::LABEL_JENIS_KELAMIN as $nilai => $label) {
            $jumlah = $jumlahPerNilai[$nilai] ?? 0;
            $hasil[] = ['label' => $label, 'jumlah' => $jumlah, 'persen' => self::persen($jumlah, $totalSiswa)];
        }

        return self::tambahBelumDiisi($hasil, $totalSiswa, 'Belum diisi');
    }

    /**
     * Sebaran jenjang prodi tujuan, termasuk siswa yang belum memilih prodi.
     *
     * @param  array<string, int>  $jumlahPerJenjang  'S1'/'D4'/'D3' => banyak siswa
     * @return array<int, array{label: string, jumlah: int, persen: float|null}>
     */
    public static function sebaranJenjang(array $jumlahPerJenjang, int $totalSiswa): array
    {
        $hasil = [];
        foreach (self::JENJANG as $jenjang) {
            $jumlah = $jumlahPerJenjang[$jenjang] ?? 0;
            $hasil[] = ['label' => $jenjang, 'jumlah' => $jumlah, 'persen' => self::persen($jumlah, $totalSiswa)];
        }

        return self::tambahBelumDiisi($hasil, $totalSiswa, 'Belum memilih');
    }

    /** Persen satu desimal; null bila penyebutnya 0 (tidak ada data, bukan 0%). */
    public static function persen(int $bagian, int $total): ?float
    {
        return $total > 0 ? round($bagian * 100 / $total, 1) : null;
    }

    /**
     * @param  array<int, array{label: string, jumlah: int, persen: float|null}>  $hasil
     * @return array<int, array{label: string, jumlah: int, persen: float|null}>
     */
    private static function tambahBelumDiisi(array $hasil, int $totalSiswa, string $label): array
    {
        $sisa = max(0, $totalSiswa - array_sum(array_column($hasil, 'jumlah')));
        $hasil[] = ['label' => $label, 'jumlah' => $sisa, 'persen' => self::persen($sisa, $totalSiswa)];

        return $hasil;
    }
}
