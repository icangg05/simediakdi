<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Arsip\PenangkapLayar;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pengaturan sistem, `/admin/pengaturan`.
 *
 * **Menampilkan nilai, tidak menyuntingnya.** Dokumen 04 bagian B meminta form
 * yang bisa mengubah ambang keyakinan dari layar. Yang dibangun di sini adalah
 * jalur cadangan yang sudah disediakan dokumen 07 sendiri: nilainya diubah
 * lewat `.env`, dan halaman ini menunjukkan nilai efektifnya beserta kunci
 * environment yang mengaturnya.
 *
 * Alasannya bukan waktu. Ambang keyakinan mengubah setiap angka di dashboard
 * eksekutif secara surut, dan dokumen 06 bagian 5 mewajibkan perubahannya
 * tercatat lengkap dengan nilai sebelum dan sesudah. Menyimpannya di database
 * berarti membangun tabel pengaturan, invalidasi cache untuk seluruh proses
 * worker, dan pencatatan audit tersendiri, agar nilai yang sudah diukur dan
 * didokumentasikan bisa diubah sambil lalu oleh siapa pun yang membuka halaman
 * ini. Lewat `.env`, perubahannya melewati deploy dan tercatat di git.
 *
 * Kolom "diukur dari" ada supaya angka-angka ini tidak terbaca sebagai selera.
 */
class PengaturanController extends Controller
{
    public function __invoke(PenangkapLayar $arsip): Response
    {
        return Inertia::render('admin/Pengaturan', [
            'kelompok' => [
                [
                    'judul' => 'Klasifikasi Gemini',
                    'catatan' => 'Mengubah nilai di sini mengubah seluruh angka dashboard secara surut, termasuk untuk periode yang sudah dilaporkan.',
                    'nilai' => [
                        [
                            'label' => 'Kunci API Gemini',
                            'nilai' => filled(config('ai.providers.gemini.key')) ? 'terisi' : 'belum diisi',
                            'env' => 'GEMINI_API_KEY',
                            'diukur' => 'Nilainya sengaja tidak ditampilkan. Kunci yang pernah muncul di layar admin harus dianggap bocor.',
                        ],
                        [
                            'label' => 'Model Gemini',
                            'nilai' => config('ai.providers.gemini.models.text.default'),
                            'env' => 'GEMINI_MODEL',
                            'diukur' => 'Rerata 1,5 sampai 2,7 detik per artikel pada pengukuran awal.',
                        ],
                        [
                            'label' => 'Versi prompt relevansi',
                            'nilai' => config('ai.prompt.relevansi'),
                            'env' => 'AI_RELEVANCE_PROMPT_VERSION',
                            'diukur' => 'Berkasnya ada di resources/prompts. Naikkan versinya saat isinya berubah, jangan sunting berkas lama.',
                        ],
                        [
                            'label' => 'Versi prompt sentimen',
                            'nilai' => config('ai.prompt.sentimen'),
                            'env' => 'AI_SENTIMENT_PROMPT_VERSION',
                            'diukur' => null,
                        ],
                    ],
                ],
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
                    'nama' => 'Gemini',
                    'sehat' => filled(config('ai.providers.gemini.key')),
                    'url' => config('ai.providers.gemini.url'),
                ],
                ['nama' => 'Layanan arsip (tangkapan layar)', 'sehat' => $arsip->sehat(), 'url' => config('arsip.base_url')],
            ],
        ]);
    }
}
