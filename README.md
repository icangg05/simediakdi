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
docker compose exec -d app php artisan serve --host=0.0.0.0 --port=8000
```

Buka http://localhost:8000.

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

Setiap URL yang akan diambil melewati `ValidatorUrl` lebih dulu: alamat privat,
loopback, link-local (termasuk `169.254.169.254`), dan skema selain http/https
ditolak, termasuk pada setiap hop redirect. Batas unduhan 5 MB, timeout 20
detik, jeda 1,5 detik per domain, dan `robots.txt` dihormati.

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
| Deduplikasi lapis 1 dan 2 | `app/Services/Dedup/` |
| Ambang dedup dan etika crawling | `config/crawler.php` |

## Yang belum dikerjakan

Sprint 3 dan seterusnya sesuai [`docs/07-roadmap.md`](docs/07-roadmap.md):
layanan NLP, gold set, dashboard eksekutif, portal media, alert.

Deduplikasi lapis 3 (kemiripan makna lewat pgvector) menunggu layanan embedding
di sprint 3. Kolom dan index-nya sudah ada.

Layanan NLP (`nlp/`) belum ada. Dokumen `05-spesifikasi-nlp.md` yang dirujuk
dokumen 00, 02, dan 07 juga belum ada di paket spesifikasi.
