<?php

namespace App\Models;

use App\Enums\LabelSentimen;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class AnalisisSentimen extends Model
{
    use LogsActivity;

    protected $table = 'analisis_sentimen';

    /** label_efektif adalah kolom generated; menulisnya ditolak Postgres. */
    protected $guarded = ['id', 'label_efektif'];

    /**
     * Sisa penilaian sentimen yang wajib dibuang begitu artikel dinyatakan
     * tidak relevan.
     *
     * Sentimen hanya dinilai setelah relevansinya berbunyi relevan, jadi begitu
     * jawabannya berubah menjadi tidak relevan, seluruh kolom hasil sentimen
     * menjadi jawaban atas pertanyaan yang sudah dibatalkan.
     *
     * Ditaruh sebagai konstanta karena dua jalur menuliskannya: klasifikasi AI
     * di KlasifikasiArtikel dan keputusan manusia di ArtikelController. Salah
     * satunya pernah punya daftar ini dan yang lain tidak, dan akibatnya
     * terlihat di layar: 327 artikel tidak relevan tetap membawa `model_versi`
     * bertuliskan indobert-sentiment-classifier dari pipeline lama, lalu
     * halaman detail merangkainya menjadi "Dinilai gemini:
     * indobert-sentiment-classifier-2.0.0".
     */
    public const SENTIMEN_KOSONG = [
        'label_model' => null,
        'model_versi' => null,
        'dianalisis_at' => null,
        'perlu_review' => false,
    ];

    protected function casts(): array
    {
        return [
            'relevan' => 'boolean',
            'perlu_review' => 'boolean',
            'fallback_used' => 'boolean',
            'evidence' => 'array',
            'label_model' => LabelSentimen::class,
            'label_manual' => LabelSentimen::class,
            'label_efektif' => LabelSentimen::class,
            'dianalisis_at' => 'datetime',
            'dikoreksi_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['label_manual', 'dikoreksi_oleh', 'catatan_koreksi'])
            ->logOnlyDirty();
    }

    public function artikel(): BelongsTo
    {
        return $this->belongsTo(Artikel::class);
    }

    public function pengoreksi(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dikoreksi_oleh');
    }
}
