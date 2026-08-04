# 02: Spesifikasi Teknis

SIMEDIA Kendari | Versi 1.0

---

## 1. Gambaran arsitektur

Dua proses, satu database.

```
                    ┌──────────────────────────────┐
                    │  Sumber berita               │
                    │  RSS · scraping · GNews RSS  │
                    └──────────────┬───────────────┘
                                   │ HTTP
                    ┌──────────────▼───────────────┐
                    │  APLIKASI LARAVEL            │
                    │                              │
                    │  Scheduler ─ crawl commands  │
                    │  Queue worker ─ jobs         │
                    │  HTTP ─ Inertia + Vue        │
                    └───┬──────────────────────┬───┘
                        │ HTTP internal        │ SQL
             ┌──────────▼───────────┐   ┌──────▼──────────┐
             │ LAYANAN NLP          │   │ PostgreSQL 16   │
             │ FastAPI + IndoBERT   │   │ + pgvector      │
             │ port 8001, localhost │   └─────────────────┘
             └──────────────────────┘
                                         ┌─────────────────┐
                                         │ Redis           │
                                         │ queue + cache   │
                                         └─────────────────┘
```

Layanan NLP hanya menerima koneksi dari localhost. Tidak ada endpoint publik, tidak ada autentikasi selain binding ke `127.0.0.1`. Kalau nanti dipisah ke server lain, tambahkan shared secret di header.

## 2. Stack lengkap

### Backend

| Komponen | Versi | Catatan |
|----------|-------|---------|
| PHP | 8.3 atau lebih baru | |
| Laravel | 13.x | |
| Inertia | 3.x | Bawaan starter kit |
| Laravel Fortify | terbaru | Bawaan starter kit, sudah termasuk 2FA |
| PostgreSQL | 16 | Ekstensi `vector` dan `pg_trgm` wajib diaktifkan |
| Redis | 7 | Queue driver dan cache |
| Laravel Horizon | terbaru | Monitoring queue. Satu halaman siap pakai, hemat banyak waktu debugging |

### Paket Composer

| Paket | Kegunaan |
|-------|----------|
| `spatie/laravel-activitylog` | Audit trail untuk F-45 |
| `spatie/laravel-simple-excel` atau `maatwebsite/excel` | Ekspor Excel F-34 |
| `barryvdh/laravel-dompdf` | Ekspor PDF F-35 |
| `fivefilters/readability.php` | Ekstraksi isi artikel dari HTML |
| `guzzlehttp/guzzle` | HTTP client untuk crawler |
| `pgvector/pgvector-php` | Tipe kolom vector di Eloquent |
| `laravel/pulse` | Opsional. Monitoring aplikasi ringan |

Untuk RSS, gunakan `SimpleXMLElement` bawaan PHP. Paket pihak ketiga tidak memberi nilai tambah dan menambah dependensi yang harus dirawat.

### Frontend

| Komponen | Versi | Catatan |
|----------|-------|---------|
| Vue | 3.x Composition API | |
| TypeScript | 5.x | |
| Tailwind CSS | 4.x | Konfigurasi CSS-first lewat `@theme` |
| shadcn-vue | terbaru | Komponen di-copy ke `resources/js/components/ui` |
| `vue-echarts` + `echarts` | 5.x | Semua grafik |
| `@tanstack/vue-table` | 8.x | Mesin tabel untuk komponen DataTable |
| `lucide-vue-next` | terbaru | Ikon. Bawaan starter kit |
| `date-fns` | 4.x | Format tanggal lokal Indonesia |
| `vee-validate` + `zod` | terbaru | Validasi form sisi klien |

Jangan memasang library grafik kedua. Kalau ECharts terasa berat untuk satu grafik sederhana, tetap pakai ECharts. Konsistensi tema lebih berharga daripada beberapa kilobyte.

### Layanan NLP

| Komponen | Versi | Catatan |
|----------|-------|---------|
| Python | 3.11 | |
| FastAPI + Uvicorn | terbaru | Satu worker cukup, model dimuat sekali di startup |
| `transformers` | terbaru | Inferensi sentimen dan relevansi, sekaligus `Trainer` untuk fine-tuning |
| `torch` | versi CPU-only | Jangan pasang build CUDA, ukurannya berlipat tanpa manfaat |
| `sentence-transformers` | terbaru | `multilingual-e5-small`, sejak revisi 1.6 khusus deduplikasi |
| `datasets` | terbaru | Membaca dataset hasil ekspor Laravel saat fine-tuning |
| `scikit-learn` | terbaru | Precision, recall, F1, dan confusion matrix pada evaluasi |
| `accelerate` | terbaru | Dibutuhkan `Trainer`, meski hanya untuk CPU |

