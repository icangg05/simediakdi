<?php

namespace App\Enums;

enum TipeSumber: string
{
    case Rss = 'rss';
    case Scrape = 'scrape';
    case GoogleNews = 'google_news';

    public function label(): string
    {
        return match ($this) {
            self::Rss => 'RSS',
            self::Scrape => 'Scraping',
            self::GoogleNews => 'Google News',
        };
    }
}
