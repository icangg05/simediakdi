<?php

namespace App\Enums;

/**
 * Google News pernah jadi tipe ketiga di sini dan sudah dicabut.
 *
 * robots.txt milik news.google.com melarang pengambilan `/rss/search`, jadi
 * ketiga sumbernya gagal pada setiap kali jalan sejak dipasang, tanpa pernah
 * menghasilkan satu artikel pun. Yang tersisa hanya log galat sejam sekali.
 */
enum TipeSumber: string
{
    case Rss = 'rss';
    case Scrape = 'scrape';

    public function label(): string
    {
        return match ($this) {
            self::Rss => 'RSS',
            self::Scrape => 'Scraping',
        };
    }
}
