<?php

declare(strict_types=1);

namespace App\Services\Ai\DTO;

/**
 * Satu keputusan klasifikasi, dari penilai mana pun.
 *
 * Dipakai Gemini maupun IndoBERT. Bentuk yang sama itulah yang membuat
 * KlasifikasiArtikel memetakan hasil ke kolom database di satu jalur, tanpa
 * percabangan per penyedia yang perlahan membuat keduanya menulis kolom yang
 * berbeda.
 *
 * Tidak ada skor dan tidak ada probabilitas, dan itu bukan kelalaian. Gemini
 * tidak mengeluarkan angka semacam itu, ia menunjuk kalimat di artikel. Kolom
 * angka yang diisi nilai karangan akan terlihat terukur, lalu dipakai menyetel
 * ambang, dan ambangnya salah tanpa ada yang tahu sebabnya. IndoBERT memang
 * punya probabilitas, tetapi ia menuliskannya ke dalam `alasanRingkas` sampai
 * ada layar yang benar-benar menyaring atau mengurutkan menurut angka itu.
 *
 * Keraguan model dinyatakan lewat dua hal saja: label `perlu_review`, dan
 * bendera `perluReview` yang bisa menyala walaupun labelnya tegas.
 */
readonly class HasilKlasifikasi
{
    /** @param list<string> $bukti kutipan yang sudah diverifikasi ada di artikel */
    public function __construct(
        public string $label,
        public string $alasanKode,
        public string $alasanRingkas,
        public array $bukti,
        public bool $perluReview,
        public string $penyedia,
        public ?string $model,
        public int $latensiMs,
        public ?string $versiPrompt = null,
    ) {}
}
