<?php

namespace Tests\Feature;

use App\Ai\Agents\RelevanceClassifier;
use App\Ai\Agents\SentimentClassifier;
use App\Models\AnalisisSentimen as BarisAnalisis;
use App\Models\Artikel;
use App\Models\KunciGemini;
use App\Models\Media;
use App\Models\PengaturanAi;
use App\Models\User;
use App\Services\Ai\KlasifikasiArtikel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Exceptions\RateLimitedException;
use Tests\TestCase;

/**
 * Alur klasifikasi Gemini, dari tombol sampai label akhir.
 *
 * Yang diuji di sini hal-hal yang kegagalannya tidak menimbulkan galat apa pun:
 * sentimen yang jalan padahal artikelnya tidak relevan, koreksi manusia yang
 * tertimpa klasifikasi ulang, bukti karangan yang lolos menjadi label, dan
 * artikel yang berubah status padahal Gemini gagal dipanggil.
 *
 * Semuanya menghasilkan dashboard yang tetap terisi angka yang tampak wajar.
 */
class KlasifikasiGeminiTest extends TestCase
{
    use RefreshDatabase;

    private Artikel $artikel;

    /** Kalimat yang benar-benar ada di isi artikel, jadi buktinya sah. */
    private const KUTIPAN_SAH = 'Pemerintah Kota Kendari memperbaiki drainase di Kecamatan Kadia';

    protected function setUp(): void
    {
        parent::setUp();

        // Klasifikasi menolak jalan tanpa kunci di database, sama seperti di
        // produksi. Jawaban modelnya tetap palsu, yang nyata hanya barisnya.
        KunciGemini::create(['label' => 'Kunci uji', 'kunci' => 'kunci-uji-yang-cukup-panjang']);

        $media = Media::create(['nama' => 'Kendari Pos', 'slug' => 'kp', 'domain' => 'kp.test']);

        $this->artikel = Artikel::create([
            'media_id' => $media->id,
            'judul' => 'Pemkot Kendari memperbaiki drainase di Kadia',
            'url' => 'https://kp.test/drainase',
            'url_kanonik' => 'https://kp.test/drainase',
            'ringkasan' => 'Perbaikan drainase dimulai pekan ini.',
            'isi' => self::KUTIPAN_SAH.'. Pekerjaan dimulai pekan ini dan '
                .'ditargetkan selesai dalam dua bulan menurut Dinas Pekerjaan Umum.',
            'dipublikasikan_at' => now(),
            'diambil_at' => now(),
            'status_proses' => 'isi_diambil',
        ]);
    }

    public function test_artikel_relevan_langsung_dinilai_sentimennya(): void
    {
        RelevanceClassifier::fake([$this->jawaban('relevan')]);
        SentimentClassifier::fake([$this->jawaban('positif')]);

        app(KlasifikasiArtikel::class)->jalankan($this->artikel);

        $baris = BarisAnalisis::firstOrFail();

        $this->assertTrue($baris->relevan);
        $this->assertSame('positif', $baris->label_model->value);
        $this->assertSame('gemini', $baris->provider);
        $this->assertSame([self::KUTIPAN_SAH], $baris->evidence);
        $this->assertSame('selesai', $this->artikel->fresh()->status_proses);
    }

    /**
     * Sentimen menyusul relevansi, bukan berjalan sendiri.
     *
     * Model sentimen tetap mengeluarkan label untuk artikel yang tidak relevan,
     * dan label itu masuk agregasi tanpa ada yang menandainya salah.
     */
    public function test_artikel_tidak_relevan_tidak_dinilai_sentimennya(): void
    {
        RelevanceClassifier::fake([$this->jawaban('tidak_relevan')]);

        // `SentimentClassifier` sengaja tidak dipalsukan. Penjaga di TestCase
        // membuat setiap prompt tanpa jawaban palsu melempar galat, jadi test
        // ini gagal seketika kalau sentimen ikut dipanggil. Itu yang menjamin
        // artikel tidak relevan hanya memakan satu permintaan ke Gemini,
        // bukan dua.
        app(KlasifikasiArtikel::class)->jalankan($this->artikel);

        $baris = BarisAnalisis::firstOrFail();

        $this->assertFalse($baris->relevan);
        $this->assertNull($baris->label_model);
        $this->assertSame('tidak_relevan', $this->artikel->fresh()->status_proses);
    }

