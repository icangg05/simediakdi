# 06 — Akses dan Keamanan

SIMEDIA Kendari | Versi 1.0

---

## 1. Model peran

Tiga peran tetap, disimpan sebagai enum di `user.peran`. Tanpa paket permission, tanpa tabel roles.

| Peran | Jumlah akun perkiraan | Prefix rute | Sifat |
|-------|----------------------|-------------|-------|
| `superadmin` | 2 sampai 4 | `/admin` | Baca dan tulis semua data |
| `walikota` | 2 sampai 3 | `/eksekutif` | Baca semua data, tanpa hak tulis |
| `media` | Sejumlah media partner | `/portal` | Baca dan tulis terbatas pada medianya sendiri |

Keputusan menggunakan enum alih-alih Spatie Permission dicatat di dokumen 00. Kalau suatu saat muncul kebutuhan seperti "staf Prokopim boleh mengoreksi label tapi tidak boleh mengelola kontrak", saat itulah paket permission dipasang. Jangan sebelumnya.

## 2. Matriks izin

Kolom: S = superadmin, W = walikota, M = media. Notasi: **B** baca, **T** tulis, **BS** baca terbatas data sendiri, **TS** tulis terbatas data sendiri, tanda hubung berarti tidak ada akses.

| Sumber daya | S | W | M |
|-------------|---|---|---|
| Artikel, daftar dan detail | B T | B | BS |
| Isi artikel utuh | — | — | — |
| Koreksi label sentimen | T | — | — |
| Skor sentimen artikel | B | B | — |
| Media, daftar | B T | B | BS |
| Sumber feed | B T | — | — |
| Konteks pantauan | B T | B | — |
| Kontrak | B T | B | BS |
| Pemuatan | B T | B | BS TS |
| Verifikasi pemuatan | T | — | — |
| Entitas | B T | B | — |
| Kata kunci dan isu | B | B | — |
| Ringkasan dan grafik agregat | B | B | BS |
| Gold set dan pelabelan | B T | — | — |
| Hasil evaluasi model | B | B | — |
| Aturan alert | B T | B | — |
| Riwayat alert | B T | B | — |
| Pengguna | B T | — | BS profil sendiri |
| Log crawl | B | — | — |
| Activity log | B | — | — |
| Pengaturan sistem | B T | — | — |
| Ekspor Excel dan PDF | T | T | TS |

Tiga baris yang perlu diperhatikan:

**Isi artikel utuh tidak dapat diakses siapa pun lewat UI.** Kolom `artikel.isi` hanya dibaca oleh pipeline analisis. Aturan ini menjaga kepatuhan hak cipta di dokumen 01 bagian 6, dan menghindari sistem berubah menjadi agregator berita yang menggantikan situs sumbernya.

**Peran media tidak melihat skor sentimen.** Keputusan produk, alasannya di dokumen 01 bagian 8 dan dokumen 04 bagian C.4.

**Peran walikota boleh mengekspor.** Ekspor secara teknis adalah aksi tulis karena membuat berkas, tapi tidak mengubah data. Kecualikan rute ekspor dari middleware penolak tulisan.

## 3. Penegakan di lapisan kode

Empat lapisan, berlapis dengan sengaja. Satu lapisan gagal, tiga lainnya masih menahan.

### Lapis 1 — Middleware rute

```php
Route::middleware(['auth', 'verified', 'peran:superadmin'])
    ->prefix('admin')->group(...);

Route::middleware(['auth', 'verified', 'peran:walikota', 'tolak.tulis'])
    ->prefix('eksekutif')->group(...);

Route::middleware(['auth', 'verified', 'peran:media'])
    ->prefix('portal')->group(...);
```

Middleware `tolak.tulis` menolak seluruh request dengan method POST, PUT, PATCH, dan DELETE, kecuali rute yang masuk daftar putih ekspor. Ini sabuk pengaman untuk F-44. Kalau suatu saat Anda lupa dan menambahkan tombol di halaman eksekutif, middleware ini menahannya.

### Lapis 2 — Global scope

