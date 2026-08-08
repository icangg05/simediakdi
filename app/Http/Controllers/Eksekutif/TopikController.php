<?php

namespace App\Http\Controllers\Eksekutif;

use App\Http\Controllers\Controller;
use App\Models\Artikel;
use App\Models\ExecutiveTopic;
use App\Support\Waktu;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class TopikController extends Controller
{
    public function __invoke(ExecutiveTopic $topik): Response
    {
        $ids = array_map('intval', $topik->article_ids ?? []);
        $articles = Artikel::query()
            ->relevanBerlabel()
            ->whereIn('id', $ids)
            ->with(['media:id,nama', 'analisisSentimen' => fn ($query) => $query->where('relevan', true)])
            ->orderByDesc('diambil_at')
            ->get(['id', 'media_id', 'judul', 'url', 'ringkasan', 'diambil_at'])
            ->map(fn (Artikel $article) => [
                'id' => $article->id,
                'judul' => $article->judul,
                'url' => $article->url,
                'ringkasan' => $article->ringkasan,
                'media' => $article->media?->nama,
                'diambil_at' => $article->diambil_at,
                'label' => $article->analisisSentimen->first()?->label_efektif?->value,
            ])
            ->all();

        $media = DB::table('artikel as a')
            ->leftJoin('media as m', 'm.id', '=', 'a.media_id')
            ->whereIn('a.id', $ids)
            ->groupBy('m.id', 'm.nama')
            ->orderByRaw('count(*) DESC')
            ->get(['m.id', DB::raw("coalesce(m.nama, 'Media belum ditautkan') AS nama"), DB::raw('count(*)::int AS jumlah')])
            ->map(fn ($row) => (array) $row)
            ->all();

        return Inertia::render('eksekutif/Topik', [
            'topik' => [
                'id' => $topik->id,
                'title' => $topik->title,
                'summary' => $topik->summary,
                'period_type' => $topik->period_type,
                'start_date' => $topik->start_date->toDateString(),
                'end_date' => $topik->end_date->toDateString(),
                'article_count' => $topik->article_count,
                'source_count' => $topik->source_count,
                'positive_count' => $topik->positive_count,
                'neutral_count' => $topik->neutral_count,
                'negative_count' => $topik->negative_count,
                'dominant_sentiment' => $topik->dominant_sentiment,
                'trend' => $topik->trend,
                'priority_level' => $topik->priority_level,
                'generated_at' => $topik->generated_at?->setTimezone(Waktu::ZONA)->toIso8601String(),
            ],
            'artikel' => $articles,
            'media' => $media,
        ]);
    }
}