Detail model dan pipeline ada di dokumen 05. Pipeline pelatihan relevansi ada
di dokumen 10 bagian 10 dan 19.

Versi keempat paket pelatihan itu dikunci di `requirements.txt` dan ikut
disimpan sebagai artefak tiap training run. Model yang tidak bisa dimuat ulang
karena versi `transformers` berubah adalah model yang hilang, dan itu baru
ketahuan berbulan-bulan kemudian saat rollback dibutuhkan.

## 3. Struktur folder

Bagian yang perlu ditambahkan di luar starter kit.

```
app/
├── Console/Commands/
│   ├── CrawlFeeds.php                  # dipanggil scheduler
│   ├── CrawlGoogleNews.php
│   ├── HitungRingkasanHarian.php       # agregasi untuk dashboard
│   ├── HitungKataKunciPeriode.php
│   ├── EvaluasiModel.php               # jalankan gold set, simpan metrik
│   └── PeriksaAturanAlert.php
├── Enums/
│   ├── PeranPengguna.php
│   ├── LabelSentimen.php
│   ├── StatusVerifikasi.php
│   ├── StatusDedup.php
│   ├── TipeSumber.php
│   └── TierMedia.php
├── Http/
│   ├── Controllers/
│   │   ├── Admin/                      # 13 controller, lihat dokumen 04
│   │   ├── Eksekutif/                  # 5 controller
│   │   └── Portal/                     # 4 controller
│   ├── Middleware/
│   │   ├── PastikanPeran.php           # middleware role:superadmin
│   │   └── TolakSemuaTulisan.php       # sabuk pengaman peran walikota
│   ├── Requests/
│   └── Resources/                      # bentuk data yang dikirim ke Inertia
├── Jobs/
│   ├── AmbilIsiArtikel.php
│   ├── HitungEmbedding.php
│   ├── PeriksaDuplikat.php
│   ├── AnalisisRelevansi.php
│   ├── AnalisisSentimen.php
│   ├── EkstrakEntitas.php
│   ├── ArsipkanBuktiPemuatan.php       # teks + screenshot saat laporan dikonfirmasi (F-52)
│   ├── KirimAlert.php
│   └── Relevance/                      # 14 job laboratorium, dokumen 10 bagian 17.4
├── Models/
├── Policies/
├── Services/
│   ├── Crawler/
│   │   ├── PembacaRss.php
│   │   ├── PembacaScrape.php
│   │   ├── EkstraktorArtikel.php
│   │   └── NormalisasiUrl.php
│   ├── Nlp/
│   │   ├── KlienNlp.php                # satu-satunya tempat memanggil FastAPI
│   │   ├── PenyaringKataKunci.php      # sinyal penjelas + skor prioritas antrean
│   │   ├── JendelaKonteks.php          # potongan kalimat sekitar sebutan Pemkot
│   │   └── DTO/
│   ├── Relevance/                      # 10 service, lihat dokumen 10 bagian 17.3
│   ├── Dedup/
│   │   ├── PenghitungSimhash.php
│   │   └── PencariDuplikat.php
│   ├── Agregasi/
│   │   ├── RingkasanHarian.php
│   │   └── SkorLonjakan.php
│   └── Alert/
│       └── PengirimTelegram.php
└── Support/

resources/js/
├── components/
│   ├── ui/                             # shadcn-vue, jangan diedit kecuali perlu
│   ├── chart/                          # wrapper ECharts bertema
│   │   ├── BaseChart.vue
│   │   ├── ChartTrenVolume.vue
│   │   ├── ChartTrenSentimen.vue
│   │   ├── ChartDonatSentimen.vue
│   │   ├── ChartPeringkatMedia.vue
│   │   └── ChartWordCloud.vue
│   ├── data-table/                     # tabel generik, dipakai 9 halaman
│   │   ├── DataTable.vue
│   │   ├── DataTableToolbar.vue
│   │   ├── DataTablePagination.vue
│   │   └── DataTableFacetedFilter.vue
│   └── domain/                         # komponen khusus domain
│       ├── BadgeSentimen.vue
│       ├── KartuArtikel.vue
│       ├── ProgresKontrak.vue
│       └── PenandaPerluReview.vue
├── composables/
│   ├── useFilterTabel.ts
│   ├── useTemaChart.ts                 # baca token Tailwind untuk ECharts
│   └── useFormatAngka.ts
├── layouts/
│   ├── LayoutAdmin.vue
│   ├── LayoutEksekutif.vue
│   └── LayoutPortal.vue
├── pages/
│   ├── admin/
│   ├── eksekutif/
│   └── portal/
└── types/
    └── index.d.ts                      # tipe hasil dari Resources

nlp/                                    # proyek Python, di dalam repo yang sama
├── main.py
├── models.py
├── relevancy/                          # pelatihan dan inferensi relevansi
│   ├── inference.py                    # muat model aktif, prediksi batch
│   ├── training.py                     # fine-tuning, checkpoint, artefak
│   ├── evaluation.py                   # metrik, confusion matrix, per kelompok
│   └── registry.py                     # pointer model aktif, warmup, rollback
├── requirements.txt
└── README.md

storage/app/private/relevance-models/   # artefak model, di luar public/
└── {versi}/                            # weights, tokenizer, config, metrics
```

