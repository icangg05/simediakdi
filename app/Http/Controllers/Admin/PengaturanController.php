<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EvaluasiModel;
use App\Services\Arsip\PenangkapLayar;
use App\Services\Nlp\KlienNlp;
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
    public function __invoke(KlienNlp $nlp, PenangkapLayar $arsip): Response
    {
        $evaluasi = EvaluasiModel::query()->latest('dievaluasi_at')->first();

        return Inertia::render('admin/Pengaturan', [
            'kelompok' => [
                [
                    'judul' => 'Ambang model',
                    'catatan' => 'Mengubah nilai di sini mengubah seluruh angka dashboard secara surut, termasuk untuk periode yang sudah dilaporkan.',
                    'nilai' => [
                        [
                            'label' => 'Ambang keyakinan sentimen',
                            'nilai' => config('nlp.ambang.sentimen'),
                            'env' => 'SENTIMEN_AMBANG_KEYAKINAN',
                            'diukur' => 'Sebaran keyakinan bimodal pada 24 pasangan nyata: 0,60-0,67 lalu kosong lalu di atas 0,998. 0,90 berada di tengah jurang itu.',
                        ],
                        [
                            'label' => 'Ambang atas relevansi',
                            'nilai' => config('nlp.ambang.relevansi_atas'),
                            'env' => 'RELEVANSI_AMBANG_ATAS',
                            'diukur' => 'Belum diukur. Selama kosong, seluruh artikel masuk antrean perlu review dan tidak ada yang otomatis masuk dashboard. Pilih dari titik presisi mencapai 80% pada validation set.',
                        ],
                        [
                            'label' => 'Ambang bawah relevansi',
                            'nilai' => config('nlp.ambang.relevansi_bawah'),
                            'env' => 'RELEVANSI_AMBANG_BAWAH',
                            'diukur' => 'Belum diukur. Pilih dari titik recall mencapai 85% pada validation set. Isinya kemiripan makna, bukan probabilitas.',
                        ],
                        [
                            'label' => 'Minimal sebutan kata kunci',
                            'nilai' => config('nlp.minimal_sebutan'),
                            'env' => 'RELEVANSI_MINIMAL_SEBUTAN',
                            'diukur' => 'Presisi 54,2% ke 80,0% dengan recall 100% ke 92,3%, diukur pada 254 label manusia dengan separuh data ditahan.',
                        ],
                    ],
                ],
                [
                    'judul' => 'Deduplikasi',
                    'catatan' => null,
                    'nilai' => [
                        [
                            'label' => 'Ambang jarak simhash',
                            'nilai' => config('crawler.dedup.ambang_simhash'),
                            'env' => 'DEDUP_AMBANG_SIMHASH',
                            'diukur' => 'Near-duplicate terukur berjarak 8-10 bit, berita yang benar-benar berbeda 30-34 bit.',
                        ],
                        [
                            'label' => 'Ambang kemiripan kosinus',
                            'nilai' => config('crawler.dedup.ambang_cosine'),
                            'env' => 'DEDUP_AMBANG_COSINE',
                            'diukur' => null,
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
                ['nama' => 'Layanan NLP', 'sehat' => $nlp->sehat(), 'url' => config('nlp.base_url')],
                ['nama' => 'Layanan arsip (tangkapan layar)', 'sehat' => $arsip->sehat(), 'url' => config('arsip.base_url')],
            ],
            'evaluasi' => $evaluasi === null ? null : [
                'f1_macro' => $evaluasi->f1_macro,
                'jumlah_sampel' => $evaluasi->jumlah_sampel,
                'dievaluasi_at' => $evaluasi->dievaluasi_at,
            ],
        ]);
    }
}
