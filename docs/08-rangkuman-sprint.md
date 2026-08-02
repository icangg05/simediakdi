# 08 — Rangkuman Sprint

Ringkasan satu halaman dari [`07-roadmap.md`](07-roadmap.md). Detail tugas,
definition of done lengkap, jalur pemangkasan, dan daftar versi 2 ada di sana.

**7 tahapan: Sprint 0–6. Total 12 minggu (~3 bulan), asumsi 1 pengembang 20 jam/minggu.**

| Sprint | Durasi | Fokus | Status |
|--------|--------|-------|--------|
| 0 | 1 minggu | Persiapan: uji feed, uji model, siapkan infra | sebagian |
| 1 | 2 minggu | Fondasi, CRUD, `DataTable.vue` | selesai |
| 2 | 2 minggu | Crawler dan deduplikasi | belum |
| 3 | 2 minggu | NLP dan gold set | belum |
| 4 | 2 minggu | Dashboard eksekutif dan kontrak | belum |
| 5 | 2 minggu | Portal media dan alert | belum |
| 6 | 2 minggu | Pemantapan dan serah terima | belum |

---

## Sprint 0 — Persiapan (1 minggu)

Bukan sprint penuh, tapi jangan dilewati.

- Jawab sembilan pertanyaan terbuka dokumen 01 bagian 9 — sudah terjawab
- Uji satu per satu 30 URL RSS di lampiran A, catat mana yang hidup
- Verifikasi dua model IndoBERT bisa diunduh dan dijalankan
- Uji manual 20 artikel Kendari ke model sentimen
- Siapkan repo, VPS, domain, sertifikat, Laravel, PostgreSQL+pgvector, Redis

**Selesai bila:** aplikasi kosong terbuka lewat HTTPS di domain produksi, dan
skrip Python bisa melabeli satu artikel dari terminal.

**Gerbang:** akurasi model di bawah 60% berarti berhenti dan baca dokumen 05
bagian 8 sebelum lanjut.

## Sprint 1 — Fondasi dan CRUD (2 minggu)

Tanpa NLP, tanpa grafik. Menyiapkan alat untuk lima sprint berikutnya.

- Seluruh migration dokumen 03 sekaligus, model, relasi, enum, seeder 30 media
  dan tiga konteks pantauan
- Middleware `peran` dan `tolak.tulis`, global scope pada model ber-`media_id`
- CRUD media dan sumber feed
- Tiga layout, token warna sentimen, komponen shadcn gelombang pertama
- `DataTable.vue` beserta toolbar, paginasi, faceted filter
- `BadgeSentimen.vue`, `KeadaanKosong.vue`, `useFormatAngka.ts`

**Selesai bila:** superadmin login dengan 2FA, menambah media dan sumber RSS,
lalu melihatnya di tabel yang bisa difilter, disortir, dan dipaginasi lewat URL.

**Prioritas:** `DataTable.vue` lebih dulu sampai nyaman dipakai. Sembilan
halaman bergantung padanya.

## Sprint 2 — Crawler (2 minggu)

Sprint pertama yang menghasilkan sesuatu yang bisa didemokan.

- `PembacaRss`, `NormalisasiUrl`, `EkstraktorArtikel` dengan readability.php
- Command `crawl:feeds` dengan `withoutOverlapping()` dan `crawl:google-news`
- Job `AmbilIsiArtikel` dengan retry dan backoff
- Deduplikasi lapis 1 dan 2: URL kanonik dan simhash
- Log crawl, penonaktifan otomatis sumber setelah 5 kegagalan
- Validasi URL anti-SSRF, konfigurasi supervisor untuk worker queue
- Halaman daftar artikel, halaman log crawl, dashboard admin KPI dasar

**Selesai bila:** crawler berjalan otomatis 72 jam tanpa intervensi, tabel
artikel terisi dari minimal 10 media, duplikat tertandai benar.

**Gerbang:** periksa manual 50 artikel sebelum menutup sprint. Ekstraksi buruk
di sini merusak seluruh analisis sprint berikutnya.

