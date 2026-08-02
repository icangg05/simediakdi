# 03 — Skema Database

SIMEDIA Kendari | Versi 1.0 | PostgreSQL 16

---

## Ekstensi yang diaktifkan

```sql
CREATE EXTENSION IF NOT EXISTS vector;    -- embedding, deduplikasi semantik
CREATE EXTENSION IF NOT EXISTS pg_trgm;   -- pencarian judul dengan typo
CREATE EXTENSION IF NOT EXISTS unaccent;  -- normalisasi nama entitas
```

## Konvensi

- Nama tabel: bahasa Indonesia, bentuk tunggal, huruf kecil, `snake_case`. Contoh `artikel`, bukan `articles`.
- Primary key: `id` bigserial.
- Timestamp: `timestamptz`, disimpan UTC.
- Kolom waktu kejadian diberi akhiran `_at`.
- Enum disimpan sebagai `varchar` dengan constraint `CHECK`, bukan tipe enum PostgreSQL. Alasannya menambah nilai enum di PostgreSQL merepotkan saat migrasi, sedangkan `CHECK` mudah diubah.
- Soft delete hanya pada `media`, `kontrak`, dan `user`. Artikel tidak pernah dihapus.

## Diagram relasi

```
user ──┬── media (nullable, hanya untuk peran media)
       │
media ─┼── sumber_feed ──┬── artikel ──┬── analisis_sentimen ── konteks_pantauan
       │                 │             │
       │                 └── log_crawl ├── artikel_entitas ── entitas
       │                               │
       ├── kontrak ── pemuatan ────────┘
       │
       └── ringkasan_harian

konteks_pantauan ── kata_kunci_periode
konteks_pantauan ── gold_set ── artikel
                    evaluasi_model
aturan_alert ── riwayat_alert
activity_log (spatie)
```

---

## 1. user

Perluasan tabel bawaan starter kit. Kolom Fortify dan 2FA tidak diulang di sini.

| Kolom | Tipe | Null | Default | Keterangan |
|-------|------|------|---------|------------|
| id | bigserial | no | | |
| name | varchar(120) | no | | |
| email | varchar(180) | no | | unique |
| password | varchar(255) | no | | |
| peran | varchar(20) | no | | CHECK: `superadmin`, `walikota`, `media` |
| media_id | bigint | yes | null | FK media. Wajib jika peran = `media`, harus null jika bukan |
| jabatan | varchar(120) | yes | | Tampil di audit log agar jejaknya bermakna |
| telepon | varchar(30) | yes | | Kontak pengguna, dipakai admin saat perlu menghubungi PIC media |
| aktif | boolean | no | true | F-46 |
| login_terakhir_at | timestamptz | yes | | |
| ip_login_terakhir | varchar(45) | yes | | |
| deleted_at | timestamptz | yes | | |
| created_at / updated_at | timestamptz | no | | |

Constraint tingkat database:

```sql
CONSTRAINT chk_media_id_sesuai_peran CHECK (
  (peran = 'media' AND media_id IS NOT NULL) OR
  (peran <> 'media' AND media_id IS NULL)
)
```

Constraint ini bukan berlebihan. Tanpa itu, satu bug di form pembuatan user bisa membuat akun superadmin yang punya `media_id`, dan global scope akan berperilaku tak terduga.

Index: `email` unique, `(peran, aktif)`, `media_id`.

---

## 2. media

| Kolom | Tipe | Null | Default | Keterangan |
|-------|------|------|---------|------------|
| id | bigserial | no | | |
| nama | varchar(150) | no | | |
| slug | varchar(150) | no | | unique |
| jenis | varchar(20) | no | `online` | CHECK: `online`, `cetak`, `tv`, `radio` |
| tier | varchar(20) | no | `lokal` | CHECK: `nasional`, `regional`, `lokal`. Dipakai untuk pembobotan di peringkat media |
| url_website | varchar(255) | yes | | |
| domain | varchar(120) | yes | | Diambil dari url_website, dipakai mencocokkan artikel ke media |
| logo_path | varchar(255) | yes | | |
| kota | varchar(100) | yes | | |
| provinsi | varchar(100) | yes | | |
| partner | boolean | no | false | true = punya kerja sama dengan Pemkot |
| nama_pic | varchar(120) | yes | | |
| kontak_pic | varchar(120) | yes | | |
| catatan | text | yes | | |
| aktif | boolean | no | true | |
| deleted_at | timestamptz | yes | | |
| created_at / updated_at | timestamptz | no | | |

