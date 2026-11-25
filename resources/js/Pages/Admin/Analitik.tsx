import { GagalDimuat } from '@/Components/Analitik/Bagian';
import TabBelajar, { DataBelajar } from '@/Components/Admin/Analitik/TabBelajar';
import TabGamifikasi, { DataGamifikasi } from '@/Components/Admin/Analitik/TabGamifikasi';
import TabOperasional, { DataOperasional } from '@/Components/Admin/Analitik/TabOperasional';
import TabTryOut, { DataTryOut } from '@/Components/Admin/Analitik/TabTryOut';
import AdminLayout from '@/Components/Layouts/AdminLayout';
import { formatWaktuWib } from '@/lib/waktu';
import { Head, Link } from '@inertiajs/react';

type Bagian = 'belajar' | 'tryout' | 'gamifikasi' | 'operasional';

interface AnalitikProps {
    bagian: Bagian;
    // null = data gagal dimuat; bentuknya bergantung pada bagian.
    data: (DataBelajar | DataTryOut | DataGamifikasi | DataOperasional) | null;
}

const TAB: { kunci: Bagian; label: string; keterangan: string }[] = [
    { kunci: 'belajar', label: 'Belajar', keterangan: 'Sesi latihan, hint, dan jam belajar' },
    { kunci: 'tryout', label: 'Try Out', keterangan: 'Peserta, sebaran skor, dan subtes' },
    { kunci: 'gamifikasi', label: 'Gamifikasi', keterangan: 'XP, point, level, avatar, dan badge' },
    { kunci: 'operasional', label: 'Operasional', keterangan: 'Akun dan aktivitas admin/editor' },
];

// Analitik admin: satu tab per bagian; server hanya menghitung tab yang dibuka.
export default function Analitik({ bagian, data }: AnalitikProps) {
    const tab = TAB.find((t) => t.kunci === bagian) ?? TAB[0];

    return (
        <AdminLayout judul="Analitik">
            <Head title="Analitik Admin" />

            <div className="w-full space-y-4">
                <section className="flex min-h-[83px] animate-muncul-halus flex-col justify-center rounded-kartu bg-gradient-to-l from-siswa-banner-awal to-siswa-banner-akhir px-[26px] py-4 shadow-kartu">
                    <p className="text-[12px] font-medium uppercase tracking-[0.06em] text-white/85">Admin</p>
                    <h2 className="mt-0.5 text-[24px] font-semibold leading-tight text-white">Analitik</h2>
                    <p className="mt-1 text-[13px] leading-tight text-white/90">{tab.keterangan}</p>
                </section>

                <nav className="-mx-1 overflow-x-auto px-1" aria-label="Bagian analitik">
                    <div className="flex w-max gap-1 rounded-full bg-white p-1 shadow-kartu">
                        {TAB.map((t) => (
                            <Link
                                key={t.kunci}
                                href={route('admin.analitik', { bagian: t.kunci })}
                                preserveScroll
                                aria-current={t.kunci === bagian ? 'page' : undefined}
                                className={`whitespace-nowrap rounded-full px-4 py-1.5 text-[13px] font-medium transition-colors ${t.kunci === bagian ? 'bg-edvora-primary text-white' : 'text-siswa-teks hover:bg-siswa-laman-awal hover:text-siswa-judul-seksi'}`}
                            >
                                {t.label}
                            </Link>
                        ))}
                    </div>
                </nav>

                {data ? (
                    <>
                        <p className="px-1 text-[12px] text-siswa-teks">
                            {bagian === 'tryout' ? '20 paket terbaru' : `${data.hari} hari terakhir`} · diperbarui {formatWaktuWib(data.dihitungPada)}
                        </p>
                        {bagian === 'belajar' && <TabBelajar data={data as DataBelajar} />}
                        {bagian === 'tryout' && <TabTryOut data={data as DataTryOut} />}
                        {bagian === 'gamifikasi' && <TabGamifikasi data={data as DataGamifikasi} />}
                        {bagian === 'operasional' && <TabOperasional data={data as DataOperasional} />}
                    </>
                ) : (
                    <GagalDimuat />
                )}
            </div>
        </AdminLayout>
    );
}