Model yang memiliki `media_id` mendapat global scope. Ini penegakan terpenting, karena bekerja bahkan ketika Anda lupa menulis kondisi `where` di controller.

Model yang perlu scope: `Artikel`, `Kontrak`, `Pemuatan`, `SumberFeed`, `RingkasanHarian`.

Perilaku scope:

- Peran `media`: tambahkan `where('media_id', auth()->user()->media_id)`
- Peran `superadmin` dan `walikota`: tanpa pembatasan
- Tanpa autentikasi: kembalikan query kosong, jangan seluruh data

Poin terakhir itu penting. Scope harus **gagal ke arah menutup**, bukan membuka. Kalau `auth()->user()` bernilai null karena satu sebab yang tidak terduga, misalnya query dijalankan dari console command, scope harus mengembalikan nol baris, bukan semuanya. Untuk console command yang memang butuh semua data, gunakan `withoutGlobalScope()` secara eksplisit sehingga niatnya terlihat di kode.

### Lapis 3 — Policy

Policy per model untuk aksi individual. Yang wajib ditulis: `ArtikelPolicy`, `KontrakPolicy`, `PemuatanPolicy`, `MediaPolicy`, `UserPolicy`, `AnalisisSentimenPolicy`.

Aturan yang paling mudah terlewat, dan sudah pernah menjadi celah di banyak aplikasi Laravel: pada `PemuatanPolicy::update()`, periksa bahwa `$pemuatan->media_id === $user->media_id` **dan** bahwa statusnya masih `menunggu`. Tanpa syarat kedua, media bisa mengubah laporan yang sudah diverifikasi admin.

### Lapis 4 — Penyaringan data yang dikirim ke frontend

Inertia mengirim seluruh props ke browser, dan pengguna bisa membacanya di devtools. Karena itu penyaringan di lapisan data wajib, tidak cukup menyembunyikan elemen di komponen Vue.

Konsekuensi konkretnya: Resource untuk peran `media` **tidak boleh memuat field skor sentimen sama sekali**. Bukan dikirim lalu disembunyikan dengan `v-if`. Buat dua Resource terpisah, `ArtikelResource` untuk admin dan eksekutif, serta `ArtikelPortalResource` untuk peran media.

Aturan umum: jangan pernah mengandalkan `v-if` untuk keamanan. `v-if` adalah pengaturan tampilan, bukan pengendalian akses.

## 4. Autentikasi

### 2FA

Fortify sudah menyediakannya di starter kit. Kebijakannya:

| Peran | 2FA |
|-------|-----|
| `superadmin` | Wajib. Blokir akses ke `/admin` sampai diaktifkan |
| `walikota` | Wajib |
| `media` | Opsional, disarankan |

Penegakan wajib dilakukan lewat middleware yang mengalihkan ke halaman pengaturan 2FA jika `two_factor_confirmed_at` masih null.

### Kebijakan sesi dan kata sandi

| Pengaturan | Nilai | Alasan |
|------------|-------|--------|
| Panjang minimal kata sandi | 12 karakter | |
| Cek terhadap kebocoran | Aktifkan `Password::uncompromised()` | Laravel sudah menyediakannya lewat API HaveIBeenPwned |
| Masa berlaku sesi | 30 hari untuk `walikota` dan `media`, 8 jam untuk `superadmin` | Superadmin memakai komputer kerja yang mungkin dipakai bersama. Walikota memakai ponsel pribadi dan akan berhenti memakai sistem kalau harus login tiap hari |
| Rate limit login | 5 percobaan per menit per kombinasi email dan IP | Bawaan Fortify |
| Rotasi wajib | Tidak ada | Rotasi berkala terbukti membuat orang memilih kata sandi yang lebih lemah dan menuliskannya |

Perbedaan masa sesi antar peran terlihat tidak konsisten dan memang disengaja. Sesi panjang pada akun walikota adalah trade-off keamanan yang dibayar dengan 2FA wajib dan tanpa hak tulis. Akun yang tidak bisa mengubah apa pun punya nilai lebih rendah bagi penyerang.

