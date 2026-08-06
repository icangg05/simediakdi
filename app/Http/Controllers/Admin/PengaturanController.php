<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KunciGemini;
use App\Models\PengaturanAi;
use App\Services\Arsip\PenangkapLayar;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pengaturan sistem, `/admin/pengaturan`.
 *
 * **Sebagian besar halaman ini menampilkan nilai, tidak menyuntingnya.**
 * Dokumen 04 bagian B meminta form yang bisa mengubah ambang keyakinan dari
 * layar. Yang dibangun di sini adalah jalur cadangan yang sudah disediakan
 * dokumen 07 sendiri: nilainya diubah lewat `.env`, dan halaman ini
 * menunjukkan nilai efektifnya beserta kunci environment yang mengaturnya.
 *
 * Alasannya bukan waktu. Ambang keyakinan mengubah setiap angka di dashboard
 * eksekutif secara surut, dan dokumen 06 bagian 5 mewajibkan perubahannya
 * tercatat lengkap dengan nilai sebelum dan sesudah. Lewat `.env`,
 * perubahannya melewati deploy dan tercatat di git.
 *
 * Pengecualiannya kelompok Gemini: model, kedua prompt, dan daftar kunci API
 * disunting dari layar lewat PengaturanAiController. Prompt disetel berkali
 * kali sampai hasilnya benar, dan kunci ditambah tepat pada saat kuota habis.
 * Keduanya tetap tercatat lewat activity log, jadi syarat dokumen 06 terpenuhi
 * tanpa menunggu deploy.
 *
 * Kolom "diukur dari" ada supaya angka-angka ini tidak terbaca sebagai selera.
 */
class PengaturanController extends Controller
{
    public function __invoke(PenangkapLayar $arsip): Response
    {
        return Inertia::render('admin/Pengaturan', [
            'pengaturanAi' => PengaturanAi::aktif()->only([
                'model',
                'versi_prompt_relevansi',
                'prompt_relevansi',
                'versi_prompt_sentimen',
                'prompt_sentimen',
            ]),
            // Kolom `kunci` sengaja tidak ikut. Kunci yang pernah muncul di
            // layar admin harus dianggap bocor, dan tidak ada satu pun layar
            // yang perlu membacanya kembali.
            'kunci' => KunciGemini::query()->orderBy('id')->get()->map(fn (KunciGemini $k) => [
                'id' => $k->id,
                'label' => $k->label,
                'aktif' => $k->aktif,
                'limit_sampai' => $k->sedangLimit() ? $k->limit_sampai->toIso8601String() : null,
                'alasan_limit' => $k->sedangLimit() ? $k->alasan_limit : null,
                'terakhir_dipakai_at' => $k->terakhir_dipakai_at?->toIso8601String(),
            ]),
            'kelompok' => [
                [
                    'judul' => 'Deduplikasi',
                    'catatan' => 'Lapis kemiripan makna dicabut bersama layanan NLP. Yang tersisa hash isi persis dan simhash, jadi salinan yang ditulis ulang dengan kalimat berbeda akan lebih sering lolos.',
                    'nilai' => [
                        [
                            'label' => 'Ambang jarak simhash',
                            'nilai' => config('crawler.dedup.ambang_simhash'),
                            'env' => 'DEDUP_AMBANG_SIMHASH',
                            'diukur' => 'Near-duplicate terukur berjarak 8-10 bit, berita yang benar-benar berbeda 30-34 bit.',
                        ],
                    ],
                ],
                [
                    'judul' => 'Etika crawling',
                    'catatan' => 'Jangan dilonggarkan tanpa alasan. Batas ini yang membuat crawler tidak membebani hosting kecil media daerah.',
                    'nilai' => [
                        ['label' => 'Jeda antar permintaan per domain', 'nilai' => config('crawler.delay_ms').' ms', 'env' => 'CRAWL_DELAY_MS', 'diukur' => null],
                        ['label' => 'Timeout unduhan', 'nilai' => config('crawler.timeout').' detik', 'env' => 'CRAWL_TIMEOUT', 'diukur' => null],
                        ['label' => 'User agent', 'nilai' => config('crawler.user_agent'), 'env' => 'CRAWL_USER_AGENT', 'diukur' => null],
                        ['label' => 'Jaring pengaman WordPress REST', 'nilai' => config('crawler.wordpress.aktif') ? 'aktif' : 'mati', 'env' => 'CRAWL_WORDPRESS_API', 'diukur' => 'HTML 147 ms dari page cache, /wp-json/ 422 ms. API dipakai hanya saat Readability gagal.'],
                    ],
                ],
                [
                    'judul' => 'Alert',
                    'catatan' => null,
                    'nilai' => [
                        ['label' => 'Token bot Telegram', 'nilai' => config('alert.telegram.token') !== '' ? 'terisi' : 'belum diisi', 'env' => 'TELEGRAM_BOT_TOKEN', 'diukur' => null],
                        ['label' => 'Chat ID grup', 'nilai' => config('alert.telegram.chat_id') !== '' ? config('alert.telegram.chat_id') : 'belum diisi', 'env' => 'TELEGRAM_CHAT_ID', 'diukur' => null],
                    ],
                ],
            ],
            'layanan' => [
                [
                    // Sehat berarti ada kunci yang boleh dipanggil sekarang.
                    // Kunci yang semuanya sedang kena limit sama saja dengan
                    // tidak ada kunci sampai kuotanya pulih.
                    'nama' => 'Gemini',
                    'sehat' => KunciGemini::query()->tersedia()->exists(),
                    'url' => config('ai.providers.gemini.url'),
                ],
                ['nama' => 'Layanan arsip (tangkapan layar)', 'sehat' => $arsip->sehat(), 'url' => config('arsip.base_url')],
            ],
        ]);
    }
}
