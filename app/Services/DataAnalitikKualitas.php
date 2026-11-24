<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Data tambahan Dashboard Analitik editor: kualitas soal (kalibrasi label, IRT, pengecoh) dan penguasaan materi
 * (sebaran tahap, topik macet, perpindahan tahap). Semua query hanya membaca (SELECT). Disimpan terpisah dari
 * DataAnalitik supaya cache lama milik bagian yang sudah ada tidak perlu diubah.
 */
class DataAnalitikKualitas
{
    private const KUNCI_CACHE = 'analitik_editor_kualitas';

    private const MENIT_CACHE = 60;

    // Jawaban yang dibaca: sekian hari terakhir.
    public const HARI = 90;

    // Soal baru dinilai bila jawabannya minimal sebanyak ini, supaya satu-dua jawaban tidak menyesatkan.
    public const MIN_JAWABAN_SOAL = 10;

    // Daya beda IRT (a) di bawah ini dianggap rendah: soal kurang bisa membedakan siswa kuat dan lemah.
    public const IRT_A_RENDAH = 0.5;

    // Topik dianggap macet bila siswa sudah mengerjakan sebanyak ini sejak tahapnya terakhir berubah.
    public const N_MACET = 3 * Penguasaan::JENDELA;

    private const MAKS_DAFTAR = 15;

    /** @return array<string, mixed> props kualitas di halaman Editor/Analitik (camelCase) */
    public function ambil(): array
    {
        return Cache::remember(self::KUNCI_CACHE, now()->addMinutes(self::MENIT_CACHE), function () {
            $sekarang = CarbonImmutable::now(AnalitikSoal::ZONA_WAKTU);
            $batas = $sekarang->startOfDay()->subDays(self::HARI - 1)->toIso8601String();

            $statistikSoal = $this->statistikSoal($batas);

            return [
                'dihitungPada' => $sekarang->toIso8601String(),
                'hari' => self::HARI,
                'minJawaban' => self::MIN_JAWABAN_SOAL,
                'kalibrasi' => [
                    'soalDinilai' => count($statistikSoal),
                    'perTingkat' => AnalitikLanjutan::akurasiPerTingkat($statistikSoal),
                    'tidakCocok' => AnalitikLanjutan::labelTidakCocok($statistikSoal, self::MAKS_DAFTAR),
                    'rentangWajar' => AnalitikLanjutan::AKURASI_WAJAR,
                ],
                'belumDikerjakan' => (int) DB::selectOne(<<<'SQL'
                    select count(*) as jumlah from soal q
                    where q.status = 'published'
                      and not exists (select 1 from jawaban_pengerjaan j where j.soal_id = q.id)
                    SQL)->jumlah,
                'irt' => $this->irt(),
                'pengecoh' => AnalitikLanjutan::pengecohKuat($this->opsiDipilih($batas), self::MIN_JAWABAN_SOAL, self::MAKS_DAFTAR),
                'tahapTopik' => $this->tahapTopik(),
                'nMacet' => self::N_MACET,
                'perpindahanTahap' => $this->perpindahanTahap($sekarang->startOfDay()->subDays(29)->toIso8601String()),
            ];
        });
    }

    /**
     * Jumlah jawaban dan jawaban benar setiap soal published sejak $batas, hanya soal dengan jawaban cukup.
     *
     * @return array<int, array{kodeSoal: string, kodeSubtes: string, topik: string, tingkat: string, jawaban: int, benar: int}>
     */
    private function statistikSoal(string $batas): array
    {
        return array_map(fn ($b) => [
            'kodeSoal' => $b->kode_soal,
            'kodeSubtes' => $b->kode_subtes,
            'topik' => $b->nama_topik,
            'tingkat' => $b->tingkat,
            'jawaban' => (int) $b->jawaban,
            'benar' => (int) $b->benar,
        ], DB::select(<<<'SQL'
            select q.kode_soal, s.kode_subtes, t.nama_topik, q.tingkat_kesulitan::text as tingkat,
                   count(j.id) as jawaban, count(j.id) filter (where j.is_correct) as benar
            from soal q
            join subtes s on s.id = q.subtes_id
            join topik t on t.id = q.topik_id
            join jawaban_pengerjaan j on j.soal_id = q.id
            where q.status = 'published' and j.waktu_menjawab >= ?
            group by q.id, q.kode_soal, s.kode_subtes, t.nama_topik, q.tingkat_kesulitan
            having count(j.id) >= ?
            SQL, [$batas, self::MIN_JAWABAN_SOAL]));
    }

