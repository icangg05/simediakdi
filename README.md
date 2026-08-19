# SIMAK V1

Sistem Monitoring dan Analisis Kendari, sistem monitoring dan analisis sentimen media untuk Pemerintah Kota Kendari.

"Membaca Pemberitaan, Mengawal Kendari Semakin Maju."

Spesifikasi lengkap ada di [`docs/`](docs/). Baca [`docs/00-README.md`](docs/00-README.md) lebih dulu.

## Menjalankan dari nol

Yang perlu ada di mesin: Docker dan Node 20+. PHP, Composer, PostgreSQL, dan
Redis semuanya jalan di dalam container, tidak ada yang dipasang ke host.

Siapkan dulu direktori data di disk. `storage/app` di-mount ke
`/mnt/data/simedia/storage-app`, jadi berkas unggahan, cadangan basis data, dan
artefak model tidak ikut folder rilis. Kalau direktorinya belum ada, Docker
membuatkannya sebagai root dan seluruh container yang berjalan sebagai UID 1000
gagal menulis.

```bash
sudo mkdir -p /mnt/data/simedia
sudo mv storage/app /mnt/data/simedia/storage-app   # atau: sudo mkdir -p, kalau belum ada data lama
sudo chown -R 1000:1000 /mnt/data/simedia/storage-app
mkdir -p storage/app                                # titik pasang kosong di dalam repo
```

Sudah terlanjur `up` sebelum langkah ini? Docker sudah membuat direktorinya
sebagai root dalam keadaan kosong, dan Laravel menjawab
`Unable to create a directory at /app/storage/app/private`. Data lama tidak
hilang, ia hanya tertutup mount. Pulihkan begini:

```bash
docker compose down
sudo rmdir /mnt/data/simedia/storage-app            # aman, isinya kosong
sudo mv storage/app /mnt/data/simedia/storage-app
sudo chown -R 1000:1000 /mnt/data/simedia/storage-app
mkdir -p storage/app
docker compose up -d
```

```bash
docker compose up -d --build
npm install
cp .env.example .env                       # lewati kalau .env sudah ada
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
npm run build                              # atau: npm run dev
```

Buka http://localhost:8000.

Akun hasil seeder, semuanya berkata sandi `password`:

| Email | Peran |
|-------|-------|
| `admin@simedia.test` | superadmin |
| `walikota@simedia.test` | walikota |
| `media@simedia.test` | media (Kendari Pos) |

Registrasi mandiri tidak dibuka. Seluruh akun dibuat superadmin.

## Port

Semua port yang diterbitkan terkunci di `127.0.0.1`. Tidak ada satu pun yang
terbuka ke jaringan luar.

| Port | Layanan | Diterbitkan | Dipakai untuk |
|------|---------|-------------|---------------|
| 8000 | `web` (Caddy) | `127.0.0.1` | Satu-satunya pintu masuk aplikasi |
| 8001 | `relevansi` | `127.0.0.1` | Pelatihan dan inferensi IndoBERT |
| 8002 | `arsip` | `127.0.0.1` | Tangkapan layar Playwright |
| 5432 | `db` (Postgres) | tidak | Diakses lewat nama `db` di jaringan compose |
| 6379 | `redis` | tidak | Diakses lewat nama `redis` di jaringan compose |

Postgres dan Redis sengaja tidak menerbitkan port ke host. Keduanya tidak
dipakai dari luar container, dan Redis di sini berjalan tanpa kata sandi. Butuh
klien database dari laptop, pakai terowongan SSH:

```bash
ssh -L 5432:localhost:5432 user@server
```

**Sebelum deploy, periksa dulu apa yang sudah dipakai di server:**

```bash
ss -ltnp | grep -E ':(80|443|8000|8001|8002)\b'
```

Kalau salah satu sudah terpakai project lain, ganti angka sisi kiri di
`docker-compose.yml`, misalnya `127.0.0.1:8100:8000`. Sisi kanan jangan diubah,
itu port di dalam container.

Aturan UFW tidak menutup port yang diterbitkan Docker. Docker menulis rule-nya
sendiri di chain `DOCKER` yang dievaluasi lebih dulu, jadi `ufw deny 5432` pada
port yang dipublikasi ke `0.0.0.0` tidak berpengaruh sama sekali. Penjaganya
adalah bind ke `127.0.0.1`, bukan firewall.

