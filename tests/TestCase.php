<?php

namespace Tests;

use App\Ai\Agents\RelevanceClassifier;
use App\Ai\Agents\SentimentClassifier;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tidak ada test yang boleh menyentuh Gemini sungguhan.
     *
     * Ini bukan kehati-hatian berlebihan. Sebelum penjaga ini dipasang, satu
     * test yang lupa memalsukan `SentimentClassifier` diam-diam memanggil API
     * Google setiap kali suite dijalankan: kuota terpakai, hasilnya bergantung
     * jaringan, dan testnya tetap hijau sehingga tidak ada yang menyadarinya.
     * Kegagalannya baru terlihat saat penyedia kebetulan kelebihan beban.
     *
     * `preventStrayPrompts` membalik keadaannya: prompt tanpa jawaban palsu
     * melempar galat yang menyebut promptnya, jadi test yang kurang lengkap
     * gagal seketika alih-alih membakar kuota. Test yang memang menguji jalur
     * AI cukup memanggil `fake([...])` sendiri, dan panggilan itu menggantikan
     * penjaga ini.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Cache dikosongkan tiap test, dan itu bukan kerapian.
        //
        // Store `array` hidup selama proses PHPUnit, sedangkan `RefreshDatabase`
        // mengembalikan urutan id dari awal. Akibatnya pengguna id 1 di satu
        // test memakai kunci cache yang sama persis dengan pengguna id 1 di
        // test berikutnya. Penghitung throttle rute dan penghitung kuota Gemini
        // sama-sama memakai cache, jadi test yang lulus sendirian bisa gagal
        // hanya karena test lain kebetulan berjalan lebih dulu. Itu benar
        // terjadi: rute klasifikasi menjawab 429 pada test peran walikota yang
        // sama sekali tidak menyentuh rute itu lebih dulu.
        Cache::flush();

        RelevanceClassifier::fake()->preventStrayPrompts();
        SentimentClassifier::fake()->preventStrayPrompts();
    }
}
