import { AngkaKecil, angka, desimal, Kosong, Panel, persenTeks, Tabel } from '@/Components/Analitik/Bagian';
import { formatTanggalWib } from '@/lib/waktu';
import { Link } from '@inertiajs/react';

interface PaketTryOut {
    id: string;
    judul: string;
    mulai: string; // ISO
    selesai: string; // ISO
    status: 'draft' | 'dibuka' | 'ditutup';
    dinilai: boolean; // skor IRT sudah dihitung
    peserta: number;
    pesertaSelesai: number;
    rataSkor: number | null;
}

interface RincianTryOut {
    ringkasan: { peserta: number; rata: number | null; median: number | null; min: number | null; maks: number | null };
    histogram: { label: string; jumlah: number }[];
    perSubtes: {
        kode: string;
        nama: string;
        peserta: number;
        rataSkor: number | null;
        akurasi: number | null;
        jatahMenit: number;
        rataMenit: number | null;
        persenWaktuHabis: number | null; // memakai ≥ 95% jatah waktu
    }[];
    peringkat: { nama: string; skor: number }[];
}

export interface DataTryOut {
    dihitungPada: string;
    hari: number;
    daftar: PaketTryOut[]; // terbaru dulu
    terpilih: string | null;
    rincian: RincianTryOut | null;
}

const LABEL_STATUS: Record<PaketTryOut['status'], { teks: string; kelas: string }> = {
    draft: { teks: 'Draft', kelas: 'bg-siswa-umpan-kosong text-siswa-teks' },
    dibuka: { teks: 'Dibuka', kelas: 'bg-siswa-umpan-benar text-siswa-umpan-benar-teks' },
    ditutup: { teks: 'Ditutup', kelas: 'bg-siswa-badge-subtes text-siswa-subtes' },
};

/** Histogram skor 0–1000: batang vertikal per rentang 100. */
function Histogram({ data }: { data: RincianTryOut['histogram'] }) {
    const maks = Math.max(0, ...data.map((d) => d.jumlah));
    if (maks === 0) return <Kosong teks="Belum ada skor." />;

    return (
        <div>
            <div className="flex h-40 items-end gap-1.5">
                {data.map((d) => (
                    <div key={d.label} className="flex h-full min-w-0 flex-1 flex-col justify-end" title={`${d.label}: ${angka(d.jumlah)} siswa`}>
                        <span className="mb-1 text-center text-[11px] font-semibold text-siswa-judul-seksi">{d.jumlah > 0 ? angka(d.jumlah) : ''}</span>
                        <div className="rounded-t-[6px] bg-edvora-primary" style={{ height: `${(d.jumlah / maks) * 100}%`, minHeight: d.jumlah > 0 ? 3 : 0 }} />
                    </div>
                ))}
            </div>
            <div className="mt-1 flex gap-1.5 border-t border-siswa-garis-halus pt-1">
                {data.map((d) => (
                    <span key={d.label} className="min-w-0 flex-1 truncate text-center text-[10px] text-siswa-teks">
                        {d.label.split('–')[0]}
                    </span>
                ))}
            </div>
        </div>
    );
}