Di produksi, reverse proxy milik host yang memegang 80 dan 443 lalu meneruskan
ke `127.0.0.1:8000`. Worker dan scheduler dijalankan supervisor, lihat
[`deploy/supervisor/simedia.conf`](deploy/supervisor/simedia.conf).

## Perintah harian

```bash
docker compose exec app php artisan test          # butuh database simedia_test
docker compose exec app php artisan migrate:fresh --seed
docker compose exec app php artisan tinker
npm run dev                                        # vite, hot reload
npm run lint
```

Database uji dibuat sekali:

```bash
docker compose exec db psql -U simedia -d postgres -c "CREATE DATABASE simedia_test OWNER simedia;"
```

Test berjalan di PostgreSQL, bukan sqlite. Skemanya memakai pgvector, index
hnsw, `UNIQUE NULLS NOT DISTINCT`, dan `ilike`. Menguji di sqlite berarti
menguji skema yang berbeda dari yang dijalankan di produksi.

## Crawler

```bash
docker compose exec app php artisan crawl:feeds --paksa   # abaikan interval_menit
docker compose exec app php artisan crawl:feeds --sumber=1

# Isi artikel diunduh di queue, bukan di command:
docker compose exec app php artisan queue:work redis --queue=crawl --stop-when-empty
```

Setiap URL yang akan diambil melewati `ValidatorUrl` lebih dulu: alamat privat,
loopback, link-local (termasuk `169.254.169.254`), dan skema selain http/https
ditolak, termasuk pada setiap hop redirect. Batas unduhan 5 MB, timeout 20
detik, jeda 1,5 detik per domain, dan `robots.txt` dihormati.

Urutan pengambilan isi: HTML dengan Readability lebih dulu (median 147 ms),
WordPress REST API hanya sebagai jaring pengaman saat hasilnya di bawah 80 kata
(median 422 ms, karena menembus page cache). Matikan dengan
`CRAWL_WORDPRESS_API=false` kalau perlu membandingkan.

### Menarik arsip lama

RSS hanya memuat 10-50 tulisan terbaru. `crawl:backfill` menyusuri arsip lewat
endpoint daftar WP REST, 50 tulisan sekali permintaan.

```bash
docker compose exec app php artisan crawl:backfill --halaman=4
docker compose exec app php artisan crawl:backfill --media=kendari-pos --halaman=10
docker compose exec app php artisan crawl:backfill --mulai=5 --halaman=4   # lanjutkan
```

Bukan bagian operasi harian. Enam media tidak punya arsip yang bisa ditarik:
Telisik, Sibernas, dan Sultra TV (robots melarang `/wp-json/`), serta Tempo,
Detik, dan Portal.id yang bukan WordPress atau nasional.

## Klasifikasi Gemini

Sentimen dan relevansi dinilai Gemini, bukan model lokal. Kunci API diisi di
`/admin/pengaturan`, tersimpan terenkripsi di database.

```bash
docker compose exec app php artisan gemini:antre --isi   # isi antrean
docker compose exec app php artisan gemini:antre         # jalankan satu putaran
```

Scheduler menjalankan keduanya sendiri, `--isi` tiap jam dan pengosongan
antrean tiap menit. Pantau lewat `/admin/antrean-ai`.

## Model relevansi (IndoBERT)

Layanan Python di [`relevansi/`](relevansi/) melatih dan menjalankan pengklasifikasi
relevansi. Ruang kerjanya di `/admin/model-relevansi`: buat snapshot dataset,
latih, uji, lalu aktifkan satu model.

```bash
docker compose up -d relevansi
curl -s http://127.0.0.1:8001/health
docker compose exec app php artisan queue:work redis --queue=pelatihan
```

Artefak model tersimpan di `storage/app/private/model-relevansi/pelatihan-<id>/`,
sekitar 1,3 GB per model. Bobot base model diunduh sekali ke volume `modeldata`.

**Memindahkan model hasil latihan ke server:** salin direktori artefaknya dengan
`rsync`, lalu salin baris `snapshot_dataset_relevansi` dan
`pelatihan_model_relevansi` yang bersangkutan. Aktifkan lewat layar admin, bukan
`UPDATE` manual, karena indeks unik `uq_pelatihan_aktif` menuntut model lama
dicabut lebih dulu.

## Layanan arsip