Direktori artefak berada di `storage/app/private/`, bukan `public/`. Bobot model
bukan berkas yang layak diambil siapa pun yang menebak URL-nya, dan sekali ia
bisa diunduh publik, tidak ada cara menariknya kembali.

Menyimpan proyek Python di dalam repo yang sama sengaja dipilih. Dua repo untuk satu orang berarti dua kali kerja saat rilis dan risiko versi model tidak sinkron dengan kode yang memanggilnya.

## 4. Komponen DataTable generik

Ini investasi terpenting di sisi frontend, dan alasan keputusan tanpa Filament tetap bisa dikerjakan sendiri.

Tulis satu komponen `DataTable.vue` di sprint 1 sebelum halaman CRUD mana pun. Kontraknya:

```ts
interface DataTableProps<T> {
  kolom: KolomDefinisi<T>[]
  data: T[]
  meta: PaginasiMeta          // dari paginator Laravel
  filter?: FilterDefinisi[]   // faceted filter, dropdown multi-pilih
  pencarian?: boolean
  aksiBaris?: AksiBaris<T>[]
  urlBasis: string            // untuk router.get saat sort atau filter berubah
}
```

Aturan penting: **seluruh filter, sort, dan paginasi dikerjakan server**, bukan di klien. Kirim state filter sebagai query string, baca di controller, kembalikan lewat Inertia. Alasannya bukan performa saja. Kalau state ada di URL, admin bisa menyimpan bookmark hasil filternya dan Anda bisa mereproduksi laporan bug dari satu tautan.

Sembilan halaman akan memakai komponen ini: artikel, media, sumber feed, kontrak, verifikasi pemuatan, entitas, pengguna, log crawl, riwayat alert. Waktu yang dihemat sekitar dua minggu.

## 5. Alur pemrosesan satu artikel

Rantai job berurutan. Setiap job punya `tries = 3` dan backoff eksponensial.

```
CrawlFeeds (scheduler, per sumber)
    ↓ untuk setiap item baru
1. Buat baris artikel sebagai kandidat, status = mentah
    ↓                        judul, url, tanggal, excerpt, tag, kategori
2. AmbilIsiArtikel        → unduh halaman, bersihkan HTML, ekstrak isi, hitung hash
    ↓
3. HitungEmbedding        → panggil /embed dengan isi penuh → embedding
    ↓                        satu vektor, hanya untuk deteksi salinan
4. PeriksaDuplikat        → hash cocok? simhash dekat? cosine di atas ambang?
    ↓  jika duplikat → tandai salinan, tautkan ke induk, RANTAI BERHENTI
    ↓  jika asli → lanjut
5. ImportArticleToRelevanceDataset
    ↓                     → artikel masuk sebagai kandidat dataset relevansi
    ↓                        status_label = belum_dilabeli, priority_score dihitung
    ↓
6. AnalisisRelevansi      → panggil /relevancy/predict dengan model produksi
    ↓  tidak ada model produksi → status = model_belum_lulus_gate, RANTAI BERHENTI
    ↓  label tidak_relevan      → status = tidak_relevan, RANTAI BERHENTI
    ↓  confidence di pita review → status = perlu_review, RANTAI BERHENTI
    ↓  label relevan dan gate passed → lanjut
7. AnalisisSentimen       → panggil /sentiment untuk konteks utama
    ↓                        job ini memeriksa ulang gate sebelum bekerja
8. EkstrakEntitas         → kata kunci dan entitas
    ↓
9. status = selesai
```

