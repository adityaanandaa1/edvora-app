import { PointerEvent as ReactPointerEvent, ReactNode, useEffect, useRef, useState } from 'react';

// Komponen bersama halaman analitik (Dashboard Admin, Analitik admin, Analitik editor). Grafik dibuat dengan SVG
// tanpa library, pola sama dengan GrafikTryOut.

export const angka = (n: number) => n.toLocaleString('id-ID');
export const desimal = (n: number | null, digit = 1) => (n === null ? '–' : n.toLocaleString('id-ID', { maximumFractionDigits: digit }));
export const persenTeks = (p: number | null) => (p === null ? '–' : `${desimal(p)}%`);

// Tanggal 'YYYY-MM-DD' sudah dalam WIB dari server; diformat di UTC supaya tidak bergeser oleh zona perangkat.
export function formatTanggal(ymd: string, opsi: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'short' }): string {
    return new Intl.DateTimeFormat('id-ID', { ...opsi, timeZone: 'UTC' }).format(new Date(`${ymd}T00:00:00Z`));
}

// Kartu putih dengan judul; gaya sama dengan panel Audit Log di Dashboard Admin.
export function Panel({
    judul,
    keterangan,
    aksi,
    children,
    urutan = 0,
    className = '',
}: {
    judul: string;
    keterangan?: string;
    aksi?: ReactNode;
    children: ReactNode;
    urutan?: number;
    className?: string;
}) {
    return (
        <div
            className={`flex min-w-0 animate-muncul-halus flex-col rounded-kartu bg-white px-[26px] py-5 shadow-kartu [animation-fill-mode:both] ${className}`}
            style={{ animationDelay: `${150 + urutan * 60}ms` }}
        >
            <div className="flex flex-wrap items-start justify-between gap-3 border-b border-siswa-garis-halus pb-3.5">
                <div className="min-w-0">
                    <h3 className="text-[18px] font-semibold leading-tight text-siswa-judul-seksi">{judul}</h3>
                    {keterangan && <p className="mt-0.5 text-[12px] text-siswa-teks">{keterangan}</p>}
                </div>
                {aksi}
            </div>
            <div className="pt-4">{children}</div>
        </div>
    );
}

export function AngkaKecil({ label, nilai, keterangan }: { label: string; nilai: string | number; keterangan?: string }) {
    return (
        <div className="min-w-0 rounded-[12px] bg-siswa-laman-awal px-4 py-3">
            <p className="truncate text-[12px] font-medium text-siswa-teks">{label}</p>
            <p className="text-[24px] font-semibold leading-tight text-siswa-judul-seksi">{typeof nilai === 'number' ? angka(nilai) : nilai}</p>
            {keterangan && <p className="mt-0.5 text-[11px] text-siswa-teks">{keterangan}</p>}
        </div>
    );
}

export function Kosong({ teks }: { teks: string }) {
    return <p className="py-8 text-center text-[13px] italic text-siswa-teks">{teks}</p>;
}

export function GagalDimuat() {
    return (
        <div role="alert" className="flex flex-col items-center rounded-kartu bg-white px-6 py-12 text-center shadow-kartu">
            <p className="text-lg font-semibold text-siswa-judul">Gagal Menampilkan Data, Coba Lagi Nanti!</p>
        </div>
    );
}

// Batas atas sumbu Y yang "bulat" (1, 2, 5 × 10^n per garis bantu) supaya label sumbu mudah dibaca.
function batasAtas(maks: number, garis: number): number {
    const kasar = Math.max(1, maks) / garis;
    const pangkat = 10 ** Math.floor(Math.log10(kasar));
    const langkah = [1, 2, 5, 10].map((k) => k * pangkat).find((l) => l >= kasar) ?? kasar;
    return Math.max(garis, Math.ceil(langkah) * garis);
}

export interface SeriGrafik {
    kunci: string;
    label: string;
    garis: string; // kelas stroke-*
    titik: string; // kelas bg-*
}

// Warna seri berurutan; dipakai bila satu grafik punya lebih dari satu garis.
export const WARNA_SERI = [
    { garis: 'stroke-edvora-primary', titik: 'bg-edvora-primary' },
    { garis: 'stroke-siswa-cincin-naik', titik: 'bg-siswa-cincin-naik' },
    { garis: 'stroke-siswa-umpan-benar-teks', titik: 'bg-siswa-umpan-benar-teks' },
    { garis: 'stroke-siswa-cincin-rendah', titik: 'bg-siswa-cincin-rendah' },
] as const;

