<?php

namespace App\Services;

use App\Models\TryOut;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Data halaman Analitik admin, per tab. Semua query hanya membaca (SELECT). Hanya tab yang dibuka yang dihitung,
 * lalu disimpan di cache selama MENIT_CACHE.
 *
 * Tanggal dikelompokkan per hari WIB, sedangkan database menyimpan waktu dalam UTC.
 */
class DataAnalitikAdmin
{
    public const BAGIAN = ['belajar', 'tryout', 'gamifikasi', 'operasional'];

    // Semua tab (kecuali daftar Try Out) melihat aktivitas sekian hari terakhir.
    public const HARI = 30;

    private const MENIT_CACHE = 15;

    // Daftar Try Out terbaru yang ditampilkan.
    private const MAKS_TRY_OUT = 20;

    private const MAKS_PERINGKAT = 10;

    private const MAKS_DAFTAR = 10;

    // Subtes Try Out dianggap "memakai hampir seluruh waktu" bila durasinya ≥ porsi ini dari jatah waktunya.
    private const PORSI_WAKTU_HABIS = 0.95;

    /** @return array<string, mixed> */
    public function ambil(string $bagian, ?string $tryOutId = null): array
    {
        $kunci = "analitik_admin_{$bagian}".($bagian === 'tryout' ? '_'.($tryOutId ?? 'terbaru') : '');

        return Cache::remember($kunci, now()->addMinutes(self::MENIT_CACHE), function () use ($bagian, $tryOutId) {
            $sekarang = CarbonImmutable::now(AnalitikSoal::ZONA_WAKTU);
            $data = match ($bagian) {
                'belajar' => $this->belajar($sekarang),
                'tryout' => $this->tryOut($sekarang, $tryOutId),
                'gamifikasi' => $this->gamifikasi($sekarang),
                'operasional' => $this->operasional($sekarang),
            };

            return ['dihitungPada' => $sekarang->toIso8601String(), 'hari' => self::HARI] + $data;
        });
    }

    /** Awal hari pertama jendela HARI (WIB). */
    private function dari(CarbonImmutable $sekarang): CarbonImmutable
    {
        return $sekarang->startOfDay()->subDays(self::HARI - 1);
    }

    // ---------------------------------------------------------------- Belajar (latihan)