    /** @return array<string, mixed> */
    private function irt(): array
    {
        $ringkas = DB::selectOne(<<<'SQL'
            select count(*) as total, count(*) filter (where irt_a is not null) as terkalibrasi
            from soal
            where status = 'published'
            SQL);

        return [
            'published' => (int) $ringkas->total,
            'terkalibrasi' => (int) $ringkas->terkalibrasi,
            'batasA' => self::IRT_A_RENDAH,
            'dayaBedaRendah' => array_map(fn ($b) => [
                'kodeSoal' => $b->kode_soal,
                'kodeSubtes' => $b->kode_subtes,
                'tingkat' => $b->tingkat,
                'a' => round((float) $b->irt_a, 2),
                'b' => $b->irt_b === null ? null : round((float) $b->irt_b, 2),
            ], DB::select(<<<'SQL'
                select q.kode_soal, s.kode_subtes, q.tingkat_kesulitan::text as tingkat, q.irt_a, q.irt_b
                from soal q
                join subtes s on s.id = q.subtes_id
                where q.status = 'published' and q.irt_a is not null and q.irt_a < ?
                order by q.irt_a
                limit ?
                SQL, [self::IRT_A_RENDAH, self::MAKS_DAFTAR])),
        ];
    }

    /**
     * Berapa kali setiap opsi soal pilihan ganda published dipilih sejak $batas. Semua opsi ikut (juga yang tidak
     * pernah dipilih), supaya label kuncinya selalu ada.
     *
     * @return array<int, array{soalId: string, kodeSoal: string, kodeSubtes: string, label: string, kunci: bool, dipilih: int}>
     */
    private function opsiDipilih(string $batas): array
    {
        return array_map(fn ($b) => [
            'soalId' => $b->soal_id,
            'kodeSoal' => $b->kode_soal,
            'kodeSubtes' => $b->kode_subtes,
            'label' => $b->label,
            'kunci' => (bool) $b->is_kunci,
            'dipilih' => (int) $b->dipilih,
        ], DB::select(<<<'SQL'
            select q.id as soal_id, q.kode_soal, s.kode_subtes, o.label, o.is_kunci, count(j.id) as dipilih
            from soal q
            join subtes s on s.id = q.subtes_id
            join opsi_jawaban o on o.soal_id = q.id
            left join jawaban_pengerjaan j on j.opsi_dipilih_id = o.id and j.waktu_menjawab >= ?
            where q.tipe = 'pilihan_ganda' and q.status = 'published'
            group by q.id, q.kode_soal, s.kode_subtes, o.id, o.label, o.is_kunci
            SQL, [$batas]));
    }

    /**
     * Banyak siswa di tiap tahap per topik, dan berapa yang macet. Topik tanpa siswa ikut dengan angka 0.
     *
     * @return array<int, array{kodeSubtes: string, topik: string, tahap1: int, tahap2: int, tahap3: int, macet: int}>
     */
    private function tahapTopik(): array
    {
        return array_map(fn ($b) => [
            'kodeSubtes' => $b->kode_subtes,
            'topik' => $b->nama_topik,
            'tahap1' => (int) $b->tahap1,
            'tahap2' => (int) $b->tahap2,
            'tahap3' => (int) $b->tahap3,
            'macet' => (int) $b->macet,
        ], DB::select(<<<'SQL'
            select s.kode_subtes, t.nama_topik,
                   count(pt.id) filter (where pt.tahap = 1) as tahap1,
                   count(pt.id) filter (where pt.tahap = 2) as tahap2,
                   count(pt.id) filter (where pt.tahap = 3) as tahap3,
                   count(pt.id) filter (where pt.tahap < ? and pt.n_di_tahap >= ?) as macet
            from topik t
            join subtes s on s.id = t.subtes_id
            left join penguasaan_topik pt on pt.topik_id = t.id
            group by s.kode_subtes, s.urutan, t.id, t.nama_topik, t.urutan
            order by s.urutan, t.urutan, t.nama_topik
            SQL, [Penguasaan::TAHAP_AKHIR, self::N_MACET]));
    }

    /** @return array<int, array{kode: string, naik: int, turun: int}> perpindahan tahap 30 hari terakhir per subtes */
    private function perpindahanTahap(string $batas): array
    {
        return array_map(fn ($b) => [
            'kode' => $b->kode_subtes,
            'naik' => (int) $b->naik,
            'turun' => (int) $b->turun,
        ], DB::select(<<<'SQL'
            select s.kode_subtes,
                   count(*) filter (where r.ke_tahap > r.dari_tahap) as naik,
                   count(*) filter (where r.ke_tahap < r.dari_tahap) as turun
            from riwayat_tahap r
            join topik t on t.id = r.topik_id
            join subtes s on s.id = t.subtes_id
            where r.created_at >= ?
            group by s.kode_subtes, s.urutan
            order by s.urutan
            SQL, [$batas]));
    }
}