export type BarisDeret = { tanggal: string } & Record<string, number | string>;

const TINGGI = 240;
const KIRI = 44;
const KANAN = 16;
const ATAS = 14;
const BAWAH = 28;
const GARIS_BANTU = 4;

/** Grafik garis harian; satu garis per seri. Menyorot titik terdekat saat kursor/jari bergerak di atasnya. */
export function GrafikGaris({ data, seri, label }: { data: BarisDeret[]; seri: SeriGrafik[]; label: string }) {
    const wadah = useRef<HTMLDivElement>(null);
    const [lebar, setLebar] = useState(600);
    const [sorot, setSorot] = useState<number | null>(null);

    useEffect(() => {
        const el = wadah.current;
        if (!el) return;
        const ukur = () => setLebar(Math.max(280, el.clientWidth));
        ukur();
        const pengamat = new ResizeObserver(ukur);
        pengamat.observe(el);
        return () => pengamat.disconnect();
    }, []);

    if (data.length === 0) {
        return (
            <div ref={wadah}>
                <Kosong teks="Belum ada data." />
            </div>
        );
    }

    const nilai = (d: BarisDeret, kunci: string) => Number(d[kunci]) || 0;
    const lebarArea = lebar - KIRI - KANAN;
    const tinggiArea = TINGGI - ATAS - BAWAH;
    const maksY = batasAtas(Math.max(0, ...data.flatMap((d) => seri.map((s) => nilai(d, s.kunci)))), GARIS_BANTU);
    const x = (i: number) => KIRI + (data.length === 1 ? lebarArea / 2 : (i * lebarArea) / (data.length - 1));
    const y = (n: number) => ATAS + tinggiArea * (1 - n / maksY);
    const setiapLabel = Math.ceil(data.length / Math.max(2, Math.floor(lebarArea / 70)));

    const geser = (e: ReactPointerEvent<SVGSVGElement>) => {
        const kotak = e.currentTarget.getBoundingClientRect();
        const posisi = ((e.clientX - kotak.left) / kotak.width) * lebar;
        const i = Math.round(((posisi - KIRI) / lebarArea) * (data.length - 1));
        setSorot(Math.min(data.length - 1, Math.max(0, i)));
    };

    const titikSorot = sorot === null ? null : data[sorot];

    return (
        <div ref={wadah} className="relative w-full">
            <svg
                width="100%"
                height={TINGGI}
                viewBox={`0 0 ${lebar} ${TINGGI}`}
                role="img"
                aria-label={label}
                className="touch-pan-y overflow-visible font-poppins"
                onPointerMove={geser}
                onPointerLeave={() => setSorot(null)}
            >
                {Array.from({ length: GARIS_BANTU + 1 }, (_, k) => (maksY / GARIS_BANTU) * k).map((n) => (
                    <g key={n}>
                        <line x1={KIRI} x2={lebar - KANAN} y1={y(n)} y2={y(n)} strokeDasharray="4 4" className="stroke-siswa-garis-halus" />
                        <text x={KIRI - 8} y={y(n)} textAnchor="end" dominantBaseline="middle" fontSize="11" className="fill-siswa-teks">
                            {angka(n)}
                        </text>
                    </g>
                ))}

                {data.map((d, i) =>
                    i % setiapLabel === 0 || i === data.length - 1 ? (
                        <text key={d.tanggal} x={x(i)} y={TINGGI - 8} textAnchor={i === 0 ? 'start' : i === data.length - 1 ? 'end' : 'middle'} fontSize="11" className="fill-siswa-teks">
                            {formatTanggal(d.tanggal)}
                        </text>
                    ) : null,
                )}

                {seri.map((s) => (
                    <polyline
                        key={s.kunci}
                        points={data.map((d, i) => `${x(i)},${y(nilai(d, s.kunci))}`).join(' ')}
                        fill="none"
                        strokeWidth="2.5"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        className={s.garis}
                    />
                ))}

                {titikSorot && sorot !== null && (
                    <g>
                        <line x1={x(sorot)} x2={x(sorot)} y1={ATAS} y2={ATAS + tinggiArea} className="stroke-siswa-teks/40" />
                        {seri.map((s) => (
                            <circle key={s.kunci} cx={x(sorot)} cy={y(nilai(titikSorot, s.kunci))} r="4.5" strokeWidth="2.5" className={`fill-white ${s.garis}`} />
                        ))}
                    </g>
                )}
            </svg>

            {titikSorot && sorot !== null && (
                <div
                    className="pointer-events-none absolute top-0 z-10 w-max rounded-[10px] bg-white px-3 py-2 text-[12px] shadow-kartu ring-1 ring-siswa-garis-halus"
                    style={{ left: `${(x(sorot) / lebar) * 100}%`, transform: `translateX(${x(sorot) > lebar / 2 ? 'calc(-100% - 12px)' : '12px'})` }}
                >
                    <p className="font-semibold text-siswa-judul-seksi">{formatTanggal(titikSorot.tanggal, { weekday: 'long', day: 'numeric', month: 'long' })}</p>
                    {seri.map((s) => (
                        <p key={s.kunci} className="mt-0.5 flex items-center gap-1.5 text-siswa-teks">
                            <span className={`h-2 w-2 rounded-full ${s.titik}`} aria-hidden="true" />
                            {s.label}: <span className="font-semibold text-siswa-judul-seksi">{angka(nilai(titikSorot, s.kunci))}</span>
                        </p>
                    ))}
                </div>
            )}

            <div className="mt-2 flex flex-wrap gap-4 text-[12px] text-siswa-teks">
                {seri.map((s) => (
                    <span key={s.kunci} className="flex items-center gap-1.5">
                        <span className={`h-2.5 w-2.5 rounded-full ${s.titik}`} aria-hidden="true" />
                        {s.label}
                    </span>
                ))}
            </div>
        </div>
    );
}