Langkah 6 berjalan sekali per artikel, bukan sekali untuk setiap konteks aktif.
Sejak versi 1.4 hanya ada satu konteks aktif dan relevansi menjadi keputusan
tingkat artikel (dokumen 01 bagian 9). Kalau nanti ada konteks kedua, ia
menambah baris sentimen, bukan baris relevansi.

**Relevansi kembali memakai model tersendiri sejak revisi 1.6.** Penilainya
adalah `apriandito/indobert-relevancy-classifier` yang di-fine-tune dengan
dataset lokal, dipanggil lewat `POST /relevancy/predict`. Cosine e5 tidak lagi
menentukan relevansi; `multilingual-e5-small` tinggal mengerjakan deteksi
salinan. Alasan perpindahannya di dokumen 10 bagian 0.

**Sampai ada model relevansi produksi yang lolos gerbang mutu, rantai ini
berhenti di langkah 6.** Artikel tetap dikumpulkan, tetap dideduplikasi, dan
tetap masuk dataset untuk dilabeli, tetapi tidak ada satu pun yang sampai ke
langkah 7. Itu bukan kegagalan melainkan perilaku yang diminta dokumen 10
bagian 1.1, dan `AnalisisSentimen` memeriksanya sendiri sekali lagi supaya
dispatch yang tidak sengaja pun tetap tertolak.

Langkah 5 sengaja ditaruh sebelum penilaian, bukan sesudah. Artikel yang belum
pernah dinilai model apa pun justru sampel yang paling dibutuhkan pelabel, dan
kalau impor dataset menunggu hasil prediksi, tidak akan ada satu pun kandidat
yang masuk selama belum ada model.

Artikel yang dinyatakan tidak relevan tetap disimpan lengkap. Ia tidak masuk
dashboard, tapi dipakai untuk audit crawler, hard negative, dan mengukur berapa
banyak kandidat yang tersaring. Keputusan retensi terpisah dari hasil model.

Alasan urutan ini: deduplikasi ditaruh sebelum analisis agar Anda tidak membayar biaya inferensi untuk rilis Antara yang sama sepuluh kali. Kalau 40% berita adalah salinan, Anda menghemat 40% waktu CPU.

Alasan relevansi sebelum sentimen: model sentimen akan tetap mengeluarkan label untuk artikel yang tidak relevan, dan labelnya akan masuk ke agregasi lalu mengotori grafik. Saringan relevansi membuang itu lebih dulu.

Antrian queue dipisah tiga nama agar crawler tidak terhalang analisis:

| Queue | Isi | Worker |
|-------|-----|--------|
| `crawl` | AmbilIsiArtikel | 3 proses |
| `nlp` | Embedding, relevansi, sentimen, entitas | 1 proses. Jangan lebih, model hanya dimuat sekali di layanan Python |
| `default` | Alert, ekspor, notifikasi | 1 proses |

## 6. Kontrak layanan NLP

Base URL `http://127.0.0.1:8001`. Seluruh pemanggilan dari Laravel hanya lewat `App\Services\Nlp\KlienNlp`. Tidak ada tempat lain di aplikasi yang boleh memanggil URL ini.

### GET /health

```json
{ "status": "ok", "model_sentimen": "apriandito/indobert-sentiment-classifier",
  "model_embedding": "intfloat/multilingual-e5-small",
  "model_relevansi_aktif": "simedia-relevancy-v1.3",
  "model_relevansi_base": "apriandito/indobert-relevancy-classifier",
  "model_relevansi_dimuat": true, "gerbang_mutu": "passed",
  "versi": "3.0.0" }
```

