<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Menyimpan alamat feed dalam bentuk yang sudah kanonik.
 *
 * Dari 35 sumber aktif, 26 di antaranya tersimpan dalam bentuk yang selalu
 * dijawab 301 oleh situsnya. Sebagian besar hanya kurang garis miring di
 * ujung, lima di antaranya juga berpindah ke subdomain www. Artinya setiap
 * pengambilan membayar satu perjalanan bolak-balik tambahan, dan crawl
 * berjalan tiap tiga jam untuk seluruh sumber, jadi sekitar dua ratus
 * permintaan pengalihan per hari yang tidak menghasilkan apa pun.
 *
 * Daftarnya ditulis satu per satu, bukan aturan "tambahkan garis miring pada
 * setiap alamat yang berakhiran /feed". Aturan seperti itu terbukti salah:
 * sibernas.id justru sebaliknya, bentuk kanoniknya tanpa garis miring dan
 * versi bergaris miringnya yang dialihkan. Setiap pasangan di sini diukur
 * lebih dulu dengan mengikuti pengalihannya sampai berhenti di HTTP 200.
 *
 * Pencocokannya memakai alamat lama secara persis, jadi baris yang sudah
 * disunting admin tidak ikut tersentuh.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const KANONIK = [
        'https://kendaripos.fajar.co.id/feed' => 'https://kendaripos.fajar.co.id/feed/',
        'https://britakita.net/feed' => 'https://britakita.net/feed/',
        'https://kolomrakyat.com/feed' => 'https://kolomrakyat.com/feed/',
        'https://sultratv.id/feed' => 'https://www.sultratv.id/feed/',
        'https://sultrademo.or.id/feed' => 'https://sultrademo.or.id/feed/',
        'https://radarkendari.com/feed' => 'https://radarkendari.com/feed/',
        'https://trijayakendari.com/feed' => 'https://www.trijayakendari.com/feed/',
        'https://kendariinfo.com/feed' => 'https://kendariinfo.com/feed/',
        'https://perdetiknews.com/feed' => 'https://perdetiknews.com/feed/',
        'https://galerisultra.com/feed' => 'https://galerisultra.com/feed/',
        'https://radarsultra.co/feed' => 'https://radarsultra.co/feed/',
        'https://figursultra.com/feed' => 'https://figursultra.com/feed/',
        'https://lensatimur.id/feed' => 'https://www.lensatimur.id/feed/',
        'https://koranheadline.com/feed' => 'https://koranheadline.com/feed/',
        'https://mediatamasultra.com/feed' => 'https://mediatamasultra.com/feed/',
        'https://kisahan.id/feed' => 'https://kisahan.id/feed/',
        'https://sultranesia.com/feed' => 'https://sultranesia.com/feed/',
        'https://tajukinfo.com/feed' => 'https://tajukinfo.com/feed/',
        'https://terassultra.com/feed' => 'https://www.terassultra.com/feed/',
        'https://lontarasultra.com/feed' => 'https://lontarasultra.com/feed/',
        'https://sultramerdeka.com/feed' => 'https://www.sultramerdeka.com/feed/',
        'https://metrokendari.com/feed' => 'https://metrokendari.com/feed/',
        'https://informasisultra.com/feed' => 'https://informasisultra.com/feed/',
        'https://kongkritpost.com/feed' => 'https://kongkritpost.com/feed/',
        'https://mitranusantara.id/feed' => 'https://mitranusantara.id/feed/',
        'https://portal.id/feed' => 'https://portal.id/feed/',
    ];

    public function up(): void
    {
        foreach (self::KANONIK as $lama => $baru) {
            DB::table('sumber_feed')->where('url', $lama)->update(['url' => $baru]);
        }
    }

    public function down(): void
    {
        foreach (self::KANONIK as $lama => $baru) {
            DB::table('sumber_feed')->where('url', $baru)->update(['url' => $lama]);
        }
    }
};
