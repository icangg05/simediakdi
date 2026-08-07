<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Ai\Agents\PemeriksaKunci;
use App\Models\KunciGemini;
use App\Support\Waktu;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Ai;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

/**
 * Menjalankan satu panggilan Gemini dengan kunci yang masih punya kuota.
 *
 * Kunci yang kena limit ditandai beserta waktu pulihnya, lalu dilewati sampai
 * waktu itu tiba. Tanpa penandaan itu, rotasi hanya memindahkan masalah:
 * setiap artikel berikutnya mencoba kunci yang sama, kena 429 yang sama, dan
 * membayar satu permintaan gagal sebelum pindah ke kunci berikutnya.
 *
 * Batasnya juga dihitung sendiri, sebelum permintaan dikirim. Menunggu 429
 * berarti selalu ada satu permintaan gagal di setiap batas, dan Google
 * menghitung permintaan yang ditolak sebagai permintaan. Pada klasifikasi
 * manual yang ditekan berturut-turut, itu cukup untuk menghabiskan kuota harian
 * tanpa satu pun artikel selesai dinilai.
 *
 * Kuncinya disuntikkan lewat config lalu instance provider dibuang, karena
 * Laravel AI SDK membaca kunci saat provider di-resolve dan menyimpan hasilnya
 * di manager. Tanpa `forgetInstance`, kunci kedua tidak pernah terpakai.
 *
 * Tidak ada jalan pintas tanpa kunci. `.env` sudah tidak memuat GEMINI_API_KEY,
 * jadi tabel kosong berarti sistem memang belum bisa memanggil Gemini, dan
 * galat yang menyebutkan itu jauh lebih berguna daripada 401 dari Google.
 */
class RotasiKunciGemini
{
    /**
     * Zona waktu reset kuota harian Gemini.
     *
     * Google menghitung kuota harian free tier per hari kalender waktu Pasifik
     * dan mengembalikannya pada tengah malam waktu itu, bukan pada 24 jam
     * setelah permintaan pertama dan bukan pada tengah malam waktu lokal.
     * Menebak WITA membuat kunci ditahan sampai 15 jam lebih lama dari perlu,
     * atau dicoba lagi terlalu cepat dan kembali kena 429.
     */
    private const ZONA_KUOTA = 'America/Los_Angeles';

    /**
     * Jeda terpendek untuk limit per menit.
     *
     * Google mengirim `retryDelay` pada sebagian galat, tetapi tidak selalu.
     * Tanpa batas bawah, kunci yang kena limit per menit langsung dicoba lagi
     * pada artikel berikutnya dan gagal lagi.
     */
    private const JEDA_MINIMAL_DETIK = 60;

    /**
     * Jeda antar uji untuk satu kunci yang sama.
     *
     * Uji bukan pemeriksaan gratis. Satu ketukan adalah satu permintaan yang
     * dihitung Google sama seperti permintaan penilaian artikel, jadi tombol
     * yang bisa ditekan bertubi-tubi memakan kuota yang seharusnya dipakai
     * menilai berita.
     */
    public const JEDA_UJI_DETIK = 15;