## Sprint 3 — NLP dan gold set (2 minggu)

Sprint terberat. Anggarkan waktu lebih.

- Layanan FastAPI tiga endpoint, `KlienNlp` di sisi Laravel
- Job `HitungEmbedding`, deduplikasi lapis 3 dengan pgvector
- Penyaring kata kunci per konteks, job `AnalisisRelevansi` dan `AnalisisSentimen`
- Logika `perlu_review`, kolom generated `label_efektif`
- CRUD konteks pantauan, halaman detail artikel dengan form koreksi label
- Ruang kerja pelabelan lengkap dengan pintasan keyboard
- Panduan pelabelan, lalu labeli 400 baris gold set (~8 jam)
- Command `evaluasi:model` dan halaman hasil evaluasi
- Setel ambang keyakinan dari gold set, ambang dedup dari 100 pasangan manual
- Command `hitung:ringkasan-harian`

**Selesai bila:** seluruh artikel punya skor sentimen per konteks relevan, ada
satu baris `evaluasi_model` dengan F1 macro terukur, ambang keyakinan
ditetapkan dari data bukan tebakan.

**Gerbang:** F1 macro di bawah 0,65 berarti hentikan sprint 4 dan kerjakan
alternatif dokumen 05 bagian 8 lebih dulu.

**Urutan:** bangun ruang kerja pelabelan sebelum mulai melabeli.

## Sprint 4 — Dashboard eksekutif dan kontrak (2 minggu)

- `BaseChart.vue`, `useTemaChart.ts`
- Lima grafik: tren volume, tren sentimen, donat, peringkat media, word cloud
- Tombol "Lihat sebagai tabel" pada setiap grafik
- `KartuKpi.vue`, `PemilihRentangTanggal.vue`, `PemilihKonteks.vue`
- Dashboard eksekutif, halaman sentimen, isu hangat, peringkat media, arsip
- Command `hitung:kata-kunci` dengan skor lonjakan
- CRUD kontrak, `ProgresKontrak.vue`, pencocokan otomatis artikel ke pemuatan
- CRUD pengguna, ekspor Excel

**Selesai bila:** dashboard render di bawah 2 detik pada 4G, terbaca di layar
375 piksel, angkanya cocok saat dihitung ulang manual dari tabel artikel.

**Catatan:** uji di ponsel sungguhan, bukan device toolbar browser.

## Sprint 5 — Portal media dan alert (2 minggu)

- Dashboard portal, halaman berita saya, kontrak saya
- Halaman lapor: tempel URL, pratinjau, konfirmasi, banyak URL, jalur cadangan
  isian manual saat ekstraksi gagal
- Daftar "sudah tercatat otomatis" di atas form lapor
- Job `ArsipkanBuktiPemuatan` dengan Playwright, validasi domain URL vs pelapor
- `ArtikelPortalResource` tanpa field sentimen
- Antrean verifikasi pemuatan di panel admin
- 30 akun media dibuat dan disosialisasikan, Google Form dimatikan
- CRUD aturan alert, command `alert:periksa`, `PengirimTelegram`, riwayat alert
  dan pembatas pengiriman berulang
- Command `kontrak:periksa-tenggat`, CRUD entitas dengan aksi gabungkan
- Halaman pengaturan sistem
- Empat test wajib dokumen 02 bagian 9

**Selesai bila:** pengguna media bisa melaporkan pemuatan dan admin
memverifikasinya, alert lonjakan negatif terkirim ke Telegram, empat test hijau.

**Catatan:** minta chat ID grup Telegram Diskominfo di awal sprint. Test
scoping peran media adalah satu-satunya fitur yang kebocorannya jadi masalah
dengan pihak luar.

## Sprint 6 — Pemantapan dan serah terima (2 minggu)

Sprint tanpa fitur baru. Ide fitur baru masuk daftar versi 2.

