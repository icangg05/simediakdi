<?php

namespace Database\Seeders;

use App\Enums\PeranPengguna;
use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            MediaSeeder::class,
            KonteksPantauanSeeder::class,
        ]);

        User::updateOrCreate(
            ['email' => 'admin@simedia.test'],
            [
                'name' => 'Admin Diskominfo',
                'password' => Hash::make('password'),
                'peran' => PeranPengguna::Superadmin,
                'jabatan' => 'Administrator Sistem',
                'email_verified_at' => now(),
            ],
        );

        // Akun contoh dua peran lain, supaya scoping dan larangan tulis bisa
        // diuji dari browser sejak sprint 1.
        User::updateOrCreate(
            ['email' => 'walikota@simedia.test'],
            [
                'name' => 'Staf Khusus Wali Kota',
                'password' => Hash::make('password'),
                'peran' => PeranPengguna::Walikota,
                'jabatan' => 'Staf Khusus',
                'email_verified_at' => now(),
            ],
        );

        User::updateOrCreate(
            ['email' => 'media@simedia.test'],
            [
                'name' => 'PIC Kendari Pos',
                'password' => Hash::make('password'),
                'peran' => PeranPengguna::Media,
                'media_id' => Media::where('slug', 'kendari-pos')->value('id'),
                'jabatan' => 'Redaktur',
                'email_verified_at' => now(),
            ],
        );
    }
}
