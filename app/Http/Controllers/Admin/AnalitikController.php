<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DataAnalitikAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Halaman Analitik admin: satu tab per bagian (?bagian=belajar|tryout|battle|gamifikasi|operasional).
 * Hanya tab yang dibuka yang dihitung.
 */
class AnalitikController extends Controller
{
    public function index(Request $request, DataAnalitikAdmin $data): Response
    {
        $bagian = in_array($request->query('bagian'), DataAnalitikAdmin::BAGIAN, true) ? $request->query('bagian') : DataAnalitikAdmin::BAGIAN[0];
        $tryOut = $request->query('tryout');
        $tryOut = is_string($tryOut) && Str::isUuid($tryOut) ? $tryOut : null;

        try {
            $isi = $data->ambil($bagian, $tryOut);
        } catch (Throwable $e) {
            // Halaman tetap terbuka dengan pesan gagal, sama seperti analitik editor.
            report($e);
            $isi = null;
        }

        return Inertia::render('Admin/Analitik', ['bagian' => $bagian, 'data' => $isi]);
    }
}