export interface ItemBatang {
    label: string;
    sub?: string;
    jumlah: number;
    persen?: number | null;
}

/** Daftar batang horizontal; panjang batang relatif terhadap nilai terbesar di daftar. */
export function DaftarBatang({ item, kosong, satuan }: { item: ItemBatang[]; kosong: string; satuan?: string }) {
    const maks = Math.max(0, ...item.map((i) => i.jumlah));
    if (maks === 0) return <Kosong teks={kosong} />;

    return (
        <ul className="space-y-3">
            {item.map((i, n) => (
                <li key={`${i.label}-${i.sub ?? ''}-${n}`}>
                    <div className="flex items-baseline justify-between gap-3 text-[13px]">
                        <span className="min-w-0 truncate font-medium text-siswa-judul-seksi" title={i.sub ? `${i.label} · ${i.sub}` : i.label}>
                            {i.label}
                            {i.sub && <span className="font-normal text-siswa-teks"> · {i.sub}</span>}
                        </span>
                        <span className="shrink-0 tabular-nums text-siswa-teks">
                            <span className="font-semibold text-siswa-judul-seksi">{angka(i.jumlah)}</span>
                            {satuan && ` ${satuan}`}
                            {i.persen !== undefined && ` (${persenTeks(i.persen)})`}
                        </span>
                    </div>
                    <div className="mt-1 h-2 overflow-hidden rounded-full bg-siswa-laman-awal">
                        <div className="h-full rounded-full bg-edvora-primary transition-[width] duration-500" style={{ width: `${(i.jumlah / maks) * 100}%` }} />
                    </div>
                </li>
            ))}
        </ul>
    );
}

/** Tabel sederhana dengan gaya seragam; kolom angka rata kanan. Lebar sempit: tabel digeser di dalam kartu. */
export function Tabel({ kepala, baris, kosong }: { kepala: { label: string; kanan?: boolean }[]; baris: ReactNode[][]; kosong: string }) {
    if (baris.length === 0) return <Kosong teks={kosong} />;

    return (
        <div className="-mx-2 overflow-x-auto">
            <table className="w-full min-w-[420px] text-[13px]">
                <thead>
                    <tr className="border-b border-siswa-garis-halus text-[12px] text-siswa-teks">
                        {kepala.map((k) => (
                            <th key={k.label} className={`whitespace-nowrap px-2 py-2 font-medium ${k.kanan ? 'text-right' : 'text-left'}`}>
                                {k.label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-siswa-garis-halus/70">
                    {baris.map((sel, i) => (
                        <tr key={i}>
                            {sel.map((isi, j) => (
                                <td key={j} className={`px-2 py-2 text-siswa-judul-seksi ${kepala[j]?.kanan ? 'text-right tabular-nums' : ''}`}>
                                    {isi}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
