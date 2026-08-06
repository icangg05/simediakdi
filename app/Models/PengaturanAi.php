<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Satu baris pengaturan klasifikasi Gemini: model dan kedua prompt.
 *
 * Dibaca setiap klasifikasi, tanpa cache. Kuerinya satu baris lewat primary
 * key, dan cache di sini berarti prompt yang baru disunting belum tentu
 * dipakai panggilan berikutnya. Selama klasifikasi dijalankan per klik, admin
 * yang menyetel prompt lalu menekan Klasifikasi harus melihat hasil prompt
 * barunya, bukan hasil prompt lama yang masih tersimpan di memori worker.
 */
class PengaturanAi extends Model
{
    use LogsActivity;

    protected $table = 'pengaturan_ai';

    protected $guarded = ['id'];

    /**
     * Barisnya diisi migration dan dijaga constraint `id = 1`, jadi
     * ketiadaannya bukan keadaan yang perlu ditangani melainkan instalasi yang
     * rusak. Nilai cadangan di sini hanya akan menyembunyikan kerusakan itu
     * sampai muncul sebagai hasil klasifikasi yang lahir dari prompt bawaan
     * tanpa ada yang tahu sebabnya.
     */
    public static function aktif(): self
    {
        return static::query()->firstOrFail();
    }

    /**
     * Versi yang disimpan bersama setiap prediksi.
     *
     * Label yang diketik admin ditambah sidik teks promptnya. Tanpa sidik itu,
     * menyunting satu kalimat tanpa menaikkan label membuat dua hasil yang
     * lahir dari prompt berbeda tercatat dengan versi yang sama, dan
     * perbandingan antar versi berhenti bisa dipercaya. Label tetap ada supaya
     * versinya terbaca manusia.
     */
    public function versiRelevansi(): string
    {
        return $this->versi($this->versi_prompt_relevansi, $this->prompt_relevansi);
    }

    public function versiSentimen(): string
    {
        return $this->versi($this->versi_prompt_sentimen, $this->prompt_sentimen);
    }

    private function versi(string $label, string $prompt): string
    {
        return $label.'.'.substr(sha1($prompt), 0, 8);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }
}
