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
    case ScrapeRender = 'scrape_render';

    public function label(): string
    {
        return match ($this) {
            self::Rss => 'RSS',
            self::Scrape => 'Scraping',
            self::ScrapeRender => 'Scraping (perlu render)',
        };
    }

    /** Kedua tipe scraping memakai selector yang sama, hanya cara mengambil halamannya yang beda. */
    public function pakaiSelector(): bool
    {
        return $this === self::Scrape || $this === self::ScrapeRender;
    }
}
