<?php

namespace App\Http\Resources;

use App\Models\Artikel;
use App\Support\Waktu;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bentuk artikel yang boleh dilihat pengguna peran media.
 *
 * **Tidak ada satu pun field sentimen di sini, dan itu keputusan produk
 * (dokumen 01 bagian 8), bukan kelalaian.** Kalau media bisa melihat nilai
 * sentimennya, sebagian akan menyesuaikan gaya penulisan agar terbaca positif
 * oleh model. Dalam beberapa bulan data sentimen berhenti mengukur nada
 * pemberitaan dan mulai mengukur kepatuhan terhadap model, dan tidak ada cara
 * mengetahui kapan pergeseran itu dimulai.
 *
 * Akan ada yang meminta fiturnya ditambahkan. Jawabannya ada di paragraf di
 * atas, bukan pada rumitnya menambahkan satu kolom.
 *
 * Resource ini juga tidak memuat `isi` artikel utuh (dokumen 01 bagian 6).
 *
 * @mixin Artikel
 */
class ArtikelPortalResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'judul' => $this->judul,
            'url' => $this->url,
            // Selalu media pengguna itu sendiri, dijamin global scope MilikMedia.
            'media' => $this->whenLoaded('media', fn () => $this->media?->nama),
            'penulis' => $this->penulis,
            'jumlah_kata' => $this->jumlah_kata,
            'dipublikasikan_at' => $this->dipublikasikan_at,
            'diambil_at' => $this->diambil_at,
            'tanggal_muat' => Waktu::tanggalWita($this->diambil_at),
        ];
    }
}
