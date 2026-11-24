import { AngkaKecil, DaftarBatang, formatTanggal, GrafikGaris, Kosong, Panel, persenTeks, angka, WARNA_SERI } from '@/Components/Analitik/Bagian';
import { formatWaktuWib } from '@/lib/waktu';
import { useMemo, useState } from 'react';

export interface ItemSebaran {
    label: string;
    jumlah: number;
    persen: number | null; // null = belum ada siswa
}

export interface DataAnalitikPengguna {
    dihitungPada: string; // ISO
    totalSiswa: number;
    keaktifan: { dau: number; wau: number; mau: number };
    harian: { tanggal: string; daftar: number; aktif: number }[]; // tanggal WIB 'YYYY-MM-DD', 90 hari, urut naik
    retensi: { minggu: string; jumlah: number; d7: number | null; d30: number | null }[]; // minggu = Senin, 'YYYY-MM-DD'
    streak: ItemSebaran[];
    jenisKelamin: ItemSebaran[];
    jenjang: ItemSebaran[];
    topUniversitas: { nama: string; jumlah: number }[];
    topProdi: { nama: string; jenjang: string; universitas: string; jumlah: number }[];
}

const PILIHAN_RENTANG = [7, 30, 90] as const;
type Rentang = (typeof PILIHAN_RENTANG)[number];

const SERI = [
    { kunci: 'aktif', label: 'Siswa aktif', ...WARNA_SERI[0] },
    { kunci: 'daftar', label: 'Siswa baru', ...WARNA_SERI[1] },
];

// Sel retensi: makin tinggi persennya makin pekat birunya.
function SelRetensi({ persen }: { persen: number | null }) {
    if (persen === null) {
        return (
            <td className="px-3 py-2 text-center text-siswa-teks" title="Belum ada siswa yang terdaftar cukup lama">
                –
            </td>
        );
    }
    return (
        <td className="px-3 py-2 text-center font-semibold tabular-nums text-siswa-judul" style={{ backgroundColor: `rgba(91, 136, 221, ${0.08 + (persen / 100) * 0.55})` }}>
            {persenTeks(persen)}
        </td>
    );
}

