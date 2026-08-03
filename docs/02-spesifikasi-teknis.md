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
| `transformers` | terbaru | |
| `torch` | versi CPU-only | Jangan pasang build CUDA, ukurannya berlipat tanpa manfaat |
| `sentence-transformers` | terbaru | `multilingual-e5-small` untuk deduplikasi dan relevansi |

Detail model dan pipeline ada di dokumen 05.

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
│   └── KirimAlert.php
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
│   │   ├── PenyaringKataKunci.php      # saringan murah + pengetat frekuensi sebutan
│   │   ├── JendelaKonteks.php          # potongan kalimat sekitar sebutan Pemkot
│   │   └── DTO/
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
├── requirements.txt
└── README.md
```

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
3. HitungEmbedding        → panggil /embed sekali dengan dua teks:
    ↓                        isi penuh       → embedding
    ↓                        teks terfokus   → embedding_relevansi
4. PeriksaDuplikat        → hash cocok? simhash dekat? cosine > 0,92?
    ↓  jika duplikat → tandai salinan, tautkan ke induk, RANTAI BERHENTI
    ↓  jika asli → lanjut
5. AnalisisRelevansi      → TIDAK memanggil layanan NLP sama sekali.
    ↓                        skor = 1 - (embedding_relevansi <=> vektor konteks)
    ↓  di bawah ambang bawah   → status = tidak_relevan, RANTAI BERHENTI
    ↓  di antara dua ambang    → status = perlu_review, RANTAI BERHENTI
    ↓  di atas ambang atas dan lolos pengetat sebutan → lanjut
6. AnalisisSentimen       → panggil /sentiment untuk konteks utama
    ↓
7. EkstrakEntitas         → kata kunci dan entitas
    ↓
8. status = selesai
```

Langkah 5 berjalan sekali per artikel, bukan sekali untuk setiap konteks aktif.
Sejak versi 1.4 hanya ada satu konteks aktif dan relevansi menjadi keputusan
tingkat artikel (dokumen 01 bagian 9). Kalau nanti ada konteks kedua, ia
menambah baris sentimen, bukan baris relevansi.

**Relevansi tidak lagi memakai model tersendiri.** Sejak revisi 1.5 skornya
adalah cosine similarity antara vektor artikel dan vektor deskripsi konteks,
keduanya dari `multilingual-e5-small`, dihitung PostgreSQL lewat pgvector.
Akibatnya `AnalisisRelevansi` bukan lagi job yang memanggil HTTP, melainkan
satu kueri. Ia tetap berupa job supaya urutan rantai dan penanganan gagalnya
seragam dengan job lain.

Keuntungan terbesarnya bukan kecepatan per artikel, melainkan penyetelan:
mengubah ambang cukup dengan menjalankan ulang kueri atas seluruh korpus,
tanpa satu pun inferensi model. Menyetel ambang yang tepat butuh puluhan kali
percobaan, dan dengan model lama tiap percobaan berarti menunggu berjam-jam.

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
  "model_embedding": "intfloat/multilingual-e5-small", "versi": "2.0.0" }
