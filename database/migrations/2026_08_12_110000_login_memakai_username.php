<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Login berpindah dari email ke username.
 *
 * Akun lama belum punya username. Kalau kolomnya langsung dibuat wajib, seluruh
 * akun yang sudah ada terkunci di halaman login. Karena itu kolom dibuat
 * nullable dulu, diisi dari bagian nama pada email, baru dijadikan wajib.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 60)->nullable()->unique()->after('name');
        });

        // Sengaja memakai User::usernameDari, bukan menyalin aturannya ke sini.
        // Aturan penanganan bentrok cukup berliku, dan dua salinan yang lambat
        // laun berbeda akan membuat username akun lama dan akun baru tidak
        // sebentuk.
        DB::table('users')->orderBy('id')->select('id', 'email')->each(function ($baris) {
            DB::table('users')->where('id', $baris->id)->update([
                'username' => User::usernameDari($baris->email),
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 60)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('username');
        });
    }
};
