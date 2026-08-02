# SIMEDIA Kendari — Paket Spesifikasi

Sistem Monitoring dan Analisis Sentimen Media untuk Pemerintah Kota Kendari.

Versi dokumen: 1.2
Tanggal: Agustus 2026
Penyusun: pengembang tunggal
Status: siap dieksekusi

Perubahan 1.2: sembilan pertanyaan terbuka sudah dijawab Diskominfo dan jawabannya masuk dokumen 01 bagian 9. Konsekuensinya: daftar 30 media partner masuk sebagai lampiran A, tiga konteks pantauan awal ditetapkan, jembatan Google Form (F-53) dan notifikasi WhatsApp (F-41) dihapus dari lingkup, dan font ditetapkan IBM Plex Sans.

Perubahan 1.1: jalur pelaporan mandiri dirombak (form satu field dengan pratinjau), arsip bukti saat pelaporan, metrik proporsi sumber data, catatan crawler untuk Blogger dan situs React. Rincian per dokumen ada di changelog masing-masing.

---

## Daftar dokumen

| No | Dokumen | Isi |
|----|---------|-----|
| 01 | `01-PRD.md` | Latar belakang, tujuan, persona, ruang lingkup, kebutuhan fungsional, metrik keberhasilan, risiko |
| 02 | `02-spesifikasi-teknis.md` | Arsitektur, stack, struktur folder, kontrak layanan NLP, queue, deployment |
| 03 | `03-skema-database.md` | Seluruh tabel, kolom, tipe, index, relasi, aturan integritas |
| 04 | `04-spesifikasi-ui.md` | Design system Tailwind, daftar komponen shadcn, inventaris halaman per peran, deskripsi wireframe |
| 05 | `05-spesifikasi-nlp.md` | Pipeline sentimen, deduplikasi, gold set, metrik evaluasi, penanganan ketidakpastian |
| 06 | `06-akses-dan-keamanan.md` | Matriks izin, policy, global scope, audit trail, kepatuhan |
| 07 | `07-roadmap.md` | Rencana enam sprint, definition of done, buffer, jalur keluar kalau mundur |
| 08 | `08-rangkuman-sprint.md` | Ringkasan satu halaman dokumen 07: tabel tujuh sprint, isi tiap sprint, gerbang, status |

Baca berurutan untuk pertama kali. Setelah itu dokumen 03 dan 04 yang paling sering Anda buka saat menulis kode.

---

## Keputusan teknis yang sudah dikunci

Daftar ini menutup perdebatan. Kalau nanti Anda tergoda mengubah salah satunya di tengah jalan, baca kolom alasan dulu.

| Keputusan | Pilihan | Alasan |
|-----------|---------|--------|
| Framework backend | Laravel 13 | Keahlian utama pengembang |
| Frontend | Inertia + Vue 3 + TypeScript | Satu paradigma untuk semua peran, termasuk admin |
| Starter kit | Laravel Vue Starter Kit | Sudah membawa Fortify, 2FA, layout sidebar, dark mode, shadcn-vue |
| Styling | Tailwind CSS 4 | Bawaan starter kit, konfigurasi CSS-first |
| Font | IBM Plex Sans, dari npm dan dilayani sendiri | Permintaan Diskominfo. Tidak lewat Google Fonts CDN agar tidak bergantung akses keluar server |
| Komponen UI | shadcn-vue | Bawaan starter kit, kode ada di repo sendiri sehingga bisa dimodifikasi |
| Grafik | ECharts via `vue-echarts` | Satu-satunya library yang punya word cloud, heatmap, treemap, dan time-series zoom sekaligus |
| Database | PostgreSQL 16 + pgvector | Deduplikasi semantik dan window function untuk agregasi waktu |
| Queue | Laravel Queue + Redis | Analisis NLP harus asinkron |
| Layanan NLP | FastAPI + IndoBERT (proses terpisah) | Model hanya tersedia di ekosistem Python |
| Model sentimen | `apriandito/indobert-sentiment-classifier` | Terkondisi konteks, F1 macro 0,856, tidak perlu dilatih ulang |
| Model relevansi | IndoBERT-Relevancy dari penulis yang sama (konfirmasi nama repo di HuggingFace) | Menyaring artikel tidak relevan sebelum analisis sentimen |
| Peran | Tiga: `superadmin`, `walikota`, `media` | Enum di kolom `users.role`, bukan paket permission |
| Panel | Satu aplikasi, tiga grup route | `/admin`, `/eksekutif`, `/portal` |

### Yang secara sadar TIDAK dipakai

- **Filament.** Menambah paradigma kedua (Livewire) di proyek yang dikerjakan satu orang.
- **Spatie Permission.** Berlebihan untuk tiga peran tetap. Tambahkan nanti kalau muncul kebutuhan izin granular.
- **Elasticsearch.** PostgreSQL cukup sampai jumlah artikel melewati sekitar 500 ribu baris.
- **GPU.** Inferensi IndoBERT di CPU cukup untuk volume satu kota. Lihat dokumen 05.
- **Microservice terpisah untuk crawler.** Crawler jalan sebagai Laravel scheduled command.
- **BERTopic pada fase awal.** Volume data satu kota terlalu kecil untuk cluster yang stabil. Lihat dokumen 05.

---

## Nama dan konvensi

- Nama produk: **SIMEDIA Kendari**
- Nama teknis repo: `simedia`
- Bahasa untuk nama tabel dan kolom database: **Bahasa Indonesia**
- Bahasa untuk nama kelas, method, dan variabel PHP/TypeScript: **Bahasa Inggris**
- Bahasa antarmuka pengguna: **Bahasa Indonesia**
- Zona waktu aplikasi: `Asia/Makassar` (WITA, UTC+8). Kendari ada di WITA, bukan WIB. Simpan semua timestamp sebagai UTC di database, konversi di layer tampilan.

Alasan campuran bahasa pada database dan kode: nama tabel akan dibaca oleh staf Diskominfo saat audit atau serah terima, sedangkan nama kelas mengikuti konvensi Laravel agar tooling dan dokumentasi framework tetap masuk akal.

---

## Cara memakai paket ini

1. Baca dokumen 01 sampai selesai. Bagian 9 berisi jawaban Diskominfo atas sembilan pertanyaan pembuka beserta konsekuensinya, dan lampiran A berisi daftar 30 media partner.
2. Eksekusi dokumen 07 sprint demi sprint.
3. Setiap kali menambah tabel atau kolom yang tidak ada di dokumen 03, catat di bagian changelog dokumen tersebut. Skema yang menyimpang dari dokumen tanpa catatan adalah sumber kebingungan enam bulan dari sekarang.