    /** @return array<string, mixed> */
    private function belajar(CarbonImmutable $sekarang): array
    {
        $dari = $this->dari($sekarang);
        $batas = $dari->toIso8601String();

        $sesi = DB::select(<<<'SQL'
            select coalesce(mode_latihan, 'lainnya') as mode,
                   count(*) as sesi,
                   count(*) filter (where status = 'selesai') as selesai,
                   count(*) filter (where status = 'dibatalkan') as dibatalkan,
                   count(*) filter (where status = 'berjalan') as berjalan,
                   count(*) filter (where sesi_ulangan) as ulangan,
                   avg(extract(epoch from (finished_at - started_at)) / 60)
                       filter (where status = 'selesai' and finished_at is not null) as durasi_menit
            from pengerjaan
            where tipe = 'latihan_bebas' and started_at >= ?
            group by 1
            SQL, [$batas]);

        $jawaban = [];
        foreach (DB::select(<<<'SQL'
            select coalesce(p.mode_latihan, 'lainnya') as mode,
                   count(*) as jawaban,
                   count(*) filter (where j.is_correct) as benar,
                   count(*) filter (where j.pakai_hint) as hint,
                   count(*) filter (where j.pakai_hint and j.is_correct) as benar_hint
            from jawaban_pengerjaan j
            join pengerjaan p on p.id = j.pengerjaan_id
            where p.tipe = 'latihan_bebas' and j.waktu_menjawab >= ?
            group by 1
            SQL, [$batas]) as $b) {
            $jawaban[$b->mode] = $b;
        }

        $perMode = array_map(function ($s) use ($jawaban) {
            $j = $jawaban[$s->mode] ?? null;

            return [
                'mode' => $s->mode,
                'sesi' => (int) $s->sesi,
                'selesai' => (int) $s->selesai,
                'dibatalkan' => (int) $s->dibatalkan,
                'berjalan' => (int) $s->berjalan,
                'ulangan' => (int) $s->ulangan,
                'persenSelesai' => AnalitikPengguna::persen((int) $s->selesai, (int) $s->sesi),
                'durasiMenit' => $s->durasi_menit === null ? null : round((float) $s->durasi_menit, 1),
                'jawaban' => (int) ($j->jawaban ?? 0),
                'akurasi' => AnalitikPengguna::persen((int) ($j->benar ?? 0), (int) ($j->jawaban ?? 0)),
            ];
        }, $sesi);

        $total = fn (string $kolom) => array_sum(array_map(fn ($b) => (int) $b->{$kolom}, $jawaban));
        $jawabanHint = $total('hint');
        $jawabanTanpaHint = $total('jawaban') - $jawabanHint;

        $perSubtes = array_map(fn ($b) => [
            'kode' => $b->kode,
            'nama' => $b->nama,
            'jawaban' => (int) $b->jawaban,
            'akurasi' => AnalitikPengguna::persen((int) $b->benar, (int) $b->jawaban),
        ], DB::select(<<<'SQL'
            select s.kode_subtes as kode, s.nama_subtes as nama, count(*) as jawaban, count(*) filter (where j.is_correct) as benar
            from jawaban_pengerjaan j
            join pengerjaan p on p.id = j.pengerjaan_id
            join soal q on q.id = j.soal_id
            join subtes s on s.id = q.subtes_id
            where p.tipe = 'latihan_bebas' and j.waktu_menjawab >= ?
            group by s.id, s.kode_subtes, s.nama_subtes, s.urutan
            order by s.urutan
            SQL, [$batas]));

        $jamBelajar = AnalitikLanjutan::matriksJam(array_map(fn ($b) => [
            'hari' => (int) $b->hari,
            'jam' => (int) $b->jam,
            'jumlah' => (int) $b->jumlah,
        ], DB::select(<<<'SQL'
            select extract(isodow from waktu_menjawab at time zone 'Asia/Jakarta')::int as hari,
                   extract(hour from waktu_menjawab at time zone 'Asia/Jakarta')::int as jam,
                   count(*) as jumlah
            from jawaban_pengerjaan
            where waktu_menjawab >= ?
            group by 1, 2
            SQL, [$batas])));

        return [
            'perMode' => $perMode,
            'hint' => [
                'jawaban' => $total('jawaban'),
                'akurasi' => AnalitikPengguna::persen($total('benar'), $total('jawaban')),
                'pakaiHint' => $jawabanHint,
                'persenPakaiHint' => AnalitikPengguna::persen($jawabanHint, $total('jawaban')),
                'akurasiDenganHint' => AnalitikPengguna::persen($total('benar_hint'), $jawabanHint),
                'akurasiTanpaHint' => AnalitikPengguna::persen($total('benar') - $total('benar_hint'), $jawabanTanpaHint),
            ],
            'harian' => AnalitikLanjutan::deret([
                'mulai' => $this->perHari("select (started_at at time zone 'Asia/Jakarta')::date as hari, count(*) as jumlah
                    from pengerjaan where tipe = 'latihan_bebas' and started_at >= ? group by 1", [$batas]),
                'selesai' => $this->perHari("select (finished_at at time zone 'Asia/Jakarta')::date as hari, count(*) as jumlah
                    from pengerjaan where tipe = 'latihan_bebas' and status = 'selesai' and finished_at >= ? group by 1", [$batas]),
            ], $dari, $sekarang->startOfDay()),
            'perSubtes' => $perSubtes,
            'jamBelajar' => $jamBelajar,
        ];
    }

    // ---------------------------------------------------------------- Try Out

    /** @return array<string, mixed> */
    private function tryOut(CarbonImmutable $sekarang, ?string $tryOutId): array
    {
        $daftar = array_map(function ($b) use ($sekarang) {
            return [
                'id' => $b->id,
                'judul' => $b->judul,
                'mulai' => Carbon::parse($b->mulai_at)->toIso8601String(),
                'selesai' => Carbon::parse($b->selesai_at)->toIso8601String(),
                'status' => TryOut::statusPada(Carbon::parse($b->mulai_at), Carbon::parse($b->selesai_at), $sekarang),
                'dinilai' => $b->dinilai_at !== null,
                'peserta' => (int) $b->peserta,
                'pesertaSelesai' => (int) $b->peserta_selesai,
                'rataSkor' => $b->rata_skor === null ? null : round((float) $b->rata_skor, 1),
            ];
        }, DB::select(<<<'SQL'
            select t.id, t.judul, t.mulai_at, t.selesai_at, t.dinilai_at,
                   count(p.id) as peserta,
                   count(p.id) filter (where p.status = 'selesai') as peserta_selesai,
                   avg(p.total_skor) filter (where p.status = 'selesai' and t.dinilai_at is not null) as rata_skor
            from try_out t
            left join pengerjaan p on p.try_out_id = t.id
            group by t.id
            order by t.mulai_at desc
            limit ?
            SQL, [self::MAKS_TRY_OUT]));

        // Paket yang dirinci: pilihan admin bila ada di daftar; selain itu paket terbaru yang sudah dinilai.
        $ids = array_column($daftar, 'id');
        $terpilih = in_array($tryOutId, $ids, true)
            ? $tryOutId
            : (collect($daftar)->firstWhere('dinilai', true)['id'] ?? ($ids[0] ?? null));

        return ['daftar' => $daftar, 'terpilih' => $terpilih, 'rincian' => $terpilih ? $this->rincianTryOut($terpilih) : null];
    }

    /** @return array<string, mixed> */
    private function rincianTryOut(string $id): array
    {
        $skor = array_map(fn ($b) => (float) $b->total_skor, DB::select(<<<'SQL'
            select total_skor from pengerjaan
            where try_out_id = ? and status = 'selesai' and total_skor is not null
            SQL, [$id]));

        $akurasi = [];
        foreach (DB::select(<<<'SQL'
            select ps.try_out_subtes_id as id, count(j.id) as jawaban, count(j.id) filter (where j.is_correct) as benar
            from pengerjaan_subtes ps
            join try_out_subtes ts on ts.id = ps.try_out_subtes_id
            join jawaban_pengerjaan j on j.pengerjaan_subtes_id = ps.id
            where ts.try_out_id = ?
            group by 1
            SQL, [$id]) as $b) {
            $akurasi[$b->id] = AnalitikPengguna::persen((int) $b->benar, (int) $b->jawaban);
        }

        $perSubtes = array_map(fn ($b) => [
            'kode' => $b->kode,
            'nama' => $b->nama,
            'peserta' => (int) $b->peserta,
            'rataSkor' => $b->rata_skor === null ? null : round((float) $b->rata_skor, 1),
            'akurasi' => $akurasi[$b->id] ?? null,
            'jatahMenit' => (float) $b->waktu_menit,
            'rataMenit' => $b->rata_menit === null ? null : round((float) $b->rata_menit, 1),
            'persenWaktuHabis' => AnalitikPengguna::persen((int) $b->waktu_habis, (int) $b->berdurasi),
        ], DB::select(<<<'SQL'
            select ts.id, s.kode_subtes as kode, s.nama_subtes as nama, ts.waktu_menit,
                   count(ps.id) as peserta,
                   avg(ps.skor_subtes) as rata_skor,
                   avg(extract(epoch from (ps.waktu_selesai - ps.waktu_mulai)) / 60) as rata_menit,
                   count(ps.id) filter (where ps.waktu_mulai is not null and ps.waktu_selesai is not null) as berdurasi,
                   count(ps.id) filter (where extract(epoch from (ps.waktu_selesai - ps.waktu_mulai)) >= ts.waktu_menit * 60 * ?) as waktu_habis
            from try_out_subtes ts
            join subtes s on s.id = ts.subtes_id
            left join pengerjaan_subtes ps on ps.try_out_subtes_id = ts.id
            where ts.try_out_id = ?
            group by ts.id, s.kode_subtes, s.nama_subtes, ts.waktu_menit, ts.urutan
            order by ts.urutan
            SQL, [self::PORSI_WAKTU_HABIS, $id]));

        $peringkat = array_map(fn ($b) => ['nama' => $b->nama, 'skor' => round((float) $b->skor, 1)], DB::select(<<<'SQL'
            select coalesce(s.nama_lengkap, u.name, 'Tanpa nama') as nama, p.total_skor as skor
            from pengerjaan p
            join users u on u.id = p.user_id
            left join siswa s on s.user_id = p.user_id
            where p.try_out_id = ? and p.status = 'selesai' and p.total_skor is not null
            order by p.total_skor desc, p.finished_at
            limit ?
            SQL, [$id, self::MAKS_PERINGKAT]));

        return [
            'ringkasan' => AnalitikLanjutan::ringkasSkor($skor),
            'histogram' => AnalitikLanjutan::histogram($skor),
            'perSubtes' => $perSubtes,
            'peringkat' => $peringkat,
        ];
    }

    // ---------------------------------------------------------------- Gamifikasi & ekonomi

    /** @return array<string, mixed> */
    private function gamifikasi(CarbonImmutable $sekarang): array
    {
        $dari = $this->dari($sekarang);
        $batas = $dari->toIso8601String();

        $xpPerSumber = array_map(fn ($b) => ['label' => $b->sumber, 'jumlah' => (int) $b->total], DB::select(<<<'SQL'
            select sumber_tipe::text as sumber, sum(jumlah) as total
            from xp_transactions
            where created_at >= ?
            group by 1
            order by total desc
            SQL, [$batas]));

        $point = DB::select(<<<'SQL'
            select sumber_tipe::text as sumber,
                   coalesce(sum(jumlah) filter (where jumlah > 0), 0) as masuk,
                   coalesce(-sum(jumlah) filter (where jumlah < 0), 0) as keluar
            from point_transactions
            where created_at >= ?
            group by 1
            SQL, [$batas]);

        $saldo = DB::selectOne(<<<'SQL'
            select count(*) as siswa, coalesce(sum(point), 0) as total_point, coalesce(avg(point), 0) as rata_point,
                   coalesce(sum(xp), 0) as total_xp
            from siswa
            SQL);

        $jumlahPerLevel = [];
        foreach (DB::select(<<<'SQL'
            select width_bucket(xp, cast(? as int[])) + 1 as level, count(*) as jumlah
            from siswa
            group by 1
            SQL, [AnalitikLanjutan::arrayPostgres(AnalitikLanjutan::ambangLevel())]) as $b) {
            $jumlahPerLevel[(int) $b->level] = (int) $b->jumlah;
        }

        $totalSiswa = (int) $saldo->siswa;

        return [
            'xpPerSumber' => $xpPerSumber,
            'pointMasukPerSumber' => array_values(array_filter(
                array_map(fn ($b) => ['label' => $b->sumber, 'jumlah' => (int) $b->masuk], $point),
                fn ($b) => $b['jumlah'] > 0,
            )),
            'pointKeluar' => array_sum(array_map(fn ($b) => (int) $b->keluar, $point)),
            'saldo' => [
                'totalPoint' => (int) $saldo->total_point,
                'rataPoint' => round((float) $saldo->rata_point, 1),
                'totalXp' => (int) $saldo->total_xp,
            ],
            'harian' => AnalitikLanjutan::deret([
                'xp' => $this->perHari("select (created_at at time zone 'Asia/Jakarta')::date as hari, sum(jumlah) as jumlah
                    from xp_transactions where created_at >= ? group by 1", [$batas]),
                'pointMasuk' => $this->perHari("select (created_at at time zone 'Asia/Jakarta')::date as hari, sum(jumlah) as jumlah
                    from point_transactions where jumlah > 0 and created_at >= ? group by 1", [$batas]),
                'pointKeluar' => $this->perHari("select (created_at at time zone 'Asia/Jakarta')::date as hari, -sum(jumlah) as jumlah
                    from point_transactions where jumlah < 0 and created_at >= ? group by 1", [$batas]),
            ], $dari, $sekarang->startOfDay()),
            'level' => AnalitikLanjutan::sebaranLevel($jumlahPerLevel),
            'avatar' => array_map(fn ($b) => [
                'label' => $b->nama,
                'sub' => "Level {$b->level_minimal} · {$b->harga_point} point",
                'jumlah' => (int) $b->pemilik,
                'persen' => AnalitikPengguna::persen((int) $b->pemilik, $totalSiswa),
            ], DB::select(<<<'SQL'
                select a.nama, a.level_minimal, a.harga_point, count(sa.id) as pemilik
                from avatar a
                left join siswa_avatar sa on sa.avatar_id = a.id
                group by a.id, a.nama, a.level_minimal, a.harga_point
                order by pemilik desc, a.level_minimal, a.nama
                SQL)),
            'badge' => array_map(fn ($b) => [
                'label' => $b->nama,
                'sub' => $b->syarat,
                'jumlah' => (int) $b->pemilik,
                'persen' => AnalitikPengguna::persen((int) $b->pemilik, $totalSiswa),
            ], DB::select(<<<'SQL'
                select b.nama_badge as nama, b.syarat_text as syarat, count(sb.id) as pemilik
                from badge b
                left join siswa_badge sb on sb.badge_id = b.id
                group by b.id, b.nama_badge, b.syarat_text
                order by pemilik desc, nama
                SQL)),
        ];
    }

    // ---------------------------------------------------------------- Operasional

    /** @return array<string, mixed> */
    private function operasional(CarbonImmutable $sekarang): array
    {
        $dari = $this->dari($sekarang);
        $batas = $dari->toIso8601String();

        $akun = array_map(fn ($b) => [
            'peran' => $b->peran,
            'total' => (int) $b->total,
            'nonaktif' => (int) $b->nonaktif,
            'belumVerifikasi' => (int) $b->belum_verifikasi,
            'login30Hari' => (int) $b->login,
        ], DB::select(<<<'SQL'
            select role::text as peran,
                   count(*) as total,
                   count(*) filter (where not is_active) as nonaktif,
                   count(*) filter (where email_verified_at is null) as belum_verifikasi,
                   count(*) filter (where terakhir_login_at >= ?) as login
            from users
            group by 1
            order by 1
            SQL, [$batas]));

        return [
            'akun' => $akun,
            'harian' => AnalitikLanjutan::deret([
                'admin' => $this->perHari("select (created_at at time zone 'Asia/Jakarta')::date as hari, count(*) as jumlah
                    from audit_log where peran = 'admin' and created_at >= ? group by 1", [$batas]),
                'editor' => $this->perHari("select (created_at at time zone 'Asia/Jakarta')::date as hari, count(*) as jumlah
                    from audit_log where peran = 'admin_editor' and created_at >= ? group by 1", [$batas]),
            ], $dari, $sekarang->startOfDay()),
            'topAksi' => array_map(fn ($b) => [
                'label' => $b->aksi,
                'sub' => $b->peran === 'admin' ? 'Admin' : 'Editor',
                'jumlah' => (int) $b->jumlah,
            ], DB::select(<<<'SQL'
                select aksi, peran::text as peran, count(*) as jumlah
                from audit_log
                where created_at >= ?
                group by 1, 2
                order by jumlah desc, aksi
                limit ?
                SQL, [$batas, self::MAKS_DAFTAR])),
            'pelaku' => array_map(fn ($b) => [
                'nama' => $b->nama,
                'peran' => $b->peran,
                'jumlah' => (int) $b->jumlah,
                'terakhir' => Carbon::parse($b->terakhir)->toIso8601String(),
            ], DB::select(<<<'SQL'
                select coalesce(u.name, 'Akun sudah dihapus') as nama, a.peran::text as peran, count(*) as jumlah,
                       max(a.created_at) as terakhir
                from audit_log a
                left join users u on u.id = a.user_id
                where a.created_at >= ?
                group by a.user_id, u.name, a.peran
                order by jumlah desc
                limit ?
                SQL, [$batas, self::MAKS_DAFTAR])),
        ];
    }

    /**
     * Jalankan query "hari, jumlah" menjadi peta 'Y-m-d' => jumlah.
     *
     * @param  array<int, mixed>  $ikatan
     * @return array<string, int>
     */
    private function perHari(string $sql, array $ikatan): array
    {
        $hasil = [];
        foreach (DB::select($sql, $ikatan) as $b) {
            $hasil[(string) $b->hari] = (int) $b->jumlah;
        }

        return $hasil;
    }
}
