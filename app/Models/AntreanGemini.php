<?php

namespace App\Models;

use App\Services\Ai\RotasiKunciGemini;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu artikel yang menunggu, sedang, atau sudah dinilai Gemini.
 *
 * Antrean yang sebenarnya ada di tabel ini, bukan di Redis. Redis melupakan
 * pekerjaan begitu selesai, sedangkan halaman pemantauan perlu tahu berapa yang
 * beres hari ini dan mana yang gagal beserta alasannya.
 */
class AntreanGemini extends Model
{
    protected $table = 'antrean_gemini';

    protected $guarded = ['id'];

    /** Satu-satunya pekerjaan otomatis: artikel yang belum pernah dinilai. */
    public const PRIORITAS = [
        1 => 'Belum pernah dinilai',
    ];

    public const STATUS = ['menunggu', 'berjalan', 'selesai', 'gagal'];

    protected function casts(): array
    {
        return [
            'dijadwalkan_at' => 'datetime',
            'coba_lagi_at' => 'datetime',
            'dimulai_at' => 'datetime',
            'selesai_at' => 'datetime',
        ];
    }

    public function artikel(): BelongsTo
    {
        return $this->belongsTo(Artikel::class);
    }

    /** Baris prioritas aktif yang artikelnya belum memperoleh keputusan. */
    public function scopeBelumTuntas(Builder $kueri): void
    {
        $kueri
            ->where('prioritas', 1)
            ->whereHas('artikel', fn (Builder $artikel) => $artikel->belumDiklasifikasi());
    }

    /** Kebalikan belumTuntas(), dipakai untuk menyatukan hasil manual dan job. */
    public function scopeSudahTuntas(Builder $kueri): void
    {
        $kueri
            ->where('prioritas', 1)
            ->whereHas('artikel', fn (Builder $artikel) => $artikel->sudahDiklasifikasi());
    }

    /** Kegagalan yang masih menunggu jadwal percobaan otomatis berikutnya. */
    public function scopeMenungguUlang(Builder $kueri): void
    {
        $kueri
            ->belumTuntas()
            ->where('status', 'gagal');
    }

    /**
     * Pekerjaan yang siap diambil berikutnya, dalam urutan yang benar.
     *
     * Kegagalan ikut lagi setelah `coba_lagi_at` tiba. Waktu ini terpisah dari
     * `dijadwalkan_at`: yang pertama adalah backoff di basis data, yang kedua
     * menandakan job nyata sudah hidup di Redis. Menyatukannya membuat penjadwal
     * mengira retry yang belum dilepas sudah menggantung di worker.
     */
    public function scopeSiapDiambil(Builder $kueri): void
    {
        $kueri
            ->belumTuntas()
            ->where(fn (Builder $q) => $q
                // Belum pernah dilepas sama sekali.
                ->where(fn (Builder $b) => $b->where('status', 'menunggu')->whereNull('dijadwalkan_at'))
                // Pekerjaan gagal sudah tidak ada di Redis. Ia baru boleh
                // dilepas ketika jeda bertahapnya selesai.
                //
                // Baris berstatus menunggu dengan `dijadwalkan_at` terisi
                // sengaja tidak ikut. Itu pekerjaan yang menunda dirinya sendiri
                // karena kuota habis, dan ia masih hidup di Redis menunggu
                // gilirannya. Melepasnya sekali lagi berarti satu artikel
                // dinilai dua kali.
                ->orWhere(fn (Builder $b) => $b
                    ->where('status', 'gagal')
                    ->where(fn (Builder $waktu) => $waktu
                        ->whereNull('coba_lagi_at')
                        ->orWhere('coba_lagi_at', '<=', now()))))
            ->orderBy('prioritas')
            ->orderBy('id');
    }

    /**
     * Jeda retry berdasarkan jumlah kegagalan total artikel.
     *
     * Gangguan singkat pulih dalam hitungan menit. Artikel yang terus gagal
     * melambat sampai sekali sehari, sehingga tetap dapat pulih otomatis setelah
     * penyebabnya diperbaiki tanpa menghabiskan kuota untuk loop tanpa batas.
     */
    public static function jedaCobaUlangDetik(int $percobaan): int
    {
        return match (true) {
            $percobaan <= 1 => 60,
            $percobaan === 2 => 5 * 60,
            $percobaan === 3 => 15 * 60,
            $percobaan === 4 => 60 * 60,
            $percobaan === 5 => 6 * 60 * 60,
            default => 24 * 60 * 60,
        };
    }

    /**
     * Rata-rata permintaan Gemini yang dihabiskan satu artikel.
     *
     * Angka terukur, bukan tebakan: 18 permintaan untuk 10 artikel pada uji
     * jalan setelah pekerjaan dilepas berjarak. Satu panggilan untuk relevansi,
     * ditambah satu lagi untuk sentimen pada yang ternyata relevan.
     */
    public const PERMINTAAN_PER_ARTIKEL = 1.8;

    /**
     * Jeda pengendali antrean, dalam detik.
     *
     * Jalur Gemini mengikuti cooldown kunci yang sama dengan klasifikasi
     * manual. Setiap panggilan memilih kunci yang sudah menganggur 15 detik;
     * bila belum ada, job menunda diri sebesar sisa cooldown-nya. Karena itu
     * antrean tidak perlu lagi menahan setiap artikel 60 detik.
     *
     * Dipakai halaman pemantauan untuk membaca jeda kunci Gemini, dan pelepas
     * pekerjaan hanya saat IndoBERT aktif untuk menjaga CPU layanan lokal.
     *
     * Dengan IndoBERT, jarak ini hanya menjaga CPU layanan inferensi. Artikel
     * yang lolos tetap masuk pemilih kunci 15 detik tepat sebelum sentimen,
     * sedangkan yang tidak relevan selesai tanpa menyentuh Gemini.
     */
    public static function jedaDetik(): float
    {
        if (PengaturanAi::aktif()->penyedia_relevansi === 'indobert') {
            return (float) config('ai.antrean.jeda_detik_indobert');
        }

        return (float) RotasiKunciGemini::JEDA_KUNCI_DETIK;
    }
}
