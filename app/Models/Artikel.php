<?php

namespace App\Models;

use App\Models\Scopes\MilikMedia;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;

#[ScopedBy(MilikMedia::class)]
class Artikel extends Model
{
    use HasNeighbors;

    protected $table = 'artikel';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'embedding' => Vector::class,
            'dipublikasikan_at' => 'datetime',
            'diambil_at' => 'datetime',
        ];
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function sumberFeed(): BelongsTo
    {
        return $this->belongsTo(SumberFeed::class);
    }

    public function analisisSentimen(): HasMany
    {
        return $this->hasMany(AnalisisSentimen::class);
    }

    /**
     * Pengguna media yang mengirim berita ini lewat portal, null kalau crawler
     * yang menemukannya sendiri.
     */
    public function pelapor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dilaporkan_oleh');
    }

    /**
     * Tahap perjalanan artikel seperti yang dibaca media di portal.
     *
     * Ada di model, bukan di controller, karena dua layar menjawab pertanyaan
     * yang sama: daftar kiriman di halaman Tambah berita, dan pesan hasil
     * pemeriksaan URL. Sewaktu keduanya punya salinan sendiri, pemeriksaan URL
     * berbunyi "penilaiannya masih berjalan" untuk artikel yang sudah lama
     * diputus di luar pantauan, dan media menyimpulkan kirimannya tersangkut.
     *
     * Menyebut relevansi, bukan sentimen. Hanya sentimen yang dirahasiakan dari
     * media (dokumen 01 bagian 8).
     *
     * @return 'tampil'|'di_luar_pantauan'|'diproses'|'gagal'
     */
    public function tahapPortal(): string
    {
        $analisis = $this->analisisSentimen->first();

        if ($analisis === null) {
            // Halaman yang gagal diunduh tidak pernah sampai ke antrean
            // penilaian, jadi ia tidak boleh ikut berbunyi "sedang diproses".
            // Media yang membaca kalimat itu akan menunggu, dan yang ditunggu
            // tidak pernah datang. Pemanggil wajib ikut memilih kolom
            // `status_proses`, kalau tidak cabang ini diam-diam tidak pernah
            // menyala.
            return $this->status_proses === 'gagal' ? 'gagal' : 'diproses';
        }

        if (! $analisis->relevan) {
            return 'di_luar_pantauan';
        }

        return $analisis->label_efektif === null ? 'diproses' : 'tampil';
    }

    /**
     * Tanggal yang berarti "kapan berita ini muncul".
     *
     * Tanggal terbit dari feed, dan tanggal unduh hanya sebagai cadangan saat
     * feed tidak memuatnya. Sebelumnya seluruh agregasi memakai `diambil_at`,
     * dan akibatnya terlihat di grafik pimpinan: penarikan arsip 3 Agustus
     * memasukkan 1.026 artikel lama ke satu hari, lalu terbaca sebagai lonjakan
     * pemberitaan yang tidak pernah terjadi.
     *
     * Ditaruh di satu tempat karena enam pemanggil memakainya, termasuk dua
     * kueri SQL mentah. Kalau grafik memakai tanggal terbit sedangkan daftar di
     * bawahnya memakai tanggal unduh, jumlah barisnya tidak akan pernah cocok
     * dengan angka di atasnya.
     *
     * @param  string|null  $alias  awalan tabel untuk kueri yang memakai JOIN
     */
    public static function waktuTerbit(?string $alias = null): string
    {
        $awalan = $alias === null ? '' : $alias.'.';

        return "coalesce({$awalan}dipublikasikan_at, {$awalan}diambil_at)";
    }

    /** Artikel yang muncul pada satu rentang, menurut tanggal terbitnya. */
    public function scopeTerbitAntara(Builder $kueri, mixed $mulai, mixed $akhir): Builder
    {
        return $kueri->whereRaw(self::waktuTerbit().' BETWEEN ? AND ?', [$mulai, $akhir]);
    }

    /**
     * Artikel yang sudah dinyatakan relevan terhadap konteks pantauan.
     *
     * Bedanya dengan relevanBerlabel(): scope ini tidak menuntut label sentimen
     * sudah turun. Dashboard admin memantau arus masuk, dan artikel yang baru
     * saja dinyatakan relevan tapi sentimennya masih mengantre tetap berita yang
     * sah untuk ditampilkan. Panel eksekutif punya kebutuhan yang berbeda dan
     * tetap memakai relevanBerlabel().
     *
     * Membaca kolom `relevan`, yang sudah berisi keputusan akhir: koreksi manual
     * admin lewat ArtikelController menuliskannya ke kolom yang sama, jadi tidak
     * ada syarat tambahan yang perlu ditumpuk di sini.
     */
    public function scopeRelevan(Builder $kueri): Builder
    {
        return $kueri->whereHas('analisisSentimen', fn (Builder $q) => $q->where('relevan', true));
    }

    /**
     * Artikel yang lolos relevansi dan sudah punya label sentimen.
     *
     * Ini populasi yang boleh muncul di panel eksekutif. Artikel hasil crawl
     * yang belum diklasifikasi atau sudah dinyatakan tidak relevan tidak
     * dilihat pimpinan, dan setiap angka di panel itu dihitung dari populasi
     * yang sama supaya daftar dan totalnya tidak saling membantah.
     *
     * `label_efektif` adalah kolom generated `COALESCE(label_manual,
     * label_model)`, jadi koreksi admin ikut terbaca tanpa syarat tambahan.
     */
    public function scopeRelevanBerlabel(Builder $kueri): Builder
    {
        return $kueri->whereHas('analisisSentimen', fn (Builder $q) => $q
            ->where('relevan', true)
            ->whereNotNull('label_efektif'));
    }
}