function TabelRetensi({ data }: { data: DataAnalitikPengguna['retensi'] }) {
    if (data.every((b) => b.jumlah === 0)) return <Kosong teks="Belum ada siswa baru dalam 8 minggu terakhir." />;

    return (
        <div className="-mx-2 overflow-x-auto">
            <table className="w-full min-w-[320px] border-separate border-spacing-y-1 text-[13px]">
                <thead>
                    <tr className="text-[12px] text-siswa-teks">
                        <th className="px-3 py-1 text-left font-medium">Minggu daftar</th>
                        <th className="px-3 py-1 text-right font-medium">Siswa</th>
                        <th className="px-3 py-1 text-center font-medium">Kembali D7</th>
                        <th className="px-3 py-1 text-center font-medium">Kembali D30</th>
                    </tr>
                </thead>
                <tbody>
                    {[...data].reverse().map((b) => (
                        <tr key={b.minggu} className="[&>td:first-child]:rounded-l-[8px] [&>td:last-child]:rounded-r-[8px]">
                            <td className="whitespace-nowrap px-3 py-2 text-siswa-judul-seksi">{formatTanggal(b.minggu, { day: 'numeric', month: 'short', year: 'numeric' })}</td>
                            <td className="px-3 py-2 text-right tabular-nums text-siswa-judul-seksi">{angka(b.jumlah)}</td>
                            <SelRetensi persen={b.jumlah === 0 ? null : b.d7} />
                            <SelRetensi persen={b.jumlah === 0 ? null : b.d30} />
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/**
 * Bagian Pengguna & Keaktifan di Dashboard Admin. "Aktif" = mengerjakan latihan/Try Out atau ikut battle pada hari
 * itu (WIB), bukan sekadar login.
 */
export default function AnalitikPengguna({ data }: { data: DataAnalitikPengguna }) {
    const [rentang, setRentang] = useState<Rentang>(30);
    const deret = useMemo(() => data.harian.slice(-rentang), [data.harian, rentang]);
    const siswaBaru = deret.reduce((n, d) => n + d.daftar, 0);
    const { dau, wau, mau } = data.keaktifan;
    const rasio = mau > 0 ? Math.round((dau / mau) * 100) : null;

    const pilihRentang = (
        <div className="flex rounded-full bg-siswa-laman-awal p-1" role="group" aria-label="Rentang waktu">
            {PILIHAN_RENTANG.map((r) => (
                <button
                    key={r}
                    type="button"
                    aria-pressed={rentang === r}
                    onClick={() => setRentang(r)}
                    className={`rounded-full px-3 py-1 text-[12px] font-medium transition-colors ${rentang === r ? 'bg-edvora-primary text-white shadow-sm' : 'text-siswa-teks hover:text-siswa-judul-seksi'}`}
                >
                    {r} hari
                </button>
            ))}
        </div>
    );

    return (
        <section className="space-y-4" aria-label="Pengguna dan Keaktifan">
            <div className="flex flex-wrap items-end justify-between gap-2 px-1">
                <div>
                    <h2 className="text-[20px] font-semibold leading-tight text-siswa-judul-seksi">Pengguna & Keaktifan</h2>
                    <p className="mt-0.5 text-[12px] text-siswa-teks">Aktif = mengerjakan latihan, Try Out, atau battle pada hari itu (WIB)</p>
                </div>
                <p className="text-[12px] text-siswa-teks">Diperbarui {formatWaktuWib(data.dihitungPada)}</p>
            </div>

            <Panel urutan={0} judul="Tren Harian" keterangan={`${rentang} hari terakhir`} aksi={pilihRentang}>
                <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <AngkaKecil label="Aktif hari ini" nilai={dau} keterangan={rasio === null ? 'DAU' : `DAU · ${rasio}% dari MAU`} />
                    <AngkaKecil label="Aktif 7 hari" nilai={wau} keterangan="WAU" />
                    <AngkaKecil label="Aktif 30 hari" nilai={mau} keterangan="MAU" />
                    <AngkaKecil label="Siswa baru" nilai={siswaBaru} keterangan={`dalam ${rentang} hari`} />
                </div>
                <GrafikGaris data={deret} seri={SERI} label="Grafik siswa aktif dan siswa baru per hari" />
            </Panel>

            <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
                <Panel urutan={1} judul="Retensi" keterangan="Siswa yang masih aktif 7 / 30 hari setelah mendaftar">
                    <TabelRetensi data={data.retensi} />
                </Panel>
                <Panel urutan={2} judul="Streak Belajar" keterangan="Hari aktif berturut-turut yang masih berjalan">
                    <DaftarBatang item={data.streak} kosong="Belum ada siswa." />
                </Panel>
            </div>

            <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
                <Panel urutan={3} judul="Universitas Tujuan" keterangan={`${data.topUniversitas.length > 0 ? `${data.topUniversitas.length} teratas` : 'Teratas'} berdasarkan jumlah siswa`}>
                    <DaftarBatang item={data.topUniversitas.map((u) => ({ label: u.nama, jumlah: u.jumlah }))} kosong="Belum ada siswa yang memilih universitas tujuan." />
                </Panel>
                <Panel urutan={4} judul="Prodi Tujuan" keterangan={`${data.topProdi.length > 0 ? `${data.topProdi.length} teratas` : 'Teratas'} berdasarkan jumlah siswa`}>
                    <DaftarBatang item={data.topProdi.map((p) => ({ label: `${p.nama} (${p.jenjang})`, sub: p.universitas, jumlah: p.jumlah }))} kosong="Belum ada siswa yang memilih prodi tujuan." />
                </Panel>
            </div>

            <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
                <Panel urutan={5} judul="Jenjang Tujuan" keterangan="Jenjang prodi yang dipilih siswa">
                    <DaftarBatang item={data.jenjang} kosong="Belum ada siswa." />
                </Panel>
                <Panel urutan={6} judul="Jenis Kelamin" keterangan={`Dari ${angka(data.totalSiswa)} siswa terdaftar`}>
                    <DaftarBatang item={data.jenisKelamin} kosong="Belum ada siswa." />
                </Panel>
            </div>
        </section>
    );
}
