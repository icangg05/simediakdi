<?php

namespace App\Models;

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

    /**
     * Tiga sumber pekerjaan, sekaligus urutan pengerjaannya.
     *
     * Angka kecil dikerjakan lebih dulu. Artikel yang belum punya analisis sama
     * sekali paling depan karena ia satu-satunya yang benar-benar belum
     * terjawab. Dua sisanya sudah punya jawaban dari pipeline IndoBERT lama,
     * jadi menundanya tidak meninggalkan lubang di dashboard.
     */
    public const PRIORITAS = [
        1 => 'Belum ada analisis',
        2 => 'Relevan, label dari pipeline lama',
        3 => 'Tidak relevan, keputusan pipeline lama',
    ];

    public const STATUS = ['menunggu', 'berjalan', 'selesai', 'gagal'];

    protected function casts(): array
    {
        return [
            'dijadwalkan_at' => 'datetime',
            'dimulai_at' => 'datetime',
            'selesai_at' => 'datetime',
        ];
    }

    public function artikel(): BelongsTo
    {
        return $this->belongsTo(Artikel::class);
    }

    /**
     * Pekerjaan yang siap diambil berikutnya, dalam urutan yang benar.
     *
     * `gagal` ikut, dan itu disengaja. Kegagalan di sini hampir selalu gangguan
     * sesaat, misalnya koneksi putus atau Gemini menjawab dengan bentuk yang
     * tidak terbaca. Membiarkannya mati permanen setelah satu kali gagal berarti
     * artikelnya tidak akan pernah dinilai, dan tidak ada yang akan menyadarinya
     * karena barisnya sudah hilang dari daftar menunggu.
     */
    public function scopeSiapDiambil(Builder $kueri): void
    {
        $kueri
            ->where(fn (Builder $q) => $q
                // Belum pernah dilepas sama sekali.
                ->where(fn (Builder $b) => $b->where('status', 'menunggu')->whereNull('dijadwalkan_at'))
                // Gagal dan masih punya jatah. Pekerjaannya sudah tidak ada di
                // Redis, jadi melepasnya lagi tidak menggandakan apa pun.
                //
                // Baris berstatus menunggu dengan `dijadwalkan_at` terisi
                // sengaja tidak ikut. Itu pekerjaan yang menunda dirinya sendiri
                // karena kuota habis, dan ia masih hidup di Redis menunggu
                // gilirannya. Melepasnya sekali lagi berarti satu artikel
                // dinilai dua kali.
                ->orWhere(fn (Builder $b) => $b
                    ->where('status', 'gagal')
                    ->where('percobaan', '<', self::MAKS_PERCOBAAN)))
            ->orderBy('prioritas')
            ->orderBy('id');
    }

    /**
     * Batas percobaan per artikel.
     *
     * Tiga kali cukup untuk melewati gangguan sesaat. Lebih dari itu artinya
     * ada yang salah pada artikelnya sendiri, dan mengulanginya terus hanya
     * membakar kuota harian yang seharusnya dipakai artikel lain.
     */
    public const MAKS_PERCOBAAN = 3;

    /**
     * Rata-rata permintaan Gemini yang dihabiskan satu artikel.
     *
     * Angka terukur, bukan tebakan: 18 permintaan untuk 10 artikel pada uji
     * jalan setelah pekerjaan dilepas berjarak. Satu panggilan untuk relevansi,
     * ditambah satu lagi untuk sentimen pada yang ternyata relevan.
     */
    public const PERMINTAAN_PER_ARTIKEL = 1.8;

    /**
     * Jarak antar artikel, dalam detik.
     *
     * Dua angka dibandingkan, yang paling longgar yang menang. Yang pertama
     * jarak pilihan dari config, yang kedua jarak minimum yang dituntut jumlah
     * kunci yang menyala. Menurunkan angka config tidak akan pernah membuat
     * antrean menembak lebih cepat daripada yang sanggup ditanggung kuncinya,
     * dan menambah kunci tidak akan membuatnya melanggar jarak yang sengaja
     * dipilih admin.
     *
     * Dipakai bersama oleh pelepas pekerjaan dan halaman pemantauan. Dua salinan
     * rumus ini pernah berarti perkiraan waktu selesai di layar menjanjikan
     * kecepatan yang tidak pernah benar-benar dijalankan.
     *
     * Dengan IndoBERT, jarak ini tidak lagi menjaga kuota sama sekali. Ia hanya
     * menjaga CPU layanan inferensi, karena berita yang ditolak tidak memanggil
     * Gemini. Kuotanya dijaga di tempat lain, yaitu jeda antar artikel yang
     * benar-benar lolos, dan itu ada di RotasiKunciGemini::jedaArtikel().
     *
     * Pemisahan itu yang membuat tumpukan berita tidak relevan bisa disapu
     * cepat. Jarak tunggal yang mengasumsikan setiap artikel memanggil Gemini
     * menghukum seluruh antrean demi separuh isinya.
     */
    public static function jedaDetik(): float
    {
        if (PengaturanAi::aktif()->penyedia_relevansi === 'indobert') {
            return (float) config('ai.antrean.jeda_detik_indobert');
        }

        $kunci = max(1, KunciGemini::where('aktif', true)->count());

        $perMenit = $kunci * (int) config('ai.batas_kunci.rpm');

        $lantaiKapasitas = 60 / max(1, $perMenit / self::PERMINTAAN_PER_ARTIKEL);

        return max((float) config('ai.antrean.jeda_detik'), $lantaiKapasitas);
    }
}
