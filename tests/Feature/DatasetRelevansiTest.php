<?php

namespace Tests\Feature;

use App\Jobs\ImporArtikelKeDatasetRelevansi;
use App\Models\Artikel;
use App\Models\KonteksPantauan;
use App\Models\Media;
use App\Models\SampelRelevansi;
use App\Models\User;
use App\Services\Relevance\SkorPrioritasPelabelan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Dataset relevansi: impor kandidat, pelabelan, dan aturan alasan wajib.
 *
 * Yang dijaga di sini terutama satu hal: keputusan manusia tidak pernah hilang
 * atau tertimpa proses otomatis. Label adalah bahan baku model, dan label yang
 * salah akan diajarkan sebagai kebenaran sampai ada yang menelusurinya kembali.
 */
class DatasetRelevansiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Media $media;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['peran' => 'superadmin']);
        $this->media = Media::create(['nama' => 'Kendari Pos', 'slug' => 'kp', 'domain' => 'kp.test']);

        KonteksPantauan::create([
            'nama' => 'Pemerintah Kota Kendari',
            'slug' => 'pemkot-kendari',
            'utama' => true,
            'kata_kunci' => ['pemkot kendari', 'pemerintah kota kendari'],
        ]);
    }

    public function test_artikel_asli_masuk_dataset_sebagai_kandidat(): void
    {
        $artikel = $this->artikel('Pemkot Kendari perbaiki drainase');

        $this->impor($artikel);

        $sampel = SampelRelevansi::first();

        $this->assertNotNull($sampel);
        $this->assertSame('crawler', $sampel->sumber_dataset);
        $this->assertSame('belum_dilabeli', $sampel->status_label->value);
        $this->assertSame($artikel->id, $sampel->duplicate_group_id);
    }

    public function test_salinan_tidak_pernah_masuk_dataset(): void
    {
        $induk = $this->artikel('Rilis yang sama');
        $salinan = $this->artikel('Rilis yang sama', 'https://kp.test/salinan');
        $salinan->update(['status_dedup' => 'salinan', 'artikel_induk_id' => $induk->id]);

        $this->impor($salinan);

        $this->assertSame(0, SampelRelevansi::count());
    }

    /**
     * Backfill dijalankan dua kali tidak boleh menggandakan dataset maupun
     * menghapus keputusan manusia yang sudah ada di antaranya.
     */
    public function test_impor_ulang_tidak_menimpa_label_dan_teks(): void
    {
        $artikel = $this->artikel('Judul saat dilabeli');
        $this->impor($artikel);

        SampelRelevansi::first()->update([
            'label_manual' => 'relevan',
            'status_label' => 'sudah_dilabeli',
            'labeled_by' => $this->admin->id,
        ]);

        $artikel->update(['judul' => 'Judul yang disunting media setelahnya']);
        $this->impor($artikel);

        $sampel = SampelRelevansi::first();

        $this->assertSame(1, SampelRelevansi::count());
        $this->assertSame('relevan', $sampel->label_manual->value);
        $this->assertSame('Judul saat dilabeli', $sampel->judul);
    }

    public function test_artikel_yang_dibahas_tanpa_disebut_di_judul_didahulukan(): void
    {
        $jelas = $this->artikel('Pemkot Kendari resmikan pasar', 'https://kp.test/a');
        $jelas->update(['isi' => 'Pemkot Kendari meresmikan pasar baru.']);

        $kabur = $this->artikel('Warga keluhkan sampah menumpuk', 'https://kp.test/b');
        $kabur->update([
            'isi' => 'Warga mengeluh. Pemkot Kendari belum menanggapi. '
                .'Dinas milik Pemkot Kendari menyatakan akan turun. Pemkot Kendari berjanji.',
        ]);

        $this->impor($jelas);
        $this->impor($kabur);

        $teratas = SampelRelevansi::orderByDesc('priority_score')->first();

        $this->assertSame($kabur->id, $teratas->artikel_id);
        $this->assertArrayHasKey('kabur_judul_bersih', $teratas->priority_reasons);
    }

    public function test_superadmin_dapat_melabeli_dan_perubahannya_tercatat(): void
    {
        $sampel = $this->sampel();

        $this->actingAs($this->admin)
            ->post("/admin/model-relevansi/sampel/{$sampel->id}/label", [
                'label' => 'relevan',
                'alasan' => 'kebijakan_program',
            ])
            ->assertRedirect();

        $sampel->refresh();

        $this->assertSame('relevan', $sampel->label_manual->value);
        $this->assertSame('sudah_dilabeli', $sampel->status_label->value);
        $this->assertSame($this->admin->id, $sampel->labeled_by);

        // Bukan sekadar ada barisnya. Yang menentukan berguna tidaknya audit
        // label adalah nilai sebelum dan sesudahnya benar-benar tersimpan,
        // beserta siapa yang mengubahnya. Versi pertama tes ini hanya memeriksa
        // `subject_type`, dan itu tetap hijau meski isinya kosong sama sekali.
        $log = Activity::where('subject_type', SampelRelevansi::class)
            ->where('subject_id', $sampel->id)
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($this->admin->id, $log->causer_id);
        $this->assertSame('relevan', $log->attribute_changes['attributes']['label_manual']);
        $this->assertSame('kebijakan_program', $log->attribute_changes['attributes']['alasan_label']);
        $this->assertArrayHasKey('label_manual', $log->attribute_changes['old']);
    }

    /**
     * Pembalikan label bisa dihitung dari log.
     *
     * Angka inilah yang menjawab seberapa jauh panduan pelabelan lama dan baru
     * berbeda, dan tanpa nilai lama yang tersimpan pertanyaannya tidak bisa
     * dijawab sama sekali.
     */
    public function test_pembalikan_label_menyimpan_nilai_lamanya(): void
    {
        $sampel = $this->sampel();
        $sampel->update(['label_manual' => 'tidak_relevan', 'status_label' => 'sudah_dilabeli']);

        $this->actingAs($this->admin)
            ->post("/admin/model-relevansi/sampel/{$sampel->id}/label", [
                'label' => 'relevan',
                'alasan' => 'kritik_keluhan',
            ])
            ->assertRedirect();

        $log = Activity::where('subject_id', $sampel->id)->where('event', 'updated')->latest('id')->first();

        $this->assertSame('tidak_relevan', $log->attribute_changes['old']['label_manual']);
        $this->assertSame('relevan', $log->attribute_changes['attributes']['label_manual']);
    }

    public function test_mengubah_label_lama_wajib_beralasan(): void
    {
        $sampel = $this->sampel();
        $sampel->update(['label_manual' => 'relevan', 'status_label' => 'sudah_dilabeli']);

        $this->actingAs($this->admin)
            ->post("/admin/model-relevansi/sampel/{$sampel->id}/label", ['label' => 'tidak_relevan'])
            ->assertSessionHasErrors('alasan');

        $this->assertSame('relevan', $sampel->fresh()->label_manual->value);
    }

    public function test_alasan_harus_cocok_dengan_labelnya(): void
    {
        $sampel = $this->sampel();

        $this->actingAs($this->admin)
            ->post("/admin/model-relevansi/sampel/{$sampel->id}/label", [
                'label' => 'relevan',
                'alasan' => 'pemprov_sultra',
            ])
            ->assertSessionHasErrors('alasan');
    }

    /**
     * Hard case harus berpasangan dengan labelnya.
     *
     * Penanda yang tertukar bukan sekadar salah istilah: strategi sampling
     * `balanced_with_hard_cases` memilih sampel berdasarkan penanda ini, dan
     * analisis kesalahan mengelompokkan false positive dengan false negative
     * memakainya. Terbalik berarti keduanya menghitung hal yang salah tanpa
     * satu pun galat muncul.
     */
    public function test_hard_negative_hanya_untuk_label_tidak_relevan(): void
    {
        $sampel = $this->sampel();

        $this->actingAs($this->admin)
            ->post("/admin/model-relevansi/sampel/{$sampel->id}/label", [
                'label' => 'relevan',
                'alasan' => 'kritik_keluhan',
                'kesulitan' => 'hard_negative',
            ])
            ->assertSessionHasErrors('kesulitan');

        $this->assertNull($sampel->fresh()->label_manual);
    }

    public function test_hard_positive_hanya_untuk_label_relevan(): void
    {
        $sampel = $this->sampel();

        $this->actingAs($this->admin)
            ->post("/admin/model-relevansi/sampel/{$sampel->id}/label", [
                'label' => 'tidak_relevan',
                'alasan' => 'pemprov_sultra',
                'kesulitan' => 'hard_positive',
            ])
            ->assertSessionHasErrors('kesulitan');
    }

    public function test_pasangan_hard_case_yang_benar_diterima(): void
    {
        $sampel = $this->sampel();

        $this->actingAs($this->admin)
            ->post("/admin/model-relevansi/sampel/{$sampel->id}/label", [
                'label' => 'tidak_relevan',
                'alasan' => 'pemprov_sultra',
                'kesulitan' => 'hard_negative',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('hard_negative', $sampel->fresh()->tingkat_kesulitan);
    }

    public function test_menandai_hard_case_wajib_beralasan(): void
    {
        $sampel = $this->sampel();

        $this->actingAs($this->admin)
            ->post("/admin/model-relevansi/sampel/{$sampel->id}/label", [
                'label' => 'tidak_relevan',
                'kesulitan' => 'hard_negative',
            ])
            ->assertSessionHasErrors('alasan');
    }

    public function test_sampel_test_terkunci_tidak_turun_status_saat_dilabeli_ulang(): void
    {
        $sampel = $this->sampel();
        $sampel->update(['status_label' => 'terkunci_test', 'label_manual' => 'relevan']);

        $this->actingAs($this->admin)
            ->post("/admin/model-relevansi/sampel/{$sampel->id}/label", [
                'label' => 'relevan',
                'alasan' => 'institusi_pemkot',
            ])
            ->assertRedirect();

        $this->assertSame('terkunci_test', $sampel->fresh()->status_label->value);
    }

    public function test_peran_walikota_tidak_bisa_membuka_laboratorium(): void
    {
        $walikota = User::factory()->create(['peran' => 'walikota']);

        $this->actingAs($walikota)->get('/admin/model-relevansi')->assertForbidden();
    }

    public function test_superadmin_dapat_membuka_kedua_tab(): void
    {
        $this->sampel();

        $this->actingAs($this->admin)->get('/admin/model-relevansi')->assertOk();
        $this->actingAs($this->admin)->get('/admin/model-relevansi?tab=dataset')->assertOk();
    }

    /**
     * Panel pelabelan tanpa id sampel mengambil antrean teratas menurut skor
     * prioritas. Itu yang membuat pelabelan bisa dimulai dengan satu klik.
     */
    public function test_panel_pelabelan_mengambil_antrean_prioritas_teratas(): void
    {
        $this->sampel('Prioritas rendah', 'https://kp.test/rendah')->update(['priority_score' => 5]);
        $tinggi = $this->sampel('Prioritas tinggi', 'https://kp.test/tinggi');
        $tinggi->update(['priority_score' => 50]);

        $this->actingAs($this->admin)
            ->get('/admin/model-relevansi?tab=dataset&labeli=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('sampel.id', $tinggi->id));
    }

    /** Filter harus terbaca dari query string, supaya hasilnya bisa dibookmark. */
    public function test_filter_dataset_dibaca_dari_query_string(): void
    {
        $this->sampel('Artikel relevan')->update([
            'label_manual' => 'relevan',
            'status_label' => 'sudah_dilabeli',
        ]);
        $this->sampel('Artikel belum dilabeli', 'https://kp.test/belum');

        $respons = $this->actingAs($this->admin)
            ->get('/admin/model-relevansi?tab=dataset&status=sudah_dilabeli');

        $respons->assertOk();
        $respons->assertInertia(fn ($page) => $page->where('dataset.total', 1));
    }

    /**
     * Panel pelabelan mengikuti antrean yang sedang disaring.
     *
     * Bug nyata: panel selalu mengambil sampel berikutnya dari antrean
     * `belum_dilabeli` sambil mengabaikan filter, sehingga menekan Simpan dan
     * lanjut di antrean tinjauan melempar pelabel ke antrean artikel baru tanpa
     * tanda apa pun. 250 label pindahan tidak pernah tersentuh selama satu sesi
     * penuh, dan layarnya terlihat baik-baik saja sepanjang waktu itu.
     */
    public function test_panel_mengambil_sampel_dari_antrean_yang_sedang_disaring(): void
    {
        $ditinjau = $this->sampel('Sudah ditinjau', 'https://kp.test/sudah');
        $ditinjau->update([
            'label_manual' => 'relevan',
            'status_label' => 'sudah_dilabeli',
            'last_reviewed_at' => now(),
        ]);

        $belumDitinjau = $this->sampel('Pindahan gold set', 'https://kp.test/pindahan');
        $belumDitinjau->update([
            'sumber_dataset' => 'migrated_gold_set',
            'label_manual' => 'relevan',
            'status_label' => 'sudah_dilabeli',
            'last_reviewed_at' => null,
        ]);

        // Sampel belum dilabeli berprioritas tinggi. Inilah yang dulu selalu
        // menang meski pelabel sedang menyaring antrean tinjauan.
        $this->sampel('Artikel baru', 'https://kp.test/baru')->update(['priority_score' => 99]);

        $this->actingAs($this->admin)
            ->get('/admin/model-relevansi?tab=dataset&belum_direview=1&labeli=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sampel.id', $belumDitinjau->id)
                ->where('sampel.sisa_antrean', 1)
                ->where('sampel.antrean', 'Belum ditinjau ulang'));
    }

    /**
     * Sampel yang baru dikerjakan turun ke urutan belakang.
     *
     * Tanpa aturan ini, antrean yang tidak menyusut sendiri, misalnya saringan
     * satu media, akan menyodorkan artikel yang sama berulang kali dan pelabel
     * berputar di tempat.
     */
    public function test_sampel_yang_baru_disimpan_tidak_disodorkan_lagi(): void
    {
        $pertama = $this->sampel('Artikel pertama', 'https://kp.test/satu');
        $pertama->update(['priority_score' => 90]);

        $kedua = $this->sampel('Artikel kedua', 'https://kp.test/dua');
        $kedua->update(['priority_score' => 10]);

        $this->actingAs($this->admin)
            ->post("/admin/model-relevansi/sampel/{$pertama->id}/label", ['label' => 'relevan']);

        $this->actingAs($this->admin)
            ->get('/admin/model-relevansi?tab=dataset&labeli=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('sampel.id', $kedua->id));
    }

    /**
     * Isi dikirim utuh, tidak dipotong.
     *
     * 16% artikel di korpus lebih dari 4.000 karakter. Potongan di sisi server
     * membuat dua hal berbohong sekaligus: tombol "Tampilkan seluruh isi" yang
     * tidak pernah menampilkan seluruhnya, dan salinan ke papan klip yang
     * terpotong tanpa penanda apa pun.
     */
    public function test_panel_pelabelan_mengirim_isi_artikel_utuh(): void
    {
        $panjang = str_repeat('Pemkot Kendari membangun drainase kota. ', 500);
        $sampel = $this->sampel();
        $sampel->update(['isi' => $panjang]);

        $this->actingAs($this->admin)
            ->get("/admin/model-relevansi?tab=dataset&labeli=1&sampel={$sampel->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('sampel.isi', $panjang));
    }

    private function artikel(string $judul, string $url = 'https://kp.test/a'): Artikel
    {
        return Artikel::create([
            'media_id' => $this->media->id,
            'judul' => $judul,
            'url' => $url,
            'url_kanonik' => $url,
            'isi' => $judul.'. Isi artikel.',
            'diambil_at' => now(),
            'status_proses' => 'isi_diambil',
        ]);
    }

    private function sampel(string $judul = 'Artikel uji', string $url = 'https://kp.test/a'): SampelRelevansi
    {
        return SampelRelevansi::create([
            'sumber_dataset' => 'crawler',
            'judul' => $judul,
            'isi' => $judul.'. Isi artikel.',
            'url' => $url,
            'media_id' => $this->media->id,
        ]);
    }

    private function impor(Artikel $artikel): void
    {
        (new ImporArtikelKeDatasetRelevansi($artikel->id))->handle(app(SkorPrioritasPelabelan::class));
    }
}
