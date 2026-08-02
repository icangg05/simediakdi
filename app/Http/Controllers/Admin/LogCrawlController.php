<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LogCrawl;
use App\Models\SumberFeed;
use App\Support\KueriTabel;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LogCrawlController extends Controller
{
    public function index(Request $request): Response
    {
        $log = KueriTabel::untuk(
            LogCrawl::query()->with('sumberFeed:id,nama,media_id', 'sumberFeed.media:id,nama'),
            $request,
        )
            ->cari(['pesan'])
            ->saring(['status' => 'status', 'sumber' => 'sumber_feed_id'])
            ->urut(['dimulai_at', 'jumlah_ditemukan', 'jumlah_baru'], 'dimulai_at', 'desc')
            ->halaman();

        return Inertia::render('admin/log-crawl/Index', [
            'log' => $log,
            'opsi' => [
                'status' => [
                    ['nilai' => 'sukses', 'label' => 'Sukses'],
                    ['nilai' => 'sebagian', 'label' => 'Sebagian'],
                    ['nilai' => 'gagal', 'label' => 'Gagal'],
                ],
                'sumber' => SumberFeed::query()->orderBy('nama')->get(['id', 'nama'])
                    ->map(fn (SumberFeed $s) => ['nilai' => (string) $s->id, 'label' => $s->nama])->all(),
            ],
        ]);
    }
}