Index: `slug` unique, `domain` unique, `(partner, aktif)`.

Kolom `domain` adalah kunci pencocokan otomatis. Saat artikel masuk dari Google News RSS, sistem mencari media berdasarkan domain URL-nya. Kalau tidak ketemu, `artikel.media_id` dibiarkan null dan admin bisa menautkannya nanti.

---

## 3. sumber_feed

Satu media bisa punya beberapa sumber. Contohnya RSS berita utama, RSS kategori daerah, dan satu scraping untuk rubrik yang tidak masuk RSS.

| Kolom | Tipe | Null | Default | Keterangan |
|-------|------|------|---------|------------|
| id | bigserial | no | | |
| media_id | bigint | yes | | FK media. Null untuk sumber Google News yang lintas media |
| nama | varchar(150) | no | | |
| tipe | varchar(20) | no | | CHECK: `rss`, `scrape`, `google_news` |
| url | varchar(500) | no | | |
| selector | jsonb | yes | | Untuk tipe `scrape`: `{"item":"...","judul":"...","tautan":"..."}` |
| kata_kunci | varchar(255) | yes | | Untuk tipe `google_news` |
| interval_menit | integer | no | 30 | |
| aktif | boolean | no | true | |
| dijalankan_terakhir_at | timestamptz | yes | | |
| berhasil_terakhir_at | timestamptz | yes | | |
| gagal_berturut | smallint | no | 0 | Sumber dinonaktifkan saat mencapai 5 (F-07) |
| pesan_error_terakhir | text | yes | | |
| created_at / updated_at | timestamptz | no | | |

Index: `(aktif, dijalankan_terakhir_at)` untuk memilih sumber yang jatuh tempo, `media_id`.

---

## 4. artikel

Tabel terbesar. Rancang indexnya dengan hati-hati.

| Kolom | Tipe | Null | Default | Keterangan |
|-------|------|------|---------|------------|
| id | bigserial | no | | |
| media_id | bigint | yes | | FK media. Null jika domain belum dikenali |
| sumber_feed_id | bigint | yes | | FK sumber_feed |
| judul | varchar(500) | no | | |
| url | varchar(1000) | no | | URL asli dari feed |
| url_kanonik | varchar(1000) | no | | Hasil normalisasi: tanpa query tracking, tanpa trailing slash, lowercase host |
| ringkasan | varchar(600) | yes | | Maksimal 300 karakter ditampilkan di UI |
| isi | text | yes | | Isi lengkap. Untuk analisis saja, tidak pernah ditampilkan utuh |
| penulis | varchar(150) | yes | | |
| jumlah_kata | integer | yes | | Artikel di bawah 80 kata biasanya hanya teaser, ditandai untuk audit |
| gambar_url | varchar(1000) | yes | | |
| dipublikasikan_at | timestamptz | yes | | Dari feed. Bisa null atau salah, jangan diandalkan sendiri |
| diambil_at | timestamptz | no | now() | Waktu sistem mengambilnya. Ini yang dipakai untuk grafik harian |
| hash_isi | char(64) | yes | | SHA-256 dari isi yang sudah dinormalisasi |
| simhash | bigint | yes | | 64-bit simhash untuk near-duplicate |
| embedding | vector(384) | yes | | |
| status_dedup | varchar(20) | no | `asli` | CHECK: `asli`, `salinan` |
| artikel_induk_id | bigint | yes | | FK artikel. Diisi jika status_dedup = `salinan` |
| skor_kemiripan | real | yes | | Cosine similarity terhadap induk, untuk audit |
| status_proses | varchar(20) | no | `mentah` | CHECK: `mentah`, `isi_diambil`, `dianalisis`, `selesai`, `gagal` |
| pesan_gagal | text | yes | | |
| created_at / updated_at | timestamptz | no | | |

Index:

```sql
CREATE UNIQUE INDEX uq_artikel_url_kanonik ON artikel (url_kanonik);
CREATE INDEX idx_artikel_diambil ON artikel (diambil_at DESC);
CREATE INDEX idx_artikel_media_waktu ON artikel (media_id, diambil_at DESC);
CREATE INDEX idx_artikel_status_proses ON artikel (status_proses)
  WHERE status_proses <> 'selesai';
CREATE INDEX idx_artikel_asli ON artikel (diambil_at DESC)
  WHERE status_dedup = 'asli';
CREATE INDEX idx_artikel_judul_trgm ON artikel USING gin (judul gin_trgm_ops);
CREATE INDEX idx_artikel_embedding ON artikel
  USING hnsw (embedding vector_cosine_ops);
CREATE INDEX idx_artikel_simhash ON artikel (simhash);
```