- Ekspor PDF ringkasan eksekutif
- Aksesibilitas: kontras, fokus, navigasi keyboard
- Keadaan kosong, loading, dan error di seluruh halaman
- Peninjauan pesan error agar menyebut tindakan yang bisa diambil
- Optimasi query, perbaiki apa pun di atas 300 ms
- Daftar periksa pengerasan dokumen 06 bagian 7
- Backup otomatis dan satu kali uji restore penuh
- Halaman kebijakan privasi, README setup dari nol, panduan pengguna per peran
- Pelatihan admin Diskominfo dua sesi
- Evaluasi model kedua setelah 3 bulan data, bandingkan dengan yang pertama
- Pastikan Google Form sudah mati

**Selesai bila:** orang lain bisa menyiapkan lingkungan pengembangan dari
README tanpa bertanya, backup pernah berhasil direstore, admin Diskominfo bisa
menambahkan sumber feed baru sendiri.

---

## Yang tidak boleh dipangkas

Kalau waktu habis, urutan pemangkasan ada di dokumen 07. Enam hal ini di luar
daftar itu dalam keadaan apa pun:

1. Gold set dan pengukuran akurasi
2. Deduplikasi
3. Status perlu review
4. Global scope peran media beserta test-nya
5. Backup dan satu kali uji restore
6. Responsivitas dashboard eksekutif di layar ponsel

### Penarikan arsip (`crawl:backfill`)

Ditambahkan di luar rencana sprint karena kelas negatif tidak bisa diukur tanpa
korpus yang jauh lebih besar. Setelah pengetat relevansi, artikel yang ditebak
negatif di konteks utama tinggal empat — dan semuanya sudah dilabeli. Pada laju
crawler RSS, mengumpulkan 40 negatif butuh sekitar dua pekan.

Hasil satu kali jalan, empat halaman per media: **4.415 artikel baru**, total
4.720, dalam hitungan menit. Enam media memberi nol — Telisik, Sibernas, dan
Sultra TV memblokir `/wp-json/` lewat robots, sedangkan Tempo, Detik, dan
Portal.id bukan WordPress atau nasional.

Inilah pemakaian WP REST yang tepat. Untuk artikel tunggal ia justru tiga kali
lebih lambat daripada mengunduh halamannya, tapi di sini satu permintaan
menggantikan lima puluh — dan itu jauh lebih ringan bagi situs medianya.

Dua hal yang muncul saat membangunnya:

1. **Logika pasca-ekstraksi dipindah ke `PenyelesaiArtikel`.** Sidik jari,
   deduplikasi, dan penerusan ke analisis dulu hanya ada di `AmbilIsiArtikel`.
   Menyalinnya ke backfill berarti dua jalur yang bisa menyimpang diam-diam —
   dan deduplikasi ada di daftar yang tidak boleh dipangkas.
2. **Bug transaksi PostgreSQL.** Menangkap pelanggaran unique lalu melanjutkan
   hanya bekerja di luar transaksi: begitu ada transaksi pembungkus, satu
   statement gagal meracuni seluruhnya dan penarikan berhenti di URL duplikat
   pertama. Diperbaiki dengan membungkus penyisipan dalam transaksi bersarang
   (SAVEPOINT).

## Yang masih menunggu pekerjaan manusia

1. **F1 negatif diukur dari tiga sampel.** Perlu tambahan label lewat mode
   terarah "ditebak negatif" di `/admin/pelabelan` sampai terkumpul minimal 40
   negatif, dilaporkan terpisah dari akurasi keseluruhan. Sampai itu ada, F1
   macro 0,7998 harus disebut bersama peringatannya.
2. **Ronde 2 gold set.** 40 baris acak dilabeli ulang seminggu setelah ronde 1
   tanpa melihat label lama, untuk mengukur konsistensi pelabel. Angkanya
   adalah batas atas yang wajar diminta dari model, dan tampil sendiri di
   `/admin/evaluasi`.
3. **Evaluasi ulang setelah pengetat relevansi.** Gold set yang sudah ada tetap
   sah — yang berubah tebakan model, bukan label manusia — jadi cukup
   menjalankan `evaluasi:model` lagi setelah analisis ulang selesai.

