# SIMAK Kendari: Paket Spesifikasi

Sistem Monitoring dan Analisis Sentimen Media untuk Pemerintah Kota Kendari.

Versi dokumen: 1.6
Tanggal: Agustus 2026
Penyusun: pengembang tunggal
Status: siap dieksekusi

Perubahan 1.6: relevansi menjadi model yang dilatih sendiri, bukan angka yang disetel. Dokumen 10 masuk paket sebagai spesifikasi Laboratorium Model Relevansi: pengumpulan dataset, pelabelan, fine-tuning `apriandito/indobert-relevancy-classifier` dengan data lokal Kendari, evaluasi, versioning, promosi, dan rollback. Dua akibat yang harus dibaca bersamaan. Pertama, **analisis sentimen diblokir mulai 4 Agustus 2026** dan baru dibuka setelah ada model relevansi produksi yang lolos gerbang mutu. Kedua, `multilingual-e5-small` turun perannya menjadi khusus deteksi salinan, tidak lagi menentukan relevansi. Alasan dan cakupan perubahannya di dokumen 10 bagian 0.

Perubahan 1.5: alur relevansi dirombak lagi. Penilai relevansi berpindah dari classifier IndoBERT ke kemiripan makna `multilingual-e5-small`, dan endpoint `/relevancy` dihapus. Skor dihitung di PostgreSQL dari vektor yang sudah tersimpan, sehingga menyetel ambang tidak lagi memerlukan inferensi ulang seluruh korpus. Alurnya sekarang: berita masuk, e5-small, keputusan relevansi, lalu IndoBERT sentimen. Lihat dokumen 05 bagian 2.

Perubahan 1.4: rancangan relevansi dirombak. Tiga konteks pantauan disederhanakan menjadi satu konteks utama "Pemerintah Kota Kendari", relevansi menjadi klasifikasi biner tingkat artikel, dan Wali Kota serta pelayanan publik turun menjadi entitas dan topik. Dokumen `05-spesifikasi-nlp.md` akhirnya ditulis, menutup rujukan menggantung sejak versi 1.0. Rincian dan alasannya di dokumen 01 bagian 9, dokumen 05, dan sprint 6 pada dokumen 07.

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
| 05 | `05-spesifikasi-nlp.md` | Tugas relevansi dan sentimen, bentuk input model, gold set, metrik, dan rencana kalau F1 di bawah gerbang |
| 06 | `06-akses-dan-keamanan.md` | Matriks izin, policy, global scope, audit trail, kepatuhan |
| 07 | `07-roadmap.md` | Rencana enam sprint, definition of done, buffer, jalur keluar kalau mundur |
| 08 | `08-rangkuman-sprint.md` | Ringkasan satu halaman dokumen 07: tabel sembilan sprint, isi tiap sprint, gerbang, status |
| 09 | `09-panduan-pelabelan.md` | Aturan memutuskan relevan atau tidak relevan, kode alasan, cara kerja pelabel, ronde konsistensi |
| 10 | `10-spesifikasi-laboratorium-model-relevansi.md` | Laboratorium Model Relevansi: dataset, snapshot, fine-tuning IndoBERT, evaluasi, uji model, versioning, gerbang mutu yang memblokir sentimen |

Baca berurutan untuk pertama kali. Setelah itu dokumen 03 dan 04 yang paling sering Anda buka saat menulis kode.

Dokumen 10 adalah otoritas untuk segala hal tentang relevansi. Kalau isinya berbeda dengan dokumen lain, dokumen 10 yang benar dan dokumen lain yang belum sempat disesuaikan.

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
| Model sentimen | `apriandito/indobert-sentiment-classifier` | Terkondisi konteks, F1 macro terukur 0,7361 pada gold set Kendari, tidak perlu dilatih ulang |
| Model relevansi | `apriandito/indobert-relevancy-classifier`, di-fine-tune dengan dataset lokal Kendari | Cosine e5 berhenti di presisi 69,9%, dan yang menahannya adalah kualitas dataset. Laboratorium di dokumen 10 membangun pipeline datasetnya, dan fine-tuning adalah muaranya |
| Penilai kemiripan | `intfloat/multilingual-e5-small`, khusus deteksi salinan | Turun peran pada revisi 1.6. Tidak lagi menentukan relevansi. Lihat dokumen 10 bagian 0.2 |
| Gerbang mutu relevansi | Memblokir sentimen sampai model relevansi produksi lolos | Sentimen akurat atas artikel yang salah tetap salah. Diberlakukan 4 Agustus 2026, dashboard sentimen kosong sampai gerbangnya lulus. Lihat dokumen 10 bagian 12 |
| Ambang relevansi | Baris berversi di database, bukan `.env` | Ambang di `.env` tidak punya alasan, pemilik, dan tanggal. `.env` hanya nilai bootstrap darurat. Lihat dokumen 10 bagian 15.2 |
| Tingkat relevansi | Biner, per artikel, satu konteks utama | Satu artikel satu keputusan. Konteks jamak menaikkan beban pelabelan tiga kali tanpa menambah jawaban yang dibutuhkan dashboard |
| Konteks pantauan aktif | Satu: Pemerintah Kota Kendari | Wali Kota, OPD, dan pelayanan publik menjadi entitas dan topik, bukan konteks terpisah. Lihat dokumen 01 bagian 9 |
| Peran | Tiga: `superadmin`, `walikota`, `media` | Enum di kolom `users.role`, bukan paket permission |
| Panel | Satu aplikasi, tiga grup route | `/admin`, `/eksekutif`, `/portal` |

### Yang secara sadar TIDAK dipakai

- **Filament.** Menambah paradigma kedua (Livewire) di proyek yang dikerjakan satu orang.
- **Spatie Permission.** Berlebihan untuk tiga peran tetap. Tambahkan nanti kalau muncul kebutuhan izin granular.
- **Elasticsearch.** PostgreSQL cukup sampai jumlah artikel melewati sekitar 500 ribu baris.
- **GPU.** Inferensi IndoBERT di CPU cukup untuk volume satu kota, sekitar 300 artikel per hari. Fine-tuning relevansi juga dijalankan di CPU: 3.000 sampel dengan 3 epoch selesai dalam hitungan jam, dan itu berjalan di queue sehingga tidak ada yang menunggunya di depan layar.
- **Microservice terpisah untuk crawler.** Crawler jalan sebagai Laravel scheduled command.
- **BERTopic pada fase awal.** Volume data satu kota terlalu kecil untuk cluster yang stabil. Masuk daftar versi 2 di dokumen 07.
- **Konteks pantauan jamak.** Ditunda, bukan dibatalkan. Biayanya bukan inferensi melainkan pelabelan manusia, dan itu tidak bisa dipercepat dengan server lebih besar. Lihat dokumen 01 bagian 9.
- **LLM eksternal per artikel.** Biaya per artikel, kuota, latensi, dan keluaran yang berubah antar versi model. Lihat dokumen 05 bagian 2.

---

## Nama dan konvensi

- Nama produk: **SIMAK Kendari**
- Nama teknis repo: `simak`
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
