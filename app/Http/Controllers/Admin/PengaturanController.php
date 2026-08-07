<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KunciGemini;
use App\Models\PengaturanAi;
use App\Services\Ai\RotasiKunciGemini;
use App\Services\Arsip\PenangkapLayar;
use App\Services\ModelRelevansi\LayananRelevansi;
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
    public function __invoke(PenangkapLayar $arsip, RotasiKunciGemini $rotasi, LayananRelevansi $relevansi): Response
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
                // Galat terakhir yang belum tercabut oleh pemakaian yang
                // berhasil. Dengan tiga kunci, satu kunci yang salah ketik
                // hanya terbaca sebagai "klasifikasi kadang gagal" kalau
                // galatnya tidak ditempelkan pada kuncinya sendiri.
                'galat_terakhir' => $k->galat_terakhir,
                'galat_at' => $k->galat_at?->toIso8601String(),

                // Dua angka yang benar-benar diketahui, dan tidak satu pun sisa
                // kuota di antaranya.
                //
                // Sisa kuota dulu ditampilkan di sini, dihitung terhadap
                // GEMINI_BATAS_RPD yang hanya salinan halaman dokumentasi free
                // tier. Hasilnya angka yang terlihat pasti padahal karangan:
                // layar menulis "497 sisa dari 500" untuk kunci yang pada detik
                // yang sama ditolak Google karena kuotanya sudah habis. Google
                // juga menghitung pemakaian kunci yang sama dari luar sistem
                // ini, dan tidak menyediakan cara membacanya lewat kunci API
                // biasa.
                //
                // Yang tersisa: berapa permintaan yang sistem ini kirim hari
                // ini, dan batas yang Google sebut sendiri lewat badan galat
                // 429. Yang kedua null selama kunci ini belum pernah kehabisan.
                'rpd_terpakai' => $rotasi->terpakaiHarian($k),
                'rpd_google' => $k->rpd_google,
                'rpd_google_at' => $k->rpd_google_at?->toIso8601String(),
            ]),

            /*
             * Satu waktu reset untuk seluruh kunci, bukan satu per kunci.
             *
             * Google memulangkan jatah harian pada pergantian hari kalender
             * waktu Pasifik, jadi tiga kunci yang habis pada jam yang berbeda
             * tetap pulih pada detik yang sama. Menampilkannya berulang di tiap
             * kunci hanya membuat admin mengira ketiganya bisa berbeda, lalu
             * menunggu kunci kedua pulih lebih dulu, padahal tidak akan pernah.
             *
             * Yang memang berbeda per kunci adalah `limit_sampai`, karena limit
             * per menit dihitung sejak permintaan kunci itu sendiri. Itu tetap
             * ditampilkan menempel pada kuncinya masing-masing.
             */
            'resetHarian' => $rotasi->resetHarian()->toIso8601String(),
            'kelompok' => [
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
                ['nama' => 'Layanan model relevansi (IndoBERT)', 'sehat' => $relevansi->kesehatan() !== null, 'url' => config('relevansi.base_url')],
            ],
        ]);
    }
}
