<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Filter, sort, dan paginasi untuk komponen DataTable, semuanya di server.
 *
 * State-nya ada di query string, jadi hasil filter bisa dibookmark dan laporan
 * bug bisa direproduksi dari satu tautan.
 */
class KueriTabel
{
    private function __construct(
        private Builder $kueri,
        private Request $request,
    ) {}

    public static function untuk(Builder $kueri, Request $request): self
    {
        return new self($kueri, $request);
    }

    /**
     * @param  list<string>  $kolom  kolom yang ikut dicari
     */
    public function cari(array $kolom): self
    {
        $kata = trim((string) $this->request->query('cari', ''));

        if ($kata === '' || $kolom === []) {
            return $this;
        }

        $this->kueri->where(function (Builder $q) use ($kolom, $kata) {
            foreach ($kolom as $k) {
                $q->orWhere($k, 'ilike', '%'.$kata.'%');
            }
        });

        return $this;
    }

    /**
     * Filter multi-pilih. Nilai di query string dipisah koma.
     *
     * @param  array<string, string>  $petaKolom  parameter query => kolom database
     */
    public function saring(array $petaKolom): self
    {
        foreach ($petaKolom as $parameter => $kolom) {
            $nilai = array_filter(explode(',', (string) $this->request->query($parameter, '')), 'strlen');

            if ($nilai === []) {
                continue;
            }

            // Boolean tidak masuk akal sebagai whereIn string di Postgres.
            $this->kueri->whereIn($kolom, array_map(
                fn (string $v) => match ($v) {
                    'true', '1' => true,
                    'false', '0' => false,
                    default => $v,
                },
                $nilai,
            ));
        }

        return $this;
    }

    /**
     * @param  list<string>  $kolomDiizinkan  daftar putih; apa pun di luar ini diabaikan
     */
    public function urut(array $kolomDiizinkan, string $bawaan, string $arahBawaan = 'asc'): self
    {
        $kolom = (string) $this->request->query('urut', '');
        $kolom = in_array($kolom, $kolomDiizinkan, strict: true) ? $kolom : $bawaan;

        $arah = $this->request->query('arah') === 'desc' ? 'desc' : 'asc';
        $arah = $this->request->query('urut') ? $arah : $arahBawaan;

        $this->kueri->orderBy($kolom, $arah);

        // Pemecah seri yang pasti. Postgres tidak menjanjikan apa pun tentang
        // urutan baris yang nilai kolom urutnya sama, dan kolom seperti
        // priority_score penuh nilai kembar. Tanpa ini urutannya boleh berbeda
        // di tiap permintaan: halaman dua mengulang baris halaman satu, dan
        // panel pelabelan melompat ke sampel yang sudah lewat.
        $this->kueri->orderBy($this->kueri->getModel()->getQualifiedKeyName());

        return $this;
    }

    public function halaman(int $perHalaman = 25): LengthAwarePaginator
    {
        return $this->kueri
            ->paginate($perHalaman, pageName: 'halaman')
            ->withQueryString();
    }
}
