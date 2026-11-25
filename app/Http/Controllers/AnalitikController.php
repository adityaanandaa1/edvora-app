<?php

namespace App\Http\Controllers;

use App\Services\DataAnalitik;
use App\Services\DataAnalitikKualitas;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**

 */
class AnalitikController extends Controller
{
    public function index(DataAnalitik $data, DataAnalitikKualitas $dataKualitas): Response
    {
        try {
            $analitik = $data->ambil();
        } catch (Throwable $e) {
            // UCS9 2a: halaman tetap terbuka dengan pesan "Gagal Menampilkan Data, Coba Lagi Nanti!".
            report($e);
            $analitik = null;
        }

        // Kualitas soal & penguasaan: terpisah, supaya bagian yang sudah ada tetap tampil walau bagian ini gagal.
        try {
            $kualitas = $dataKualitas->ambil();
        } catch (Throwable $e) {
            report($e);
            $kualitas = null;
        }

        return Inertia::render('Editor/Analitik', ['analitik' => $analitik, 'kualitas' => $kualitas]);
    }
}
