<?php

namespace App\Services\Nlp;

use App\Services\Nlp\DTO\HasilRelevansi;
use App\Services\Nlp\DTO\HasilSentimen;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Satu-satunya tempat aplikasi memanggil layanan NLP.
 *
 * Tidak ada tempat lain yang boleh menyentuh URL itu. Aturan ini yang membuat
 * penggantian model, penambahan header rahasia saat layanan dipisah ke server
 * lain, atau perubahan bentuk permintaan cukup disunting di satu berkas.
 */
class KlienNlp
{
    private function permintaan(): PendingRequest
    {
        return Http::baseUrl((string) config('nlp.base_url'))
            ->timeout((int) config('nlp.timeout'))
            ->acceptJson()
            ->asJson();
    }

    public function sehat(): bool
    {
        try {
            return $this->permintaan()->timeout(10)->get('/health')->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function kesehatan(): array
    {
        return $this->permintaan()->timeout(10)->get('/health')->throw()->json();
    }

    /**
     * Vektor 384 dimensi untuk setiap teks, urutannya sama dengan masukan.
     *
     * @param  list<string>  $teks
     * @return list<list<float>>
     */
    public function embed(array $teks): array
    {
        $hasil = [];

        foreach (array_chunk($teks, (int) config('nlp.batch_embed')) as $bagian) {
            $tanggapan = $this->permintaan()
                ->post('/embed', ['teks' => array_values($bagian)])
                ->throw()
                ->json();

            $hasil = [...$hasil, ...$tanggapan['embedding']];
        }

        return $hasil;
    }

    /**
     * @param  list<array{id: int, konteks: string, teks: string}>  $pasangan
     * @return array<int, HasilRelevansi> dikunci artikel_id
     */
    public function relevansi(array $pasangan): array
    {
        return $this->pasangan('/relevancy', $pasangan, HasilRelevansi::dariArray(...));
    }

    /**
     * @param  list<array{id: int, konteks: string, teks: string}>  $pasangan
     * @return array<int, HasilSentimen> dikunci artikel_id
     */
    public function sentimen(array $pasangan): array
    {
        return $this->pasangan('/sentiment', $pasangan, HasilSentimen::dariArray(...));
    }

    /**
     * Hasil dikunci `id` yang dikirim balik layanan, bukan urutan array.
     *
     * Memetakan lewat urutan terlihat lebih ringkas dan akan benar hampir
     * selalu — sampai suatu hari layanan mengembalikan satu baris lebih sedikit
     * dan seluruh label bergeser satu artikel tanpa ada yang menyadarinya.
     *
     * @param  list<array{id: int, konteks: string, teks: string}>  $pasangan
     * @return array<int, mixed>
     */
    private function pasangan(string $jalur, array $pasangan, callable $bentuk): array
    {
        $hasil = [];

        foreach (array_chunk($pasangan, (int) config('nlp.batch_pasangan')) as $bagian) {
            $tanggapan = $this->permintaan()
                ->post($jalur, ['pasangan' => array_values($bagian)])
                ->throw()
                ->json();

            foreach ($tanggapan['hasil'] as $baris) {
                $hasil[(int) $baris['id']] = $bentuk($baris);
            }
        }

        return $hasil;
    }
}