```

Dua model, bukan tiga. `model_relevansi` dihapus dari respons.

Dipanggil scheduler tiap 5 menit. Kalau gagal tiga kali berturut-turut, kirim alert ke admin.

### POST /embed

```json
{ "teks": ["judul dan isi artikel 1", "artikel 2"] }
```

```json
{ "embedding": [[0.01, -0.23, ...], [...]], "dimensi": 384 }
```

Maksimal 32 teks per permintaan.

### POST /relevancy: dihapus

Endpoint ini tidak ada lagi sejak revisi 1.5. Model `indobert-relevancy`
dilepas dari layanan.

Relevansi dihitung di PostgreSQL:

```sql
SELECT 1 - (a.embedding_relevansi <=> k.embedding) AS skor_relevansi
FROM artikel a
CROSS JOIN konteks_pantauan k
WHERE a.id = :artikel_id AND k.utama = true;
```

Nilai `skor_relevansi` adalah cosine similarity, **bukan probabilitas**. Angka
0,82 tidak berarti yakin 82%. Perbedaan ini harus tertulis di mana pun angkanya
ditampilkan ke admin, kalau tidak, orang akan membacanya sebagai persentase
kepercayaan dan menyimpulkan hal yang salah.

Sinyal judul, tag, dan alias yang dipakai menjelaskan keputusan ke admin
dihitung di sisi Laravel oleh `PenyaringKataKunci`, lalu disimpan ke
`sinyal_relevansi`. Sinyal itu tidak pernah ikut menentukan skor, hanya
menjelaskannya dan menjadi bahan pengetat sebutan.

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
| `kontrak:periksa-tenggat` | Harian 07.00 | F-26 |
| `db:backup` | Harian 01.00 | Simpan 14 hari terakhir, salin satu ke penyimpanan luar |

Gunakan `withoutOverlapping()` pada seluruh perintah crawl dan agregasi.

## 8. Deployment

### Spesifikasi server minimum

| Sumber daya | Ukuran | Alasan |
|-------------|--------|--------|
| vCPU | 4 | 2 untuk PHP-FPM dan worker, 2 untuk inferensi IndoBERT |
| RAM | 8 GB | IndoBERT Large butuh sekitar 1,5 GB, PostgreSQL 2 GB, sisanya PHP dan Redis |
| Disk | 80 GB SSD | Database, isi artikel, dan backup 14 hari |
| Bandwidth | Wajar | Crawling 300 artikel per hari tidak berat |

Kalau anggaran ketat, 4 GB RAM masih jalan dengan syarat layanan NLP dijalankan sebagai batch berjadwal, bukan proses yang selalu hidup. Konsekuensinya sentimen baru muncul dengan jeda sampai satu jam.

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
RELEVANSI_AMBANG_ATAS=
RELEVANSI_AMBANG_BAWAH=
RELEVANSI_MINIMAL_SEBUTAN=3
RELEVANSI_KONTEKS_VERSI=pemkot-kendari-v2
DEDUP_AMBANG_COSINE=0.92
DEDUP_AMBANG_SIMHASH=4

CRAWL_USER_AGENT="SimediaKendariBot/1.0 (+https://simedia.kendarikota.go.id/bot)"
CRAWL_DELAY_MS=1500
CRAWL_TIMEOUT=20

TELEGRAM_BOT_TOKEN=
TELEGRAM_CHAT_ID=
```

Ambang keyakinan ditaruh di environment, bukan hardcode, karena Anda akan menyetelnya setelah gold set jadi dan tidak akan mau deploy ulang untuk mengubah satu angka.

Tiga catatan, jangan dihapus:

- **Dua ambang relevansi sengaja dibiarkan kosong.** Selama kosong, seluruh artikel yang lolos deduplikasi masuk antrean `perlu_review` dan tidak ada yang otomatis masuk atau keluar dashboard. Itu perilaku yang benar sebelum ambangnya diukur. Memasang angka tebakan lalu lupa menggantinya adalah cara paling umum sebuah ambang menjadi permanen tanpa pernah diuji. Cara memilihnya ada di dokumen 05 bagian 5.1.
- `RELEVANSI_AMBANG_KEYAKINAN` dihapus bersama model relevansi lama. Nilai itu terbukti tidak berpengaruh apa pun: diuji 0,55 sampai 0,999 dan presisi hanya bergerak 47,4% ke 48,1%, karena model lama sama yakinnya saat benar maupun saat keliru.
- `RELEVANSI_KONTEKS_VERSI` dinaikkan setiap kali deskripsi konteks utama atau aturan inklusi dan eksklusinya berubah. Menaikkannya **mewajibkan** vektor konteks dihitung ulang dan seluruh skor relevansi dihitung ulang, karena skor lama dibandingkan terhadap deskripsi yang sudah tidak berlaku.

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

Sisanya cukup smoke test bahwa setiap halaman mengembalikan 200 untuk peran yang berhak.

## 10. Backup dan pemulihan

`pg_dump` harian terkompresi, simpan 14 hari di server dan salin satu versi mingguan ke penyimpanan di luar server. Simpan juga daftar sumber feed sebagai file seed terpisah, karena itu data paling menyakitkan kalau hilang dan paling mudah diselamatkan.

Uji restore sebulan sekali ke database lain. Backup yang belum pernah direstore bukan backup.
