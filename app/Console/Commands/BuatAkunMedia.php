<?php

namespace App\Console\Commands;

use App\Enums\PeranPengguna;
use App\Models\Media;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Membuat satu akun portal untuk tiap media partner.
 *
 * Sebagai command, bukan seeder. Seeder dijalankan ulang bersama
 * `migrate:fresh --seed` di mesin pengembangan, dan kata sandi yang tercetak
 * di situ akan tercampur dengan kata sandi produksi yang sebenarnya. Akun 30
 * media dibuat sekali, sengaja, oleh orang yang tahu ke mana kata sandinya
 * akan dikirim.
 *
 * Kata sandi ditampilkan sekali saja dan tidak disimpan di mana pun selain
 * hash-nya. Salin keluarannya sebelum menutup terminal.
 */
class BuatAkunMedia extends Command
{
    protected $signature = 'pengguna:buat-akun-media
        {--media= : Hanya satu media, dari slug-nya}
        {--setel-ulang : Setel ulang kata sandi akun yang sudah ada}';

    protected $description = 'Membuat akun portal untuk media partner beserta kata sandi awalnya';

    public function handle(): int
    {
        $media = Media::query()
            ->where('aktif', true)
            ->when($this->option('media'), fn ($q, $slug) => $q->where('slug', $slug))
            ->orderBy('nama')
            ->get();

        if ($media->isEmpty()) {
            $this->error('Tidak ada media yang cocok.');

            return self::FAILURE;
        }

        $baris = [];

        foreach ($media as $satu) {
            $email = 'portal@'.$satu->domain;
            $adaAkun = User::where('media_id', $satu->id)->where('peran', PeranPengguna::Media)->first();

            if ($adaAkun !== null && ! $this->option('setel-ulang')) {
                $baris[] = [$satu->nama, $adaAkun->email, 'sudah ada, dilewati'];

                continue;
            }

            // 16 karakter acak. Kata sandi yang bisa diketik ulang dari ingatan
            // akan dipakai bersama-sama satu kantor redaksi, dan portal ini
            // menulis ke arsip berita yang dibaca panel eksekutif.
            $sandi = Str::password(16, symbols: false);

            if ($adaAkun !== null) {
                $adaAkun->update(['password' => $sandi]);
                $baris[] = [$satu->nama, $adaAkun->email, $sandi];

                continue;
            }

            $pengguna = User::create([
                'name' => 'PIC '.$satu->nama,
                'email' => $email,
                'password' => $sandi,
                'peran' => PeranPengguna::Media,
                'media_id' => $satu->id,
                // Portal tidak mengirim email verifikasi: alamatnya dibuat
                // admin, bukan didaftarkan sendiri oleh pemiliknya.
                'email_verified_at' => now(),
            ]);

            $baris[] = [$satu->nama, $pengguna->email, $sandi];
        }

        $this->table(['Media', 'Email', 'Kata sandi'], $baris);
        $this->warn('Kata sandi di atas tidak ditampilkan lagi. Salin sekarang, kirim lewat kanal yang aman, dan minta media menggantinya saat pertama masuk.');

        return self::SUCCESS;
    }
}
