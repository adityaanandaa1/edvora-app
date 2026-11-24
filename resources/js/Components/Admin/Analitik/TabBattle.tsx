import { AngkaKecil, angka, DaftarBatang, desimal, GrafikGaris, Panel, persenTeks, Tabel, WARNA_SERI } from '@/Components/Analitik/Bagian';

export interface DataBattle {
    dihitungPada: string;
    hari: number;
    perMode: { mode: string; total: number; selesai: number; batal: number; menunggu: number; berjalan: number; rataPeserta: number | null }[];
    tertahan: number; // battle waiting lebih lama dari menitTertahan
    menitTertahan: number;
    jawaban: number;
    akurasiBattle: number | null;
    akurasiLatihan: number | null; // pembanding pada rentang yang sama
    medianDetik: number | null; // median waktu jawab
    perSubtes: { label: string; sub: string; jumlah: number }[];
    harian: { tanggal: string; dibuat: number; selesai: number }[];
}

const LABEL_MODE: Record<string, string> = { solo: 'Solo', '1v1': '1 lawan 1', grup: 'Grup' };

export default function TabBattle({ data }: { data: DataBattle }) {
    const total = data.perMode.reduce((n, m) => n + m.total, 0);

    return (
        <div className="space-y-4">
            {total === 0 && (
                <div role="status" className="rounded-kartu bg-siswa-hint-latar px-[26px] py-4 text-[13px] text-siswa-hint-teks shadow-kartu">
                    Belum ada battle yang tercatat. Halaman Battle saat ini baru tampilan; data akan muncul setelah fiturnya menyimpan battle ke database.
                </div>
            )}

            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <AngkaKecil label="Battle dibuat" nilai={total} keterangan={`${data.hari} hari terakhir`} />
                <AngkaKecil label="Tertahan" nilai={data.tertahan} keterangan={`menunggu lawan > ${data.menitTertahan} menit`} />
                <AngkaKecil label="Median waktu jawab" nilai={data.medianDetik === null ? '–' : `${desimal(data.medianDetik)} dtk`} keterangan={`dari ${angka(data.jawaban)} jawaban`} />
                <AngkaKecil label="Akurasi battle" nilai={persenTeks(data.akurasiBattle)} keterangan={`latihan: ${persenTeks(data.akurasiLatihan)}`} />
            </div>

            <Panel urutan={0} judul="Battle per Hari" keterangan="Battle yang dibuat dan yang selesai">
                <GrafikGaris
                    data={data.harian}
                    seri={[
                        { kunci: 'dibuat', label: 'Dibuat', ...WARNA_SERI[0] },
                        { kunci: 'selesai', label: 'Selesai', ...WARNA_SERI[2] },
                    ]}
                    label="Grafik battle per hari"
                />
            </Panel>

            <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
                <Panel urutan={1} judul="Per Mode" keterangan="Status battle yang dibuat">
                    <Tabel
                        kepala={[
                            { label: 'Mode' },
                            { label: 'Total', kanan: true },
                            { label: 'Selesai', kanan: true },
                            { label: 'Batal', kanan: true },
                            { label: 'Menunggu', kanan: true },
                            { label: 'Rata-rata peserta', kanan: true },
                        ]}
                        baris={data.perMode.map((m) => [LABEL_MODE[m.mode] ?? m.mode, angka(m.total), angka(m.selesai), angka(m.batal), angka(m.menunggu), desimal(m.rataPeserta)])}
                        kosong="Belum ada battle."
                    />
                </Panel>
                <Panel urutan={2} judul="Subtes Battle" keterangan="Subtes yang paling sering dipilih">
                    <DaftarBatang item={data.perSubtes} kosong="Belum ada battle." />
                </Panel>
            </div>
        </div>
    );
}