### Kebijakan khusus akun walikota

1. Satu orang satu akun. Kalau staf khusus perlu akses, buat akun terpisah dengan peran yang sama. Akun bersama menghapus seluruh nilai audit log.
2. Login pada akun berperan `walikota` dicatat di activity log, meskipun bukan aksi tulis. Simpan waktu, alamat IP, dan user agent.
3. Kalau login terjadi dari alamat IP yang belum pernah dipakai akun tersebut, kirim notifikasi Telegram ke admin. Bukan untuk memblokir, hanya untuk diketahui.
4. Halaman eksekutif tidak boleh menampilkan nama pengguna lain atau informasi akun apa pun. Tidak ada alasan halaman ini memuat data pengguna.

## 5. Audit trail

Menggunakan `spatie/laravel-activitylog`. Konfigurasinya ada di dokumen 03 bagian 15.

Yang dicatat:

| Peristiwa | Alasan pencatatan |
|-----------|-------------------|
| Koreksi label sentimen | Paling penting. Ini titik di mana manusia mengubah kesimpulan sistem, dan suatu saat akan ada yang menanyakan siapa yang mengubahnya |
| Verifikasi dan penolakan pemuatan | Punya konsekuensi terhadap kontrak dan pembayaran |
| Perubahan kontrak, terutama `target_pemuatan` dan `nilai` | Konsekuensi keuangan |
| Pembuatan, perubahan, dan penonaktifan akun | |
| Perubahan ambang keyakinan di pengaturan | Mengubah angka ambang mengubah seluruh angka di dashboard. Tanpa catatan, tidak akan ada yang bisa menjelaskan kenapa grafik bulan lalu terlihat berbeda |
| Perubahan konteks pantauan | Sama alasannya |
| Login peran walikota | Lihat bagian 4 |
| Ekspor data | Siapa mengambil data apa dan kapan |

Yang tidak dicatat: pembuatan artikel oleh crawler, hasil analisis model, dan pembacaan halaman biasa. Volumenya besar dan nilainya kecil.

Retensi activity log: simpan permanen. Tabel ini tumbuh lambat karena hanya aksi manusia yang tercatat, dan nilainya justru naik seiring waktu.

## 6. Perlindungan data

### Klasifikasi

| Jenis data | Sensitivitas | Perlakuan |
|------------|--------------|-----------|
| Artikel dan metadata | Publik | Sudah publik di sumbernya |
| Isi artikel tersimpan | Publik dengan batasan hak cipta | Tidak ditampilkan utuh |
| Skor sentimen | Internal | Hanya superadmin dan walikota |
| Nilai kontrak | Sensitif | Hanya superadmin dan media pemilik kontrak. Sebagian informasi kontrak pengadaan pemerintah bersifat publik, tapi jangan menampilkannya sebelum ada kepastian dari Diskominfo |
| Data kontak PIC media | Data pribadi | Berlaku UU Perlindungan Data Pribadi |
| Data akun pengguna | Data pribadi | Berlaku UU PDP |

### Kewajiban UU PDP

Sistem menyimpan nama, email, dan nomor telepon PIC media serta pengguna. Konsekuensi minimalnya:

1. Kumpulkan hanya yang dibutuhkan. Nomor telepon PIC berguna, tanggal lahir tidak.
2. Sediakan halaman kebijakan privasi yang menjelaskan data apa yang disimpan dan untuk apa.
3. Sediakan cara pengguna melihat dan memperbaiki datanya sendiri, yaitu halaman profil.
4. Batasi retensi data akun yang sudah dinonaktifkan. Soft delete permanen di database bukan penghapusan menurut UU PDP. Sediakan perintah `user:hapus-permanen` yang menganonimkan data akun setelah masa retensi yang disepakati.
5. Catat aktivitas pemrosesan data pribadi. Activity log sudah memenuhi ini.

Poin 4 sering dilewatkan pada sistem pemerintah. Bahas dengan Diskominfo apa masa retensinya, karena instansi mungkin punya kebijakan sendiri.

