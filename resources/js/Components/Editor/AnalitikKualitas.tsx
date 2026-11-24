import { AngkaKecil, angka, desimal, Kosong, Panel, persenTeks, Tabel } from '@/Components/Analitik/Bagian';

type Tingkat = 'mudah' | 'sedang' | 'sulit';

export interface DataKualitas {
    dihitungPada: string;
    hari: number; // jawaban yang dibaca: sekian hari terakhir
    minJawaban: number; // soal baru dinilai bila jawabannya minimal sebanyak ini
    kalibrasi: {
        soalDinilai: number;
        perTingkat: { tingkat: Tingkat; soal: number; akurasi: number | null }[];
        tidakCocok: { kodeSoal: string; kodeSubtes: string; topik: string; tingkat: Tingkat; jawaban: number; akurasi: number; saran: string }[];
        rentangWajar: Record<Tingkat, [number, number]>;
    };
    belumDikerjakan: number; // soal published yang belum pernah dijawab
    irt: {
        published: number;
        terkalibrasi: number;
        batasA: number;
        dayaBedaRendah: { kodeSoal: string; kodeSubtes: string; tingkat: Tingkat; a: number; b: number | null }[];
    };
    pengecoh: { kodeSoal: string; kodeSubtes: string; jawaban: number; labelKunci: string | null; persenKunci: number; labelPengecoh: string; persenPengecoh: number }[];
    tahapTopik: { kodeSubtes: string; topik: string; tahap1: number; tahap2: number; tahap3: number; macet: number }[];
    nMacet: number;
    perpindahanTahap: { kode: string; naik: number; turun: number }[];
}

const LABEL_TINGKAT: Record<Tingkat, string> = { mudah: 'Mudah', sedang: 'Sedang', sulit: 'Sulit' };

// Batang bertumpuk tahap 1/2/3 untuk satu topik.
function BatangTahap({ t1, t2, t3 }: { t1: number; t2: number; t3: number }) {
    const total = t1 + t2 + t3;
    if (total === 0) return <span className="text-siswa-teks">–</span>;
    const lebar = (n: number) => `${(n / total) * 100}%`;

    return (
        <div className="flex h-2.5 w-full min-w-[120px] overflow-hidden rounded-full bg-siswa-laman-awal" title={`Tahap 1: ${t1} · Tahap 2: ${t2} · Tahap 3: ${t3}`}>
            <div className="bg-siswa-cincin-rendah" style={{ width: lebar(t1) }} />
            <div className="bg-siswa-cincin-naik" style={{ width: lebar(t2) }} />
            <div className="bg-siswa-umpan-benar-teks" style={{ width: lebar(t3) }} />
        </div>
    );
}

