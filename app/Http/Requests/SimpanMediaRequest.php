<?php

namespace App\Http\Requests;

use App\Enums\TierMedia;
use App\Models\Media;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class SimpanMediaRequest extends FormRequest
{
    public function rules(): array
    {
        $id = $this->route('media')?->id;

        return [
            'nama' => ['required', 'string', 'max:150'],
            // Indeks unik database mencakup baris yang sudah soft-delete, jadi
            // validasinya harus menghitung populasi yang sama. Mengabaikan
            // baris terhapus di sini akan lolos validasi lalu ditolak database.
            'slug' => ['required', 'string', 'max:150', 'alpha_dash', Rule::unique('media', 'slug')->ignore($id)],
            'jenis' => ['required', Rule::in(['online', 'cetak', 'tv', 'radio'])],
            'tier' => ['required', new Enum(TierMedia::class)],
            'url_website' => ['nullable', 'url', 'max:255'],
            // Subdomain lengkap dipertahankan agar artikel dari domain induk
            // yang berbeda tidak ikut tercocokkan.
            'domain' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9.-]+$/', Rule::unique('media', 'domain')->ignore($id)->withoutTrashed()],
            'kota' => ['nullable', 'string', 'max:100'],
            'provinsi' => ['nullable', 'string', 'max:100'],
            'partner' => ['boolean'],
            'nama_pic' => ['nullable', 'string', 'max:120'],
            'kontak_pic' => ['nullable', 'string', 'max:120'],
            'catatan' => ['nullable', 'string'],
            'aktif' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        /** @var Media|null $media */
        $media = $this->route('media');
        $nama = trim((string) $this->input('nama', ''));

        $this->merge([
            'nama' => $nama,
            // Slug dan jenis bukan bidang yang boleh dipercaya dari browser.
            // Saat nama media diubah, slug ikut berubah dan bentrokan diberi
            // akhiran angka. Jenis lama dipertahankan saat menyunting data
            // historis, sedangkan media baru selalu dimulai sebagai online.
            'slug' => $media && $nama === $media->nama
                ? $media->slug
                : $this->slugUnik($nama, $media?->id),
            'jenis' => $media?->jenis ?? 'online',
            'domain' => $this->domain ? str($this->domain)->lower()->after('www.')->value() : null,
            'partner' => $this->boolean('partner'),
            'aktif' => $this->boolean('aktif'),
        ]);
    }

    /** Buat slug unik terhadap seluruh tabel, termasuk media terhapus. */
    private function slugUnik(string $nama, ?int $abaikanId = null): string
    {
        $dasar = rtrim(Str::substr(Str::slug($nama), 0, 150), '-');

        if ($dasar === '') {
            return '';
        }

        $calon = $dasar;
        $urutan = 2;

        while ($this->slugSudahDipakai($calon, $abaikanId)) {
            $akhiran = "-{$urutan}";
            $pangkal = rtrim(Str::substr($dasar, 0, 150 - strlen($akhiran)), '-');
            $calon = $pangkal.$akhiran;
            $urutan++;
        }

        return $calon;
    }

    private function slugSudahDipakai(string $slug, ?int $abaikanId): bool
    {
        return DB::table('media')
            ->where('slug', $slug)
            ->when($abaikanId, fn ($kueri) => $kueri->where('id', '!=', $abaikanId))
            ->exists();
    }

    public function messages(): array
    {
        return [
            'slug.required' => 'Nama media harus memuat huruf atau angka agar slug dapat dibuat otomatis.',
            'slug.unique' => 'Slug ini baru saja dipakai media lain. Ubah nama media lalu simpan kembali.',
            'domain.unique' => 'Domain ini sudah dipakai media lain. Pencocokan artikel ke media memakai domain, jadi tiap media harus punya domain sendiri.',
            'domain.regex' => 'Isi domain saja tanpa http:// dan tanpa garis miring, contohnya kendaripos.fajar.co.id.',
        ];
    }
}