## Posisi saat ini

**Sprint 0 sampai 3 selesai.** Definition of done sprint 3 tercapai: seluruh
artikel punya skor sentimen per konteks relevan, ada baris `evaluasi_model`
dengan F1 macro terukur (0,7998), dan ambang keyakinan ditetapkan dari data.

Gold set ronde 1 berisi 254 label — 200 di konteks utama, 27 di masing-masing
konteks lain. Cukup untuk akurasi keseluruhan; belum cukup untuk F1 negatif.

Yang belum: **ronde 2 (40 baris ulang untuk mengukur konsistensi pelabel)** dan
**suplemen kelas negatif**. Keduanya butuh pekerjaan manusia.

Layanan NLP (`nlp/`) sudah ada dan berjalan. Dokumen `05-spesifikasi-nlp.md`
tetap tidak ada; panduan pelabelan ditulis ulang sebagai
`09-panduan-pelabelan.md`, tapi rencana alternatif kalau F1 macro di bawah 0,65
belum ada penggantinya.

### Hasil evaluasi pertama (254 label gold set, ronde 1)

**Sentimen — lolos gerbang.** F1 macro **0,7998**, akurasi 81,3%, dari 48 sampel
relevan di konteks utama.

| Kelas | F1 | Sampel |
|-------|-----|--------|
| Negatif | 1,000 | **3** |
| Netral | 0,526 | 5 |
| Positif | 0,873 | 40 |

Angka 0,7998 harus dibaca dengan dua peringatan:

1. **F1 negatif sempurna dari tiga sampel bukan pengukuran.** Ia menaikkan F1
   macro yang merata-ratakan tiga kelas dengan bobot sama. Tanpa kelas itu,
   rata-rata netral dan positif hanya **0,70** — masih lolos, tapi marginnya
   jauh lebih tipis daripada yang terlihat.
2. **Kesalahan utama model: 9 artikel positif ditebak netral.** Model cenderung
   meredam, bukan melebih-lebihkan. Untuk sistem peringatan dini itu arah yang
   relatif aman, tapi berarti angka positif di dashboard cenderung dikecilkan.

**Relevansi — sempat tidak terukur sama sekali, lalu diperbaiki.**

| Metrik | Model saja | Setelah pengetat |
|--------|-----------|------------------|
| Presisi | 46,6% | **75,4%** |
| Recall | 92,3% | 88,5% |
| F1 | 0,619 | **0,814** |
| Salah dianggap relevan | 55 dari 254 | **15** |
| Relevan yang terlewat | 4 dari 254 | 6 |

Sebelum diperbaiki, separuh artikel yang masuk grafik sebenarnya tidak membahas
konteksnya. Biayanya dua artikel relevan tambahan yang terlewat — pertukaran
yang sepadan, tapi perlu diingat: artikel yang terlewat hilang dari analisis
tanpa jejak, sedangkan artikel yang lolos keliru masih bisa dikoreksi admin
lewat halaman detail.

**Menaikkan ambang keyakinan tidak menolong.** Diuji 0,55 sampai 0,999: presisi
hanya bergerak 47,4% → 48,1%. Median keyakinan pada keputusan yang benar dan
yang salah sama-sama 1,000 — model sama yakinnya saat keliru.

Penyebabnya definisi, bukan kalibrasi: model menganggap artikel yang menyebut
Kendari sekali lewat sebagai relevan, sedangkan panduan pelabelan menghitungnya
tidak relevan (pertanyaan nomor 1).

**Sudah diperbaiki dengan pengetat berbasis frekuensi kata kunci.** Sepuluh
varian diuji terhadap 254 label manusia, dengan **separuh data ditahan dan tidak
pernah dilihat saat memilih**:

| Aturan | Presisi | Recall | F1 |
|--------|---------|--------|-----|
| Model apa adanya | 54,2% | 100% | 0,703 |
| Kata kunci di judul saja | 92,3% | 46,2% | 0,615 |
| Kata kunci di judul ATAU 400 huruf awal | 65,1% | 78,8% | 0,713 |
| **Kata kunci di judul ATAU ≥3× di isi** | **80,0%** | **92,3%** | **0,857** |
| Kata kunci di judul ATAU ≥4× di isi | 80,0% | 76,9% | 0,784 |

