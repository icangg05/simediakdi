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

## Posisi saat ini

Sprint 0 sampai 3 selesai, kecuali dua hal di sprint 3 yang butuh pekerjaan
manusia: **400 baris gold set belum dilabeli**, dan karena itu **ambang
keyakinan belum dikalibrasi dari data**. Ruang kerja pelabelan dan panduannya
sudah siap.

Layanan NLP (`nlp/`) sudah ada dan berjalan. Dokumen `05-spesifikasi-nlp.md`
tetap tidak ada; panduan pelabelan ditulis ulang sebagai
`09-panduan-pelabelan.md`, tapi rencana alternatif kalau F1 macro di bawah 0,65
belum ada penggantinya.

### Audit feed 30 media (tugas sprint 0)

27 dari 30 punya feed hidup, seluruhnya `/feed` bawaan WordPress kecuali
Telisik yang memakai `/feed/rss`. Daftarnya disimpan di `SumberFeedSeeder`.

| Media | Masalah | Jalur pengganti |
|-------|---------|-----------------|
| Tempo | Tidak ada feed yang bisa dipakai | Belum ada — lihat catatan Google News |
| Detikcom | Tidak ada feed yang bisa dipakai | Belum ada — lihat catatan Google News |
| Sibernas | Tidak ada feed di jalur lazim maupun tautan halaman depan | Portal pelaporan mandiri (sprint 5) |

**F-05 belum terpenuhi dan butuh keputusan.** `news.google.com/robots.txt`
melarang seluruh path untuk `User-agent: *`, termasuk `/rss/search`. Dokumen 02
bagian 8 dan dokumen 06 mewajibkan menghormati robots.txt, jadi jalur Google
News ditutup selama aturan itu dipegang. Sumbernya sudah dibuat tapi
dinonaktifkan beserta alasannya.

Tiga pilihan, semuanya perlu diputuskan manusia:

1. Terima F-05 tidak terpenuhi. 27 feed langsung sudah menutup seluruh daftar
   media partner; yang hilang hanya media di luar daftar.
2. Tarik feed kanal daerah milik Tempo dan Detik sendiri, lalu saring dengan
   kata kunci sebelum disimpan — dokumen 01 lampiran A catatan 1 sudah
   mengantisipasi cara ini.
3. Kecualikan news.google.com dari pemeriksaan robots. Ini melanggar aturan
   yang ditulis sendiri di dokumen 02, jadi harus jadi keputusan sadar dan
   tercatat, bukan diam-diam.

Temuan pengukuran yang mengubah nilai awal di dokumen:

| Nilai | Dokumen | Dipakai | Alasan |
|-------|---------|---------|--------|
| `DEDUP_AMBANG_SIMHASH` | 4 | 12 | Near-duplicate terukur 8–10 bit, berita berbeda 30–34 bit |
| `SENTIMEN_AMBANG_KEYAKINAN` | 0,60 | 0,90 | Sebaran keyakinan bimodal: 0,60–0,67 lalu kosong lalu ≥0,998 |
| `app.timezone` | Asia/Makassar | UTC | WITA menggeser setiap timestamp 8 jam; konversi lewat `App\Support\Waktu` |
