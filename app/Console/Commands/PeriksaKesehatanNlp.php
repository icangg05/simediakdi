<?php

namespace App\Console\Commands;

use App\Services\Nlp\KlienNlp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Dipanggil scheduler tiap 5 menit. Tiga kegagalan berturut-turut berarti
 * layanan benar-benar mati, bukan sekadar sibuk — baru saat itu admin diberi
 * tahu, supaya peringatannya tidak kehilangan makna karena terlalu sering.
 */
class PeriksaKesehatanNlp extends Command
{
    protected $signature = 'nlp:health';

    protected $description = 'Memeriksa layanan NLP dan mencatat statusnya untuk dashboard admin';

    private const KUNCI_GAGAL = 'nlp:gagal-berturut';

    private const KUNCI_STATUS = 'nlp:status';

    public function handle(KlienNlp $nlp): int
    {
        try {
            $kesehatan = $nlp->kesehatan();
        } catch (\Throwable $e) {
            $gagal = Cache::increment(self::KUNCI_GAGAL) ?: 1;

            Cache::put(self::KUNCI_STATUS, [
                'sehat' => false,
                'pesan' => $e->getMessage(),
                'diperiksa_at' => now()->toIso8601String(),
                'gagal_berturut' => $gagal,
            ], now()->addHour());

            if ($gagal >= 3) {
                Log::error('Layanan NLP tidak menjawab tiga kali berturut-turut', ['pesan' => $e->getMessage()]);
            }

            $this->error("Layanan NLP tidak menjawab ({$gagal}x): {$e->getMessage()}");

            return self::FAILURE;
        }

        Cache::forget(self::KUNCI_GAGAL);
        Cache::put(self::KUNCI_STATUS, [
            'sehat' => true,
            'versi' => $kesehatan['versi'] ?? null,
            'model_sentimen' => $kesehatan['model_sentimen'] ?? null,
            'diperiksa_at' => now()->toIso8601String(),
            'gagal_berturut' => 0,
        ], now()->addHour());

        $this->info('Layanan NLP sehat, versi '.($kesehatan['versi'] ?? '?'));

        return self::SUCCESS;
    }

    /** @return array<string, mixed>|null */
    public static function statusTerakhir(): ?array
    {
        return Cache::get(self::KUNCI_STATUS);
    }
}