Container terpisah berisi Playwright, dipakai `ArsipkanBuktiPemuatan` untuk
menangkap layar halaman saat laporan pemuatan dikonfirmasi (F-52).

```bash
docker compose up -d arsip
curl -s http://127.0.0.1:8002/health
```

Kalau layanan ini mati, pengarsipan tetap berjalan tanpa gambar. Bukti teks dari
ekstraksi jauh lebih penting, dan kegagalan gambar tidak boleh membuang bukti
teks yang sudah di tangan.

## Portal media dan alert

```bash
# Satu akun portal per media aktif. Kata sandi tercetak sekali, salin sebelum
# menutup terminal.
docker compose exec app php artisan pengguna:buat-akun-media
docker compose exec app php artisan pengguna:buat-akun-media --media=kendari-pos

docker compose exec app php artisan alert:periksa --kering
```

Alert butuh token bot dan chat ID Telegram yang diisi di `/admin/pengaturan`.
Keduanya tersimpan di database, tokennya terenkripsi, dan tidak ada lagi di
`.env`. Selama keduanya kosong, aturan tetap dinilai dan tercatat di riwayat,
tapi pesannya tidak sampai ke mana pun.

**Portal media tidak pernah menampilkan skor sentimen.** Itu keputusan produk di
dokumen 01 bagian 8, bukan kelalaian, dan alasannya ditulis di
`ArtikelPortalResource` serta di controller kontraknya. Akan ada yang meminta
fiturnya ditambahkan.

## Agregasi

Seluruh grafik membaca tabel praperhitungan, bukan menghitung saat request.
Scheduler mengurusnya, tapi bisa dijalankan manual:

```bash
docker compose exec app php artisan hitung:ringkasan-harian --hari=7
docker compose exec app php artisan narasi:eksekutif --periode=7d
```

Terukur pada korpus 4.806 artikel: dashboard eksekutif 123 ms di sisi server, 14
kueri. Halaman lainnya di bawah 40 ms.

## Susunan

| Bagian | Lokasi |
|--------|--------|
| Global scope peran media | `app/Models/Scopes/MilikMedia.php` |
| Filter, sort, paginasi sisi server | `app/Support/KueriTabel.php` |
| Rentang tanggal dan konversi WITA | `app/Support/Periode.php`, `app/Support/Waktu.php` |
| Tiga grup route | `routes/admin.php`, `routes/eksekutif.php`, `routes/portal.php` |
| Tabel dan grafik generik | `resources/js/components/data-table/`, `components/chart/` |
| Crawler: unduh, baca feed, ekstrak | `app/Services/Crawler/`, `config/crawler.php` |
| Agen dan klien Gemini | `app/Ai/Agents/`, `config/ai.php` |
| Snapshot, pelatihan, inferensi relevansi | `app/Services/ModelRelevansi/`, `config/relevansi.php` |
| Aturan alert dan pengiriman Telegram | `app/Services/Alert/` |
| Praperhitungan grafik | `app/Services/Agregasi/` |
| Bentuk data yang boleh dilihat media | `app/Http/Resources/ArtikelPortalResource.php` |
| Layanan Python | `arsip/`, `relevansi/` |

Aplikasi berjalan di UTC dan seluruh timestamp disimpan UTC. **Jangan mengubah
`app.timezone` menjadi `Asia/Makassar`**. Alasannya, beserta hasil ujinya, ada di
komentar `config/app.php`. Konversi ke WITA dilakukan lewat `App\Support\Waktu`
di tempat yang memang membutuhkannya.

## Yang belum dikerjakan

Sprint 6 sesuai [`docs/07-roadmap.md`](docs/07-roadmap.md): pemantapan dan serah
terima. Sprint tanpa fitur baru. Rinciannya di
[`docs/08-rangkuman-sprint.md`](docs/08-rangkuman-sprint.md).

Dua hal menunggu pekerjaan manusia dan tidak bisa diselesaikan kode:

1. **Chat ID grup Telegram Diskominfo belum ada.** Seluruh jalur alert sudah jadi
   dan teruji, termasuk pencatatan kegagalan pengiriman.
2. **30 akun media belum dibuat dan portal belum disosialisasikan.** Perintahnya
   siap. Menjalankannya, mengirim kredensial lewat kanal yang aman, dan
   memastikan tiap media berhasil melapor sekali adalah pekerjaan manusia. Google
   Form lama dimatikan setelah itu, bukan sebelumnya.
