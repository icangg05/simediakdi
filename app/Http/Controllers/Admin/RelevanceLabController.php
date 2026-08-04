<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StatusLabelRelevansi;
use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\PelatihanModelRelevansi;
use App\Models\SampelRelevansi;
use App\Models\SnapshotDatasetRelevansi;
use App\Models\User;
use App\Services\Relevance\RelevanceQualityGateService;
use App\Services\Relevance\RelevanceSplitValidator;
use App\Services\Relevance\RelevanceTrainingService;
use App\Support\AlasanLabelRelevansi;
use App\Support\KueriTabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Laboratorium Model Relevansi. Dokumen 10.
 *
 * Delapan tab pada spesifikasi, dua yang sudah ada isinya. Ringkasan dan
 * dataset dikerjakan di fase 1 karena keduanya yang dibutuhkan untuk melabeli;
 * enam sisanya menunggu snapshot, pelatihan, dan model yang belum ada. Tab
 * kosong yang dipasang lebih dulu hanya membuat halaman terlihat lengkap
 * padahal tidak, dan itu menyesatkan orang yang membukanya.
 */
class RelevanceLabController extends Controller
{
    public function __invoke(Request $request, RelevanceQualityGateService $gerbang): Response
    {
        $tab = in_array($request->query('tab'), ['ringkasan', 'dataset', 'snapshot', 'pelatihan'], strict: true)
            ? $request->query('tab')
            : 'ringkasan';

        return Inertia::render('admin/model-relevansi/Index', [
            'tab' => $tab,
            'gerbang' => [
                'status' => $gerbang->status()->value,
                'label' => $gerbang->status()->label(),
                'alasan' => $gerbang->alasan(),
            ],
            'ringkasan' => $tab === 'ringkasan' ? $this->ringkasan() : null,
            'dataset' => $tab === 'dataset' ? $this->dataset($request) : null,
            'sampel' => $this->sampelTerpilih($request),
            'opsi' => $tab === 'dataset' ? $this->opsi() : null,
            'snapshot' => $tab === 'snapshot' ? $this->snapshot() : null,
            'pelatihan' => $tab === 'pelatihan' ? $this->pelatihan() : null,
        ]);
    }

    /**
     * Daftar snapshot beserta laporan kebocorannya.
     *
     * Laporan dihitung saat halaman dibuka, tidak disimpan. Dataset berubah
     * terus selama pelabelan berjalan, dan laporan tersimpan yang usianya
     * seminggu akan dibaca seolah masih berlaku. Snapshot terkunci
     * dikecualikan: isinya sudah beku, jadi laporannya tidak akan berubah lagi
     * dan menghitungnya ulang tiap kali hanya membuang kueri.
     *
     * @return list<array<string, mixed>>
     */
    private function snapshot(): array
    {
        $validator = app(RelevanceSplitValidator::class);

        return SnapshotDatasetRelevansi::with('pembuat:id,name')
            ->latest('id')
            ->get()
            ->map(fn (SnapshotDatasetRelevansi $s) => [
                'id' => $s->id,
                'nama' => $s->nama,
                'versi' => $s->versi,
                'deskripsi' => $s->deskripsi,
                'status' => $s->status,
                'strategi_sampling' => $s->strategi_sampling,
                'random_seed' => $s->random_seed,
                'versi_panduan_label' => $s->versi_panduan_label,
                'manifest_hash' => $s->manifest_hash,
                'total_relevan' => $s->total_relevan,
                'total_tidak_relevan' => $s->total_tidak_relevan,
                'total_train' => $s->total_train,
                'total_validation' => $s->total_validation,
                'total_test' => $s->total_test,
                'pembuat' => $s->pembuat?->name,
                'locked_at' => $s->locked_at,
                'created_at' => $s->created_at,
                'kebocoran' => $s->terkunci() ? [] : $validator->periksa($s),
            ])
            ->all();
    }