Dua index partial di atas penting. `idx_artikel_status_proses` hanya mengindeks baris yang belum selesai, dan jumlahnya selalu kecil, sehingga worker menemukan pekerjaan tanpa memindai seluruh tabel. `idx_artikel_asli` melayani hampir semua query dashboard, karena hampir semua agregasi mengecualikan salinan.

Aturan integritas:

- `artikel_induk_id` harus null saat `status_dedup = 'asli'`.
- `artikel_induk_id` tidak boleh menunjuk artikel yang sendirinya salinan. Rantai duplikat maksimal satu tingkat. Kalau artikel C mirip artikel B yang salinan dari A, C menunjuk ke A.

---

## 5. konteks_pantauan

Sasaran penilaian sentimen. Isinya menjadi input `konteks` pada model IndoBERT.

| Kolom | Tipe | Null | Default | Keterangan |
|-------|------|------|---------|------------|
| id | bigserial | no | | |
| nama | varchar(200) | no | | Persis seperti yang dikirim ke model. Contoh: `Pemerintah Kota Kendari` |
| slug | varchar(200) | no | | unique |
| deskripsi | text | yes | | Untuk admin, tidak dikirim ke model |
| kata_kunci | jsonb | yes | | Penyaring awal sebelum model relevansi dipanggil, menghemat inferensi |
| utama | boolean | no | false | Tepat satu baris bernilai true. Ini yang tampil di dashboard eksekutif |
| urutan | smallint | no | 0 | |
| aktif | boolean | no | true | |
| created_at / updated_at | timestamptz | no | | |

Index: `slug` unique, `(aktif, urutan)`, dan unique partial agar hanya ada satu konteks utama:

```sql
CREATE UNIQUE INDEX uq_konteks_utama ON konteks_pantauan (utama) WHERE utama = true;
```

Baris awal yang disiapkan lewat seeder: Pemerintah Kota Kendari (utama), Walikota Kendari, Infrastruktur dan jalan, Kebersihan dan sampah, Pelayanan publik, Kesehatan, Pendidikan, Ekonomi dan UMKM.

Peringatan: menambah konteks berarti menambah beban inferensi secara linear. Delapan konteks berarti delapan kali panggilan relevansi per artikel. Kata kunci penyaring di kolom `kata_kunci` yang membuat ini tetap murah.

---

## 6. analisis_sentimen

Satu baris per pasangan artikel dan konteks.

| Kolom | Tipe | Null | Default | Keterangan |
|-------|------|------|---------|------------|
| id | bigserial | no | | |
| artikel_id | bigint | no | | FK artikel, on delete cascade |
| konteks_pantauan_id | bigint | no | | FK konteks_pantauan |
| relevan | boolean | no | | Hasil model relevansi |
| keyakinan_relevansi | real | yes | | |
| label_model | varchar(10) | yes | | CHECK: `negatif`, `netral`, `positif` |
| skor_negatif | real | yes | | |
| skor_netral | real | yes | | |
| skor_positif | real | yes | | |
| keyakinan | real | yes | | Skor tertinggi dari ketiganya |
| perlu_review | boolean | no | false | true jika keyakinan di bawah ambang (F-12) |
| model_versi | varchar(60) | yes | | |
| dianalisis_at | timestamptz | yes | | |
| label_manual | varchar(10) | yes | | CHECK sama. Mengalahkan label_model |
| dikoreksi_oleh | bigint | yes | | FK user |
| dikoreksi_at | timestamptz | yes | | |
| catatan_koreksi | text | yes | | |
| created_at / updated_at | timestamptz | no | | |

Kolom turunan yang disimpan, bukan dihitung saat query:

| Kolom | Tipe | Keterangan |
|-------|------|------------|
| label_efektif | varchar(10) | `COALESCE(label_manual, label_model)`. Kolom generated |

```sql
ALTER TABLE analisis_sentimen ADD COLUMN label_efektif varchar(10)
  GENERATED ALWAYS AS (COALESCE(label_manual, label_model)) STORED;
```