    /**
     * @template T
     *
     * @param  Closure(): T  $panggil
     * @return T
     *
     * @throws RateLimitedException
     */
    public function jalankan(Closure $panggil): mixed
    {
        $terakhir = null;

        foreach ($this->giliran() as $kunci) {
            // Dijaga sebelum dipanggil, bukan sesudah ditolak. Permintaan yang
            // dijawab 429 tetap dihitung Google sebagai permintaan, jadi kunci
            // yang terus ditembak setelah batasnya lewat menghabiskan kuota
            // harian tanpa satu pun artikel selesai dinilai.
            if (! $this->punyaJatah($kunci)) {
                continue;
            }

            $this->pakai($kunci);

            try {
                $hasil = $panggil();
            } catch (RateLimitedException $galat) {
                $this->tandaiLimit($kunci, $galat);
                $this->catatGalat($kunci, $this->pesanGalat($galat));

                $terakhir = $galat;

                continue;
            } catch (Throwable $galat) {
                // Dicatat pada kuncinya, lalu dilempar apa adanya tanpa pindah
                // kunci. Galat selain kuota belum tentu soal kuncinya: jawaban
                // model yang bentuknya keliru akan gagal dengan cara yang sama
                // pada ketiga kunci, dan mencobanya satu per satu hanya
                // membakar tiga permintaan untuk kegagalan yang sama.
                //
                // Catatannya tetap berguna. Kalau yang rusak memang kuncinya,
                // misalnya salah ketik atau izin proyeknya dicabut, galatnya
                // menempel di kunci itu saja dan langsung terlihat di halaman
                // Pengaturan.
                $this->catatGalat($kunci, $this->pesanGalat($galat));

                throw $galat;
            }

            // Berhasil berarti kuncinya sehat sekarang. Catatan galat lama
            // dicabut, kalau tidak peringatan merah di halaman Pengaturan
            // menempel selamanya pada kunci yang sudah lama pulih.
            $this->pulih($kunci);

            return $hasil;
        }

        throw $terakhir ?? $this->semuaHabis();
    }

    private function catatGalat(KunciGemini $kunci, string $pesan): void
    {
        $kunci->forceFill([
            'galat_terakhir' => mb_substr($pesan, 0, 500),
            'galat_at' => now(),
        ])->saveQuietly();
    }

    private function pulih(KunciGemini $kunci): void
    {
        if ($kunci->galat_terakhir === null) {
            return;
        }

        $kunci->forceFill(['galat_terakhir' => null, 'galat_at' => null])->saveQuietly();
    }