    /**
     * Riwayat pelatihan, snapshot yang siap dilatih, dan preset konfigurasi.
     *
     * Riwayat yang gagal ikut ditampilkan. Satu-satunya cara mengetahui
     * konfigurasi mana yang sudah dicoba dan tidak perlu diulang adalah dengan
     * melihatnya, dan daftar yang hanya memuat keberhasilan menyembunyikan
     * justru pelajaran yang paling mahal didapat.
     *
     * @return array<string, mixed>
     */
    private function pelatihan(): array
    {
        $this->segarkanPelatihanBerjalan();

        return [
            'preset' => config('relevance.preset'),
            'snapshot' => SnapshotDatasetRelevansi::sudahTerkunci()
                ->latest('id')
                ->get(['id', 'nama', 'versi', 'total_train', 'total_validation', 'total_test'])
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'label' => "{$s->nama} {$s->versi} ({$s->total_train}/{$s->total_validation}/{$s->total_test})",
                ])->all(),
            'riwayat' => PelatihanModelRelevansi::with('pembuat:id,name', 'snapshot:id,nama,versi')
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (PelatihanModelRelevansi $p) => [
                    'id' => $p->id,
                    'nama' => $p->nama,
                    'base_model' => $p->base_model,
                    'status' => $p->status,
                    'progress' => $p->progress,
                    'current_epoch' => $p->current_epoch,
                    'current_step' => $p->current_step,
                    'total_steps' => $p->total_steps,
                    'configuration' => $p->configuration,
                    'metrics_validation' => $p->metrics_validation,
                    'metrics_test' => $p->metrics_test,
                    'error_summary' => $p->error_summary,
                    'snapshot' => $p->snapshot ? "{$p->snapshot->nama} {$p->snapshot->versi}" : null,
                    'pembuat' => $p->pembuat?->name,
                    'started_at' => $p->started_at,
                    'finished_at' => $p->finished_at,
                    'selesai' => $p->selesai(),
                    'perkiraan' => $this->perkiraanWaktu($p),
                ])->all(),
        ];
    }

    /**
     * Menarik status terbaru dari layanan sebelum halaman digambar.
     *
     * Tanpa ini halaman hanya sejujur job pemantau yang mengisinya, dan pada
     * pemasangan tanpa worker antrean angkanya berhenti di 0% selamanya
     * sementara pelatihannya berjalan normal. Itu bukan sekadar tampilan
     * ketinggalan: admin melihat pelatihan yang tampak menggantung lalu
     * membatalkannya.
     *
     * Satu permintaan HTTP ke localhost per pemuatan halaman, dan hanya saat
     * benar-benar ada pelatihan yang belum berhenti.
     */
    private function segarkanPelatihanBerjalan(): void
    {
        $berjalan = PelatihanModelRelevansi::whereNotIn('status', ['selesai', 'gagal', 'dibatalkan'])->get();

        if ($berjalan->isEmpty()) {
            return;
        }

        $service = app(RelevanceTrainingService::class);

        foreach ($berjalan as $run) {
            // Layanan mati tidak boleh membuat halaman ikut mati. Statusnya
            // tinggal basi, dan itu jauh lebih baik daripada 500.
            rescue(fn () => $service->tarikStatus($run), report: false);
        }
    }

    /**
     * Perkiraan sisa waktu, dihitung dari laju langkah yang sudah terjadi.
     *
     * Sengaja tidak memakai angka bawaan atau tebakan: laju pelatihan di CPU
     * berbeda jauh antar mesin, dan perkiraan yang dikarang lebih menyesatkan
     * daripada tidak ada perkiraan sama sekali.
     *
     * Optimistis di awal dan menajam belakangan. Waktu memuat model ikut
     * terhitung sebagai bagian dari langkah pertama, jadi perkiraan pada dua
     * langkah pertama terlalu besar. Itu sebabnya ia baru muncul setelah
     * langkah ketiga.
     *
     * @return array<string, mixed>|null
     */
    private function perkiraanWaktu(PelatihanModelRelevansi $run): ?array
    {
        if ($run->selesai() || ! $run->started_at || ($run->current_step ?? 0) < 3 || ! $run->total_steps) {
            return null;
        }

        $berlalu = max(1, now()->diffInSeconds($run->started_at, absolute: true));
        $detikPerLangkah = $berlalu / $run->current_step;
        $sisaLangkah = max(0, $run->total_steps - $run->current_step);

        // Evaluasi test dan penyimpanan artefak setelah langkah terakhir, kira
        // kira selama tiga langkah pelatihan.
        $sisaDetik = (int) round(($sisaLangkah + 3) * $detikPerLangkah);

        return [
            'detik_per_langkah' => round($detikPerLangkah, 1),
            'sisa_detik' => $sisaDetik,
            'berlalu_detik' => $berlalu,
            'selesai_sekitar' => now()->addSeconds($sisaDetik)->toIso8601String(),
        ];
    }

    /**
     * Angka yang menjawab satu pertanyaan: sudah cukupkah untuk melatih?
     *
     * Dihitung dari artikel unik, bukan dari baris. Doc 10 bagian 18 menuntut
     * itu, dan alasannya pernah terbukti mahal: gold set lama dihitung per
     * pasangan artikel dan konteks, sehingga 470 label terlihat seperti 470
     * artikel padahal hanya 249.
     *
     * @return array<string, mixed>
     */
    private function ringkasan(): array
    {
        $per = SampelRelevansi::query()
            ->selectRaw('status_label, count(*) as jumlah')
            ->where('is_excluded', false)
            ->groupBy('status_label')
            ->pluck('jumlah', 'status_label');

        $berlabel = SampelRelevansi::layakLatih();
        $relevan = (clone $berlabel)->where('label_manual', 'relevan')->count();
        $tidakRelevan = (clone $berlabel)->where('label_manual', 'tidak_relevan')->count();
        $total = $relevan + $tidakRelevan;

        return [
            'kandidat' => SampelRelevansi::count(),
            'belum_dilabeli' => (int) ($per[StatusLabelRelevansi::BelumDilabeli->value] ?? 0),
            'sudah_dilabeli' => $total,
            'relevan' => $relevan,
            'tidak_relevan' => $tidakRelevan,
            'perlu_review' => (int) ($per[StatusLabelRelevansi::PerluReview->value] ?? 0),
            'dikeluarkan' => SampelRelevansi::where('is_excluded', true)->count(),
            'hard_positive' => SampelRelevansi::where('tingkat_kesulitan', 'hard_positive')->count(),
            'hard_negative' => SampelRelevansi::where('tingkat_kesulitan', 'hard_negative')->count(),
            'kelompok_duplikat' => SampelRelevansi::whereNotNull('duplicate_group_id')
                ->distinct('duplicate_group_id')->count('duplicate_group_id'),
            'belum_direview' => SampelRelevansi::layakLatih()->whereNull('last_reviewed_at')->count(),
            'keseimbangan' => $this->keseimbangan($relevan, $tidakRelevan),
            'kesiapan' => $this->kesiapan($total, min($relevan, $tidakRelevan)),
        ];
    }

    /**
     * Peringatan menyala di bawah 35%, dokumen 10 bagian 6.2.
     *
     * @return array<string, mixed>
     */
    private function keseimbangan(int $relevan, int $tidakRelevan): array
    {
        $total = $relevan + $tidakRelevan;

        if ($total === 0) {
            return ['status' => 'belum_ada_data', 'persen_relevan' => null, 'persen_tidak_relevan' => null];
        }

        $persenRelevan = round($relevan / $total * 100, 1);
        $terkecil = min($persenRelevan, 100 - $persenRelevan);

        return [
            'status' => match (true) {
                $terkecil < 35 => 'timpang',
                $terkecil < 42 => 'perlu_perhatian',
                default => 'seimbang',
            },
            'persen_relevan' => $persenRelevan,
            'persen_tidak_relevan' => round(100 - $persenRelevan, 1),
        ];
    }

    /**
     * Tiga tingkat kesiapan, dokumen 10 bagian 9.3.
     *
     * Jumlah bukan satu-satunya syarat, dan angka di sini tidak pernah
     * mengizinkan apa pun sendiri. Ia hanya menjawab pertanyaan yang paling
     * sering ditanyakan selama fase pelabelan: masih berapa lagi.
     *
     * @return array<string, mixed>
     */
    private function kesiapan(int $total, int $perKelasTerkecil): array
    {
        $tingkat = match (true) {
            $total >= 3000 && $perKelasTerkecil >= 1200 => 'kandidat_produksi',
            $total >= 1500 && $perKelasTerkecil >= 600 => 'fine_tuning_awal',
            $total >= 500 && $perKelasTerkecil >= 200 => 'eksperimen',
            default => 'belum_layak',
        };

        [$targetTotal, $targetKelas] = match ($tingkat) {
            'kandidat_produksi' => [5000, 2000],
            'fine_tuning_awal' => [3000, 1200],
            'eksperimen' => [1500, 600],
            default => [500, 200],
        };

        return [
            'tingkat' => $tingkat,
            'target_total' => $targetTotal,
            'target_per_kelas' => $targetKelas,
            'kurang_total' => max(0, $targetTotal - $total),
            'kurang_per_kelas' => max(0, $targetKelas - $perKelasTerkecil),
        ];
    }

    /**
     * Tabel dataset. Seluruh filter, sort, dan paginasi ada di query string.
     */
    private function dataset(Request $request): mixed
    {
        $kueri = $this->kueriTersaring($request)
            ->with(['media:id,nama', 'pelabel:id,name'])
            ->select([
                'id', 'artikel_id', 'media_id', 'judul', 'excerpt', 'url',
                'tanggal_publikasi', 'label_manual', 'alasan_label', 'status_label',
                'tingkat_kesulitan', 'sumber_dataset', 'priority_score',
                'duplicate_group_id', 'is_excluded', 'labeled_by', 'labeled_at',
                'last_reviewed_at', 'updated_at',
            ]);

        return KueriTabel::untuk($kueri, $request)
            ->urut(
                ['judul', 'tanggal_publikasi', 'priority_score', 'labeled_at', 'updated_at'],
                'priority_score',
                'desc',
            )
            ->halaman();
    }

    /**
     * Kueri yang sudah menerapkan seluruh filter di URL, tanpa urutan.
     *
     * Dipakai bersama oleh tabel dan panel pelabelan, dan justru itu inti
     * perbaikannya. Sebelumnya panel selalu mengambil sampel berikutnya dari
     * antrean `belum_dilabeli` sambil mengabaikan filter yang sedang aktif,
     * sehingga menekan Simpan dan lanjut di antrean tinjauan melempar pelabel
     * ke antrean artikel baru tanpa tanda apa pun. Dua ratus lima puluh label
     * pindahan tidak pernah tersentuh karena statusnya `sudah_dilabeli`.
     */
    private function kueriTersaring(Request $request): Builder
    {
        $kueri = SampelRelevansi::query();

        $this->saringKhusus($kueri, $request);

        // KueriTabel menyunting builder yang diberikan, jadi $kueri sudah ikut
        // tersaring setelah baris ini.
        KueriTabel::untuk($kueri, $request)
            ->cari(['judul', 'isi'])
            ->saring([
                'status' => 'status_label',
                'label' => 'label_manual',
                'media' => 'media_id',
                'kesulitan' => 'tingkat_kesulitan',
                'sumber' => 'sumber_dataset',
                'pelabel' => 'labeled_by',
            ]);

        return $kueri;
    }

    /**
     * Filter yang tidak berbentuk daftar nilai kolom.
     *
     * `belum_direview` sengaja ada sejak fase 1. Ia yang memunculkan kembali
     * 249 label pindahan dari gold set, yang dibuat di bawah panduan versi 2.0
     * dan belum pernah ditinjau dengan kode alasan yang sekarang.
     */
    private function saringKhusus(Builder $kueri, Request $request): void
    {
        if ($request->query('dikeluarkan') === '1') {
            $kueri->where('is_excluded', true);
        } elseif ($request->query('dikeluarkan') !== 'semua') {
            $kueri->where('is_excluded', false);
        }

        if ($request->query('belum_direview') === '1') {
            // Artinya harus sama persis dengan kartu di tab ringkasan: sudah
            // punya keputusan manusia, tetapi belum pernah ditinjau ulang di
            // bawah kode alasan yang sekarang.
            //
            // Tanpa `label_manual` ikut disaring, seluruh kandidat yang belum
            // dilabeli pun ikut lolos, karena mereka juga tidak punya
            // `last_reviewed_at`. Kartu menyebut 250, tabelnya menampilkan
            // 4.137, dan yang berprioritas tinggi di antara ribuan artikel baru
            // itu justru yang disodorkan lebih dulu ke panel.
            $kueri->whereNotNull('label_manual')->whereNull('last_reviewed_at');
        }

        if ($request->query('duplikat') === '1') {
            $kueri->whereIn('duplicate_group_id', function ($sub) {
                $sub->select('duplicate_group_id')
                    ->from('sampel_relevansi')
                    ->whereNotNull('duplicate_group_id')
                    ->groupBy('duplicate_group_id')
                    ->havingRaw('count(*) > 1');
            });
        }

        if ($grup = $request->query('grup')) {
            $kueri->where('duplicate_group_id', (int) $grup);
        }
    }

    /**
     * Sampel yang sedang dibuka di panel pelabelan.
     *
     * Tanpa `sampel` di URL, yang diambil adalah sampel berikutnya **dari
     * antrean yang sedang disaring pelabel**, bukan dari antrean bawaan.
     * Filter di URL adalah antreannya, dan panel harus menghormatinya.
     *
     * Urutannya `last_reviewed_at` kosong lebih dulu, baru skor prioritas. Yang
     * pertama itu yang membuat "Simpan dan lanjut" selalu maju: setiap
     * penyimpanan mengisi `last_reviewed_at`, jadi sampel yang baru dikerjakan
     * langsung turun ke urutan paling belakang. Tanpa aturan itu, filter yang
     * tidak menyusut sendiri (misalnya menyaring satu media) akan menyodorkan
     * artikel yang sama berulang kali.
     *
     * @return array<string, mixed>|null
     */
    private function sampelTerpilih(Request $request): ?array
    {
        if ($request->query('labeli') !== '1') {
            return null;
        }

        $antrean = $this->kueriTersaring($request);

        $sampel = $request->query('sampel')
            ? SampelRelevansi::with(['media:id,nama', 'pelabel:id,name'])->find($request->query('sampel'))
            : (clone $antrean)->with('media:id,nama')
                ->orderByRaw('last_reviewed_at ASC NULLS FIRST')
                ->orderByDesc('priority_score')
                ->orderBy('id')
                ->first();

        if ($sampel === null) {
            return null;
        }

        return [
            'id' => $sampel->id,
            'artikel_id' => $sampel->artikel_id,
            'judul' => $sampel->judul,
            'excerpt' => $sampel->excerpt,
            // Utuh, tidak dipotong. Panel ini memuat satu sampel, dan yang
            // terpanjang di korpus 19.648 karakter, jadi tidak ada yang
            // dihemat. Yang hilang kalau dipotong justru dua hal yang dipakai:
            // tombol "Tampilkan seluruh isi" berbohong untuk 16% artikel, dan
            // salinan ke papan klip ikut terpotong tanpa penanda.
            'isi' => $sampel->isi,
            'url' => $sampel->url,
            'media' => $sampel->media?->nama,
            'tanggal_publikasi' => $sampel->tanggal_publikasi,
            'kategori_sumber' => $sampel->kategori_sumber,
            'tag_sumber' => $sampel->tag_sumber,
            'label_manual' => $sampel->label_manual?->value,
            'alasan_label' => $sampel->alasan_label,
            'tingkat_kesulitan' => $sampel->tingkat_kesulitan,
            'status_label' => $sampel->status_label->value,
            'priority_score' => $sampel->priority_score,
            'priority_reasons' => $sampel->priority_reasons,
            'pelabel' => $sampel->pelabel?->name,
            'labeled_at' => $sampel->labeled_at,
            // Sisa antrean yang sedang dikerjakan, bukan sisa seluruh dataset.
            // Angka 3.887 yang muncul saat menyaring 250 sampel tinjauan bukan
            // sekadar salah, ia menyembunyikan bahwa antreannya sudah berganti.
            'sisa_antrean' => (clone $antrean)->count(),
            'antrean' => $this->namaAntrean($request),
        ];
    }

    /**
     * Nama antrean yang sedang dikerjakan, untuk ditampilkan di panel.
     *
     * Pelabel harus bisa melihat sedang mengerjakan apa tanpa membaca URL.
     * Berpindah antrean tanpa disadari adalah cara 250 sampel tinjauan
     * terlewat sama sekali sementara layarnya terlihat baik-baik saja.
     */
    private function namaAntrean(Request $request): string
    {
        return match (true) {
            $request->query('belum_direview') === '1' => 'Belum ditinjau ulang',
            $request->query('dikeluarkan') === '1' => 'Dikeluarkan dari dataset',
            $request->query('duplikat') === '1' => 'Kelompok duplikat',
            $request->query('status') === 'belum_dilabeli' => 'Belum dilabeli',
            $request->query('status') === 'perlu_review' => 'Perlu review',
            (string) $request->query('cari') !== '' => 'Hasil pencarian',
            default => 'Seluruh dataset',
        };
    }

    /** @return array<string, mixed> */
    private function opsi(): array
    {
        return [
            'status' => [
                ['nilai' => 'belum_dilabeli', 'label' => 'Belum dilabeli'],
                ['nilai' => 'sudah_dilabeli', 'label' => 'Sudah dilabeli'],
                ['nilai' => 'perlu_review', 'label' => 'Perlu review'],
                ['nilai' => 'terkunci_test', 'label' => 'Test terkunci'],
            ],
            'label' => [
                ['nilai' => 'relevan', 'label' => 'Relevan'],
                ['nilai' => 'tidak_relevan', 'label' => 'Tidak relevan'],
            ],
            'kesulitan' => [
                ['nilai' => 'normal', 'label' => 'Normal'],
                ['nilai' => 'hard_positive', 'label' => 'Hard positive'],
                ['nilai' => 'hard_negative', 'label' => 'Hard negative'],
            ],
            'sumber' => [
                ['nilai' => 'crawler', 'label' => 'Crawler'],
                ['nilai' => 'migrated_gold_set', 'label' => 'Gold set lama'],
                ['nilai' => 'url_test', 'label' => 'Uji URL'],
                ['nilai' => 'manual_text', 'label' => 'Teks manual'],
                ['nilai' => 'production_error', 'label' => 'Kesalahan produksi'],
                ['nilai' => 'import', 'label' => 'Impor'],
            ],
            'media' => Media::query()->orderBy('nama')->get(['id', 'nama'])
                ->map(fn (Media $m) => ['nilai' => (string) $m->id, 'label' => $m->nama])->all(),
            'pelabel' => User::query()
                ->whereIn('id', DB::table('sampel_relevansi')->whereNotNull('labeled_by')->distinct()->pluck('labeled_by'))
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $u) => ['nilai' => (string) $u->id, 'label' => $u->name])->all(),
            'alasan' => [
                'relevan' => AlasanLabelRelevansi::opsi(AlasanLabelRelevansi::RELEVAN),
                'tidak_relevan' => AlasanLabelRelevansi::opsi(AlasanLabelRelevansi::TIDAK_RELEVAN),
            ],
        ];
    }
}