Tiga model sejak revisi 1.6. `model_relevansi_aktif` bernilai null selama belum
ada model yang dipromosikan, dan itu keadaan normal di awal, bukan galat.

Dipanggil scheduler tiap 5 menit. Kalau gagal tiga kali berturut-turut, kirim alert ke admin.

Respons `model_relevansi_dimuat: false` padahal ada model produksi berarti
gerbang mutu dicabut otomatis, sesuai dokumen 10 bagian 12.6.

### POST /embed

```json
{ "teks": ["judul dan isi artikel 1", "artikel 2"] }
```

```json
{ "embedding": [[0.01, -0.23, ...], [...]], "dimensi": 384 }
```

Maksimal 32 teks per permintaan.

### Kelompok /relevancy/*

Kembali ada sejak revisi 1.6, dengan bentuk yang berbeda dari versi 1.0. Sekarang
bukan satu endpoint inferensi melainkan sembilan endpoint yang juga melayani
pelatihan, evaluasi, dan pergantian model aktif.

| Endpoint | Kegunaan |
|---|---|
| `POST /relevancy/predict` | Inferensi batch, dipakai `AnalisisRelevansi` dan tab Uji Model |
| `POST /relevancy/training-runs` | Mulai fine-tuning dari dataset yang diekspor Laravel |
| `GET /relevancy/training-runs/{id}` | Progres, epoch, loss, metrik validation |
| `POST /relevancy/training-runs/{id}/cancel` | Batalkan pelatihan yang sedang jalan |
| `POST /relevancy/evaluate` | Evaluasi model pada test set snapshot terkunci |
| `POST /relevancy/models/{versi}/warmup` | Muat kandidat ke memori sebelum dipromosikan |
| `POST /relevancy/models/{versi}/activate` | Ganti pointer model aktif secara atomik |
| `POST /relevancy/models/{versi}/rollback` | Kembalikan ke versi sebelumnya |

Skema permintaan dan responsnya lengkap di dokumen 10 bagian 19. Jangan menulis
ulang di sini, cukup satu tempat.

Tiga aturan yang berlaku khusus untuk kelompok ini:

1. **`activate` hanya boleh dipanggil `RelevanceModelPromotionService`**, setelah
   gerbang mutu lulus. Tidak dari controller, tidak dari command, tidak manual.
2. **Endpoint pelatihan tidak boleh dijangkau dari luar localhost.** Ia menerima
   path direktori dan menjalankan proses panjang, dan keduanya bukan hal yang
   pantas diterima dari jaringan.
3. **Path artefak divalidasi terhadap direktori dasar** sebelum dipakai, supaya
   `../` di nama versi tidak berubah menjadi penulisan berkas di tempat lain.

Berbeda dengan skor cosine yang digantikannya, `probabilitas_relevan` dari
endpoint ini **memang probabilitas** keluaran softmax, jadi menampilkannya
sebagai persentase keyakinan tidak lagi menyesatkan. Kalibrasinya tetap perlu
diperiksa: model yang selalu menjawab 0,99 tetap salah meski formatnya benar.

Sinyal judul, tag, dan alias tetap dihitung di sisi Laravel oleh
`PenyaringKataKunci` lalu disimpan ke `sinyal_relevansi`. Sejak revisi 1.6 ia
tidak lagi mengetatkan keputusan, hanya menjelaskannya ke admin dan memberi
skor prioritas antrean pelabelan.

### POST /sentiment

```json
{ "pasangan": [ { "id": 101, "konteks": "Pemerintah Kota Kendari", "teks": "..." } ] }
```

```json
{ "hasil": [ {
    "id": 101,
    "label": "negatif",
    "skor": { "negatif": 0.81, "netral": 0.15, "positif": 0.04 },
    "keyakinan": 0.81,
    "model_versi": "indobert-sentiment-1.0"
} ] }
```

Aturan yang berlaku di kedua sisi:

- `id` adalah `artikel_id`, dikirim balik apa adanya supaya Laravel bisa memetakan hasil tanpa bergantung pada urutan array.
- Batas 16 pasangan per permintaan, sesuai batch size model.
- Timeout klien 120 detik. Kalau layanan lambat, job akan di-retry, bukan gagal permanen.
- Layanan NLP tidak menyentuh database. Ia menerima teks dan mengembalikan angka. Semua penyimpanan dikerjakan Laravel. Aturan ini menjaga satu sumber kebenaran dan membuat layanan Python bisa dimatikan kapan saja tanpa risiko.

