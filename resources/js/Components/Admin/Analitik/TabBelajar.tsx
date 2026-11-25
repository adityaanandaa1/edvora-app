import { AngkaKecil, angka, desimal, GrafikGaris, Kosong, Panel, persenTeks, Tabel, WARNA_SERI } from '@/Components/Analitik/Bagian';

export interface DataBelajar {
    dihitungPada: string;
    hari: number;
    perMode: {
        mode: string; // fleksibel | simulasi | remedial | lainnya
        sesi: number;
        selesai: number;
        dibatalkan: number;
        berjalan: number;
        ulangan: number; // sesi "Ulangi Latihan"
        persenSelesai: number | null;
        durasiMenit: number | null; // rata-rata sesi yang selesai
        jawaban: number;
        akurasi: number | null;
    }[];
    hint: { jawaban: number; akurasi: number | null; pakaiHint: number; persenPakaiHint: number | null; akurasiDenganHint: number | null; akurasiTanpaHint: number | null };
    harian: { tanggal: string; mulai: number; selesai: number }[];
    perSubtes: { kode: string; nama: string; jawaban: number; akurasi: number | null }[];
    jamBelajar: number[][]; // [hari Senin..Minggu][jam 0..23] = jumlah jawaban
}

const LABEL_MODE: Record<string, string> = { fleksibel: 'Fleksibel', simulasi: 'Simulasi', remedial: 'Remedial', lainnya: 'Lainnya' };
const HARI = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];

/** Heatmap jumlah jawaban per hari × jam (WIB): kapan siswa paling banyak belajar. */
function PetaJam({ matriks }: { matriks: number[][] }) {
    const maks = Math.max(0, ...matriks.flat());
    if (maks === 0) return <Kosong teks="Belum ada jawaban." />;

    return (
        <div className="-mx-2 overflow-x-auto px-2">
            <table className="border-separate border-spacing-[3px] text-[11px]">
                <thead>
                    <tr>
                        <th />
                        {Array.from({ length: 24 }, (_, j) => (
                            <th key={j} className="w-6 font-normal text-siswa-teks">
                                {j % 3 === 0 ? j : ''}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {matriks.map((baris, h) => (
                        <tr key={h}>
                            <th className="pr-2 text-left font-medium text-siswa-teks">{HARI[h]}</th>
                            {baris.map((n, j) => (
                                <td
                                    key={j}
                                    title={`${HARI[h]}, ${String(j).padStart(2, '0')}.00–${String(j).padStart(2, '0')}.59 WIB: ${angka(n)} jawaban`}
                                    className="h-6 w-6 rounded-[4px]"
                                    style={{ backgroundColor: n === 0 ? '#F2F7FD' : `rgba(91, 136, 221, ${0.15 + (n / maks) * 0.85})` }}
                                />
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export default function TabBelajar({ data }: { data: DataBelajar }) {
    const sesi = data.perMode.reduce((n, m) => n + m.sesi, 0);
    const selesai = data.perMode.reduce((n, m) => n + m.selesai, 0);

    return (
        <div className="space-y-4">
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <AngkaKecil label="Sesi latihan" nilai={sesi} keterangan={`${data.hari} hari terakhir`} />
                <AngkaKecil label="Tingkat selesai" nilai={persenTeks(sesi > 0 ? Math.round((selesai / sesi) * 1000) / 10 : null)} keterangan={`${angka(selesai)} sesi selesai`} />
                <AngkaKecil label="Akurasi latihan" nilai={persenTeks(data.hint.akurasi)} keterangan={`dari ${angka(data.hint.jawaban)} jawaban`} />
                <AngkaKecil label="Jawaban dengan hint" nilai={persenTeks(data.hint.persenPakaiHint)} keterangan={`${angka(data.hint.pakaiHint)} jawaban`} />
            </div>

            <Panel urutan={0} judul="Sesi Latihan per Hari" keterangan="Sesi yang dimulai dan yang selesai">
                <GrafikGaris
                    data={data.harian}
                    seri={[
                        { kunci: 'mulai', label: 'Dimulai', ...WARNA_SERI[0] },
                        { kunci: 'selesai', label: 'Selesai', ...WARNA_SERI[2] },
                    ]}
                    label="Grafik sesi latihan per hari"
                />
            </Panel>

            <Panel urutan={1} judul="Per Mode Latihan" keterangan="Sesi Ulangi Latihan ikut dihitung di kolom Sesi">
                <Tabel
                    kepala={[
                        { label: 'Mode' },
                        { label: 'Sesi', kanan: true },
                        { label: 'Selesai', kanan: true },
                        { label: 'Batal', kanan: true },
                        { label: 'Berjalan', kanan: true },
                        { label: 'Ulangan', kanan: true },
                        { label: 'Durasi rata-rata', kanan: true },
                        { label: 'Jawaban', kanan: true },
                        { label: 'Akurasi', kanan: true },
                    ]}
                    baris={data.perMode.map((m) => [
                        LABEL_MODE[m.mode] ?? m.mode,
                        angka(m.sesi),
                        persenTeks(m.persenSelesai),
                        angka(m.dibatalkan),
                        angka(m.berjalan),
                        angka(m.ulangan),
                        m.durasiMenit === null ? '–' : `${desimal(m.durasiMenit)} menit`,
                        angka(m.jawaban),
                        persenTeks(m.akurasi),
                    ])}
                    kosong="Belum ada sesi latihan."
                />
            </Panel>

            <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
                <Panel urutan={2} judul="Pengaruh Hint" keterangan="Akurasi jawaban latihan dengan dan tanpa membuka hint">
                    <div className="grid grid-cols-2 gap-3">
                        <AngkaKecil label="Dengan hint" nilai={persenTeks(data.hint.akurasiDenganHint)} keterangan={`${angka(data.hint.pakaiHint)} jawaban`} />
                        <AngkaKecil label="Tanpa hint" nilai={persenTeks(data.hint.akurasiTanpaHint)} keterangan={`${angka(data.hint.jawaban - data.hint.pakaiHint)} jawaban`} />
                    </div>
                    <p className="mt-3 text-[12px] text-siswa-teks">Hint hanya tersedia di mode Fleksibel dan Remedial.</p>
                </Panel>
                <Panel urutan={3} judul="Akurasi per Subtes" keterangan="Jawaban latihan">
                    <Tabel
                        kepala={[{ label: 'Subtes' }, { label: 'Jawaban', kanan: true }, { label: 'Akurasi', kanan: true }]}
                        baris={data.perSubtes.map((s) => [
                            <span key="s">
                                <span className="font-medium">{s.kode}</span> <span className="text-siswa-teks">{s.nama}</span>
                            </span>,
                            angka(s.jawaban),
                            persenTeks(s.akurasi),
                        ])}
                        kosong="Belum ada jawaban latihan."
                    />
                </Panel>
            </div>

            <Panel urutan={4} judul="Jam Belajar" keterangan="Jumlah jawaban (latihan dan Try Out) per hari dan jam, WIB">
                <PetaJam matriks={data.jamBelajar} />
            </Panel>
        </div>
    );
}