    public function test_hasil_perlu_review_masuk_antrean_manusia(): void
    {
        RelevanceClassifier::fake([$this->jawaban('perlu_review')]);

        app(KlasifikasiArtikel::class)->jalankan($this->artikel);

        $this->assertSame('perlu_review', $this->artikel->fresh()->status_proses);
        $this->assertFalse(BarisAnalisis::firstOrFail()->relevan);
    }

    /**
     * `perlu_review` bukan label sentimen.
     *
     * Memaksanya menjadi netral membuat dashboard menghitung keraguan model
     * sebagai pernyataan bahwa nadanya datar.
     */
    public function test_sentimen_ragu_mengosongkan_label_bukan_menjadi_netral(): void
    {
        RelevanceClassifier::fake([$this->jawaban('relevan')]);
        SentimentClassifier::fake([$this->jawaban('perlu_review')]);

        app(KlasifikasiArtikel::class)->jalankan($this->artikel);

        $baris = BarisAnalisis::firstOrFail();

        $this->assertNull($baris->label_model);
        $this->assertTrue($baris->perlu_review);
    }

    /**
     * Bukti karangan adalah satu-satunya cacat Gemini yang tidak punya gejala
     * lain. Alasannya tetap kalimat Indonesia yang rapi dan tetap lolos skema.
     */
    public function test_bukti_yang_tidak_ada_di_artikel_menjadi_perlu_review(): void
    {
        RelevanceClassifier::fake([[
            'label' => 'relevan',
            'reason_code' => 'program_pemkot',
            'reason_summary' => 'Artikel membahas program Pemkot.',
            'evidence' => ['Wali Kota meresmikan jembatan baru di Poasia pekan lalu'],
            'requires_manual_review' => false,
        ]]);

        app(KlasifikasiArtikel::class)->jalankan($this->artikel);

        // Bukan tidak_relevan. Yang gagal alasannya, bukan artikelnya.
        $this->assertSame('perlu_review', $this->artikel->fresh()->status_proses);
        $this->assertFalse(BarisAnalisis::firstOrFail()->relevan);
    }

    /**
     * F-13: koreksi manusia mengalahkan hasil model.
     *
     * Sebelum penanda `relevan_manual` ada, keputusan antrean review ditulis ke
     * kolom `relevan` yang sama persis dengan yang ditimpa klasifikasi ulang,
     * tanpa cara apa pun untuk mengetahui bahwa isinya keputusan manusia.
     */
    public function test_relevansi_manual_bertahan_setelah_klasifikasi_ulang(): void
    {
        $baris = BarisAnalisis::create([
            'artikel_id' => $this->artikel->id,
            'relevan' => true,
            'relevan_manual' => true,
            'relevan_dikoreksi_at' => now(),
        ]);

        RelevanceClassifier::fake([$this->jawaban('tidak_relevan')]);
        SentimentClassifier::fake([$this->jawaban('netral')]);

        app(KlasifikasiArtikel::class)->jalankan($this->artikel);

        $this->assertTrue($baris->fresh()->relevan);
        $this->assertSame('selesai', $this->artikel->fresh()->status_proses);
    }

    public function test_label_sentimen_manual_mengalahkan_hasil_gemini(): void
    {
        $baris = BarisAnalisis::create([
            'artikel_id' => $this->artikel->id,
            'relevan' => true,
            'label_manual' => 'positif',
        ]);

        SentimentClassifier::fake([$this->jawaban('negatif')]);

        app(KlasifikasiArtikel::class)->jalankanSentimen($this->artikel);

        $baris->refresh();

        $this->assertSame('negatif', $baris->label_model->value);
        $this->assertSame('positif', $baris->label_manual->value);
        $this->assertSame('positif', $baris->label_efektif->value);
        $this->assertFalse($baris->perlu_review);
    }

    /**
     * Kegagalan Gemini tidak boleh mengubah status artikel.
     *
     * Yang dilarang adalah halaman yang menelan galatnya lalu menandai artikel
     * `tidak_relevan`, karena artikel itu tidak akan pernah dinilai ulang oleh
     * siapa pun.
     */
    public function test_artikel_tidak_hilang_saat_gemini_gagal(): void
    {
        RelevanceClassifier::fake(fn () => throw RateLimitedException::forProvider('gemini'));

        $this->actingAs(User::factory()->create(['peran' => 'superadmin']))
            ->post("/admin/artikel/{$this->artikel->id}/klasifikasi")
            ->assertRedirect()
            ->assertSessionHas('galat');

        $this->assertSame('isi_diambil', $this->artikel->fresh()->status_proses);
        $this->assertSame(0, BarisAnalisis::count());
    }

