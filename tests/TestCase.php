<?php

namespace Tests;

use App\Ai\Agents\RelevanceClassifier;
use App\Ai\Agents\SentimentClassifier;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

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

        RelevanceClassifier::fake()->preventStrayPrompts();
        SentimentClassifier::fake()->preventStrayPrompts();
    }
}