Kolom generated dipakai karena seluruh agregasi membaca label efektif, dan menulis `COALESCE` di setiap query adalah sumber bug yang tidak terlihat sampai ada satu tempat yang lupa.

Index:

```sql
CREATE UNIQUE INDEX uq_analisis_artikel_konteks
  ON analisis_sentimen (artikel_id, konteks_pantauan_id);
CREATE INDEX idx_analisis_konteks_label
  ON analisis_sentimen (konteks_pantauan_id, label_efektif)
  WHERE relevan = true;
CREATE INDEX idx_analisis_perlu_review
  ON analisis_sentimen (perlu_review) WHERE perlu_review = true;
```

---

## 7. entitas dan artikel_entitas

**entitas**

| Kolom | Tipe | Null | Keterangan |
|-------|------|------|------------|
| id | bigserial | no | |
| nama | varchar(200) | no | Bentuk kanonik yang ditampilkan |
| nama_normal | varchar(200) | no | Huruf kecil, tanpa aksen, untuk pencocokan. unique |
| jenis | varchar(20) | no | CHECK: `orang`, `organisasi`, `opd`, `lokasi`, `program`, `lain` |
| alias | jsonb | yes | Daftar variasi penulisan (F-18) |
| digabung_ke | bigint | yes | FK entitas. Diisi saat admin menggabungkan duplikat |
| created_at / updated_at | timestamptz | no | |

**artikel_entitas**

| Kolom | Tipe | Keterangan |
|-------|------|------------|
| artikel_id | bigint | FK, bagian dari PK komposit |
| entitas_id | bigint | FK, bagian dari PK komposit |
| jumlah_sebutan | smallint | Berapa kali muncul di artikel |

Index: `(entitas_id, artikel_id)` untuk arah query sebaliknya.

---

## 8. kata_kunci_periode

Tabel praperhitungan untuk halaman isu hangat. Jangan menghitung ini saat request.

| Kolom | Tipe | Null | Keterangan |
|-------|------|------|------------|
| id | bigserial | no | |
| konteks_pantauan_id | bigint | yes | Null = lintas semua konteks |
| granularitas | varchar(10) | no | CHECK: `harian`, `mingguan` |
| periode_mulai | date | no | |
| periode_akhir | date | no | |
| istilah | varchar(120) | no | Unigram atau bigram |
| frekuensi | integer | no | Total kemunculan |
| jumlah_artikel | integer | no | Jumlah artikel yang memuatnya |
| skor_lonjakan | real | yes | frekuensi periode ini dibagi rata-rata 4 periode sebelumnya |
| sentimen_dominan | varchar(10) | yes | Label efektif terbanyak pada artikel yang memuat istilah ini |
| created_at | timestamptz | no | |

Index: `(granularitas, periode_mulai, skor_lonjakan DESC)`, unique `(konteks_pantauan_id, granularitas, periode_mulai, istilah)`.

Kolom `sentimen_dominan` yang membuat halaman ini berguna. Kata kunci "banjir" naik 300% tidak bermakna apa pun sampai Anda tahu 85% artikel yang memuatnya bernada negatif.

---

## 9. kontrak

| Kolom | Tipe | Null | Default | Keterangan |
|-------|------|------|---------|------------|
| id | bigserial | no | | |
| media_id | bigint | no | | FK media |
| nomor | varchar(120) | yes | | Nomor dokumen kontrak |
| judul | varchar(250) | no | | |
| jenis | varchar(30) | no | | CHECK: `advertorial`, `publikasi`, `banner`, `lain` |
| tanggal_mulai | date | no | | |
| tanggal_akhir | date | no | | |
| nilai | numeric(15,2) | yes | | Rupiah |
| target_pemuatan | integer | yes | | Jumlah artikel yang dijanjikan |
| berkas_path | varchar(255) | yes | | Salinan dokumen |
| status | varchar(20) | no | `draft` | CHECK: `draft`, `aktif`, `selesai`, `batal` |
| catatan | text | yes | | |
| deleted_at | timestamptz | yes | | |
| created_at / updated_at | timestamptz | no | | |

Constraint: `CHECK (tanggal_akhir >= tanggal_mulai)`.

Index: `(media_id, status)`, `(status, tanggal_akhir)` untuk F-26.