## 7. Jadwal scheduler

| Perintah | Frekuensi | Catatan |
|----------|-----------|---------|
| `crawl:feeds` | Tiap 15 menit | Menghormati `interval_menit` per sumber |
| `crawl:google-news` | Tiap jam | |
| `nlp:health` | Tiap 5 menit | |
| `hitung:ringkasan-harian` | Tiap 10 menit | Perbarui tabel ringkasan hari ini |
| `hitung:kata-kunci` | Tiap jam | |
| `alert:periksa` | Tiap 15 menit | |
| `evaluasi:model` | Mingguan, Senin 02.00 | Jalankan gold set |
| `relevance:recalculate-priority` | Harian 03.00 | Urutkan ulang antrean pelabelan, dokumen 10 bagian 8 |
| `relevance:audit-production` | Mingguan, Senin 03.00 | Sampling audit produksi, dokumen 10 bagian 22.1 |
| `relevance:health` | Tiap 15 menit | Model produksi masih termuat dan checksum artefaknya cocok. Gagal berarti gerbang dicabut |
| `kontrak:periksa-tenggat` | Harian 07.00 | F-26 |
| `db:backup` | Harian 01.00 | Simpan 14 hari terakhir, salin satu ke penyimpanan luar |

Gunakan `withoutOverlapping()` pada seluruh perintah crawl dan agregasi.

## 8. Deployment

### Spesifikasi server minimum

| Sumber daya | Ukuran | Alasan |
|-------------|--------|--------|
| vCPU | 4 | 2 untuk PHP-FPM dan worker, 2 untuk inferensi IndoBERT |
| RAM | 8 GB | Dua model inferensi sekitar 2,5 GB, PostgreSQL 2 GB, sisanya PHP dan Redis |
| Disk | 120 GB SSD | Database, isi artikel, backup 14 hari, dan artefak model relevansi |
| Bandwidth | Wajar | Crawling 300 artikel per hari tidak berat |

Kalau anggaran ketat, 4 GB RAM masih jalan dengan syarat layanan NLP dijalankan sebagai batch berjadwal, bukan proses yang selalu hidup. Konsekuensinya sentimen baru muncul dengan jeda sampai satu jam.

**Fine-tuning relevansi tidak muat di server ini.** Angka pada revisi pertama
catatan ini salah: ia mengasumsikan IndoBERT base, sedangkan checkpoint yang
dipakai ternyata **large**, 24 layer dan hidden 1024, sekitar 335 juta
parameter. Dengan bobot, gradien, dan state Adam, pelatihan menuntut 8 sampai
10 GB, dan itu di atas dua model inferensi yang sudah hidup.

Jadi jalurnya satu: **latih di mesin pengembangan, salin direktori artefaknya
ke server.** Server produksi hanya perlu memuat hasilnya, bukan
menghasilkannya, dan untuk itu 8 GB cukup.

Dua penghematan yang tetap berlaku di mesin mana pun:

1. Panjang 256 token, bukan 512. Input relevansi sudah berupa jendela konteks
   terfokus, jadi separuh anggaran token itu tidak kehilangan apa pun.
2. Batch 4 dengan `gradient_accumulation` 4. Batch efektifnya tetap 16.

Keduanya menjadi preset di `config/relevance.php`, bukan hardcode.

Setiap versi model menempati sekitar 500 MB. Sepuluh versi berarti 5 GB, dan
itu alasan disk naik ke 120 GB. Retensi artefak diatur di dokumen 10 bagian
15.4, dengan satu pengecualian yang tidak boleh dilanggar: model yang pernah
menjadi produksi tidak pernah dihapus otomatis, karena itulah target rollback.

### Susunan proses

Pakai Docker Compose atau supervisor langsung. Untuk satu orang, supervisor lebih sederhana untuk didebug.

```
nginx           → PHP-FPM (aplikasi Laravel)
php-fpm         → 4 worker
supervisor:
  ├── queue-crawl   (3 proses, queue=crawl)
  ├── queue-nlp     (1 proses, queue=nlp)
  ├── queue-default (1 proses, queue=default)
  ├── horizon       (opsional, ganti tiga di atas)
  └── nlp-service   (uvicorn, 1 worker, port 8001)
cron            → schedule:run tiap menit
postgresql
redis
```

