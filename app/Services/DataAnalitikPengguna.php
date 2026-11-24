<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Data Dashboard Admin bagian Pengguna & Keaktifan. Semua query hanya membaca (SELECT). Hasilnya disimpan di cache
 * selama MENIT_CACHE supaya query agregat tidak berjalan setiap kali halaman dibuka.
 *
 * Tanggal dikelompokkan per hari WIB (AnalitikSoal::ZONA_WAKTU), sedangkan database menyimpan waktu dalam UTC.
 */
class DataAnalitikPengguna
{
    private const KUNCI_CACHE = 'analitik_pengguna';

    private const MENIT_CACHE = 15;

    /** @return array<string, mixed> props analitikPengguna di halaman Dashboard/Admin (camelCase) */
    public function ambil(): array
    {
        return Cache::remember(
            self::KUNCI_CACHE,
            now()->addMinutes(self::MENIT_CACHE),
            fn () => $this->hitung(CarbonImmutable::now(AnalitikSoal::ZONA_WAKTU)),
        );
    }

    /** @return array<string, mixed> */
    private function hitung(CarbonImmutable $sekarang): array
    {
        $hariIni = $sekarang->startOfDay();
        $dariTren = $hariIni->subDays(AnalitikPengguna::HARI_TREN - 1);
        $mingguAwal = $hariIni->startOfWeek(CarbonInterface::MONDAY)->subWeeks(AnalitikPengguna::MINGGU_KOHORT - 1);
        // Aktivitas dibaca sejak tanggal paling awal yang dibutuhkan deret harian maupun kohort retensi.
        $dariAktivitas = $dariTren->min($mingguAwal);

        $totalSiswa = DB::table('users')->where('role', 'siswa')->count();
        $deret = AnalitikPengguna::deretHarian(
            $this->pendaftaranHarian($dariTren),
            $this->aktifHarian($dariAktivitas),
            $dariTren,
            $hariIni,
        );

        return [
            'dihitungPada' => $sekarang->toIso8601String(),
            'totalSiswa' => $totalSiswa,
            'keaktifan' => $this->keaktifan($dariAktivitas, $hariIni),
            'harian' => $deret,
            'retensi' => AnalitikPengguna::retensi($this->kohort($dariAktivitas, $mingguAwal, $hariIni), $mingguAwal, AnalitikPengguna::MINGGU_KOHORT),
            'streak' => AnalitikPengguna::sebaranStreak($this->panjangStreak($dariAktivitas, $hariIni), $totalSiswa),
            'jenisKelamin' => AnalitikPengguna::sebaranJenisKelamin($this->jumlahJenisKelamin(), $totalSiswa),
            'jenjang' => AnalitikPengguna::sebaranJenjang($this->jumlahJenjang(), $totalSiswa),
            'topUniversitas' => $this->topUniversitas(),
            'topProdi' => $this->topProdi(),
        ];
    }

    /**
     * CTE "aktivitas": satu baris per (siswa, hari WIB) yang aktif. UNION sekaligus membuang duplikat.
     * Pengerjaan dihitung pada hari dimulai dan hari selesai, supaya latihan yang dilanjutkan esok hari ikut tercatat.
     *
     * @return array{0: string, 1: array<int, string>} [sql, bindings]
     */
    private function cteAktivitas(CarbonImmutable $dari): array
    {
        $sql = <<<'SQL'
            aktivitas as (
                select user_id, (started_at at time zone 'Asia/Jakarta')::date as hari
                from pengerjaan where started_at >= ?
                union
                select user_id, (finished_at at time zone 'Asia/Jakarta')::date
                from pengerjaan where finished_at >= ?
                union
                select user_id, (joined_at at time zone 'Asia/Jakarta')::date
                from battle_peserta where joined_at >= ?
            )
            SQL;
        $batas = $dari->toIso8601String();

        return [$sql, [$batas, $batas, $batas]];
    }

