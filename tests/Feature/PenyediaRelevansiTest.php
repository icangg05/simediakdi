<?php

namespace Tests\Feature;

use App\Ai\Agents\RelevanceClassifier;
use App\Ai\Agents\SentimentClassifier;
use App\Console\Commands\AntreGemini;
use App\Jobs\KlasifikasiGemini;
use App\Models\AnalisisSentimen;
use App\Models\AntreanGemini;
use App\Models\Artikel;
use App\Models\KunciGemini;
use App\Models\Media;
use App\Models\PelatihanModelRelevansi;
use App\Models\PengaturanAi;
use App\Models\SnapshotDatasetRelevansi;
use App\Models\User;
use App\Services\Ai\KlasifikasiArtikel;
use App\Services\Ai\RotasiKunciGemini;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pemilihan penilai relevansi, Gemini atau IndoBERT.
 *
 * Yang diuji di sini empat hal yang kegagalannya tidak menimbulkan galat apa
 * pun sampai berhari-hari kemudian: keputusan IndoBERT yang tercatat sebagai
 * keputusan Gemini, model ragu-ragu yang tetap membuang berita, penyisiran
 * antrean yang mengantrekan ulang hasil IndoBERT selamanya, dan pengaturan
 * yang menunjuk model yang tidak ada.
 *
 * Yang ketiga paling penting. Tanpa penjaganya, `gemini:antre --isi` menemukan
 * seluruh artikel berpenyedia indobert setiap jam, mengantrekannya lagi, lalu
 * IndoBERT menilainya lagi. Antreannya tidak pernah kosong dan tidak ada satu
 * pun pesan galat yang menjelaskan sebabnya.
 */
class PenyediaRelevansiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Artikel $artikel;

    /** Balasan `/prediksi` yang sedang dipakai palsu layanan IndoBERT. */
    private array $prediksi = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['peran' => 'superadmin']);

        Storage::fake('local');

        // Klasifikasi menolak jalan tanpa kunci di database, sama seperti di
        // produksi, walaupun jalur IndoBERT tidak memakainya.
        KunciGemini::create(['label' => 'Kunci uji', 'kunci' => 'kunci-uji-yang-cukup-panjang']);

        $media = Media::create(['nama' => 'Kendari Pos', 'slug' => 'kp', 'domain' => 'kp.test']);

        $this->artikel = Artikel::create([
            'media_id' => $media->id,
            'judul' => 'Pemkot Kendari memperbaiki drainase di Kadia',
            'url' => 'https://kp.test/drainase',
            'url_kanonik' => 'https://kp.test/drainase',
            'ringkasan' => 'Perbaikan drainase dimulai pekan ini.',
            'isi' => 'Pemerintah Kota Kendari memperbaiki drainase di Kecamatan Kadia. '
                .'Pekerjaan dimulai pekan ini menurut Dinas Pekerjaan Umum.',
            'dipublikasikan_at' => now(),
            'diambil_at' => now(),
            'status_proses' => 'isi_diambil',
        ]);

        $this->prediksi = [
            'label' => 'relevan',
            'probabilitas_relevan' => 0.93,
            'probabilitas_tidak_relevan' => 0.07,
            'confidence' => 0.86,
            'inferensi_ms' => 120,
        ];

        // Dibaca lewat closure, bukan ditanam sebagai nilai. `Http::fake()`
        // yang dipanggil kedua kali menggabungkan stub dan yang pertama tetap
        // menang, jadi test yang perlu balasan berbeda mengubah `$this->prediksi`.
        Http::fake([
            '*/prediksi' => fn () => Http::response($this->prediksi),
        ]);
    }

    /**
     * Penanda IndoBERT bertahan sampai artikel selesai dinilai.
     *
     * Termasuk pada artikel yang relevan, dan justru itu inti tesnya. Baris
     * analisis punya satu kolom `provider` untuk dua keputusan, dan langkah
     * sentimen yang menyusul pernah menuliskan `gemini` di atasnya. Akibatnya
     * penanda IndoBERT hanya bertahan pada artikel yang dibuang, sedangkan
     * artikel yang lolos saringan, yang paling perlu ditelusuri saat menilai
     * apakah modelnya layak dipercaya, terbaca seolah Gemini yang memutuskan.
     */
    public function test_indobert_menandai_barisnya_dan_tidak_memanggil_gemini_untuk_relevansi(): void
    {
        $this->modelAktif();
        $this->pilihIndoBert();

        SentimentClassifier::fake([$this->jawabanSentimen('positif')]);

        // RelevanceClassifier sengaja tidak dipalsukan. `preventStrayPrompts`
        // di TestCase melempar galat begitu ia dipanggil, jadi test ini gagal
        // seketika kalau relevansi diam-diam tetap lewat Gemini.
        app(KlasifikasiArtikel::class)->jalankan($this->artikel);

        $baris = AnalisisSentimen::firstOrFail();

        $this->assertTrue($baris->relevan);
        $this->assertSame('indobert', $baris->provider);
        $this->assertSame('selesai', $this->artikel->fresh()->status_proses);

        // Sentimennya tetap Gemini. IndoBERT memang tidak dilatih untuk itu.
        $this->assertSame('positif', $baris->label_model->value);
    }

    /**
     * Keraguan model bukan keputusan.
     *
     * IndoBERT selalu mengeluarkan salah satu dari dua label, betapapun
     * tipisnya selisih probabilitas. Tanpa gerbang ini, tebakan 51 banding 49
     * membuang satu berita dari dashboard tanpa pernah ada yang meninjaunya.
     */
    public function test_confidence_di_bawah_ambang_masuk_perlu_review(): void
    {
        $this->modelAktif();
        $this->pilihIndoBert();

        config(['relevansi.ambang_ragu' => 0.4]);

        $this->prediksi = [
            'label' => 'tidak_relevan',
            'probabilitas_relevan' => 0.44,
            'probabilitas_tidak_relevan' => 0.56,
            'confidence' => 0.12,
            'inferensi_ms' => 118,
        ];

        app(KlasifikasiArtikel::class)->jalankan($this->artikel);

        $baris = AnalisisSentimen::firstOrFail();

        // `relevan` false karena labelnya bukan relevan, dan itu memang cara
        // keraguan disimpan. Yang membedakannya dari penolakan tegas adalah
        // `status_proses`, bukan kolom ini, persis seperti saat Gemini menjawab
        // perlu_review. Kolom `perlu_review` di baris analisis menjawab
        // pertanyaan lain, yaitu apakah nada beritanya perlu ditinjau, dan itu
        // hanya diisi langkah sentimen yang memang tidak pernah jalan di sini.
        $this->assertFalse($baris->relevan);
        $this->assertSame('perlu_review', $this->artikel->fresh()->status_proses);
        $this->assertSame('confidence_rendah', $baris->reason_code);

        // Antrean tidak menyentuhnya lagi. Yang ditunggu keputusan manusia
        // lewat tombol Relevan atau Tidak, bukan percobaan kedua dari model
        // yang sama.
        $this->artisan(AntreGemini::class, ['--isi' => true, '--batas' => 0])
            ->assertSuccessful();

        $this->assertSame(0, AntreanGemini::query()->count());
    }

    /**
     * Penyisiran antrean tidak boleh menemukan hasilnya sendiri.
     *
     * Aturan kandidat dulu berbunyi "provider kosong atau bukan gemini", dan
     * itu benar selama gemini satu-satunya penyedia yang mencap. Sejak IndoBERT
     * ikut mengisi kolom itu, bunyi tersebut menjadi lingkaran yang tidak
     * pernah berhenti. Test ini yang menahannya kembali secara diam-diam.
     */
    public function test_hasil_indobert_tidak_diantrekan_ulang(): void
    {
        AnalisisSentimen::create([
            'artikel_id' => $this->artikel->id,
            'relevan' => false,
            'provider' => 'indobert',
            'reason_code' => 'model_relevansi',
            'reason_summary' => 'Model uji menilai peluang relevan 4,0 persen.',
        ]);

        $this->artikel->update(['status_proses' => 'tidak_relevan']);

        $this->artisan(AntreGemini::class, ['--isi' => true, '--batas' => 0])
            ->assertSuccessful();

        $this->assertSame(0, AntreanGemini::query()->count());
    }

    /**
     * Baris warisan tetap tersapu.
     *
     * Perbaikan di atas tidak boleh sekalian mematikan sebab aslinya. Baris
     * dari pipeline lama tidak pernah dicap penyedia mana pun, dan itulah yang
     * memang perlu dinilai ulang.
     */
    public function test_baris_tanpa_penyedia_tetap_diantrekan(): void
    {
        AnalisisSentimen::create([
            'artikel_id' => $this->artikel->id,
            'relevan' => false,
            'provider' => null,
        ]);

        $this->artikel->update(['status_proses' => 'tidak_relevan']);

        $this->artisan(AntreGemini::class, ['--isi' => true, '--batas' => 0])
            ->assertSuccessful();

        $this->assertSame(1, AntreanGemini::query()->count());
    }

    /**
     * Pengaturan tidak boleh menunjuk model yang tidak ada.
     *
     * Ditolak saat disimpan, bukan saat klasifikasi berjalan. Pengaturan yang
     * tersimpan terbaca sebagai pengaturan yang berlaku, dan sebab antrean
     * berhenti tidak boleh hanya muncul sebagai pesan galat per baris di
     * halaman Antrean AI.
     */
    public function test_indobert_ditolak_selama_belum_ada_model_aktif(): void
    {
        $this->actingAs($this->admin)
            ->put('/admin/pengaturan/ai', $this->formPengaturan('indobert'))
            ->assertSessionHas('galat');

        $this->assertSame('gemini', PengaturanAi::aktif()->penyedia_relevansi);
    }

    public function test_indobert_bisa_disimpan_setelah_ada_model_aktif(): void
    {
        $this->modelAktif();

        $this->actingAs($this->admin)
            ->put('/admin/pengaturan/ai', $this->formPengaturan('indobert'))
            ->assertSessionHas('sukses');

        $this->assertSame('indobert', PengaturanAi::aktif()->penyedia_relevansi);
    }

    /**
     * Penilaian yang tidak menyentuh Gemini tidak menghabiskan jeda.
     *
     * Jedanya ada untuk menjaga kuota Gemini, jadi klik yang tidak memakai kuota
     * tidak boleh dihukum. Dengan IndoBERT, artikel yang ditolak selesai tanpa
     * satu pun permintaan ke Google, dan admin yang menyisir daftar tidak
     * relevan dulu menunggu lima belas detik untuk kuota yang tidak pernah
     * terpakai.
     */
    public function test_hasil_tidak_relevan_indobert_tidak_menghabiskan_jeda(): void
    {
        $this->modelAktif();
        $this->pilihIndoBert();

        $this->prediksi = [
            'label' => 'tidak_relevan',
            'probabilitas_relevan' => 0.04,
            'probabilitas_tidak_relevan' => 0.96,
            'confidence' => 0.92,
            'inferensi_ms' => 110,
        ];

        // Dua klik beruntun, tanpa jeda sedetik pun di antaranya.
        $this->actingAs($this->admin)
            ->post("/admin/artikel/{$this->artikel->id}/klasifikasi")
            ->assertSessionHas('jedaGemini', false);

        $this->actingAs($this->admin)
            ->post("/admin/artikel/{$this->artikel->id}/klasifikasi")
            ->assertSessionMissing('galat');
    }

    /**
     * Menandai tidak relevan juga tidak memanggil Gemini.
     *
     * Keputusan manusia langsung tersimpan, sentimennya tidak pernah dinilai.
     * Ini jalur yang paling sering dipakai saat menyisir daftar, dan justru ia
     * yang dulu paling terasa lambat.
     */
    public function test_menandai_tidak_relevan_tidak_menghabiskan_jeda(): void
    {
        $this->actingAs($this->admin)
            ->post("/admin/artikel/{$this->artikel->id}/relevansi", ['relevan' => false])
            ->assertSessionHas('jedaGemini', false);

        $this->actingAs($this->admin)
            ->post("/admin/artikel/{$this->artikel->id}/relevansi", ['relevan' => false])
            ->assertSessionMissing('galat');
    }

    /**
     * Penilaian yang memanggil Gemini tetap dijeda.
     *
     * Ini sisi lain perbaikannya, dan yang menjaga maksud aslinya tetap hidup.
     * Melonggarkan jalur IndoBERT tidak boleh sekalian melonggarkan jalur yang
     * benar-benar membakar kuota.
     */
    public function test_penilaian_lewat_gemini_tetap_dijeda(): void
    {
        RelevanceClassifier::fake([$this->jawabanRelevansi('tidak_relevan')]);

        $this->actingAs($this->admin)
            ->post("/admin/artikel/{$this->artikel->id}/klasifikasi")
            ->assertSessionHas('jedaGemini', true);

        $this->actingAs($this->admin)
            ->post("/admin/artikel/{$this->artikel->id}/klasifikasi")
            ->assertSessionHas('galat');
    }

    /**
     * Berita tidak relevan tidak menggeser giliran Gemini.
     *
     * Inti pemisahannya. Jeda itu ada untuk menjaga kuota, dan artikel yang
     * ditolak IndoBERT tidak memakai kuota sepeser pun. Menghitungnya sebagai
     * giliran berarti tumpukan berita tidak relevan ikut mengantre di belakang
     * jarak yang tidak menjaga apa pun, dan antreannya menumpuk tanpa satu pun
     * permintaan terkirim ke Google.
     */
    public function test_artikel_tidak_relevan_tidak_menggeser_giliran_gemini(): void
    {
        $this->modelAktif();
        $this->pilihIndoBert();

        $this->prediksi = [
            'label' => 'tidak_relevan',
            'probabilitas_relevan' => 0.04,
            'probabilitas_tidak_relevan' => 0.96,
            'confidence' => 0.92,
            'inferensi_ms' => 110,
        ];

        $this->kerjakanAntrean();

        $rotasi = app(RotasiKunciGemini::class);

        $this->assertSame(0, $rotasi->jedaArtikel());
        $this->assertSame('selesai', AntreanGemini::firstOrFail()->status);
        $this->assertSame('tidak_relevan', $this->artikel->fresh()->status_proses);
    }

    /**
     * Berita yang lolos menggeser giliran, karena ia memang memanggil Gemini.
     *
     * Sisi lain pemisahannya, dan yang menjaga maksud aslinya tetap hidup.
     * Melonggarkan jalur yang tidak memakai kuota tidak boleh sekalian
     * melonggarkan jalur yang membakarnya.
     */
    public function test_artikel_yang_lolos_menggeser_giliran_gemini(): void
    {
        $this->modelAktif();
        $this->pilihIndoBert();

        SentimentClassifier::fake([$this->jawabanSentimen('positif')]);

        $this->kerjakanAntrean();

        $this->assertGreaterThan(0, app(RotasiKunciGemini::class)->jedaArtikel());
        $this->assertSame('selesai', $this->artikel->fresh()->status_proses);
    }

    /**
     * Giliran yang belum tiba menunda job, bukan menggagalkannya.
     *
     * Keputusan relevansinya sudah tersimpan sebelum job berhenti, jadi
     * pengulangan nanti tidak mengulang pekerjaan yang mahal. Yang diulang cuma
     * panggilan IndoBERT yang berjalan di server sendiri.
     */
    public function test_giliran_belum_tiba_menunda_tanpa_memakan_percobaan(): void
    {
        $this->modelAktif();
        $this->pilihIndoBert();

        // Artikel lain barusan memakai Gemini, jadi gilirannya belum tiba.
        app(RotasiKunciGemini::class)->tandaiArtikel();

        $this->kerjakanAntrean();

        $baris = AntreanGemini::firstOrFail();

        $this->assertSame('menunggu', $baris->status);
        $this->assertSame(0, (int) $baris->percobaan);

        // Relevansinya sudah diputuskan dan tersimpan, tinggal sentimennya.
        $this->assertTrue(AnalisisSentimen::firstOrFail()->relevan);
        $this->assertSame('dianalisis', $this->artikel->fresh()->status_proses);
    }

    /**
     * Jarak antar artikel yang lolos menjaga jatah harian bertahan sehari.
     *
     * Enam kunci berarti 3.000 permintaan sehari, dan satu artikel yang lolos
     * IndoBERT memakan tepat satu permintaan untuk sentimen. Dibagi rata ke
     * dalam 86.400 detik, jaraknya 28,8 detik. Tanpa lantai ini kuotanya habis
     * sebelum sore, lalu seluruh antrean mematung sampai tengah malam waktu
     * Pasifik dan tombol Klasifikasi ikut menolak bekerja.
     */
    public function test_jarak_artikel_menjaga_jatah_harian(): void
    {
        $this->modelAktif();
        $this->pilihIndoBert();

        // Tepat enam kunci, sama dengan produksi. Yang dibuat setUp dibuang
        // dulu supaya jumlahnya angka yang dihitung komentar di atas.
        KunciGemini::query()->delete();

        foreach (range(1, 6) as $nomor) {
            KunciGemini::create(['label' => "Kunci {$nomor}", 'kunci' => "kunci-{$nomor}-cukup-panjang", 'aktif' => true]);
        }

        config(['ai.batas_kunci.rpd' => 500, 'ai.antrean.jeda_gemini_indobert' => 0]);

        $rotasi = app(RotasiKunciGemini::class);
        $rotasi->tandaiArtikel();

        $this->assertEqualsWithDelta(28.8, $rotasi->jedaArtikel(), 1.0);
    }

    /**
     * Jarak antrean sendiri tetap lima detik, dan itu bukan soal kuota.
     *
     * Ia hanya menjaga CPU layanan inferensi. Kuotanya dijaga jarak antar
     * artikel yang lolos, dan memisahkan keduanya justru itu yang membuat
     * berita tidak relevan bisa disapu cepat.
     */
    public function test_jarak_antrean_indobert_tidak_ikut_menjaga_kuota(): void
    {
        $this->modelAktif();
        $this->pilihIndoBert();

        config(['ai.antrean.jeda_detik_indobert' => 5, 'ai.batas_kunci.rpd' => 1]);

        $this->assertSame(5.0, AntreanGemini::jedaDetik());
    }

    /** Satu baris antrean untuk artikel uji, lalu dikerjakan job sungguhan. */
    private function kerjakanAntrean(): void
    {
        $baris = AntreanGemini::create([
            'artikel_id' => $this->artikel->id,
            'prioritas' => 1,
            'status' => 'menunggu',
        ]);

        (new KlasifikasiGemini($baris->id))->handle(
            app(KlasifikasiArtikel::class),
            app(RotasiKunciGemini::class),
        );
    }

    /** Satu model berhasil dengan artefak di disk, ditandai aktif. */
    private function modelAktif(): PelatihanModelRelevansi
    {
        $snapshot = SnapshotDatasetRelevansi::create([
            'nama' => 'snapshot-uji',
            'random_seed' => 3,
            'persen_relevan' => 50,
            'persen_tidak_relevan' => 50,
            'persen_train' => 80,
            'persen_validation' => 10,
            'persen_test' => 10,
            'dibuat_oleh' => $this->admin->id,
        ]);

        // Manifest wajib ada di disk. PrediktorRelevansi memeriksanya sebelum
        // menembak layanan, supaya pesan galatnya menyebut nama model alih-alih
        // nama direktori yang tidak dikenal siapa pun di layar admin.
        Storage::disk('local')->put('model-relevansi/1/manifest.json', '{"max_seq_length":256}');

        return PelatihanModelRelevansi::create([
            'nama' => 'model-uji',
            'snapshot_dataset_relevansi_id' => $snapshot->id,
            'base_model' => 'apriandito/indobert-relevancy-classifier',
            'konfigurasi' => [
                'base_model' => 'apriandito/indobert-relevancy-classifier',
                'epoch' => 3,
                'batch_size' => 8,
                'learning_rate' => 0.00001,
                'max_seq_length' => 256,
                'seed' => 1,
                'early_stopping' => null,
            ],
            'status' => 'berhasil',
            'artefak_path' => 'model-relevansi/1',
            'aktif' => true,
            'dibuat_oleh' => $this->admin->id,
        ]);
    }

    private function pilihIndoBert(): void
    {
        PengaturanAi::aktif()->update(['penyedia_relevansi' => 'indobert']);
    }

    /** @return array<string, string> */
    private function formPengaturan(string $penyedia): array
    {
        $pengaturan = PengaturanAi::aktif();

        return [
            'model' => $pengaturan->model,
            'penyedia_relevansi' => $penyedia,
            'versi_prompt_relevansi' => $pengaturan->versi_prompt_relevansi,
            'prompt_relevansi' => $pengaturan->prompt_relevansi,
            'versi_prompt_sentimen' => $pengaturan->versi_prompt_sentimen,
            'prompt_sentimen' => $pengaturan->prompt_sentimen,
        ];
    }

    /** @return array<string, mixed> */
    private function jawabanRelevansi(string $label): array
    {
        return [
            'label' => $label,
            'reason_code' => 'bukan_pemkot',
            'reason_summary' => 'Artikel tidak membahas Pemkot Kendari.',
            'evidence' => ['Pemerintah Kota Kendari memperbaiki drainase di Kecamatan Kadia'],
            'requires_manual_review' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function jawabanSentimen(string $label): array
    {
        return [
            'label' => $label,
            'reason_code' => 'nada_positif',
            'reason_summary' => 'Berita menyebut perbaikan yang sedang berjalan.',
            'evidence' => ['Pemerintah Kota Kendari memperbaiki drainase di Kecamatan Kadia'],
            'needs_review' => false,
        ];
    }
}
