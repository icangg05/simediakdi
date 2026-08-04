<?php

namespace App\Services\Relevance;

use App\Models\KonteksPantauan;
use App\Models\SampelRelevansi;
use App\Services\Nlp\JendelaKonteks;
use InvalidArgumentException;

/**
 * Menyusun teks yang benar-benar dilihat model relevansi. Dokumen 10 bagian 20.
 *
 * Kelas ini kecil dan paling berbahaya di seluruh laboratorium. Model yang
 * dilatih dengan satu susunan lalu dipakai dengan susunan lain akan tetap
 * mengeluarkan angka, tetap terlihat wajar, dan salah. Tidak ada galat, tidak
 * ada log, dan gejalanya cuma presisi yang turun tanpa sebab yang jelas.
 *
 * Tiga aturan yang menjaganya:
 *
 * 1. **Satu-satunya tempat susunan ini ditulis.** Pelatihan, inferensi
 *    produksi, dan tab Uji Model memanggil method yang sama. Menyalin
 *    formatnya ke tempat kedua adalah cara paling umum keduanya berbeda.
 * 2. **Punya versi**, dan versinya ikut tersimpan di artefak setiap pelatihan.
 *    Mengubah susunan berarti menaikkan versi, dan model lama menjadi tidak
 *    sebanding dengan model baru.
 * 3. **Bagian kosong dihilangkan, bukan ditulis kosong.** Baris `Kategori:`
 *    tanpa isi mengajari model bahwa baris itu biasanya kosong, lalu artikel
 *    yang benar-benar punya kategori terlihat aneh baginya.
 */
class RelevanceInputBuilder
{
    /**
     * Naikkan setiap kali susunan teks berubah.
     *
     * v1: judul, kategori, tag, ringkasan, potongan isi terkait.
     */
    public const VERSI = 'v1';

    /**
     * Batas huruf bagian isi.
     *
     * Sekitar 300 token untuk bahasa Indonesia, jadi masih memberi ruang bagi
     * tokenizer memotong di 256 tanpa kehilangan judul. Judul ditaruh paling
     * depan justru karena pemotongan selalu terjadi di ekor.
     */
    private const MAKS_HURUF_ISI = 1200;

    public function __construct(private JendelaKonteks $jendela) {}

    /**
     * Batas huruf kalimat konteks.
     *
     * Bukan angka yang dikira-kira. Konteks dipasangkan ke setiap sampel, jadi
     * setiap tokennya dibayar di seluruh dataset sekaligus, dan isinya sama
     * persis di semua baris sehingga tidak membedakan apa pun. Paragraf aturan
     * sepanjang 700 huruf pernah tertulis di sini dan memakan 137 dari 256
     * token, menyisakan 116 untuk artikel yang butuh 264.
     */
    private const MAKS_HURUF_KONTEKS = 120;

    /**
     * Sisi konteks, yang dipasangkan ke teks artikel pada tokenizer.
     *
     * Pendek dan persis, bukan paragraf aturan. Aturan inklusi dan eksklusi
     * yang panjang berguna untuk pelabel manusia, tempatnya di dokumen 09 dan
     * di `versi_konteks_relevansi`, bukan di teks yang dikirim ke model.
     *
     * Melempar, bukan memotong diam-diam. Konteks yang terpotong di tengah
     * kalimat tetap menghasilkan model yang jalan dan angka yang tampak wajar,
     * dan tidak ada yang akan menyadarinya.
     */
    public function konteks(KonteksPantauan $konteks): string
    {
        $teks = trim($konteks->deskripsi_model ?: $konteks->nama);

        if (mb_strlen($teks) > self::MAKS_HURUF_KONTEKS) {
            throw new InvalidArgumentException(
                'Kalimat konteks terlalu panjang: '.mb_strlen($teks).' huruf, batasnya '
                .self::MAKS_HURUF_KONTEKS.'. Ia dipasangkan ke setiap sampel, jadi kelebihannya '
                .'dibayar di seluruh dataset. Ringkas `deskripsi_model` konteks utama menjadi '
                .'sebutan pendek, misalnya "Pemerintah Kota Kendari", dan pindahkan aturan '
                .'inklusi serta eksklusinya ke panduan pelabelan.'
            );
        }

        return $teks;
    }

    /**
     * @param  list<string>|null  $kategori
     * @param  list<string>|null  $tag
     */
    public function artikel(
        string $judul,
        ?string $excerpt,
        ?string $isi,
        ?array $kategori,
        ?array $tag,
        KonteksPantauan $konteks,
    ): string {
        $bagian = ['Judul: '.trim($judul)];

        if ($kategori) {
            $bagian[] = 'Kategori: '.implode(', ', $kategori);
        }

        if ($tag) {
            $bagian[] = 'Tag: '.implode(', ', $tag);
        }

        if ($excerpt !== null && trim($excerpt) !== '') {
            $bagian[] = 'Ringkasan: '.trim($excerpt);
        }

        $potongan = $this->jendela->potongan(
            (string) $isi,
            $konteks->kata_kunci ?? [],
            self::MAKS_HURUF_ISI,
        );

        if ($potongan !== '') {
            $bagian[] = 'Potongan isi terkait: '.$potongan;
        }

        return implode("\n", $bagian);
    }

    public function dariSampel(SampelRelevansi $sampel, KonteksPantauan $konteks): string
    {
        return $this->artikel(
            $sampel->judul,
            $sampel->excerpt,
            $sampel->isi,
            $sampel->kategori_sumber,
            $sampel->tag_sumber,
            $konteks,
        );
    }

    /**
     * Cap jari input, disimpan bersama tiap prediksi.
     *
     * Menjawab pertanyaan yang selalu muncul saat sebuah prediksi terlihat
     * aneh: teks apa persisnya yang dilihat model waktu itu. Versi ikut
     * dimasukkan supaya teks yang sama di bawah susunan berbeda tidak
     * menghasilkan cap jari yang sama.
     */
    public function inputHash(string $konteks, string $artikel): string
    {
        return hash('sha256', self::VERSI."\n".$konteks."\n".$artikel);
    }
}
