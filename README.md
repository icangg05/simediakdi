Kakanwil Kemenkum Sultra Koordinasikan Penguatan Kekayaan Intelektual Di Sultra ke DJKI

# SIMEDIA Kendari

Sistem Monitoring dan Analisis Sentimen Media untuk Pemerintah Kota Kendari.

Spesifikasi lengkap ada di [`docs/`](docs/). Baca [`docs/00-README.md`](docs/00-README.md) lebih dulu.

## Menjalankan dari nol

Yang perlu ada di mesin: Docker dan Node 20+. PHP, Composer, PostgreSQL, dan
Redis semuanya jalan di dalam container, tidak ada yang dipasang ke host.

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

Di produksi, worker dan scheduler dijalankan supervisor, lihat
[`deploy/supervisor/simedia.conf`](deploy/supervisor/simedia.conf).

### Menarik arsip lama

RSS hanya memuat 10-50 tulisan terbaru, jadi korpus untuk gold set tidak bisa
dibangun darinya tanpa menunggu berminggu-minggu. `crawl:backfill` menyusuri
arsip lewat endpoint daftar WP REST, 50 tulisan sekali permintaan.

```bash
docker compose exec app php artisan crawl:backfill --halaman=4
docker compose exec app php artisan crawl:backfill --media=kendari-pos --halaman=10
docker compose exec app php artisan crawl:backfill --mulai=5 --halaman=4   # lanjutkan
```

Bukan bagian operasi harian, crawler RSS yang mengurus berita baru. Jalankan
saat gold set butuh lebih banyak artikel daripada yang bisa dikumpulkan feed
dalam waktu wajar.

Enam media tidak punya arsip yang bisa ditarik: Telisik, Sibernas, dan Sultra TV
(robots melarang `/wp-json/`), serta Tempo, Detik, dan Portal.id yang bukan
WordPress atau nasional.

Isi artikel ikut terbawa dalam respons arsip, jadi `AmbilIsiArtikel` dilewati.
Sisanya (sidik jari, deduplikasi, penerusan ke analisis) dikerjakan
`PenyelesaiArtikel`, kelas yang sama dengan jalur crawl harian, supaya kedua
jalur tidak pernah berbeda perlakuan.

## Layanan NLP

Proses Python terpisah di [`nlp/`](nlp/), dijalankan container `nlp`. Model
diunduh sekali ke volume `modelnlp` (sekitar 3 GB) saat pertama kali hidup,
startup pertama makan waktu 20-30 menit, berikutnya beberapa detik.

```bash
docker compose up -d nlp
docker compose logs -f nlp                    # tunggu "Seluruh model siap"
docker compose exec app php artisan nlp:health

# Analisis berjalan di antrean terpisah, satu proses saja:
docker compose exec app php artisan queue:work redis --queue=nlp --stop-when-empty
```

| Model | Dipakai untuk |
|-------|---------------|
| `apriandito/indobert-sentiment-classifier` | Sentimen terkondisi konteks |
| `intfloat/multilingual-e5-small` | Embedding 384 dimensi untuk relevansi dan dedup lapis 3 |

Dua model, bukan tiga. Relevansi tidak punya model sendiri: skornya kemiripan
makna antara vektor artikel dan vektor deskripsi konteks, dihitung PostgreSQL
lewat pgvector. Karena skornya berasal dari vektor tersimpan, mengubah ambang
tidak menuntut inferensi ulang, dan menyetel ambang yang harus dicoba puluhan
kali jadi murah. Rinciannya di [`docs/05-spesifikasi-nlp.md`](docs/05-spesifikasi-nlp.md).

Skor kemiripan diketatkan aturan frekuensi kata kunci sesudah ambang, karena
teks yang dinilai disusun dari sekitar sebutan Pemkot sehingga penyebutan
sekali lewat pun bisa berskor tinggi.

Model sentimen menerima `[CLS] konteks [SEP] teks [SEP]`, konteks dikirim
sebagai kalimat terpisah, bukan digabung ke teks. Menggabungnya membuat model
kehilangan batas antara keduanya dan hasilnya kembali seperti model sentimen
biasa.