## 7. Pengerasan aplikasi

Daftar periksa sebelum sistem dibuka ke publik.

| Item | Keterangan |
|------|------------|
| HTTPS wajib | Middleware `\Illuminate\Http\Middleware\TrustProxies` dan pengalihan HTTP ke HTTPS di nginx |
| `APP_DEBUG=false` di produksi | Kesalahan konfigurasi paling umum dan paling merugikan |
| Header keamanan | `Content-Security-Policy`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin` |
| CSRF | Aktif bawaan Laravel. Jangan mengecualikan rute apa pun |
| Rate limit rute | 60 per menit untuk rute umum, 10 per menit untuk pencarian dan ekspor |
| Validasi unggahan | Bukti pemuatan: hanya `jpg`, `jpeg`, `png`, `webp`, maksimal 4 MB. Dokumen kontrak: `pdf`, maksimal 10 MB. **Validasi tipe MIME sebenarnya, bukan ekstensi berkas** |
| Penyimpanan unggahan | Di luar `public/`, disajikan lewat controller yang memeriksa policy. Bukti pemuatan milik media A tidak boleh bisa dibuka dengan menebak URL. Berlaku juga untuk arsip tangkapan layar yang dibuat sistem |
| Arsip bukti | `arsip_teks` dan `arsip_screenshot_path` bersifat append-only: tidak ada rute yang mengubah atau menghapusnya, termasuk untuk superadmin. Nilai buktinya justru pada ketidakbisaannya diubah |
| Layanan NLP | Bind ke `127.0.0.1`, jangan `0.0.0.0` |
| Redis | Bind ke localhost, aktifkan `requirepass` |
| PostgreSQL | Hanya koneksi localhost, pengguna aplikasi tanpa hak `SUPERUSER` |
| Dependensi | `composer audit` dan `npm audit` bulanan |
| Log | Rotasi harian, simpan 30 hari. Jangan mencatat isi request yang memuat kata sandi |

### Risiko khusus crawler

Crawler mengunduh dan mengolah HTML dari situs luar. Dua hal yang perlu dijaga:

1. **Server-side request forgery.** Admin bisa memasukkan URL sumber feed apa pun, termasuk `http://169.254.169.254/` atau alamat internal jaringan. Validasi URL sebelum mengambilnya: tolak alamat IP privat, localhost, dan skema selain `http` dan `https`. Kesalahan ini nyata, dan pada server cloud bisa berujung pada kebocoran kredensial metadata.
2. **Ukuran respons.** Batasi unduhan pada 5 MB per halaman dan timeout 20 detik, agar satu situs yang bermasalah tidak menghabiskan memori worker.

## 8. Pemulihan bencana

| Skenario | Tindakan |
|----------|----------|
| Database rusak | Restore `pg_dump` harian. Kehilangan maksimal 24 jam artikel, dan artikel yang hilang akan ter-crawl ulang selama URL-nya masih ada di feed |
| Layanan NLP mati | Crawler tetap jalan, job menumpuk di queue `nlp`. Tidak ada data hilang. Sentimen menyusul setelah layanan hidup |
| Redis mati | Queue berhenti, cache kosong. Aplikasi tetap bisa dibuka tapi lambat. Tidak ada kehilangan data permanen selama `queue:restart` tidak dijalankan sembarangan |
| Server hilang seluruhnya | Restore dari backup mingguan di luar server. Target waktu pemulihan 4 jam dengan asumsi provisioning ulang manual |
| Koreksi label hilang | Ini yang paling tidak bisa dipulihkan karena hasil kerja manusia. Sertakan tabel `analisis_sentimen` dan `gold_set` dalam backup harian, dan uji restore dua tabel ini secara khusus |

Yang paling penting dan paling sering dilupakan: **uji restore sebulan sekali**. Restore ke database terpisah, buka aplikasi menunjuk ke database itu, periksa jumlah baris dan beberapa halaman. Backup yang belum pernah direstore adalah asumsi, bukan cadangan.