/** Bagian tambahan Dashboard Analitik editor: kualitas soal dan penguasaan materi. */
export default function AnalitikKualitas({ data }: { data: DataKualitas }) {
    const { kalibrasi, irt } = data;
    const topikAdaSiswa = data.tahapTopik.filter((t) => t.tahap1 + t.tahap2 + t.tahap3 > 0);

    return (
        <section className="mt-8 space-y-4" aria-label="Kualitas Soal dan Penguasaan">
            <div>
                <h2 className="text-2xl font-semibold text-siswa-judul">Kualitas Soal</h2>
                <p className="text-sm text-siswa-teks">
                    Dari jawaban {data.hari} hari terakhir; soal dinilai bila sudah dijawab minimal {data.minJawaban} kali
                </p>
            </div>

            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <AngkaKecil label="Soal dinilai" nilai={kalibrasi.soalDinilai} keterangan="jawaban cukup" />
                <AngkaKecil label="Label tidak cocok" nilai={kalibrasi.tidakCocok.length} keterangan="akurasi di luar rentang wajar" />
                <AngkaKecil label="Terkalibrasi IRT" nilai={`${angka(irt.terkalibrasi)} / ${angka(irt.published)}`} keterangan="soal published" />
                <AngkaKecil label="Belum pernah dijawab" nilai={data.belumDikerjakan} keterangan="soal published" />
            </div>

            <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
                <Panel judul="Akurasi per Label" keterangan="Rata-rata akurasi soal sesuai label tingkat kesulitannya">
                    <Tabel
                        kepala={[{ label: 'Label' }, { label: 'Soal', kanan: true }, { label: 'Akurasi', kanan: true }, { label: 'Rentang wajar', kanan: true }]}
                        baris={kalibrasi.perTingkat.map((t) => [
                            LABEL_TINGKAT[t.tingkat],
                            angka(t.soal),
                            persenTeks(t.akurasi),
                            `${kalibrasi.rentangWajar[t.tingkat][0]}–${kalibrasi.rentangWajar[t.tingkat][1]}%`,
                        ])}
                        kosong="Belum ada soal dengan jawaban cukup."
                    />
                </Panel>
                <Panel judul="Pengecoh Lebih Kuat dari Kunci" keterangan="Satu opsi salah dipilih lebih sering daripada kunci: periksa kunci atau redaksi soal">
                    <Tabel
                        kepala={[{ label: 'Soal' }, { label: 'Jawaban', kanan: true }, { label: 'Kunci', kanan: true }, { label: 'Pengecoh', kanan: true }]}
                        baris={data.pengecoh.map((p) => [
                            <span key="s">
                                <span className="font-medium">{p.kodeSoal}</span> <span className="text-siswa-teks">{p.kodeSubtes}</span>
                            </span>,
                            angka(p.jawaban),
                            `${p.labelKunci ?? '–'} · ${persenTeks(p.persenKunci)}`,
                            <span key="p" className="font-semibold text-siswa-umpan-salah-teks">
                                {p.labelPengecoh} · {persenTeks(p.persenPengecoh)}
                            </span>,
                        ])}
                        kosong="Tidak ada soal dengan pengecoh yang lebih kuat dari kuncinya."
                    />
                </Panel>
            </div>

            <Panel judul="Label Tingkat Kesulitan Tidak Cocok" keterangan="Akurasi siswa di luar rentang wajar labelnya, paling menyimpang dulu">
                <Tabel
                    kepala={[{ label: 'Soal' }, { label: 'Topik' }, { label: 'Label' }, { label: 'Jawaban', kanan: true }, { label: 'Akurasi', kanan: true }, { label: 'Catatan' }]}
                    baris={kalibrasi.tidakCocok.map((s) => [
                        <span key="s">
                            <span className="font-medium">{s.kodeSoal}</span> <span className="text-siswa-teks">{s.kodeSubtes}</span>
                        </span>,
                        s.topik,
                        LABEL_TINGKAT[s.tingkat],
                        angka(s.jawaban),
                        persenTeks(s.akurasi),
                        <span key="c" className="text-siswa-teks">
                            {s.saran}
                        </span>,
                    ])}
                    kosong="Semua soal yang dinilai sesuai labelnya."
                />
            </Panel>

            <Panel judul="Daya Beda Rendah (IRT)" keterangan={`Soal dengan parameter a < ${desimal(irt.batasA)}: kurang bisa membedakan siswa kuat dan lemah`}>
                <Tabel
                    kepala={[{ label: 'Soal' }, { label: 'Label' }, { label: 'Daya beda (a)', kanan: true }, { label: 'Kesulitan (b)', kanan: true }]}
                    baris={irt.dayaBedaRendah.map((s) => [
                        <span key="s">
                            <span className="font-medium">{s.kodeSoal}</span> <span className="text-siswa-teks">{s.kodeSubtes}</span>
                        </span>,
                        LABEL_TINGKAT[s.tingkat],
                        desimal(s.a, 2),
                        desimal(s.b, 2),
                    ])}
                    kosong={irt.terkalibrasi === 0 ? 'Belum ada soal yang dikalibrasi (paket Try Out belum dinilai).' : 'Tidak ada soal dengan daya beda rendah.'}
                />
            </Panel>

            <div className="pt-4">
                <h2 className="text-2xl font-semibold text-siswa-judul">Penguasaan Materi</h2>
                <p className="text-sm text-siswa-teks">Sebaran siswa per tahap di setiap topik</p>
            </div>

            <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
                <Panel judul="Sebaran Tahap per Topik" keterangan={`Macet = belum naik tahap setelah ${data.nMacet} soal`} className="lg:col-span-2">
                    <div className="mb-3 flex flex-wrap gap-4 text-[12px] text-siswa-teks">
                        <span className="flex items-center gap-1.5">
                            <span className="h-2.5 w-2.5 rounded-full bg-siswa-cincin-rendah" /> Tahap 1
                        </span>
                        <span className="flex items-center gap-1.5">
                            <span className="h-2.5 w-2.5 rounded-full bg-siswa-cincin-naik" /> Tahap 2
                        </span>
                        <span className="flex items-center gap-1.5">
                            <span className="h-2.5 w-2.5 rounded-full bg-siswa-umpan-benar-teks" /> Tahap 3
                        </span>
                    </div>
                    {topikAdaSiswa.length === 0 ? (
                        <Kosong teks="Belum ada siswa yang berlatih." />
                    ) : (
                        <Tabel
                            kepala={[{ label: 'Topik' }, { label: 'Sebaran' }, { label: 'Siswa', kanan: true }, { label: 'Macet', kanan: true }]}
                            baris={topikAdaSiswa.map((t) => [
                                <span key="t">
                                    <span className="font-medium">{t.kodeSubtes}</span> <span className="text-siswa-teks">{t.topik}</span>
                                </span>,
                                <BatangTahap key="b" t1={t.tahap1} t2={t.tahap2} t3={t.tahap3} />,
                                angka(t.tahap1 + t.tahap2 + t.tahap3),
                                t.macet > 0 ? <span key="m" className="font-semibold text-siswa-umpan-salah-teks">{angka(t.macet)}</span> : '0',
                            ])}
                            kosong="Belum ada topik."
                        />
                    )}
                </Panel>
                <Panel judul="Perpindahan Tahap" keterangan="30 hari terakhir">
                    <Tabel
                        kepala={[{ label: 'Subtes' }, { label: 'Naik', kanan: true }, { label: 'Turun', kanan: true }]}
                        baris={data.perpindahanTahap.map((p) => [p.kode, angka(p.naik), angka(p.turun)])}
                        kosong="Belum ada perpindahan tahap."
                    />
                </Panel>
            </div>
        </section>
    );
}
