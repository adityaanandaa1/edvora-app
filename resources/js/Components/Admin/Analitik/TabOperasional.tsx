import { angka, DaftarBatang, GrafikGaris, ItemBatang, Panel, Tabel, WARNA_SERI } from '@/Components/Analitik/Bagian';
import { formatWaktuWib } from '@/lib/waktu';

export interface DataOperasional {
    dihitungPada: string;
    hari: number;
    akun: { peran: string; total: number; nonaktif: number; belumVerifikasi: number; login30Hari: number }[];
    harian: { tanggal: string; admin: number; editor: number }[];
    topAksi: ItemBatang[];
    pelaku: { nama: string; peran: string; jumlah: number; terakhir: string }[];
}

const LABEL_PERAN: Record<string, string> = { siswa: 'Siswa', admin_editor: 'Editor', admin: 'Admin' };

export default function TabOperasional({ data }: { data: DataOperasional }) {
    return (
        <div className="space-y-4">
            <Panel urutan={0} judul="Akun" keterangan={`Login = pernah login dalam ${data.hari} hari terakhir`}>
                <Tabel
                    kepala={[
                        { label: 'Peran' },
                        { label: 'Total', kanan: true },
                        { label: 'Nonaktif', kanan: true },
                        { label: 'Email belum terverifikasi', kanan: true },
                        { label: 'Login', kanan: true },
                    ]}
                    baris={data.akun.map((a) => [LABEL_PERAN[a.peran] ?? a.peran, angka(a.total), angka(a.nonaktif), angka(a.belumVerifikasi), angka(a.login30Hari)])}
                    kosong="Belum ada akun."
                />
            </Panel>

            <Panel urutan={1} judul="Aktivitas Audit Log per Hari">
                <GrafikGaris
                    data={data.harian}
                    seri={[
                        { kunci: 'admin', label: 'Admin', ...WARNA_SERI[0] },
                        { kunci: 'editor', label: 'Editor', ...WARNA_SERI[1] },
                    ]}
                    label="Grafik aktivitas admin dan editor per hari"
                />
            </Panel>

            <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
                <Panel urutan={2} judul="Aksi Terbanyak">
                    <DaftarBatang item={data.topAksi} kosong="Belum ada aktivitas." />
                </Panel>
                <Panel urutan={3} judul="Pelaku Teraktif">
                    <Tabel
                        kepala={[{ label: 'Nama' }, { label: 'Peran' }, { label: 'Aksi', kanan: true }, { label: 'Terakhir' }]}
                        baris={data.pelaku.map((p) => [
                            p.nama,
                            LABEL_PERAN[p.peran] ?? p.peran,
                            angka(p.jumlah),
                            <span key="t" className="whitespace-nowrap text-siswa-teks">
                                {formatWaktuWib(p.terakhir)}
                            </span>,
                        ])}
                        kosong="Belum ada aktivitas."
                    />
                </Panel>
            </div>
        </div>
    );
}
