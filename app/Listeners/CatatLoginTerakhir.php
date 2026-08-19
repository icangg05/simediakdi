<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;

/**
 * Menandai kapan dan dari mana seorang pengguna terakhir masuk.
 *
 * Kolomnya sudah ada sejak awal dan halaman Pengguna sudah menampilkannya,
 * tetapi tidak satu pun kode yang pernah mengisinya, jadi seluruh barisnya
 * selalu terbaca "belum pernah masuk".
 *
 * Ditulis sebagai listener event, bukan di dalam controller login. Sesi juga
 * dibangun lewat jalur lain, misalnya cookie "ingat saya", dan penandaan yang
 * hanya menempel di satu controller akan melewatkan seluruh jalur itu.
 */
class CatatLoginTerakhir
{
    public function __construct(private Request $request) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        // `saveQuietly` supaya masuknya seseorang tidak menjadi baris di catatan
        // aktivitas. Catatan itu merekam perubahan identitas yang dilakukan
        // admin, dan dua kolom yang ditulis sistem sendiri tiap kali orang login
        // akan menenggelamkan isinya.
        $event->user->forceFill([
            'login_terakhir_at' => now(),
            'ip_login_terakhir' => $this->request->ip(),
        ])->saveQuietly();
    }
}