Sudah dikonfirmasi ke Diskominfo (dokumen 01 bagian 9 nomor 2): tidak ada dokumen kontrak yang bisa dirujuk, jadi target tetap berupa jumlah artikel per periode. Kalau nanti muncul kontrak yang mengatur target per jenis penempatan, misalnya 10 advertorial ditambah 20 berita biasa, tabel ini butuh tabel anak `kontrak_target`. Jangan bangun itu sebelum kontraknya ada di tangan.

---

## 10. pemuatan

Realisasi kontrak. Bisa terisi otomatis dari artikel yang ter-crawl, atau dilaporkan media.

| Kolom | Tipe | Null | Default | Keterangan |
|-------|------|------|---------|------------|
| id | bigserial | no | | |
| kontrak_id | bigint | no | | FK kontrak |
| media_id | bigint | no | | FK media. Denormalisasi agar global scope peran media sederhana |
| artikel_id | bigint | yes | | FK artikel. Null jika dilaporkan manual dan belum ter-crawl |
| url | varchar(1000) | no | | |
| judul | varchar(500) | yes | | |
| tanggal_muat | date | no | | |
| sumber_catatan | varchar(20) | no | | CHECK: `otomatis`, `laporan_media`, `input_admin` |
| bukti_path | varchar(255) | yes | | Tangkapan layar unggahan media, pelengkap arsip sistem |
| status_ekstraksi | varchar(20) | no | `menunggu` | CHECK: `menunggu`, `berhasil`, `gagal`. Gagal tidak menghalangi verifikasi (F-51) |
| arsip_teks | text | yes | | Teks hasil ekstraksi saat laporan dikonfirmasi (F-52). Bukti permanen, tidak diubah walau artikel sumber berubah |
| arsip_screenshot_path | varchar(255) | yes | | Tangkapan layar yang diambil sistem sendiri lewat Playwright |
| arsip_diambil_at | timestamptz | yes | | Waktu pengambilan arsip |
| status_verifikasi | varchar(20) | no | `menunggu` | CHECK: `menunggu`, `terverifikasi`, `ditolak` |
| dilaporkan_oleh | bigint | yes | | FK user |
| diverifikasi_oleh | bigint | yes | | FK user |
| diverifikasi_at | timestamptz | yes | | |
| alasan_penolakan | text | yes | | Wajib diisi saat status = `ditolak` |
| created_at / updated_at | timestamptz | no | | |

Index: unique `(kontrak_id, url)` agar satu URL tidak diklaim dua kali pada kontrak yang sama, `(media_id, status_verifikasi)`, `(kontrak_id, status_verifikasi)`.

Pemuatan dengan `sumber_catatan = 'otomatis'` langsung berstatus `terverifikasi`, karena sistem sendiri yang menemukannya. Yang perlu diverifikasi manusia hanya laporan dari media.

Alasan kolom arsip: media bisa menghapus atau mengubah artikel setelah pembayaran cair. Arsip teks dan tangkapan layar yang diambil sistem sendiri, dengan waktu tercatat, adalah bukti yang tidak bergantung pada itikad media. `bukti_path` dari unggahan media dipertahankan sebagai pelengkap, bukan bukti utama, karena tangkapan layar buatan pihak yang berkepentingan mudah dimanipulasi.

---

## 11. ringkasan_harian

Tabel praperhitungan untuk seluruh grafik. Satu baris per kombinasi tanggal, media, dan konteks.

| Kolom | Tipe | Null | Keterangan |
|-------|------|------|------------|
| id | bigserial | no | |
| tanggal | date | no | Dalam WITA |
| media_id | bigint | yes | Null = agregat semua media |
| konteks_pantauan_id | bigint | yes | Null = agregat semua konteks |
| jumlah_artikel | integer | no | Hanya yang status_dedup = asli |
| jumlah_salinan | integer | no | Ditampilkan terpisah agar angka tetap dipercaya |
| jumlah_negatif | integer | no | |
| jumlah_netral | integer | no | |
| jumlah_positif | integer | no | |
| jumlah_perlu_review | integer | no | |
| dihitung_at | timestamptz | no | |

Index: unique `(tanggal, media_id, konteks_pantauan_id)`, `(tanggal DESC)`.

Baris dengan `media_id` dan `konteks_pantauan_id` bernilai null melayani dashboard eksekutif dengan satu baris per hari. Itu sebabnya halaman ini bisa selesai di bawah dua detik.

