<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('pengaturan_ai')
            ->select(['id', 'prompt_relevansi', 'prompt_sentimen'])
            ->orderBy('id')
            ->each(function (object $pengaturan): void {
                DB::table('pengaturan_ai')
                    ->where('id', $pengaturan->id)
                    ->update([
                        'prompt_relevansi' => $this->istilahSentimen($pengaturan->prompt_relevansi),
                        'prompt_sentimen' => $this->istilahSentimen($pengaturan->prompt_sentimen),
                    ]);
            });
    }

    public function down(): void
    {
        // Istilah lama tidak dipulihkan karena prompt dapat disunting admin.
    }

    private function istilahSentimen(string $teks): string
    {
        return preg_replace(
            [
                '/\bBERNADA\b/u', '/\bBernada\b/u', '/\bbernada\b/u',
                '/\bNADANYA\b/u', '/\bNadanya\b/u', '/\bnadanya\b/u',
                '/\bNADA\b/u', '/\bNada\b/u', '/\bnada\b/u',
            ],
            [
                'BERSENTIMEN', 'Bersentimen', 'bersentimen',
                'SENTIMENNYA', 'Sentimennya', 'sentimennya',
                'SENTIMEN', 'Sentimen', 'sentimen',
            ],
            $teks,
        ) ?? $teks;
    }
};