    /**
     * Menguji satu kunci tertentu, tanpa rotasi.
     *
     * Tanpa rotasi karena yang ditanyakan justru kunci ini, bukan "apakah ada
     * kunci yang jalan". Berpindah ke kunci lain saat yang diuji menolak akan
     * menjawab pertanyaan yang berbeda dengan jawaban yang terlihat meyakinkan.
     *
     * Pemakaiannya tetap dicatat penuh: `terakhir_dipakai_at`, jatah per menit,
     * dan jatah harian. Satu uji adalah satu permintaan yang dihitung Google
     * sama seperti permintaan lain, dan penjaga kuota yang tidak menghitungnya
     * akan mengizinkan satu permintaan berlebih untuk setiap kali tombol
     * ditekan.
     *
     * @return array{berhasil: bool, jawaban: ?string, galat: ?string, ms: int}
     */
    public function uji(KunciGemini $kunci, string $model): array
    {
        $mulai = hrtime(true);

        if (! $kunci->aktif) {
            return $this->hasilUji(false, null, 'Kunci ini dimatikan, jadi tidak dipakai sistem.', $mulai);
        }

        // Jeda antar uji, ditegakkan di sini dan bukan hanya di tombol. Tombol
        // yang diredupkan bukan aturan: permintaannya tetap bisa dikirim
        // langsung, dan satu ketukan adalah satu permintaan yang dihitung
        // Google sama seperti permintaan penilaian artikel.
        if (($sisa = $this->sisaJedaUji($kunci)) > 0) {
            return $this->hasilUji(false, null, "Tunggu {$sisa} detik lagi sebelum menguji kunci ini kembali.", $mulai);
        }

        Cache::put($this->namaJedaUji($kunci), now()->timestamp, self::JEDA_UJI_DETIK);

        if (($tunggu = $this->slotMenit($kunci)) > 0) {
            return $this->hasilUji(false, null, 'Jatah per menit kunci ini sedang penuh. Coba lagi '
                .ceil($tunggu).' detik lagi.', $mulai);
        }

        // Jatah harian dipungut tanpa memeriksa batasnya lebih dulu. Tombol uji
        // memang boleh dipakai pada kunci yang jatah hariannya sudah habis,
        // karena yang ditanyakan admin adalah "kuncinya masih sah atau tidak",
        // dan jawaban dari Google tetap berguna meski berbentuk 429. Yang wajib
        // adalah permintaannya ikut terhitung.
        $this->pungutJatahHarian($kunci);

        $this->pakai($kunci);

        try {
            $tanggapan = (new PemeriksaKunci)->prompt(
                $this->perintahUji($kunci),
                provider: Lab::Gemini,
                model: $model,
                timeout: (int) config('ai.gemini_timeout'),
            );
        } catch (RateLimitedException $galat) {
            // Ditandai persis seperti saat penilaian biasa kena limit, supaya
            // status di halaman ini sama dengan yang dibaca rotasi.
            $this->tandaiLimit($kunci, $galat);
            $this->catatGalat($kunci, $this->pesanGalat($galat));

            return $this->hasilUji(false, null, 'Kuota kunci ini sedang habis: '.$this->pesanGalat($galat), $mulai);
        } catch (Throwable $galat) {
            report($galat);

            $pesan = $this->pesanGalat($galat);

            $this->catatGalat($kunci, $pesan);

            return $this->hasilUji(false, null, $pesan, $mulai);
        }

        // Berhasil berarti kuncinya sehat sekarang, jadi penandaan limit lama
        // dan catatan galat lama yang mungkin masih menempel dicabut. Tanpa ini
        // kunci yang sudah pulih tetap dilewati rotasi sampai waktu tandanya
        // lewat, dan peringatan merahnya menempel selamanya di layar.
        $kunci->forceFill(['limit_sampai' => null, 'alasan_limit' => null])->saveQuietly();

        $this->pulih($kunci);

        $jawaban = trim($tanggapan->text);

        // Jawaban ikut diperiksa isinya, bukan cuma keberadaannya. Kunci yang
        // salah proyek bisa saja tetap menjawab, dan jawaban apa pun akan
        // terbaca sebagai bukti kalau yang dicek hanya "ada balasan". Meminta
        // model menuliskan ulang label kunci yang sedang diuji membuat balasan
        // itu tertaut pada kunci ini, bukan pada kunci mana saja.
        if (($kurang = $this->yangHilang($jawaban, $kunci->label)) !== null) {
            return $this->hasilUji(
                false,
                $jawaban,
                "Gemini menjawab, tetapi jawabannya tidak memuat {$kurang}. "
                    .'Paketnya sampai dan kembali, jadi kuncinya sendiri kemungkinan besar baik. '
                    .'Yang meleset instruksinya, coba ulangi.',
                $mulai,
            );
        }

        return $this->hasilUji(true, $jawaban, null, $mulai);
    }

    /**
     * Perintah uji: tulis ulang label kunci ini, lalu bubuhkan tanda.
     *
     * Labelnya ikut dikirim supaya jawaban yang kembali bisa dicocokkan dengan
     * kunci yang sedang diuji. Tanpa itu, jawaban benar dari kunci mana pun
     * terbaca sama, termasuk jawaban yang tersimpan dari uji sebelumnya.
     */
    private function perintahUji(KunciGemini $kunci): string
    {
        return 'Tulis ulang baris berikut apa adanya, lalu tambahkan tanda RESPONSE BERHASIL di akhir. '
            ."Jangan menambahkan apa pun selain itu.\n\n{$kunci->label}";
    }

    /**
     * Bagian yang seharusnya ada di jawaban tetapi tidak ditemukan.
     *
     * Pencocokannya longgar: huruf besar kecil dan spasi diabaikan, karena yang
     * dibuktikan adalah paketnya sampai dan kembali membawa label yang benar,
     * bukan kepatuhan model pada format. Model yang menulis
     * "my_api|hapida1973@gmail.com" tanpa spasi tetap lulus.
     */
    private function yangHilang(string $jawaban, string $label): ?string
    {
        $rapikan = fn (string $teks): string => str_replace(' ', '', mb_strtolower(
            preg_replace('/\s+/u', ' ', $teks) ?? $teks
        ));

        $bersih = $rapikan($jawaban);

        return match (true) {
            ! str_contains($bersih, $rapikan($label)) => "label kunci \"{$label}\"",
            ! str_contains($bersih, 'responseberhasil') => 'tanda "RESPONSE BERHASIL"',
            default => null,
        };
    }