### Variabel lingkungan tambahan

```
APP_TIMEZONE=Asia/Makassar

NLP_BASE_URL=http://127.0.0.1:8001
NLP_TIMEOUT=120
NLP_BATCH_SIZE=16

SENTIMEN_AMBANG_KEYAKINAN=0.60
DEDUP_AMBANG_COSINE=0.92
DEDUP_AMBANG_SIMHASH=4

RELEVANSI_ARTEFAK_PATH=/var/www/simedia/storage/app/private/relevance-models
RELEVANSI_TRAINING_SECRET=
RELEVANSI_TRAINING_TIMEOUT=1800

CRAWL_USER_AGENT="SimediaKendariBot/1.0 (+https://simedia.kendarikota.go.id/bot)"
CRAWL_DELAY_MS=1500
CRAWL_TIMEOUT=20

TELEGRAM_BOT_TOKEN=
TELEGRAM_CHAT_ID=
```

Ambang keyakinan ditaruh di environment, bukan hardcode, karena Anda akan menyetelnya setelah gold set jadi dan tidak akan mau deploy ulang untuk mengubah satu angka.

Empat catatan, jangan dihapus:

- **Ambang relevansi tidak ada lagi di sini sejak revisi 1.6.** Ia berpindah ke tabel `versi_threshold_relevansi` sebagai baris berversi yang punya alasan, pemilik, dan tanggal aktivasi. `RELEVANSI_AMBANG_ATAS`, `RELEVANSI_AMBANG_BAWAH`, dan `RELEVANSI_MINIMAL_SEBUTAN` dihapus. Alasannya di dokumen 05 bagian 5.1.
- **Versi konteks juga berpindah ke database**, ke tabel `versi_konteks_relevansi`. `RELEVANSI_KONTEKS_VERSI` dihapus. Aturannya tetap sama dan justru menguat: perubahan definisi konteks mengubah gerbang mutu menjadi `needs_review` sampai model dievaluasi ulang.
- `RELEVANSI_AMBANG_KEYAKINAN` dihapus bersama model relevansi lama. Nilai itu terbukti tidak berpengaruh apa pun: diuji 0,55 sampai 0,999 dan presisi hanya bergerak 47,4% ke 48,1%, karena model lama sama yakinnya saat benar maupun saat keliru.
- **`RELEVANSI_TRAINING_SECRET` wajib diisi sebelum endpoint pelatihan dipakai.** Ia dikirim sebagai header pada setiap permintaan ke kelompok `/relevancy/*` dan diperiksa layanan Python. Binding ke `127.0.0.1` sudah menutup sebagian besar risiko, tetapi endpoint yang menerima path direktori dan menjalankan proses panjang pantas mendapat lapisan kedua. Kosongkan hanya di lingkungan pengembangan lokal.

`DEDUP_AMBANG_COSINE=0.92` masih menyimpan utang: angka itu disetel untuk MiniLM, sedangkan vektornya sekarang dari e5. Ukur ulang dengan 100 pasangan manual. Utang ini tidak ikut hilang bersama perpindahan penilai relevansi, justru menjadi satu-satunya tugas e5 yang tersisa.

### Etika crawling

User agent harus mengidentifikasi sistem dan mencantumkan URL penjelasan. Hormati `robots.txt`. Beri jeda minimal 1,5 detik antar permintaan ke domain yang sama. Ini bukan formalitas: media lokal sering memakai hosting kecil, dan crawler yang agresif akan diblokir atau memicu keluhan ke Diskominfo.

### Strategi per jenis situs media

Crawler bekerja dari luar, jadi CMS media tidak menentukan apakah media bisa dipantau. Media tidak perlu memasang atau mengubah apa pun. Yang berbeda hanya jalur pengambilannya.