    /**
     * Halaman dibuka pada tahap Selesai, dan urutannya tetap.
     *
     * Yang paling sering dicari admin adalah hasil, bukan tumpukan pekerjaan
     * yang menunggu. Urutan tombol ikut diuji karena ia menentukan tombol mana
     * yang jatuh paling kiri, dan itu yang ditekan orang tanpa membaca.
     */
    /**
     * Pesan hasil menyebut judul dan tahap tujuannya.
     *
     * Artikel yang selesai dinilai langsung berpindah tahap dan hilang dari
     * daftar yang sedang dibuka. Pesan yang hanya berbunyi "selesai dinilai"
     * meninggalkan admin menebak baris mana yang barusan diproses dan ke mana
     * perginya, terutama saat menilai belasan artikel berturut-turut.
     */
    public function test_pesan_hasil_menyebut_judul_dan_tahap_tujuan(): void
    {
        RelevanceClassifier::fake([$this->jawaban('relevan')]);
        SentimentClassifier::fake([$this->jawaban('positif')]);

        $this->actingAs(User::factory()->create(['peran' => 'superadmin']))
            ->post("/admin/artikel/{$this->artikel->id}/klasifikasi")
            ->assertRedirect();

        $pesan = session('sukses');

        $this->assertStringContainsString('Pemkot Kendari memperbaiki drainase', $pesan);
        $this->assertStringContainsString('relevan, positif', $pesan);
        $this->assertStringContainsString('Selesai', $pesan);

        // Tautan ikut dikirim supaya toast bisa menampilkan tombol Lihat.
        // Menyebut judulnya saja membuat admin tahu apa yang terjadi tetapi
        // tidak punya cara membuka datanya lagi setelah barisnya berpindah.
        $this->assertSame(
            ['url' => "/admin/artikel/{$this->artikel->id}", 'label' => 'Lihat'],
            session('tautan'),
        );
    }

    /**
     * Angka di tombol ikut saringan media dan tanggal.
     *
     * Angka yang tidak ikut menyaring menjanjikan isi yang berbeda dari yang
     * benar-benar muncul begitu tombolnya ditekan. Angka yang berbohong lebih
     * buruk daripada tidak ada angka sama sekali, karena admin memakainya untuk
     * memutuskan tab mana yang layak dibuka.
     */
    public function test_angka_tombol_mengikuti_saringan_media(): void
    {
        $lain = Media::create(['nama' => 'Media Lain', 'slug' => 'ml', 'domain' => 'ml.test']);

        // Satu artikel milik media lain, sudah selesai dan relevan.
        $milikLain = Artikel::create([
            'media_id' => $lain->id,
            'judul' => 'Berita milik media lain',
            'url' => 'https://ml.test/berita',
            'url_kanonik' => 'https://ml.test/berita',
            'isi' => 'Isi berita.',
            'diambil_at' => now(),
            'status_proses' => 'selesai',
        ]);

        BarisAnalisis::create(['artikel_id' => $milikLain->id, 'relevan' => true]);

        // Artikel dari setUp ditandai selesai tetapi tidak relevan.
        $this->artikel->update(['status_proses' => 'selesai']);
        BarisAnalisis::create(['artikel_id' => $this->artikel->id, 'relevan' => false]);

        $admin = User::factory()->create(['peran' => 'superadmin']);

        $this->actingAs($admin)
            ->get("/admin/artikel?media={$lain->id}")
            ->assertOk()
            ->assertInertia(fn ($halaman) => $halaman
                ->where('daftarTahap.0.jumlah', 1)
                ->where('daftarRelevansi.0.jumlah', 1)
                ->where('daftarRelevansi.1.jumlah', 0));

        // Tanpa saringan media keduanya terhitung, satu di tiap sisi.
        $this->actingAs($admin)
            ->get('/admin/artikel')
            ->assertOk()
            ->assertInertia(fn ($halaman) => $halaman
                ->where('daftarTahap.0.jumlah', 2)
                ->where('daftarRelevansi.0.jumlah', 1)
                ->where('daftarRelevansi.1.jumlah', 1));
    }

