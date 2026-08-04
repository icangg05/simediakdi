<?php

namespace Tests\Concerns;

use App\Models\GerbangMutuRelevansi;
use App\Models\User;
use App\Models\VersiModelRelevansi;
use Illuminate\Support\Facades\DB;

/**
 * Membuat model relevansi produksi yang gerbangnya lulus.
 *
 * Dipakai test mana pun yang perlu menjalankan analisis sentimen. Sejak
 * gerbang mutu berlaku, sentimen tidak jalan tanpa model produksi yang lulus,
 * dan itu berarti sebagian besar skenario sentimen butuh persiapan ini.
 */
trait MembuatModelRelevansi
{
    protected function modelRelevansiProduksi(
        string $gerbang = 'passed',
        string $versi = 'simedia-relevancy-v1',
    ): VersiModelRelevansi {
        $pembuat = User::factory()->create(['peran' => 'superadmin'])->id;

        $konteksId = DB::table('versi_konteks_relevansi')->insertGetId([
            'nama' => 'Pemkot Kendari',
            'versi' => 'v1',
            'slug' => 'konteks-'.$versi,
            'deskripsi_manusia' => 'Pemerintah Kota Kendari',
            'deskripsi_model' => 'Pemerintah Kota Kendari',
            'aturan_inklusi' => '[]',
            'aturan_eksklusi' => '[]',
            'status' => 'active',
            'created_by' => $pembuat,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ambangId = DB::table('versi_threshold_relevansi')->insertGetId([
            'nama' => 'ambang-'.$versi,
            'relevant_threshold' => 0.6,
            'review_lower_bound' => 0.4,
            'review_upper_bound' => 0.6,
            'reason' => 'Nilai uji.',
            'status' => 'active',
            'created_by' => $pembuat,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $model = VersiModelRelevansi::create([
            'nama' => 'SIMEDIA Relevancy',
            'versi' => $versi,
            'base_model' => 'apriandito/indobert-relevancy-classifier',
            'versi_konteks_relevansi_id' => $konteksId,
            'versi_threshold_relevansi_id' => $ambangId,
            'status' => 'production',
            'artifact_path' => 'relevance-models/'.$versi,
            'artifact_checksum' => str_repeat('a', 64),
            'quality_gate_status' => $gerbang,
        ]);

        GerbangMutuRelevansi::create([
            'versi_model_relevansi_id' => $model->id,
            'status' => $gerbang,
            'standar' => ['precision_relevan' => 0.85],
            'hasil' => ['precision_relevan' => 0.91],
        ]);

        return $model;
    }
}