    /** @return array<string, int> 'Y-m-d' => siswa baru */
    private function pendaftaranHarian(CarbonImmutable $dari): array
    {
        $baris = DB::select(<<<'SQL'
            select (created_at at time zone 'Asia/Jakarta')::date as hari, count(*) as jumlah
            from users
            where role = 'siswa' and created_at >= ?
            group by 1
            SQL, [$dari->toIso8601String()]);

        return $this->petakan($baris, 'hari');
    }

    /** @return array<string, int> 'Y-m-d' => siswa aktif */
    private function aktifHarian(CarbonImmutable $dari): array
    {
        [$cte, $ikatan] = $this->cteAktivitas($dari);
        $baris = DB::select("with {$cte} select hari, count(*) as jumlah from aktivitas group by hari", $ikatan);

        return $this->petakan($baris, 'hari');
    }

    /** @return array{dau: int, wau: int, mau: int} siswa aktif hari ini, 7 hari, dan 30 hari terakhir (termasuk hari ini) */
    private function keaktifan(CarbonImmutable $dari, CarbonImmutable $hariIni): array
    {
        [$cte, $ikatan] = $this->cteAktivitas($dari);
        $b = DB::selectOne(<<<SQL
            with {$cte}
            select count(distinct user_id) filter (where hari = cast(? as date)) as dau,
                   count(distinct user_id) filter (where hari >= cast(? as date)) as wau,
                   count(distinct user_id) filter (where hari >= cast(? as date)) as mau
            from aktivitas
            SQL, [...$ikatan, $hariIni->format('Y-m-d'), $hariIni->subDays(6)->format('Y-m-d'), $hariIni->subDays(29)->format('Y-m-d')]);

        return ['dau' => (int) $b->dau, 'wau' => (int) $b->wau, 'mau' => (int) $b->mau];
    }

    /**
     * Panjang streak yang masih berjalan (hari aktif terakhirnya hari ini atau kemarin), dihitung dengan teknik
     * gaps-and-islands: hari dikurangi nomor urutnya bernilai sama untuk hari-hari yang berturut-turut.
     * Streak paling panjang yang terbaca = rentang aktivitas yang dibaca.
     *
     * @return array<int, int> panjang => banyak siswa
     */
    private function panjangStreak(CarbonImmutable $dari, CarbonImmutable $hariIni): array
    {
        [$cte, $ikatan] = $this->cteAktivitas($dari);
        $baris = DB::select(<<<SQL
            with {$cte},
            pulau as (
                select user_id, hari, hari - cast(row_number() over (partition by user_id order by hari) as int) as grup
                from aktivitas
            ),
            deret as (
                select user_id, max(hari) as akhir, count(*) as panjang
                from pulau
                group by user_id, grup
            )
            select panjang, count(*) as jumlah
            from deret
            where akhir >= cast(? as date)
            group by panjang
            SQL, [...$ikatan, $hariIni->subDay()->format('Y-m-d')]);

        $hasil = [];
        foreach ($baris as $b) {
            $hasil[(int) $b->panjang] = (int) $b->jumlah;
        }

        return $hasil;
    }

    /**
     * Hitungan retensi per minggu pendaftaran (Senin, sama dengan date_trunc('week') Postgres).
     *
     * @return array<string, array{jumlah: int, layak_d7: int, kembali_d7: int, layak_d30: int, kembali_d30: int}>
     */
    private function kohort(CarbonImmutable $dari, CarbonImmutable $mingguAwal, CarbonImmutable $hariIni): array
    {
        [$cte, $ikatan] = $this->cteAktivitas($dari);
        [$d7, $d30] = AnalitikPengguna::HARI_RETENSI;
        $hari = $hariIni->format('Y-m-d');

        $baris = DB::select(<<<SQL
            with {$cte},
            kohort as (
                select id as user_id, (created_at at time zone 'Asia/Jakarta')::date as tgl_daftar
                from users
                where role = 'siswa' and created_at >= ?
            )
            select cast(date_trunc('week', k.tgl_daftar) as date) as minggu,
                   count(*) as jumlah,
                   count(*) filter (where k.tgl_daftar <= cast(? as date) - {$d7}) as layak_d7,
                   count(*) filter (where k.tgl_daftar <= cast(? as date) - {$d7}
                       and exists (select 1 from aktivitas a where a.user_id = k.user_id and a.hari >= k.tgl_daftar + {$d7})) as kembali_d7,
                   count(*) filter (where k.tgl_daftar <= cast(? as date) - {$d30}) as layak_d30,
                   count(*) filter (where k.tgl_daftar <= cast(? as date) - {$d30}
                       and exists (select 1 from aktivitas a where a.user_id = k.user_id and a.hari >= k.tgl_daftar + {$d30})) as kembali_d30
            from kohort k
            group by 1
            SQL, [...$ikatan, $mingguAwal->toIso8601String(), $hari, $hari, $hari, $hari]);

        $hasil = [];
        foreach ($baris as $b) {
            $hasil[(string) $b->minggu] = [
                'jumlah' => (int) $b->jumlah,
                'layak_d7' => (int) $b->layak_d7,
                'kembali_d7' => (int) $b->kembali_d7,
                'layak_d30' => (int) $b->layak_d30,
                'kembali_d30' => (int) $b->kembali_d30,
            ];
        }

        return $hasil;
    }

