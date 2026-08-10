<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indeks untuk tanggal terbit, yang kini menjadi sumbu waktu seluruh agregasi.
 *
 * Sebelumnya semuanya membaca `diambil_at` dan indeks yang ada cukup. Sejak
 * agregasi berpindah ke coalesce(dipublikasikan_at, diambil_at), setiap kueri
 * rentang menjadi pemindaian penuh tabel artikel, dan tabel itu tumbuh tiap jam.
 *
 * Ekspresinya harus sama persis dengan App\Models\Artikel::waktuTerbit(),
 * termasuk urutan argumennya. Postgres hanya memakai indeks ekspresi kalau
 * ekspresi di kueri cocok, dan ketidakcocokan tidak menimbulkan galat apa pun,
 * hanya halaman yang pelan tanpa sebab yang terlihat.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX idx_artikel_terbit
            ON artikel ((coalesce(dipublikasikan_at, diambil_at)) DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_artikel_terbit');
    }
};