    /**
     * Keputusan tidak relevan menghapus sisa penilaian AI yang tidak berlaku.
     *
     * Ini pernah salah pada data sungguhan, artikel 5832. Barisnya sudah
     * ditandai tidak relevan oleh admin, tetapi tetap membawa `perlu_review`
     * menyala beserta alasan dari penilaian sentimen sebelumnya. Layar
     * detailnya berbunyi "kutipan bukti tidak ditemukan, label asli netral"
     * untuk artikel yang keputusannya sudah final di tangan manusia, dan tidak
     * ada satu pun galat yang menunjukkan keadaan itu.
     */
    public function test_keputusan_tidak_relevan_membersihkan_sisa_penilaian_ai(): void
    {
        $baris = BarisAnalisis::create([
            'artikel_id' => $this->artikel->id,
            'relevan' => true,
            'label_model' => 'netral',
            'perlu_review' => true,
            'reason_code' => 'bukti_kosong',
            'reason_summary' => 'Kutipan bukti dari model tidak ditemukan di isi artikel.',
            'evidence' => ['kutipan karangan'],
        ]);

        $this->actingAs(User::factory()->create(['peran' => 'superadmin']))
            ->post("/admin/artikel/{$this->artikel->id}/relevansi", ['relevan' => '0'])
            ->assertRedirect();

        $baris->refresh();

        $this->assertFalse($baris->relevan);
        $this->assertFalse($baris->relevan_manual);
        $this->assertFalse($baris->perlu_review);
        $this->assertNull($baris->label_model);
        $this->assertNull($baris->evidence);
        $this->assertSame('keputusan_manusia', $baris->reason_code);
    }

    /**
     * Reset mencabut koreksi manusia lalu menilai ulang.
     *
     * Penilaian ulang bukan tambahan. Sentimen punya dua kolom terpisah
     * sehingga mencabut koreksinya cukup mengosongkan `label_manual`, tetapi
     * relevansi hanya punya satu kolom `relevan` yang sudah ditimpa keputusan
     * admin. Tanpa memanggil AI lagi, artikelnya akan berhenti pada nilai
     * manusia yang justru baru saja dicabut.
     */
    public function test_reset_mencabut_koreksi_lalu_menilai_ulang(): void
    {
        $baris = BarisAnalisis::create([
            'artikel_id' => $this->artikel->id,
            'relevan' => false,
            'relevan_manual' => false,
            'relevan_dikoreksi_at' => now(),
            'label_manual' => 'negatif',
            'dikoreksi_at' => now(),
            'catatan_koreksi' => 'Menurut saya tidak relevan.',
        ]);

        RelevanceClassifier::fake([$this->jawaban('relevan')]);
        SentimentClassifier::fake([$this->jawaban('positif')]);

        $this->actingAs(User::factory()->create(['peran' => 'superadmin']))
            ->post("/admin/artikel/{$this->artikel->id}/reset")
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $baris->refresh();

        // Seluruh penanda manusia hilang.
        $this->assertNull($baris->relevan_manual);
        $this->assertNull($baris->relevan_dikoreksi_at);
        $this->assertNull($baris->label_manual);
        $this->assertNull($baris->catatan_koreksi);

        // Dan nilainya benar-benar berasal dari penilaian baru, bukan sisa lama.
        $this->assertTrue($baris->relevan);
        $this->assertSame('positif', $baris->label_efektif->value);
        $this->assertSame('selesai', $this->artikel->fresh()->status_proses);
    }

    /**
     * Reset ditolak kalau tidak ada yang bisa dicabut.
     *
     * Tanpa penjaga ini tombolnya berubah menjadi cara lain memanggil Gemini,
     * dan kuota terpakai untuk mengulang hasil yang sudah ada tanpa alasan.
     */
    public function test_reset_ditolak_saat_tidak_ada_koreksi_manusia(): void
    {
        BarisAnalisis::create([
            'artikel_id' => $this->artikel->id,
            'relevan' => true,
            'label_model' => 'netral',
        ]);

        // Agen sengaja tidak dipalsukan: penjaga di TestCase membuat test ini
        // gagal seketika kalau Gemini sampai tersentuh.
        $this->actingAs(User::factory()->create(['peran' => 'superadmin']))
            ->post("/admin/artikel/{$this->artikel->id}/reset")
            ->assertRedirect()
            ->assertSessionHas('galat');
    }