Varian ≥4× terlihat lebih baik pada separuh yang dipakai memilih (F1 0,830) tapi
lebih buruk pada data tahan (0,784) — overfit. Tanpa data tahan, varian yang
salah yang akan dipasang.

Hipotesis awal "kata kunci di paragraf pertama" juga kalah (0,713). Frekuensi
lebih menentukan daripada posisi.

Disetel lewat `RELEVANSI_MINIMAL_SEBUTAN`, bawaan 3. Konteks tanpa kata kunci
tidak diketatkan sama sekali.

**Bug yang ditemukan bersamaan:** `RELEVANSI_AMBANG_KEYAKINAN` ada di `.env`,
di `config/nlp.php`, di dokumen 02, dan ditampilkan di halaman detail artikel —
tapi tidak pernah diterapkan di mana pun. Relevansi diputuskan argmax murni.
Karena pengujian menunjukkan ambang tidak berpengaruh pada model ini, nilainya
dibiarkan tapi statusnya dicatat di sini agar tidak dikira sudah bekerja.

### Audit feed 30 media (tugas sprint 0)

27 dari 30 punya feed hidup, seluruhnya `/feed` bawaan WordPress kecuali
Telisik yang memakai `/feed/rss`. Daftarnya disimpan di `SumberFeedSeeder`.

| Media | Masalah | Jalur pengganti |
|-------|---------|-----------------|
| Tempo | Tidak ada feed yang bisa dipakai | Belum ada — lihat catatan Google News |
| Detikcom | Tidak ada feed yang bisa dipakai | Belum ada — lihat catatan Google News |
| Sibernas | Tidak ada feed di jalur lazim maupun tautan halaman depan | Portal pelaporan mandiri (sprint 5) |

**F-05 terpenuhi lewat jalur lain.** `news.google.com/robots.txt` melarang
seluruh path untuk `User-agent: *`, termasuk `/rss/search`, sehingga jalur
Google News tertutup selama kewajiban menghormati robots dipegang. Sumbernya
tetap ada di database tapi dinonaktifkan beserta alasannya.

Penggantinya: **feed milik media nasional sendiri, disaring kata kunci sebelum
artikel disimpan** — cara yang sudah diantisipasi dokumen 01 lampiran A catatan
1. Keduanya diuji dan tidak melarang bot ini:

| Sumber | Item per tarikan | Saringan |
|--------|------------------|----------|
| `rss.tempo.co/nasional` | 50 | `Kendari` |
| `news.detik.com/rss` | 100 | `Kendari` |

Penyaringan dilakukan dari judul dan ringkasan feed, bukan dari isi halaman:
mengunduh seratus artikel nasional untuk membuang sembilan puluh delapan justru
banjir yang mau dihindari. Feed yang seluruh isinya tersaring tidak dihitung
sebagai kegagalan — hari tanpa liputan Kendari itu wajar, dan menghitungnya
gagal akan menonaktifkan sumbernya setelah lima hari sepi.

Sibernas tetap tanpa feed; jalurnya portal pelaporan mandiri di sprint 5.

Temuan pengukuran yang mengubah nilai awal di dokumen:

| Nilai | Dokumen | Dipakai | Alasan |
|-------|---------|---------|--------|
| `DEDUP_AMBANG_SIMHASH` | 4 | 12 | Near-duplicate terukur 8–10 bit, berita berbeda 30–34 bit |
| `SENTIMEN_AMBANG_KEYAKINAN` | 0,60 | 0,90 | Sebaran keyakinan bimodal: 0,60–0,67 lalu kosong lalu ≥0,998 |
| `app.timezone` | Asia/Makassar | UTC | WITA menggeser setiap timestamp 8 jam; konversi lewat `App\Support\Waktu` |
