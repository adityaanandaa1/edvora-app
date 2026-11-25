import { AngkaKecil, angka, DaftarBatang, desimal, GrafikGaris, ItemBatang, Panel, WARNA_SERI } from '@/Components/Analitik/Bagian';

export interface DataGamifikasi {
    dihitungPada: string;
    hari: number;
    xpPerSumber: { label: string; jumlah: number }[];
    pointMasukPerSumber: { label: string; jumlah: number }[];
    pointKeluar: number; // dibelanjakan (avatar) dalam rentang
    saldo: { totalPoint: number; rataPoint: number; totalXp: number };
    harian: { tanggal: string; xp: number; pointMasuk: number; pointKeluar: number }[];
    level: ItemBatang[];
    avatar: ItemBatang[]; // pemilik per avatar
    badge: ItemBatang[]; // pemilik per badge
}

const LABEL_SUMBER: Record<string, string> = {
    pengerjaan: 'Latihan & Try Out',
    battle: 'Battle',
    avatar: 'Beli avatar',
    reward: 'Reward',
    lainnya: 'Lainnya',
};

const labelSumber = (item: { label: string; jumlah: number }[]) => item.map((i) => ({ ...i, label: LABEL_SUMBER[i.label] ?? i.label }));

export default function TabGamifikasi({ data }: { data: DataGamifikasi }) {
    const xpRentang = data.xpPerSumber.reduce((n, s) => n + s.jumlah, 0);
    const pointMasuk = data.pointMasukPerSumber.reduce((n, s) => n + s.jumlah, 0);

    return (
        <div className="space-y-4">
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <AngkaKecil label="XP diberikan" nilai={xpRentang} keterangan={`${data.hari} hari terakhir`} />
                <AngkaKecil label="Point diberikan" nilai={pointMasuk} keterangan={`${data.hari} hari terakhir`} />
                <AngkaKecil label="Point dibelanjakan" nilai={data.pointKeluar} keterangan="untuk avatar" />
                <AngkaKecil label="Point beredar" nilai={data.saldo.totalPoint} keterangan={`rata-rata ${desimal(data.saldo.rataPoint)} per siswa`} />
            </div>

            <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
                <Panel urutan={0} judul="XP per Hari">
                    <GrafikGaris data={data.harian} seri={[{ kunci: 'xp', label: 'XP', ...WARNA_SERI[0] }]} label="Grafik XP per hari" />
                </Panel>
                <Panel urutan={1} judul="Point per Hari" keterangan="Diberikan dan dibelanjakan">
                    <GrafikGaris
                        data={data.harian}
                        seri={[
                            { kunci: 'pointMasuk', label: 'Diberikan', ...WARNA_SERI[2] },
                            { kunci: 'pointKeluar', label: 'Dibelanjakan', ...WARNA_SERI[3] },
                        ]}
                        label="Grafik point per hari"
                    />
                </Panel>
            </div>

            <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
                <Panel urutan={2} judul="Sumber XP">
                    <DaftarBatang item={labelSumber(data.xpPerSumber)} kosong="Belum ada XP yang diberikan." satuan="XP" />
                </Panel>
                <Panel urutan={3} judul="Sumber Point">
                    <DaftarBatang item={labelSumber(data.pointMasukPerSumber)} kosong="Belum ada point yang diberikan." satuan="point" />
                </Panel>
            </div>

            <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
                <Panel urutan={4} judul="Sebaran Level" keterangan={`Total XP semua siswa: ${angka(data.saldo.totalXp)}`}>
                    <DaftarBatang item={data.level} kosong="Belum ada siswa." />
                </Panel>
                <Panel urutan={5} judul="Avatar" keterangan="Jumlah pemilik setiap avatar">
                    <DaftarBatang item={data.avatar} kosong="Belum ada avatar yang dibeli." />
                </Panel>
            </div>

            <Panel urutan={6} judul="Badge" keterangan="Jumlah siswa yang sudah memperoleh setiap badge">
                <DaftarBatang item={data.badge} kosong="Belum ada badge yang diperoleh." />
            </Panel>
        </div>
    );
}