    public function test_halaman_artikel_terbuka_pada_tahap_selesai(): void
    {
        $this->actingAs(User::factory()->create(['peran' => 'superadmin']))
            ->get('/admin/artikel')
            ->assertOk()
            ->assertInertia(fn ($halaman) => $halaman
                ->where('tahap', 'selesai')
                ->where('daftarTahap.0.nilai', 'selesai')
                ->where('daftarTahap.1.nilai', 'belum')
                ->where('daftarTahap.2.nilai', 'review'));
    }

    /**
     * Paginasi memakai parameter `halaman`, bukan `page`.
     *
     * Tombol paginasi di DataTable mengirim `halaman`, sedangkan paginator
     * bawaan Laravel membaca `page`. Ketidakcocokan itu tidak menimbulkan galat
     * apa pun: tombolnya menembak URL yang benar dan halamannya termuat, hanya
     * isinya selalu baris yang sama.
     */
    /**
     * Artikel tanpa isi tidak boleh sampai ke Gemini lewat pintu mana pun.
     *
     * Tombol Klasifikasi sudah menjaganya sejak awal, koreksi relevansi manual
     * belum. Akibatnya nyata dan pernah terjadi di data sungguhan: satu artikel
     * kosong ditandai relevan, sentimennya tetap dinilai, Gemini membaca judul
     * saja, dan kutipan buktinya lolos verifikasi karena judul memang ikut
     * dikirim. Yang tersimpan label yang terlihat sah untuk berita yang tidak
     * pernah terbaca isinya.
     */
    public function test_relevansi_manual_menolak_artikel_tanpa_isi(): void
    {
        $kosong = Artikel::create([
            'judul' => 'Judul tanpa isi',
            'url' => 'https://kp.test/kosong',
            'url_kanonik' => 'https://kp.test/kosong',
            'diambil_at' => now(),
            'status_proses' => 'mentah',
        ]);

        // Tidak dipalsukan: penjaga di TestCase membuat test ini gagal kalau
        // Gemini sampai tersentuh.
        $this->actingAs(User::factory()->create(['peran' => 'superadmin']))
            ->post("/admin/artikel/{$kosong->id}/relevansi", ['relevan' => true])
            ->assertRedirect()
            ->assertSessionHas('galat');

        $this->assertSame(0, BarisAnalisis::where('artikel_id', $kosong->id)->count());
    }

    public function test_paginasi_membaca_parameter_halaman(): void
    {
        for ($i = 0; $i < 30; $i++) {
            Artikel::create([
                'judul' => "Berita uji {$i}",
                'url' => "https://kp.test/uji-{$i}",
                'url_kanonik' => "https://kp.test/uji-{$i}",
                'isi' => 'Isi berita uji.',
                'diambil_at' => now(),
                'status_proses' => 'perlu_review',
            ]);
        }

        $this->actingAs(User::factory()->create(['peran' => 'superadmin']))
            ->get('/admin/artikel?tahap=review&halaman=2')
            ->assertOk()
            ->assertInertia(fn ($halaman) => $halaman
                ->where('artikel.current_page', 2)
                ->where('artikel.per_page', 25));
    }

    /**
     * Tahap Menunggu review tidak boleh terbuka kosong.
     *
     * Saringan relevansi berbawaan Relevan, sedangkan seluruh artikel di tahap
     * ini tersimpan dengan `relevan = false`, bukan karena Gemini memutuskan
     * artikelnya tidak relevan melainkan karena ia menolak memutuskan.
     * Menerapkan bawaan itu di sini menghasilkan tabel kosong yang terlihat
     * seperti kerusakan, tepat di tahap yang isinya justru menunggu dikerjakan
     * manusia.
     */
    public function test_tahap_menunggu_review_tidak_menerapkan_saringan_relevansi(): void
    {
        RelevanceClassifier::fake([$this->jawaban('perlu_review')]);

        app(KlasifikasiArtikel::class)->jalankan($this->artikel);

        $this->actingAs(User::factory()->create(['peran' => 'superadmin']))
            ->get('/admin/artikel?tahap=review')
            ->assertOk()
            ->assertInertia(fn ($halaman) => $halaman
                ->where('relevansi', null)
                ->has('artikel.data', 1));
    }

