<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Satu baris kredensial kanal alert: token bot Telegram dan chat ID tujuannya.
 *
 * Satu-satunya tempat kedua nilai itu dibaca, dan sekarang juga satu-satunya
 * tempat keduanya disimpan. Cadangan `.env` dihapus: dua sumber untuk satu
 * nilai berarti dua tempat yang bisa berbeda, dan admin yang mengosongkan chat
 * ID di layar lalu melihat angkanya kembali sendiri setelah reload akan
 * menganggap halamannya rusak.
 *
 * Instalasi baru berangkat dengan kanal kosong. Itu memang keadaannya, dan
 * halaman Alert menyebutnya apa adanya sampai ada yang mengisi formnya.
 */
class PengaturanAlert extends Model
{
    use LogsActivity;

    protected $table = 'pengaturan_alert';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            // Token bot adalah kredensial penuh, diperlakukan seperti kunci
            // Gemini. Nilainya tidak pernah dikirim kembali ke layar.
            'telegram_token' => 'encrypted',
        ];
    }

    /**
     * Barisnya diisi migration dan dijaga constraint `id = 1`, jadi
     * ketiadaannya bukan keadaan yang perlu ditangani melainkan instalasi yang
     * rusak. Nilai cadangan di sini hanya akan menyembunyikan kerusakan itu
     * sampai muncul sebagai alert yang diam tanpa ada yang tahu sebabnya.
     */
    public static function aktif(): self
    {
        return static::query()->firstOrFail();
    }

    public function token(): string
    {
        return (string) $this->telegram_token;
    }

    public function chatId(): string
    {
        return (string) $this->telegram_chat_id;
    }

    /**
     * Siap berarti kedua nilai terisi, bukan sekadar salah satunya.
     *
     * Token tanpa chat ID dan chat ID tanpa token sama-sama berakhir sebagai
     * alert yang tidak pernah terkirim, jadi keduanya harus terbaca sebagai
     * belum siap di layar.
     */
    public function siap(): bool
    {
        return $this->token() !== '' && $this->chatId() !== '';
    }

    /**
     * Isi token tidak pernah masuk log, hanya kenyataan bahwa ia berubah.
     *
     * `logOnlyDirty` saja tidak cukup: nilai lama dan nilai barunya ikut
     * tersimpan di tabel activity log dalam bentuk terbaca, dan itu membuat
     * kredensial yang sengaja dienkripsi di kolomnya sendiri bocor lewat
     * pintu belakang.
     *
     * Jejaknya tetap ada. `logEmptyChanges` bawaan spatie bernilai true, jadi
     * penggantian token yang tidak menyentuh chat ID tetap menulis satu baris
     * activity log berisi pelaku dan waktunya, hanya tanpa nilainya. Syarat
     * dokumen 06 bagian 5 terpenuhi untuk hal yang memang boleh dicatat.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['telegram_chat_id'])
            ->logOnlyDirty();
    }
}