    /** @return array<string, int> nilai enum jenis_kelamin => banyak siswa (yang belum mengisi tidak ikut) */
    private function jumlahJenisKelamin(): array
    {
        $baris = DB::select(<<<'SQL'
            select s.jenis_kelamin::text as nilai, count(*) as jumlah
            from siswa s
            join users u on u.id = s.user_id
            where u.role = 'siswa' and s.jenis_kelamin is not null
            group by 1
            SQL);

        return $this->petakan($baris, 'nilai');
    }

    /** @return array<string, int> jenjang prodi tujuan => banyak siswa */
    private function jumlahJenjang(): array
    {
        $baris = DB::select(<<<'SQL'
            select p.jenjang::text as jenjang, count(*) as jumlah
            from siswa s
            join users u on u.id = s.user_id
            join program_studi p on p.id = s.prodi_tujuan_id
            where u.role = 'siswa'
            group by 1
            SQL);

        return $this->petakan($baris, 'jenjang');
    }

    /** @return array<int, array{nama: string, jumlah: int}> */
    private function topUniversitas(): array
    {
        $baris = DB::select(<<<'SQL'
            select un.nama_universitas as nama, count(*) as jumlah
            from siswa s
            join users u on u.id = s.user_id
            join universitas un on un.id = s.universitas_tujuan_id
            where u.role = 'siswa'
            group by un.id, un.nama_universitas
            order by jumlah desc, nama
            limit ?
            SQL, [AnalitikPengguna::MAKS_TUJUAN]);

        return array_map(fn ($b) => ['nama' => $b->nama, 'jumlah' => (int) $b->jumlah], $baris);
    }

    /** @return array<int, array{nama: string, jenjang: string, universitas: string, jumlah: int}> */
    private function topProdi(): array
    {
        $baris = DB::select(<<<'SQL'
            select p.nama_prodi as nama, p.jenjang::text as jenjang, un.nama_universitas as universitas, count(*) as jumlah
            from siswa s
            join users u on u.id = s.user_id
            join program_studi p on p.id = s.prodi_tujuan_id
            join universitas un on un.id = p.universitas_id
            where u.role = 'siswa'
            group by p.id, p.nama_prodi, p.jenjang, un.nama_universitas
            order by jumlah desc, nama
            limit ?
            SQL, [AnalitikPengguna::MAKS_TUJUAN]);

        return array_map(fn ($b) => [
            'nama' => $b->nama,
            'jenjang' => $b->jenjang,
            'universitas' => $b->universitas,
            'jumlah' => (int) $b->jumlah,
        ], $baris);
    }

    /**
     * @param  array<int, object>  $baris  hasil query dengan kolom $kunci dan jumlah
     * @return array<string, int>
     */
    private function petakan(array $baris, string $kunci): array
    {
        $hasil = [];
        foreach ($baris as $b) {
            $hasil[(string) $b->{$kunci}] = (int) $b->jumlah;
        }

        return $hasil;
    }
}