Mengganti model embedding atau mengubah deskripsi konteks mewajibkan:

```bash
docker compose exec app php artisan nlp:hitung-ulang-vektor --paksa
```

Vektor dari model berbeda tidak sebanding. Membandingkannya menghasilkan angka
yang terlihat wajar tapi tidak berarti apa-apa.

Layanan ini tidak pernah menyentuh database. Kalau mati, job menumpuk di antrean
`nlp` dan jalan lagi setelah hidup, tidak ada data yang hilang.

## Layanan arsip

Container terpisah berisi Playwright, dipakai `ArsipkanBuktiPemuatan` untuk
menangkap layar halaman saat laporan pemuatan dikonfirmasi (F-52).

```bash
docker compose up -d arsip
curl -s http://127.0.0.1:8002/health
```

Dipisah dari layanan NLP dengan sengaja. Layanan NLP berjalan satu worker
karena modelnya memakan 1,5 GB memori, dan satu render Chromium yang memakan
CPU beberapa detik akan menahan antrean analisis sentimen di belakangnya.

Kalau layanan ini mati, pengarsipan tetap berjalan tanpa gambar. Bukti teks
dari ekstraksi jauh lebih penting, dan kegagalan gambar tidak boleh membuang
bukti teks yang sudah di tangan.

## Portal media dan alert

```bash
# Satu akun portal per media aktif. Kata sandi tercetak sekali, salin sebelum
# menutup terminal.
docker compose exec app php artisan pengguna:buat-akun-media
docker compose exec app php artisan pengguna:buat-akun-media --media=kendari-pos

docker compose exec app php artisan alert:periksa --kering
docker compose exec app php artisan kontrak:periksa-tenggat --kering
docker compose exec app php artisan hitung:entitas --semua
```

Alert butuh `TELEGRAM_BOT_TOKEN` dan `TELEGRAM_CHAT_ID` di `.env`. Selama
keduanya kosong, `/admin/alert` menampilkan peringatan: aturan tetap dinilai
dan tercatat di riwayat, tapi pesannya tidak sampai ke mana pun.

Pencocokan entitas memakai kamus dengan alias, bukan model NER. Entitas yang
dipantau Pemkot adalah daftar tertutup dan pendek, dan salah tulisnya bisa
langsung diperbaiki admin lewat alias tanpa melatih ulang apa pun. Terukur:
4.137 artikel selesai dicocokkan dalam 14 detik.

**Portal media tidak pernah menampilkan skor sentimen.** Itu keputusan produk
di dokumen 01 bagian 8, bukan kelalaian, dan alasannya ditulis di
`ArtikelPortalResource` serta di controller kontraknya. Akan ada yang meminta
fiturnya ditambahkan.

## Gold set dan akurasi

```bash
docker compose exec app php artisan evaluasi:model
```

Labeli lewat `/admin/pelabelan`, baca
[`docs/09-panduan-pelabelan.md`](docs/09-panduan-pelabelan.md) sampai habis
sebelum baris pertama. Hasil evaluasi tampil di `/admin/evaluasi`.

F1 macro di bawah 0,65 berarti berhenti, jangan bangun dashboard di atasnya.

Setiap URL yang akan diambil melewati `ValidatorUrl` lebih dulu: alamat privat,
loopback, link-local (termasuk `169.254.169.254`), dan skema selain http/https
ditolak, termasuk pada setiap hop redirect. Batas unduhan 5 MB, timeout 20
detik, jeda 1,5 detik per domain, dan `robots.txt` dihormati.

### Pengambilan isi artikel