    /** Berapa detik lagi kunci ini boleh diuji kembali. Nol berarti boleh sekarang. */
    public function sisaJedaUji(KunciGemini $kunci): int
    {
        $terakhir = (int) Cache::get($this->namaJedaUji($kunci), 0);

        return $terakhir === 0
            ? 0
            : max(0, self::JEDA_UJI_DETIK - (now()->timestamp - $terakhir));
    }

    private function namaJedaUji(KunciGemini $kunci): string
    {
        return "gemini:uji:{$kunci->id}";
    }

    /** @return array{berhasil: bool, jawaban: ?string, galat: ?string, ms: int} */
    private function hasilUji(bool $berhasil, ?string $jawaban, ?string $galat, int $mulai): array
    {
        return [
            'berhasil' => $berhasil,
            'jawaban' => $jawaban,
            'galat' => $galat,
            'ms' => (int) ((hrtime(true) - $mulai) / 1_000_000),
        ];
    }

    /**
     * Pesan galat yang menyebut sebabnya, bukan cuma kelas pengecualiannya.
     *
     * Badan jawaban Google memuat kalimat yang jauh lebih berguna daripada
     * pesan bawaan SDK, misalnya "API key not valid" untuk kunci yang salah
     * ketik. Admin yang menekan tombol uji sedang mencari kalimat itu.
     */
    private function pesanGalat(Throwable $galat): string
    {
        foreach ([$galat, $galat->getPrevious()] as $lapis) {
            if ($lapis instanceof RequestException && $lapis->response !== null) {
                $pesan = $lapis->response->json('error.message');

                if (\is_string($pesan) && $pesan !== '') {
                    return $pesan;
                }
            }
        }

        // Jalan terakhir: badan JSON-nya ikut tercetak di dalam teks
        // pengecualian, dan di situlah kalimat yang berguna berada. Tanpa
        // langkah ini yang tersimpan adalah dump mentah setinggi sepuluh baris
        // yang diawali "HTTP request returned status code 400", dan kalimat
        // "API key not valid" terkubur di tengahnya.
        if (preg_match('/"message"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $galat->getMessage(), $cocok) === 1) {
            return stripcslashes($cocok[1]);
        }

        return $galat->getMessage();
    }

    /**
     * Berapa detik lagi sebelum ada kunci yang bisa dipakai. Nol berarti siap.
     *
     * Dipakai antrean latar belakang untuk menunda pekerjaan alih-alih
     * menjalankannya sampai kena 429. Menunda satu kali jauh lebih murah
     * daripada menembak Gemini lalu ditolak, karena permintaan yang ditolak
     * tetap dihitung Google sebagai permintaan.
     */
    public function tungguDetik(): int
    {
        $pulih = $this->kuotaPulihAt();

        if ($pulih !== null) {
            return max(1, (int) now()->diffInSeconds($pulih, false));
        }

        // Tidak ada kunci yang siap dan tidak ada pula waktu pulih yang bisa
        // dibaca, artinya tabelnya kosong atau seluruh kuncinya dimatikan. Bukan
        // soal kuota melainkan soal pengaturan yang belum diisi, jadi menunggu
        // sebentar lalu memeriksa lagi lebih berguna daripada menyerah.
        return KunciGemini::query()->tersedia()->exists() ? 0 : 900;
    }

    /**
     * Kapan kunci pertama pulih, atau null selama masih ada yang siap dipakai.
     *
     * Satu-satunya jawaban sah atas pertanyaan "kuotanya habis atau tidak".
     * Pertanyaan itu sebelumnya dijawab dengan menebak dari `dijadwalkan_at`
     * pekerjaan yang sedang tidur, dan tebakan itu tetap berbunyi habis lama
     * setelah admin menambahkan kunci baru yang jatahnya masih utuh.
     *
     * Null juga dipulangkan saat tidak ada kunci aktif sama sekali. Itu bukan
     * kehabisan kuota, dan menyebutnya begitu mengirim admin mencari jawaban
     * pada jam reset Google alih-alih pada halaman Pengaturan.
     */
    public function kuotaPulihAt(): ?Carbon
    {
        if (KunciGemini::query()->tersedia()->exists()) {
            return null;
        }

        $pulih = KunciGemini::query()
            ->where('aktif', true)
            ->whereNotNull('limit_sampai')
            ->min('limit_sampai');

        return $pulih === null ? null : Carbon::parse($pulih);
    }

    /**
     * Berapa permintaan yang sistem ini kirim hari ini, seluruh kunci digabung.
     *
     * Yang dilaporkan pemakaian, bukan sisa. Sisa menuntut batas yang benar,
     * dan batas yang benar hanya diketahui untuk kunci yang pernah kehabisan
     * kuota sampai Google menyebut angkanya. Untuk kunci lain sisanya adalah
     * hasil pengurangan terhadap angka salinan dokumentasi, dan angka yang
     * terlihat pasti padahal tebakan lebih berbahaya daripada tidak ada angka.
     *
     * Google juga menghitung permintaan yang tidak lewat sini, misalnya kunci
     * yang sama dipakai proyek lain, jadi angka ini adalah batas bawah
     * pemakaian sebenarnya.
     */
    public function terkirimHarian(): int
    {
        return KunciGemini::query()->where('aktif', true)->get()
            ->sum(fn (KunciGemini $kunci) => $this->terpakaiHarian($kunci));
    }

    /**
     * Batas harian yang berlaku untuk satu kunci.
     *
     * Angka dari Google didahulukan karena ia satu-satunya yang benar. Nilai
     * config hanyalah salinan halaman dokumentasi free tier, dan salinan itu
     * meleset untuk kunci yang proyeknya sudah berbayar maupun saat Google
     * mengubah jatahnya.
     */
    public function batasHarian(KunciGemini $kunci): int
    {
        return $kunci->rpd_google ?? (int) config('ai.batas_kunci.rpd');
    }

    /**
     * Berapa permintaan kunci ini yang sudah terpakai pada hari kuota berjalan.
     *
     * Angkanya tersimpan di barisnya sendiri, bukan di cache. Cache pernah
     * dipakai dan tempatnya salah: `cache:clear` saat deploy atau Redis yang
     * dijatuhkan mengembalikannya ke nol, lalu layar melaporkan kuota utuh
     * untuk kunci yang tinggal beberapa permintaan lagi.
     *
     * Tanggal ikut diperiksa, bukan diandaikan. Baris yang tanggalnya bukan
     * hari kuota berjalan adalah sisa hitungan kemarin, dan membacanya sebagai
     * hitungan hari ini akan menahan kunci yang jatahnya baru saja pulih.
     */
    public function terpakaiHarian(KunciGemini $kunci): int
    {
        return $kunci->rpd_hari?->toDateString() === $this->hariKuota()
            ? (int) $kunci->rpd_terpakai
            : 0;
    }

    /** Tanggal kalender di zona kuota Google, satuan yang dipakai hitungan harian. */
    private function hariKuota(): string
    {
        return Carbon::now(self::ZONA_KUOTA)->toDateString();
    }

    /**
     * Menambah satu ke hitungan harian, sekaligus menyapu hitungan hari kemarin.
     *
     * Satu perintah UPDATE, bukan baca lalu tulis. Tiga worker antrean ditambah
     * tombol di layar bisa menyentuh baris yang sama pada detik yang sama, dan
     * baca-lalu-tulis di antara mereka menghasilkan hitungan yang selalu lebih
     * rendah daripada pemakaian sebenarnya. Persis kesalahan yang paling mahal
     * di sini, karena hitungan yang terlalu rendah berarti permintaan dilepas
     * melewati batas lalu dijawab 429, dan Google menghitung penolakannya.
     *
     * @return int hitungan setelah ditambah
     */
    private function pungutJatahHarian(KunciGemini $kunci): int
    {
        $hari = $this->hariKuota();

        $baru = (int) DB::selectOne(
            'UPDATE kunci_gemini
             SET rpd_terpakai = CASE WHEN rpd_hari = ?::date THEN rpd_terpakai + 1 ELSE 1 END,
                 rpd_hari = ?::date
             WHERE id = ?
             RETURNING rpd_terpakai',
            [$hari, $hari, $kunci->id],
        )->rpd_terpakai;

        // Salinan di memori disamakan supaya pemanggil yang membaca modelnya
        // segera setelah ini tidak melihat angka basi.
        $kunci->forceFill(['rpd_terpakai' => $baru, 'rpd_hari' => $hari])->syncOriginal();

        return $baru;
    }

    /**
     * Kapan kuota harian seluruh kunci pulih.
     *
     * Satu waktu untuk semua kunci, bukan satu per kunci. Google memulangkan
     * jatah harian free tier pada pergantian hari kalender waktu Pasifik, bukan
     * 24 jam setelah kunci itu mulai dipakai, jadi tiga kunci yang habis pada
     * jam yang berbeda tetap pulih pada detik yang sama.
     */
    public function resetHarian(): Carbon
    {
        return Carbon::tomorrow(self::ZONA_KUOTA)->utc();
    }

    /**
     * Berapa permintaan yang sudah terpakai pada 60 detik terakhir per kunci.
     *
     * Dibaca tanpa gembok dan tanpa mengubah apa pun, karena ini hanya untuk
     * ditampilkan. Angka yang meleset satu permintaan pada layar pemantauan
     * tidak berakibat apa-apa, sedangkan menunggu gembok demi menampilkannya
     * bisa menahan permintaan yang sedang benar-benar dikerjakan.
     *
     * @return array<int, int>
     */
    public function pemakaianMenit(): array
    {
        $sekarang = microtime(true);

        return KunciGemini::query()->where('aktif', true)->get()
            ->mapWithKeys(fn (KunciGemini $kunci): array => [
                $kunci->id => \count(array_filter(
                    (array) Cache::get("gemini:rpm:{$kunci->id}", []),
                    fn (float $cap): bool => $cap > $sekarang - 60,
                )),
            ])
            ->all();
    }

    /**
     * Urutan pemakaian: yang paling lama menganggur lebih dulu.
     *
     * @return Collection<int, KunciGemini>
     */
    private function giliran()
    {
        return KunciGemini::query()
            ->tersedia()
            ->orderByRaw('terakhir_dipakai_at ASC NULLS FIRST')
            ->orderBy('id')
            ->get();
    }

    /**
     * Apakah kunci ini masih punya jatah, sekaligus mencatat pemakaiannya.
     *
     * Kunci yang jatahnya habis ditandai lewat `limit_sampai`, kolom yang sama
     * dengan yang dipakai saat Google mengirim 429. Itu disengaja: scope
     * `tersedia`, pesan galat, dan status di halaman Pengaturan sudah membaca
     * kolom itu, jadi batas yang dijaga sendiri tidak perlu jalur tampilan
     * sendiri untuk bisa terbaca admin.
     */
    private function punyaJatah(KunciGemini $kunci): bool
    {
        // Harian diperiksa lebih dulu, dan hanya dibaca. Ia yang paling mahal
        // ditabrak: jatah per menit pulih dalam hitungan detik, sedangkan jatah
        // harian baru pulih tengah malam waktu Pasifik. Memeriksanya belakangan
        // berarti satu slot menit terpakai untuk permintaan yang tidak akan
        // pernah dikirim.
        $batasHarian = $this->batasHarian($kunci);

        if ($batasHarian > 0 && $this->terpakaiHarian($kunci) >= $batasHarian) {
            $this->tahan($kunci, $this->resetHarian(), 'kuota_harian_lokal');

            return false;
        }

        if (($tunggu = $this->slotMenit($kunci)) > 0) {
            $this->tahan($kunci, now()->addSeconds((int) ceil($tunggu)), 'kuota_menit_lokal');

            return false;
        }

        // Baru di sini jatahnya benar-benar dipungut, dan hasilnya diperiksa
        // ulang. Antara pembacaan di atas dan pemungutan di sini ada celah
        // selebar beberapa milidetik yang cukup dilewati worker lain, dan
        // UPDATE-lah yang memutuskan siapa yang mendapat slot terakhir.
        $terpakai = $this->pungutJatahHarian($kunci);

        if ($batasHarian > 0 && $terpakai > $batasHarian) {
            $this->tahan($kunci, $this->resetHarian(), 'kuota_harian_lokal');

            return false;
        }

        return true;
    }

    /**
     * Satu slot dari jendela geser 60 detik. Nol berarti dapat, selain itu
     * berapa detik lagi sebelum slot berikutnya terbuka.
     *
     * Jendela geser, bukan menit kalender, dan bedanya bukan soal kerapian.
     * Dengan menit kalender, lima belas permintaan pada detik ke-59 lalu lima
     * belas lagi pada detik ke-01 menit berikutnya semuanya sah menurut
     * hitungan lokal, padahal Google melihat tiga puluh permintaan dalam dua
     * detik. Itu bukan dugaan: uji jalan antrean pertama mengirim 109
     * permintaan untuk 19 artikel karena celah ini, dan sekitar tujuh puluh di
     * antaranya dijawab 429 yang tetap dihitung Google.
     *
     * Digembok karena tiga pemakai bisa membacanya bersamaan: worker antrean,
     * tombol Klasifikasi di layar, dan tombol uji kunci di halaman Pengaturan.
     * Tanpa gembok, ketiganya bisa membaca jendela yang sama lalu sama-sama
     * menyimpulkan masih ada tempat.
     */
    private function slotMenit(KunciGemini $kunci): float
    {
        $batas = (int) config('ai.batas_kunci.rpm');

        if ($batas <= 0) {
            return 0;
        }

        $nama = "gemini:rpm:{$kunci->id}";

        try {
            return (float) Cache::lock("{$nama}:gembok", 5)->block(3, function () use ($nama, $batas): float {
                $sekarang = microtime(true);

                $jendela = array_values(array_filter(
                    (array) Cache::get($nama, []),
                    fn (float $cap): bool => $cap > $sekarang - 60,
                ));

                if (\count($jendela) >= $batas) {
                    // Slot tertua kedaluwarsa 60 detik setelah ia dipakai, dan
                    // saat itulah tempat berikutnya terbuka.
                    return max(1.0, 60 - ($sekarang - min($jendela)));
                }

                $jendela[] = $sekarang;

                Cache::put($nama, $jendela, 120);

                return 0;
            });
        } catch (LockTimeoutException) {
            // Ada yang memegang gembok terlalu lama. Menunggu sebentar lebih
            // aman daripada melewati penjaga ini, karena melewatinya berarti
            // mengirim permintaan yang mungkin melampaui batas.
            return 1;
        }
    }

    private function tahan(KunciGemini $kunci, CarbonInterface $sampai, string $alasan): void
    {
        $kunci->forceFill(['limit_sampai' => $sampai, 'alasan_limit' => $alasan])->saveQuietly();
    }

    private function pakai(KunciGemini $kunci): void
    {
        config(['ai.providers.gemini.key' => $kunci->kunci]);

        Ai::forgetInstance('gemini');

        $kunci->forceFill(['terakhir_dipakai_at' => now()])->saveQuietly();
    }

    private function tandaiLimit(KunciGemini $kunci, RateLimitedException $galat): void
    {
        [$sampai, $alasan, $rpd] = $this->waktuPulih($galat);

        $kunci->forceFill([
            'limit_sampai' => $sampai,
            'alasan_limit' => $alasan,
            // Hanya ditimpa saat Google benar-benar menyebut angkanya. Menulis
            // null di sini akan membuang batas yang sudah diketahui setiap kali
            // kunci kena limit per menit, dan sistem kembali menebak.
            ...($rpd === null ? [] : ['rpd_google' => $rpd, 'rpd_google_at' => now()]),
        ])->saveQuietly();
    }

    /**
     * Waktu pulih dibaca dari badan galat Google, bukan ditebak.
     *
     * Badan 429 memuat `QuotaFailure` berisi `quotaId` yang menyebut apakah
     * yang jebol kuota per menit atau per hari, dan sering memuat `RetryInfo`
     * berisi `retryDelay`. Keduanya jauh lebih tepat daripada satu angka mati,
     * dan bedanya besar: menit versus sisa hari.
     *
     * Pelanggaran harian juga membawa `quotaValue`, yaitu batas harian yang
     * sesungguhnya menurut Google. Angka itu ikut dipungut karena inilah
     * satu-satunya tempat ia bisa dibaca memakai kunci API biasa. Kuota proyek
     * hanya terbuka lewat Cloud Quotas API yang menuntut kredensial OAuth dan
     * nomor proyek.
     *
     * @return array{Carbon, string, ?int}
     */
    private function waktuPulih(RateLimitedException $galat): array
    {
        $harian = false;
        $jeda = null;
        $batasHarian = null;

        foreach ($this->rincian($galat) as $rincian) {
            foreach ((array) ($rincian['violations'] ?? []) as $pelanggaran) {
                $penanda = strtolower(
                    ($pelanggaran['quotaId'] ?? '').' '.($pelanggaran['quotaMetric'] ?? '')
                );

                if (! str_contains($penanda, 'perday') && ! str_contains($penanda, 'per_day')) {
                    continue;
                }

                $harian = true;

                // quotaValue dikirim sebagai string, dan pada tier tertentu bisa
                // bernilai nol. Nol berarti kunci ini memang tidak punya jatah
                // untuk model tersebut, bukan berarti angkanya tidak terbaca,
                // jadi yang ditolak hanya nilai yang bukan angka.
                if (isset($pelanggaran['quotaValue']) && is_numeric($pelanggaran['quotaValue'])) {
                    $batasHarian = (int) $pelanggaran['quotaValue'];
                }
            }

            if (isset($rincian['retryDelay'])) {
                $jeda = (int) rtrim((string) $rincian['retryDelay'], 's');
            }
        }

        // Dikembalikan sebagai UTC. Kolom timestamptz dikirimi jam dinding
        // tanpa offset (lihat config/app.php), jadi Carbon yang masih berzona
        // Pasifik akan tersimpan meleset beberapa jam.
        if ($harian) {
            return [$this->resetHarian(), 'kuota_harian', $batasHarian];
        }

        return [
            now()->addSeconds(max($jeda ?? 0, self::JEDA_MINIMAL_DETIK)),
            $jeda !== null ? 'retry_delay' : 'kuota_menit',
            null,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function rincian(RateLimitedException $galat): array
    {
        $asal = $galat->getPrevious();

        if (! $asal instanceof RequestException || $asal->response === null) {
            return [];
        }

        return (array) $asal->response->json('error.details', []);
    }

    private function semuaHabis(): RateLimitedException
    {
        $pulih = KunciGemini::query()
            ->where('aktif', true)
            ->whereNotNull('limit_sampai')
            ->orderBy('limit_sampai')
            ->value('limit_sampai');

        $pesan = $pulih === null
            ? 'Tidak ada kunci API Gemini yang aktif. Tambahkan kuncinya di halaman Pengaturan.'
            : 'Semua kunci API Gemini sedang kena limit. Kuota berikutnya terbuka '
                .Carbon::parse($pulih)->timezone(Waktu::ZONA)->translatedFormat('j F Y H:i').' WITA.';

        return new RateLimitedException($pesan, 429);
    }
}