| Jenis situs | Jalur | Catatan |
|-------------|-------|---------|
| WordPress | RSS di `/feed` | Hampir selalu aktif. Cek juga `/feed` per kategori |
| Blogger / Blogspot | RSS bawaan | Selalu tersedia di `https://NAMA.blogspot.com/feeds/posts/default?alt=rss`, juga untuk custom domain di path yang sama. Tidak perlu diaktifkan pemilik blog |
| PHP biasa / CMS lain | Coba `/feed`, `/rss`, `/rss.xml`, `<link rel="alternate" type="application/rss+xml">` di homepage | Kalau tidak ada, pakai tipe `scrape` dengan CSS selector, atau andalkan Google News RSS |
| React / SPA render klien | HTML unduhan kosong | Tiga pilihan berurutan: (1) cek apakah tetap ada RSS atau sitemap.xml, banyak SPA masih punya; (2) Playwright untuk render; (3) jadikan laporan mandiri portal sebagai jalur utama untuk media ini. Google News RSS tetap menangkap indeksnya |

Saat mendaftarkan sumber baru di sprint 0, catat jenis situsnya di kolom `catatan` media. Media dengan situs SPA tanpa feed diberi tahu sejak awal bahwa jalur mereka adalah portal pelaporan, supaya ekspektasinya jelas.

Tidak ada endpoint webhook di sistem ini, dan tidak ada rute yang dikecualikan dari CSRF. Google Form yang berjalan sekarang tidak dijembatani: form tetap dipakai apa adanya sampai portal siap, lalu dimatikan (dokumen 01 bagian 9).

### Pengarsipan bukti pemuatan

Job `ArsipkanBuktiPemuatan` dijalankan saat laporan dikonfirmasi. Isinya: simpan teks hasil ekstraksi, ambil tangkapan layar penuh halaman lewat Playwright (Chromium headless), simpan sebagai JPEG terkompresi maksimal 500 KB, catat waktunya. Playwright dipasang sekali dan dipakai dua hal: tangkapan layar ini dan render situs SPA. Screenshot berjalan di queue `default`, bukan `crawl`, karena lambat (3 sampai 8 detik per halaman) dan volumenya kecil.

Kalau tangkapan layar gagal, arsip teks tetap disimpan dan pemuatan tetap bisa diverifikasi. Bukti dari media (unggahan manual) melengkapi, bukan menggantikan, arsip sistem.

## 9. Testing

Untuk pengembang tunggal, test lengkap adalah kemewahan. Empat alur berikut wajib punya test karena kalau rusak Anda tidak akan langsung sadar.

1. **Deduplikasi.** Feed test dengan artikel yang sama disalin tiga media. Pastikan hanya satu terhitung sebagai asli.
2. **Scoping peran media.** Login sebagai media A, minta artikel media B, harapkan 403 atau daftar kosong. Test ini mencegah kebocoran data antar-media.
3. **Peran walikota tidak bisa menulis.** Kirim POST, PUT, dan DELETE ke rute admin, harapkan 403 di semuanya.
4. **Prioritas label manual.** Simpan label model, lalu koreksi manual, lalu jalankan analisis ulang. Pastikan koreksi tidak tertimpa.

Revisi 1.6 menambahkan alur kelima, dan bobotnya setara empat di atas:

5. **Penjaga sentimen.** Dengan gerbang mutu berstatus selain `passed`, pastikan `AnalisisSentimen` tidak pernah didispatch, dan kalau dipaksa dispatch pun ia berhenti sendiri tanpa memanggil layanan NLP. Uji juga tiga status artikel yang dilarang lewat: `tidak_relevan`, `perlu_review`, dan `model_belum_lulus_gate`.

Alur kelima ini punya sifat yang membuatnya wajib: **kegagalannya tidak terlihat.** Sentimen yang berjalan padahal seharusnya diblokir tetap menghasilkan angka yang tampak wajar di dashboard, dan tidak ada satu pun galat yang muncul. Satu-satunya cara mengetahuinya adalah test yang sengaja mencobanya.

Daftar test wajib laboratorium yang lebih lengkap, dua puluh feature test dan enam unit test, ada di dokumen 10 bagian 25.

Sisanya cukup smoke test bahwa setiap halaman mengembalikan 200 untuk peran yang berhak.

## 10. Backup dan pemulihan

`pg_dump` harian terkompresi, simpan 14 hari di server dan salin satu versi mingguan ke penyimpanan di luar server. Simpan juga daftar sumber feed sebagai file seed terpisah, karena itu data paling menyakitkan kalau hilang dan paling mudah diselamatkan.

Uji restore sebulan sekali ke database lain. Backup yang belum pernah direstore bukan backup.