Urutannya HTML dulu, WordPress REST API sebagai jaring pengaman, bukan
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
| Grafik: pembungkus dan tema | `resources/js/components/chart/` |
| Rentang tanggal dan konteks | `app/Support/Periode.php` |
| Pencocokan pemuatan kontrak | `app/Services/Kontrak/` |
| Pemeriksaan URL laporan pemuatan | `app/Services/Portal/` |
| Aturan alert dan pengiriman Telegram | `app/Services/Alert/` |
| Klien layanan tangkapan layar | `app/Services/Arsip/` |
| Pencocokan kamus entitas | `app/Services/Nlp/PencocokEntitas.php` |
| Bentuk data yang boleh dilihat media | `app/Http/Resources/ArtikelPortalResource.php` |
| Layanan tangkapan layar (Python) | `arsip/` |
| Crawler: unduh, baca feed, ekstrak | `app/Services/Crawler/` |
| Deduplikasi tiga lapis | `app/Services/Dedup/` |
| Ambang dedup dan etika crawling | `config/crawler.php` |
| Klien NLP, penyaring, evaluator | `app/Services/Nlp/` |
| Ambang keyakinan dan batch NLP | `config/nlp.php` |
| Layanan model (Python) | `nlp/` |
| Praperhitungan grafik | `app/Services/Agregasi/` |
| Konversi WITA yang eksplisit | `app/Support/Waktu.php` |

Aplikasi berjalan di UTC dan seluruh timestamp disimpan UTC. **Jangan mengubah
`app.timezone` menjadi `Asia/Makassar`**. Alasannya, beserta hasil ujinya, ada
di komentar `config/app.php`. Konversi ke WITA dilakukan lewat `App\Support\Waktu`
di tempat yang memang membutuhkannya.

## Agregasi

Seluruh grafik membaca tabel praperhitungan, bukan menghitung saat request.
Scheduler mengurusnya, tapi bisa dijalankan manual:

```bash
docker compose exec app php artisan hitung:ringkasan-harian --hari=7
docker compose exec app php artisan hitung:kata-kunci --hari=7
docker compose exec app php artisan hitung:entitas --hari=7
```

Terukur pada korpus 4.806 artikel: dashboard eksekutif 123 ms di sisi server,
14 kueri. Halaman lainnya di bawah 40 ms.

## Yang belum dikerjakan

Sprint 6 sesuai [`docs/07-roadmap.md`](docs/07-roadmap.md): pemantapan dan
serah terima. Sprint tanpa fitur baru.

Word cloud di halaman isu tidak dibangun: `echarts-wordcloud` menuntut
`echarts ^5`, sedangkan `vue-echarts@8` menuntut `echarts ^6`. Penggantinya
daftar peringkat dengan skor lonjakan. Rinciannya di
[`docs/08-rangkuman-sprint.md`](docs/08-rangkuman-sprint.md).

Empat hal menunggu pekerjaan manusia dan tidak bisa diselesaikan kode:

1. **400 baris gold set belum dilabeli.** Ruang kerjanya siap di
   `/admin/pelabelan`, panduannya ada, tapi pelabelannya sendiri butuh penilaian
   pengembang (dokumen 01 bagian 9 nomor 7). Sekitar dua jam terfokus.
2. **Ambang keyakinan belum dikalibrasi dari gold set.** Nilai sekarang (0,90)
   dipilih dari sebaran keyakinan pada 24 pasangan nyata, bukan dari titik di
   mana model mulai salah. Setel ulang setelah gold set selesai.
3. **Chat ID grup Telegram Diskominfo belum ada.** Seluruh jalur alert sudah
   jadi dan teruji, termasuk pencatatan kegagalan pengiriman. Yang kosong hanya
   dua baris di `.env`.
4. **30 akun media belum dibuat dan portal belum disosialisasikan.**
   Perintahnya siap; menjalankannya, mengirim kredensial lewat kanal yang aman,
   dan memastikan tiap media berhasil melapor sekali adalah pekerjaan manusia.
   Google Form lama dimatikan setelah itu, bukan sebelumnya.

Berkas `05-spesifikasi-nlp.md` yang dirujuk dokumen 00, 02, dan 07 tidak ada di
paket spesifikasi. Panduan pelabelan ditulis ulang sebagai
[`docs/09-panduan-pelabelan.md`](docs/09-panduan-pelabelan.md); rencana
alternatif kalau F1 macro di bawah 0,65 (dokumen 05 bagian 8) belum ada
penggantinya.