Job `hitung:ringkasan-harian` menulis ulang baris hari ini setiap 10 menit menggunakan `INSERT ... ON CONFLICT DO UPDATE`, dan menulis ulang tujuh hari terakhir sekali sehari untuk menangkap koreksi label yang terjadi belakangan.

---

## 12. gold_set dan evaluasi_model

**gold_set** — data uji berlabel manusia (F-19)

| Kolom | Tipe | Null | Keterangan |
|-------|------|------|------------|
| id | bigserial | no | |
| artikel_id | bigint | no | FK artikel |
| konteks_pantauan_id | bigint | no | FK konteks_pantauan |
| label_gold | varchar(10) | no | CHECK: `negatif`, `netral`, `positif` |
| relevan_gold | boolean | no | |
| dilabeli_oleh | bigint | no | FK user |
| dilabeli_at | timestamptz | no | |
| ronde | smallint | no | 1 atau 2. Ronde 2 untuk mengukur konsistensi pelabel yang sama |
| catatan | text | yes | Alasan pelabelan, berguna saat menyelesaikan sengketa |

Index: unique `(artikel_id, konteks_pantauan_id, ronde)`.

**evaluasi_model**

| Kolom | Tipe | Null | Keterangan |
|-------|------|------|------------|
| id | bigserial | no | |
| model_versi | varchar(60) | no | |
| dievaluasi_at | timestamptz | no | |
| jumlah_sampel | integer | no | |
| akurasi | real | no | |
| f1_macro | real | no | |
| f1_negatif | real | no | |
| f1_netral | real | no | |
| f1_positif | real | no | |
| confusion_matrix | jsonb | no | Matriks 3x3 |
| ambang_keyakinan | real | no | Ambang yang berlaku saat evaluasi dijalankan |
| catatan | text | yes | |

Angka pada baris terbaru di tabel ini tampil di UI, di halaman detail artikel dan di footer dashboard eksekutif. Lihat dokumen 04.

---

## 13. aturan_alert dan riwayat_alert

**aturan_alert**

| Kolom | Tipe | Null | Default | Keterangan |
|-------|------|------|---------|------------|
| id | bigserial | no | | |
| nama | varchar(150) | no | | |
| jenis | varchar(30) | no | | CHECK: `lonjakan_negatif`, `kata_kunci_muncul`, `sumber_mati`, `kontrak_tertinggal` |
| konteks_pantauan_id | bigint | yes | | |
| kondisi | jsonb | no | | Parameter spesifik per jenis |
| ambang | real | yes | | |
| jendela_jam | smallint | no | 6 | |
| jeda_minimal_jam | smallint | no | 6 | Pembatas pengiriman berulang (F-40) |
| kanal | varchar(20) | no | `telegram` | CHECK: `telegram`, `email` |
| penerima | jsonb | no | | Chat ID atau alamat email |
| aktif | boolean | no | true | |
| dipicu_terakhir_at | timestamptz | yes | | |
| created_at / updated_at | timestamptz | no | | |

Contoh isi `kondisi` untuk jenis `lonjakan_negatif`:

```json
{ "minimal_artikel": 3, "kelipatan_dari_rata_rata": 2.0, "abaikan_perlu_review": true }
```

**riwayat_alert**

| Kolom | Tipe | Null | Keterangan |
|-------|------|------|------------|
| id | bigserial | no | |
| aturan_alert_id | bigint | no | FK |
| dipicu_at | timestamptz | no | |
| ringkasan | varchar(500) | no | Isi pesan yang dikirim |
| payload | jsonb | yes | Artikel dan angka yang memicunya, untuk audit |
| status_kirim | varchar(20) | no | CHECK: `terkirim`, `gagal`, `tertunda` |
| pesan_error | text | yes | |
| dibaca_at | timestamptz | yes | Diisi saat dibuka di UI |

---

## 14. log_crawl

| Kolom | Tipe | Null | Keterangan |
|-------|------|------|------------|
| id | bigserial | no | |
| sumber_feed_id | bigint | no | FK |
| dimulai_at | timestamptz | no | |
| selesai_at | timestamptz | yes | |
| jumlah_ditemukan | integer | no | Total item di feed |
| jumlah_baru | integer | no | Yang belum ada di database |
| jumlah_salinan | integer | no | Yang terdeteksi duplikat |
| status | varchar(20) | no | CHECK: `sukses`, `sebagian`, `gagal` |
| pesan | text | yes | |

