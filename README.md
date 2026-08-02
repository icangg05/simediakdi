# SIMEDIA Kendari

Sistem Monitoring dan Analisis Sentimen Media untuk Pemerintah Kota Kendari.

Spesifikasi lengkap ada di [`docs/`](docs/). Baca [`docs/00-README.md`](docs/00-README.md) lebih dulu.

## Menjalankan dari nol

Yang perlu ada di mesin: Docker dan Node 20+. PHP, Composer, PostgreSQL, dan
Redis semuanya jalan di dalam container — tidak ada yang dipasang ke host.

```bash
docker compose up -d --build
npm install
cp .env.example .env                       # lewati kalau .env sudah ada
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
npm run build                              # atau: npm run dev
```

Buka http://localhost:8000. Server web ikut hidup bersama container app, jadi
`docker compose up -d` sudah cukup untuk menyalakannya lagi setelah ini.

Akun hasil seeder, semuanya berkata sandi `password`:

| Email | Peran |
|-------|-------|
| `admin@simedia.test` | superadmin |
| `walikota@simedia.test` | walikota |
| `media@simedia.test` | media (Kendari Pos) |

Registrasi mandiri tidak dibuka. Seluruh akun dibuat superadmin.

## Perintah harian

```bash
docker compose exec app php artisan test          # butuh database simedia_test
docker compose exec app php artisan migrate:fresh --seed
docker compose exec app php artisan tinker
npm run dev                                        # vite, hot reload
npm run lint
```

## Menjalankan crawler

```bash
docker compose exec app php artisan crawl:feeds --paksa   # abaikan interval_menit
docker compose exec app php artisan crawl:feeds --sumber=1
docker compose exec app php artisan crawl:google-news

# Isi artikel diunduh di queue, bukan di command:
docker compose exec app php artisan queue:work redis --queue=crawl --stop-when-empty
```

Di produksi, worker dan scheduler dijalankan supervisor — lihat
[`deploy/supervisor/simedia.conf`](deploy/supervisor/simedia.conf).

## Layanan NLP

Proses Python terpisah di [`nlp/`](nlp/), dijalankan container `nlp`. Model
diunduh sekali ke volume `modelnlp` (sekitar 3 GB) saat pertama kali hidup —
startup pertama makan waktu 20–30 menit, berikutnya beberapa detik.

```bash
docker compose up -d nlp
docker compose logs -f nlp                    # tunggu "Seluruh model siap"
docker compose exec app php artisan nlp:health

# Analisis berjalan di antrean terpisah, satu proses saja:
docker compose exec app php artisan queue:work redis --queue=nlp --stop-when-empty
```

| Model | Dipakai untuk |
|-------|---------------|
| `apriandito/indobert-sentiment-classifier` | Sentimen terkondisi konteks, F1 macro 0,856 |
| `apriandito/indobert-relevancy-classifier` | Saringan relevansi, F1 0,948 |
| `paraphrase-multilingual-MiniLM-L12-v2` | Embedding 384 dimensi untuk dedup lapis 3 |

Keduanya menerima `[CLS] konteks [SEP] teks [SEP]` — konteks dikirim sebagai
kalimat terpisah, bukan digabung ke teks. Menggabungnya membuat model kehilangan
batas antara keduanya dan hasilnya kembali seperti model sentimen biasa.

Layanan ini tidak pernah menyentuh database. Kalau mati, job menumpuk di antrean
`nlp` dan jalan lagi setelah hidup — tidak ada data yang hilang.

## Gold set dan akurasi

```bash
docker compose exec app php artisan evaluasi:model
```

Labeli lewat `/admin/pelabelan` — baca
[`docs/09-panduan-pelabelan.md`](docs/09-panduan-pelabelan.md) sampai habis
sebelum baris pertama. Hasil evaluasi tampil di `/admin/evaluasi`.

F1 macro di bawah 0,65 berarti berhenti, jangan bangun dashboard di atasnya.

Setiap URL yang akan diambil melewati `ValidatorUrl` lebih dulu: alamat privat,
loopback, link-local (termasuk `169.254.169.254`), dan skema selain http/https
ditolak, termasuk pada setiap hop redirect. Batas unduhan 5 MB, timeout 20
detik, jeda 1,5 detik per domain, dan `robots.txt` dihormati.

### Pengambilan isi artikel

Urutannya HTML dulu, WordPress REST API sebagai jaring pengaman — bukan
sebaliknya. Alasannya terukur pada media partner sungguhan:

| Jalur | Median | Ukuran |
|-------|--------|--------|
| HTML penuh + Readability | 147 ms | 148 KB |
| `/wp-json/` polos | 422 ms | 7 KB |
| `/wp-json/` dengan `_embed` | 461 ms | 12 KB |

Situs-situs itu menyajikan HTML dari page cache, sedangkan `/wp-json/` menembus
cache lalu menjalankan PHP dan query MySQL. Menaruh API di depan berarti tiga
kali lipat latensi dan beban lebih berat bagi hosting kecil media daerah,
untuk keuntungan yang pada 22 dari 27 artikel uji tidak ada.

`EkstraktorWordPress` dipanggil hanya ketika Readability gagal atau hasilnya di
bawah 80 kata. Di situ kepastian datanya baru bernilai. Matikan dengan
`CRAWL_WORDPRESS_API=false` kalau perlu membandingkan hasil.

26 dari 30 media lampiran A punya WP REST aktif; pada 27 media lokal dan
regional angkanya 25 dari 27.

Database uji dibuat sekali:

```bash
docker compose exec db psql -U simedia -d postgres -c "CREATE DATABASE simedia_test OWNER simedia;"
```

Test berjalan di PostgreSQL, bukan sqlite. Skemanya memakai pgvector, index
hnsw, `UNIQUE NULLS NOT DISTINCT`, dan `ilike`; menguji di sqlite berarti
menguji skema yang berbeda dari yang dijalankan di produksi.

## Susunan

| Bagian | Lokasi |
|--------|--------|
| Migration seluruh tabel dokumen 03 | `database/migrations/2026_08_02_09*` |
| Enum peran, sentimen, dedup, tier | `app/Enums/` |
| Global scope peran media | `app/Models/Scopes/MilikMedia.php` |
| Filter, sort, paginasi sisi server | `app/Support/KueriTabel.php` |
| Tiga grup route | `routes/admin.php`, `routes/eksekutif.php`, `routes/portal.php` |
| Komponen tabel generik | `resources/js/components/data-table/` |
| Token warna sentimen | `resources/css/app.css` |
| Crawler: unduh, baca feed, ekstrak | `app/Services/Crawler/` |
| Deduplikasi tiga lapis | `app/Services/Dedup/` |
| Ambang dedup dan etika crawling | `config/crawler.php` |
| Klien NLP, penyaring, evaluator | `app/Services/Nlp/` |
| Ambang keyakinan dan batch NLP | `config/nlp.php` |
| Layanan model (Python) | `nlp/` |
| Praperhitungan grafik | `app/Services/Agregasi/` |
| Konversi WITA yang eksplisit | `app/Support/Waktu.php` |

Aplikasi berjalan di UTC dan seluruh timestamp disimpan UTC. **Jangan mengubah
`app.timezone` menjadi `Asia/Makassar`** — alasannya, beserta hasil ujinya, ada
di komentar `config/app.php`. Konversi ke WITA dilakukan lewat `App\Support\Waktu`
di tempat yang memang membutuhkannya.

## Yang belum dikerjakan

Sprint 4 dan seterusnya sesuai [`docs/07-roadmap.md`](docs/07-roadmap.md):
dashboard eksekutif, kontrak, portal media, alert, entitas.

Dua hal dari sprint 3 menunggu pekerjaan manusia dan tidak bisa diselesaikan
kode:

1. **400 baris gold set belum dilabeli.** Ruang kerjanya siap di
   `/admin/pelabelan`, panduannya ada, tapi pelabelannya sendiri butuh penilaian
   pengembang (dokumen 01 bagian 9 nomor 7). Sekitar dua jam terfokus.
2. **Ambang keyakinan belum dikalibrasi dari gold set.** Nilai sekarang (0,90)
   dipilih dari sebaran keyakinan pada 24 pasangan nyata, bukan dari titik di
   mana model mulai salah. Setel ulang setelah gold set selesai.

Berkas `05-spesifikasi-nlp.md` yang dirujuk dokumen 00, 02, dan 07 tidak ada di
paket spesifikasi. Panduan pelabelan ditulis ulang sebagai
[`docs/09-panduan-pelabelan.md`](docs/09-panduan-pelabelan.md); rencana
alternatif kalau F1 macro di bawah 0,65 (dokumen 05 bagian 8) belum ada
penggantinya.

Layanan NLP (`nlp/`) belum ada. Dokumen `05-spesifikasi-nlp.md` yang dirujuk
dokumen 00, 02, dan 07 juga belum ada di paket spesifikasi.