export default function TabTryOut({ data }: { data: DataTryOut }) {
    const paket = data.daftar.find((p) => p.id === data.terpilih) ?? null;
    const r = data.rincian;

    return (
        <div className="space-y-4">
            <Panel urutan={0} judul="Paket Try Out" keterangan="Pilih paket untuk melihat rinciannya">
                <Tabel
                    kepala={[
                        { label: 'Paket' },
                        { label: 'Periode' },
                        { label: 'Status' },
                        { label: 'Peserta', kanan: true },
                        { label: 'Selesai', kanan: true },
                        { label: 'Rata-rata skor', kanan: true },
                    ]}
                    baris={data.daftar.map((p) => [
                        <Link
                            key="j"
                            href={route('admin.analitik', { bagian: 'tryout', tryout: p.id })}
                            preserveScroll
                            className={`font-medium hover:underline ${p.id === data.terpilih ? 'text-edvora-primary' : ''}`}
                        >
                            {p.judul}
                        </Link>,
                        <span key="p" className="whitespace-nowrap text-siswa-teks">
                            {formatTanggalWib(p.mulai)} – {formatTanggalWib(p.selesai)}
                        </span>,
                        <span key="s" className={`whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-medium ${LABEL_STATUS[p.status].kelas}`}>
                            {LABEL_STATUS[p.status].teks}
                        </span>,
                        angka(p.peserta),
                        angka(p.pesertaSelesai),
                        p.dinilai ? desimal(p.rataSkor) : <span className="text-siswa-teks">belum dinilai</span>,
                    ])}
                    kosong="Belum ada paket Try Out."
                />
            </Panel>

            {paket && r && (
                <>
                    <div className="px-1">
                        <h3 className="text-[18px] font-semibold text-siswa-judul-seksi">{paket.judul}</h3>
                        {!paket.dinilai && <p className="text-[12px] text-siswa-teks">Skor muncul setelah paket ditutup dan dinilai.</p>}
                    </div>

                    <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <AngkaKecil label="Peserta dinilai" nilai={r.ringkasan.peserta} keterangan={`dari ${angka(paket.peserta)} peserta`} />
                        <AngkaKecil label="Rata-rata" nilai={desimal(r.ringkasan.rata)} keterangan="skala 0–1000" />
                        <AngkaKecil label="Median" nilai={desimal(r.ringkasan.median)} />
                        <AngkaKecil label="Terendah – tertinggi" nilai={r.ringkasan.min === null ? '–' : `${desimal(r.ringkasan.min, 0)}–${desimal(r.ringkasan.maks, 0)}`} />
                    </div>

                    <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
                        <Panel urutan={1} judul="Sebaran Skor" keterangan="Banyak siswa per rentang skor">
                            <Histogram data={r.histogram} />
                        </Panel>
                        <Panel urutan={2} judul="Peringkat" keterangan={`${r.peringkat.length} skor tertinggi`}>
                            {r.peringkat.length === 0 ? (
                                <Kosong teks="Belum ada skor." />
                            ) : (
                                <ol className="divide-y divide-siswa-garis-halus/70">
                                    {r.peringkat.map((p, i) => (
                                        <li key={`${p.nama}-${i}`} className="flex items-center gap-3 py-2 text-[13px]">
                                            <span className="w-6 shrink-0 text-right font-semibold text-siswa-teks">{i + 1}</span>
                                            <span className="min-w-0 flex-1 truncate text-siswa-judul-seksi">{p.nama}</span>
                                            <span className="shrink-0 font-semibold tabular-nums text-siswa-judul-seksi">{desimal(p.skor)}</span>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </Panel>
                    </div>

                    <Panel urutan={3} judul="Per Subtes" keterangan="Waktu habis = memakai 95% jatah waktu atau lebih">
                        <Tabel
                            kepala={[
                                { label: 'Subtes' },
                                { label: 'Peserta', kanan: true },
                                { label: 'Rata-rata skor', kanan: true },
                                { label: 'Akurasi', kanan: true },
                                { label: 'Waktu rata-rata', kanan: true },
                                { label: 'Waktu habis', kanan: true },
                            ]}
                            baris={r.perSubtes.map((s) => [
                                <span key="s">
                                    <span className="font-medium">{s.kode}</span> <span className="text-siswa-teks">{s.nama}</span>
                                </span>,
                                angka(s.peserta),
                                desimal(s.rataSkor),
                                persenTeks(s.akurasi),
                                s.rataMenit === null ? '–' : `${desimal(s.rataMenit)} / ${desimal(s.jatahMenit)} menit`,
                                persenTeks(s.persenWaktuHabis),
                            ])}
                            kosong="Paket ini belum punya subtes."
                        />
                    </Panel>
                </>
            )}
        </div>
    );
}