Index: `(sumber_feed_id, dimulai_at DESC)`.

Simpan 90 hari, lalu hapus lewat scheduled job. Tabel ini tumbuh cepat dan nilainya menurun tajam setelah beberapa minggu.

---

## 15. activity_log

Dari `spatie/laravel-activitylog`, skema bawaan paket. Yang perlu diatur:

- Aktifkan `logOnlyDirty()` agar hanya perubahan yang tercatat.
- Aktifkan pada model: `Media`, `SumberFeed`, `Kontrak`, `Pemuatan`, `KontekPantauan`, `AnalisisSentimen`, `User`, `AturanAlert`.
- Jangan aktifkan pada `Artikel`. Volume perubahannya besar dan nilainya kecil.
- Catat khusus untuk peristiwa login peran walikota, meskipun bukan aksi tulis. Lihat dokumen 06.

---

## Query agregasi utama

Dua query berikut adalah yang paling sering dipakai. Tulis sebagai method di service `Agregasi`, bukan di controller.

### Ringkasan dashboard eksekutif

```sql
SELECT tanggal, jumlah_artikel, jumlah_negatif, jumlah_netral,
       jumlah_positif, jumlah_perlu_review
FROM ringkasan_harian
WHERE media_id IS NULL
  AND konteks_pantauan_id = :konteks_utama
  AND tanggal BETWEEN :mulai AND :akhir
ORDER BY tanggal;
```

Satu index scan, tanpa join, tanpa agregasi. Inilah alasan tabel ringkasan ada.

### Pencarian duplikat semantik

```sql
SELECT id, judul, 1 - (embedding <=> :embedding_baru) AS kemiripan
FROM artikel
WHERE status_dedup = 'asli'
  AND diambil_at > now() - interval '7 days'
  AND embedding IS NOT NULL
ORDER BY embedding <=> :embedding_baru
LIMIT 5;
```

Batasan tujuh hari itu penting. Tanpa itu, pencarian menyusuri seluruh tabel dan berita tentang banjir tahun lalu bisa dianggap salinan berita banjir hari ini.

---

## Changelog

| Versi | Tanggal | Perubahan |
|-------|---------|-----------|
| 1.0 | Juli 2026 | Skema awal |
| 1.1 | Juli 2026 | Tabel `pemuatan`: `media_id` menjadi nullable; kolom baru `status_ekstraksi`, `arsip_teks`, `arsip_screenshot_path`, `arsip_diambil_at`, `email_pelapor`; nilai `google_form` pada `sumber_catatan` |
| 1.3 | Agustus 2026 | Penyesuaian saat implementasi sprint 1. **(a)** Index unique `ringkasan_harian (tanggal, media_id, konteks_pantauan_id)` dan `kata_kunci_periode (konteks_pantauan_id, granularitas, periode_mulai, istilah)` dibuat dengan `NULLS NOT DISTINCT`. Baris agregat memakai NULL pada kolom-kolom itu, dan dengan perilaku default PostgreSQL (NULLS DISTINCT) `INSERT ... ON CONFLICT DO UPDATE` tidak pernah cocok sehingga baris duplikat menumpuk tiap 10 menit dan angka dashboard salah. **(b)** `users.peran` sengaja tanpa nilai default; user yang dibuat tanpa peran harus gagal keras, bukan diam-diam menjadi `superadmin`. **(c)** Tiga CHECK tambahan yang menegakkan aturan integritas yang sudah tertulis di dokumen ini tapi belum punya constraint: `artikel` (artikel_induk_id null persis saat status_dedup = `asli`), `pemuatan` (alasan_penolakan wajib saat status_verifikasi = `ditolak`), `gold_set` (ronde hanya 1 atau 2) |
| 1.2 | Agustus 2026 | Jembatan Google Form dibatalkan: `pemuatan.media_id` kembali NOT NULL, kolom `email_pelapor` dihapus, nilai `google_form` dihapus dari CHECK `sumber_catatan`. Kolom `user.telepon` bukan lagi untuk WhatsApp. Seeder media diisi 30 media dari lampiran A dokumen 01, seeder konteks pantauan diisi tiga konteks dari dokumen 01 bagian 9 |

Setiap penambahan tabel atau kolom yang tidak ada di dokumen ini dicatat di sini bersamaan dengan migration-nya.