    public function test_halaman_artikel_menyaring_menurut_tahap(): void
    {
        $this->actingAs(User::factory()->create(['peran' => 'superadmin']))
            ->get('/admin/artikel?tahap=belum')
            ->assertOk()
            ->assertInertia(fn ($halaman) => $halaman
                ->component('admin/artikel/Index')
                ->where('tahap', 'belum')
                ->has('artikel.data', 1)
                ->has('daftarTahap', 3));
    }

    /**
     * Tahap Selesai memuat yang relevan maupun tidak, dan select relevansi yang
     * memisahkan keduanya.
     *
     * Tanpa saringan ini artikel yang dibuang Gemini tidak punya tempat sama
     * sekali di layar, dan keputusan yang salah tidak bisa dikoreksi siapa pun.
     */
    public function test_tahap_selesai_bisa_disaring_menurut_relevansi(): void
    {
        RelevanceClassifier::fake([$this->jawaban('tidak_relevan')]);

        app(KlasifikasiArtikel::class)->jalankan($this->artikel);

        $admin = User::factory()->create(['peran' => 'superadmin']);

        $this->actingAs($admin)
            ->get('/admin/artikel?tahap=selesai&relevansi=tidak_relevan')
            ->assertOk()
            ->assertInertia(fn ($halaman) => $halaman->has('artikel.data', 1));

        $this->actingAs($admin)
            ->get('/admin/artikel?tahap=selesai&relevansi=relevan')
            ->assertOk()
            ->assertInertia(fn ($halaman) => $halaman->has('artikel.data', 0));
    }

    /**
     * Admin boleh memutuskan relevansi sebelum Gemini pernah dipanggil.
     *
     * Barisnya belum ada, jadi keputusannya harus membuat baris itu sendiri.
     * Memaksa admin menekan Klasifikasi lebih dulu berarti membayar satu
     * panggilan untuk jawaban yang sudah diketahuinya.
     */
    public function test_relevansi_manual_bisa_diputuskan_tanpa_memanggil_gemini(): void
    {
        RelevanceClassifier::fake(fn () => throw new \RuntimeException('Gemini tidak boleh dipanggil.'));
        SentimentClassifier::fake([$this->jawaban('netral')]);

        $this->actingAs(User::factory()->create(['peran' => 'superadmin']))
            ->post("/admin/artikel/{$this->artikel->id}/relevansi", ['relevan' => true])
            ->assertRedirect();

        $baris = BarisAnalisis::firstOrFail();

        $this->assertTrue($baris->relevan);
        $this->assertTrue($baris->relevan_manual);
        $this->assertSame('netral', $baris->label_model->value);
        $this->assertSame('selesai', $this->artikel->fresh()->status_proses);
    }

    /**
     * Model dan prompt dibaca dari `pengaturan_ai`, bukan dari config.
     *
     * Versi yang tercatat memuat sidik isi promptnya. Tanpa sidik itu,
     * menyunting prompt tanpa menaikkan labelnya membuat dua hasil dari prompt
     * berbeda tercatat dengan versi yang sama, dan perbandingan antar versi
     * berhenti bisa dipercaya tanpa ada yang menyadarinya.
     */
    public function test_model_dan_versi_prompt_dibaca_dari_pengaturan_database(): void
    {
        $prompt = str_repeat('Nilai nada artikel ini. ', 5);

        PengaturanAi::aktif()->fill([
            'model' => 'gemini-uji',
            'versi_prompt_sentimen' => 'sentiment-v9',
            'prompt_sentimen' => $prompt,
        ])->save();

        RelevanceClassifier::fake([$this->jawaban('relevan')]);
        SentimentClassifier::fake([$this->jawaban('positif')]);

        app(KlasifikasiArtikel::class)->jalankan($this->artikel);

        $baris = BarisAnalisis::firstOrFail();

        $this->assertSame('gemini-uji', $baris->model_versi);
        $this->assertSame('sentiment-v9.'.substr(sha1($prompt), 0, 8), $baris->prompt_version);
    }

    /** @return array<string, mixed> */
    private function jawaban(string $label): array
    {
        return [
            'label' => $label,
            'reason_code' => 'program_pemkot',
            'reason_summary' => 'Artikel membahas pekerjaan drainase Pemkot Kendari.',
            'evidence' => [self::KUTIPAN_SAH],
            'requires_manual_review' => false,
        ];
    }
}
