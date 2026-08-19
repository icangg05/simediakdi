# 03: Skema Database

SIMAK Kendari | Versi 1.0 | PostgreSQL 16

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
| tipe | varchar(20) | no | | CHECK: `rss`, `scrape`, `scrape_render`. `google_news` sudah dicabut |
| url | varchar(500) | no | | |
| selector | jsonb | yes | | Wajib untuk `scrape` dan `scrape_render`: `{"item":"...","judul":"...","tautan":"..."}`. Pada `scrape_render`, `item` juga jadi syarat tunggu peramban |
| kata_kunci | varchar(255) | yes | | Untuk tipe `google_news` |
| interval_menit | integer | no | 30 | |
| aktif | boolean | no | true | |
| dijalankan_terakhir_at | timestamptz | yes | | |
| berhasil_terakhir_at | timestamptz | yes | | |
| gagal_berturut | smallint | no | 0 | Ditandai perlu diperiksa saat mencapai 5 (F-07). Tidak menonaktifkan sumber |
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
| post_id_sumber | bigint | yes | | ID post di CMS sumber. Dari `/wp-json/wp/v2/posts` |
| url_api_sumber | varchar(1000) | yes | | Endpoint REST tempat artikel ini ditarik, untuk penarikan ulang |
| kategori_sumber | jsonb | yes | | Nama kategori WordPress, sudah diterjemahkan dari ID term |
| tag_sumber | jsonb | yes | | Nama tag WordPress. Sinyal, bukan keputusan |
| diubah_sumber_at | timestamptz | yes | | `modified` dari WordPress. Menandai artikel yang disunting setelah terbit |
| hash_isi | char(64) | yes | | SHA-256 dari isi yang sudah dinormalisasi |
| simhash | bigint | yes | | 64-bit simhash untuk near-duplicate |
| embedding | vector(384) | yes | | Dari isi penuh. Dipakai mencari salinan |
| ~~embedding_relevansi~~ | vector(384) | yes | | **Dipensiunkan versi 1.6.** Relevansi tidak lagi dinilai dari kemiripan vektor. Dihapus pada fase 1 laboratorium |
| status_dedup | varchar(20) | no | `asli` | CHECK: `asli`, `salinan` |
| artikel_induk_id | bigint | yes | | FK artikel. Diisi jika status_dedup = `salinan` |
| skor_kemiripan | real | yes | | Cosine similarity terhadap induk, untuk audit |
| status_proses | varchar(20) | no | `mentah` | CHECK: `mentah`, `isi_diambil`, `dianalisis`, `tidak_relevan`, `perlu_review`, `selesai`, `gagal` |
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

**Kembali satu vektor sejak versi 1.6.** Versi 1.5 menyimpan dua vektor per
artikel, satu untuk mencari salinan dan satu untuk menilai relevansi. Yang
kedua tidak dipakai lagi setelah relevansi berpindah ke classifier, dan
dihapus pada fase 1 laboratorium.

Yang tersisa adalah `embedding` beserta index HNSW-nya, dan bagian ini tidak
berubah sama sekali. Alasan aslinya juga tidak berubah: pencarian salinan
membutuhkan tetangga terdekat, dan itu memang pekerjaan yang dilayani index
HNSW.

Kebutuhan yang dulu dijawab `embedding_relevansi`, yaitu gambaran artikel yang
terfokus pada bagian yang menyinggung Pemkot, tidak hilang. Ia tetap dibangun
oleh `JendelaKonteks`, hanya keluarannya sekarang menjadi teks yang masuk
tokenizer, bukan vektor yang disimpan.

Dua index partial di atas penting. `idx_artikel_status_proses` hanya mengindeks baris yang belum selesai, dan jumlahnya selalu kecil, sehingga worker menemukan pekerjaan tanpa memindai seluruh tabel. `idx_artikel_asli` melayani hampir semua query dashboard, karena hampir semua agregasi mengecualikan salinan.

**Grup duplikat** untuk pembagian data train dan test adalah
`COALESCE(artikel_induk_id, id)`, bukan kolom baru. Rantai duplikat sudah
dijamin maksimal satu tingkat oleh aturan integritas di bawah, jadi ekspresi
itu selalu menghasilkan satu identitas grup yang stabil. Menambah kolom
`grup_duplikat` berarti menyimpan ulang informasi yang sudah ada di tabel
terbesar sistem, dan menambah satu tempat lagi yang bisa tidak sinkron.

Empat hal yang diminta usulan revisi 1.3 sengaja **tidak** ditambahkan karena
kolomnya sudah ada dengan nama lain: `canonical_url` sudah `url_kanonik`,
`content_hash` sudah `hash_isi`, `duplicate_parent_id` sudah `artikel_induk_id`,
dan `source_excerpt` sudah `ringkasan`.

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
| deskripsi_model | text | yes | | Teks konteks yang dipasangkan ke artikel pada tokenizer relevansi. Sejak versi 1.6 tidak di-embed lagi, dan versinya dikelola tabel `versi_konteks_relevansi` |
| ~~embedding~~ | vector(384) | yes | | **Dipensiunkan versi 1.6.** Dihapus pada fase 1 laboratorium |
| kata_kunci | jsonb | yes | | Sinyal penjelas keputusan dan bahan skor prioritas antrean pelabelan |
| utama | boolean | no | false | Tepat satu baris bernilai true. Ini yang tampil di dashboard eksekutif |
| urutan | smallint | no | 0 | |
| aktif | boolean | no | true | |
| created_at / updated_at | timestamptz | no | | |

Index: `slug` unique, `(aktif, urutan)`, dan unique partial agar hanya ada satu konteks utama:

```sql
CREATE UNIQUE INDEX uq_konteks_utama ON konteks_pantauan (utama) WHERE utama = true;
```

Baris yang disiapkan lewat seeder sejak versi 1.4: **satu konteks aktif**,
Pemerintah Kota Kendari (`utama = true`). Wali Kota Kendari dan Pelayanan
publik dan infrastruktur tetap ada sebagai baris dengan `aktif = false`, supaya
gold set lama yang menunjuk ke sana tidak menggantung dan supaya tabelnya siap
kalau konteks kedua benar-benar diminta.

Alasan penyederhanaannya, beserta angka presisi yang mendasarinya, ada di
dokumen 01 bagian 9.

Peringatan yang tetap berlaku: menambah konteks aktif menambah beban inferensi
secara linear, dan yang lebih mahal, menambah beban pelabelan manusia secara
linear juga. Yang kedua itu yang sebenarnya membunuh, karena gold set adalah
satu-satunya hal di sistem ini yang tidak bisa dipercepat dengan server lebih
besar.

---

## 6. analisis_sentimen

Satu baris per pasangan artikel dan konteks.

| Kolom | Tipe | Null | Default | Keterangan |
|-------|------|------|---------|------------|
| id | bigserial | no | | |
| artikel_id | bigint | no | | FK artikel, on delete cascade |
| konteks_pantauan_id | bigint | no | | FK konteks_pantauan |
| relevan | boolean | no | | Hasil penilaian relevansi |
| skor_relevansi | real | yes | | Sejak versi 1.6 berisi `probabilitas_relevan` dari classifier, dan itu **memang probabilitas**. Sebelumnya cosine similarity |
| versi_model_relevansi_id | bigint | yes | | FK `versi_model_relevansi`. Model yang menghasilkan keputusan pada baris ini |
| versi_threshold_relevansi_id | bigint | yes | | FK `versi_threshold_relevansi`. Ambang yang berlaku saat itu |
| relevan_manual | boolean | yes | | Koreksi manusia atas relevansi. Mengalahkan `relevan` |
| alasan_relevansi | text | yes | | Alasan admin mengubah keputusan relevansi |
| sinyal_relevansi | jsonb | yes | | Sinyal judul, tag, dan alias yang benar-benar ditemukan sistem |
| konteks_versi | varchar(40) | yes | | Versi definisi konteks saat baris ini dinilai |
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
| relevan_efektif | boolean | `COALESCE(relevan_manual, relevan)`. Kolom generated |

```sql
ALTER TABLE analisis_sentimen ADD COLUMN label_efektif varchar(10)
  GENERATED ALWAYS AS (COALESCE(label_manual, label_model)) STORED;

ALTER TABLE analisis_sentimen ADD COLUMN relevan_efektif boolean
  GENERATED ALWAYS AS (COALESCE(relevan_manual, relevan)) STORED;
```

Kolom generated dipakai karena seluruh agregasi membaca label efektif, dan menulis `COALESCE` di setiap query adalah sumber bug yang tidak terlihat sampai ada satu tempat yang lupa. `relevan_efektif` mengikuti pola yang sama, dan alasannya lebih kuat lagi: F-13 menjanjikan koreksi manusia selalu mengalahkan model, dan janji itu paling mudah dilanggar oleh satu query agregasi yang lupa memeriksanya.

**Mengapa relevansi tidak dipindah ke tabel `artikel`.** Usulan revisi 1.3 meminta relevansi menjadi kolom tingkat artikel. Dengan satu konteks aktif, index unik `(artikel_id, konteks_pantauan_id)` sudah menjamin tepat satu baris relevansi per artikel, jadi bentuknya sama persis. Memindahkannya berarti migrasi tabel terbesar sistem, menulis ulang seluruh kueri agregasi dan halaman evaluasi yang sudah terukur, dan menutup pintu ke konteks kedua. Yang dibeli hanya satu join yang sudah ada indexnya.

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

Index `idx_analisis_konteks_label` memakai `relevan = true` sejak versi 1.0.
Ubah menjadi `relevan_efektif = true` bersamaan dengan migration kolom generated,
kalau tidak, artikel yang admin nyatakan tidak relevan tetap terhitung di
seluruh grafik.

**Dua kolom versi yang ditambahkan versi 1.6 menjawab pertanyaan yang dulu
tidak bisa dijawab:** model mana yang memutuskan baris ini. Selama relevansi
berupa cosine dengan ambang global di `.env`, jawabannya ada di git dan itu
cukup. Begitu ada beberapa versi model yang bergantian menjadi produksi,
sebuah baris tanpa penunjuk versi adalah keputusan tanpa asal usul, dan tidak
mungkin diketahui apakah kesalahannya berasal dari model yang sudah diganti.

Kolom ini menyimpan keputusan **yang berlaku sekarang**, satu baris per artikel.
Riwayat lengkap tiap prediksi, termasuk prediksi model kandidat yang tidak
pernah dipromosikan, disimpan di `prediksi_relevansi` dan tidak pernah ditimpa.
Lihat dokumen 10 bagian 16.2.

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

**Artikel salinan tetap dihitung sebagai pemuatan.** Ini aturan yang mudah dilanggar, jadi ditulis di sini dan dikunci dengan test.

Deduplikasi menjawab "berapa isu", kontrak menjawab "berapa pemuatan dari media ini". Keduanya pertanyaan berbeda. Satu rilis yang dimuat lima media adalah satu isu tetapi lima pemuatan, masing-masing dengan URL, halaman, dan bukti arsipnya sendiri, dan tiap media berhak menghitungnya ke targetnya.

Alasan kedua lebih tajam: `status_dedup = 'asli'` menandai artikel yang **lebih dulu di-crawl**, bukan yang lebih dulu terbit. Nilainya bergantung pada jadwal crawler. Memakainya untuk menilai realisasi kontrak berarti menghukum media yang feed-nya kebetulan ditarik belakangan, dan itu tidak bisa dipertahankan kalau media menanyakannya.

Ringkasnya:

| Pertanyaan | Menghitung apa |
|---|---|
| Berapa berita hari ini di dashboard | Hanya `asli` |
| Bagaimana komposisi sentimennya | Hanya `asli` |
| Berapa realisasi kontrak media X | **Semua**, termasuk `salinan` |
| Berapa banyak media X memuat | **Semua**, dengan jumlah salinan ditampilkan terpisah |

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

> **Versi 1.6:** untuk relevansi, kedua tabel ini digantikan tabel laboratorium
> di bagian 16. `gold_set.relevan_gold` bermigrasi menjadi
> `sampel_relevansi.label_manual`, dan metrik relevansi berpindah ke
> `evaluasi_model_relevansi`. Keduanya **tetap ada dan tetap dipakai untuk
> sentimen**, yang belum punya laboratorium sendiri. Barisnya jangan dihapus:
> 249 label relevansi di dalamnya adalah benih dataset laboratorium, dan
> riwayat evaluasi lama adalah satu-satunya pembanding untuk mengukur apakah
> model baru benar-benar lebih baik.

**gold_set**, data uji berlabel manusia (F-19)

| Kolom | Tipe | Null | Keterangan |
|-------|------|------|------------|
| id | bigserial | no | |
| artikel_id | bigint | no | FK artikel |
| konteks_pantauan_id | bigint | no | FK konteks_pantauan |
| label_gold | varchar(10) | yes | CHECK: `negatif`, `netral`, `positif`. Null saat `relevan_gold = false` |
| relevan_gold | boolean | no | Label relevansi biner. Inilah gold set relevansi |
| dilabeli_oleh | bigint | no | FK user |
| dilabeli_at | timestamptz | no | |
| ronde | smallint | no | 1 atau 2. Ronde 2 untuk mengukur konsistensi pelabel yang sama |
| gold_set_versi | varchar(20) | no | Dinaikkan setiap definisi konteks berubah |
| split | varchar(10) | yes | CHECK: `latih`, `validasi`, `uji`. Ditetapkan per grup duplikat |
| catatan | text | yes | Alasan pelabelan, berguna saat menyelesaikan sengketa |

Index: unique `(artikel_id, konteks_pantauan_id, ronde)`.

`label_gold` menjadi nullable pada versi 1.4. Artikel tidak relevan tidak diberi
sentimen, dan sebelumnya keharusan mengisinya memaksa pelabel memilih `netral`
untuk artikel yang bahkan tidak dibaca nadanya. Constraint penggantinya:

```sql
CONSTRAINT chk_sentimen_hanya_saat_relevan CHECK (
  (relevan_gold = true AND label_gold IS NOT NULL) OR
  (relevan_gold = false AND label_gold IS NULL)
)
```

Kolom `split` ditetapkan per grup duplikat (`COALESCE(artikel_induk_id, id)`),
tidak pernah per baris. Seluruh salinan satu berita harus jatuh di split yang
sama, kalau tidak, rilis Antara yang sama muncul di data latih dan di data uji
sekaligus, dan angka evaluasinya bohong ke atas. Baris dengan `split = 'uji'`
tidak pernah dipakai memilih ambang.

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
| minimal_sebutan | smallint | yes | Nilai pengetat frekuensi yang berlaku saat evaluasi |
| konteks_versi | varchar(40) | yes | Versi definisi konteks utama |
| gold_set_versi | varchar(20) | yes | Versi gold set yang dipakai |
| presisi_relevansi | real | yes | Metrik utama relevansi, lihat dokumen 05 bagian 7.1 |
| recall_relevansi | real | yes | |
| f1_relevansi | real | yes | |
| catatan | text | yes | |

Angka pada baris terbaru di tabel ini tampil di UI, di halaman detail artikel dan di footer dashboard eksekutif. Lihat dokumen 04.

Empat kolom versi di atas ditambahkan pada versi 1.4 karena evaluasi tanpa
versi tidak bisa direproduksi. Dua evaluasi dengan F1 berbeda tidak berarti
apa-apa kalau tidak diketahui apakah yang berubah modelnya, definisi
konteksnya, gold set-nya, atau ambangnya. Hindari menyimpan dua baris untuk
kombinasi versi yang persis sama.

Metrik relevansi disimpan terpisah dari metrik sentimen dan tidak pernah
digabung menjadi satu angka. Angka relevansi gabungan tiga konteks pada
evaluasi lama tercatat 63,2%, dan tidak menggambarkan satu pun dari ketiganya
(57,0%, 87,7%, 51,1%).

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

## 16. Tabel laboratorium relevansi

Sebelas tabel, ditambahkan versi 1.6. **Kolom lengkapnya ada di dokumen 10
bagian 16 dan tidak diulang di sini**, karena satu skema yang ditulis di dua
tempat akan berbeda dalam sebulan.

| Tabel | Isi | Baris tumbuh mengikuti |
|---|---|---|
| `sampel_relevansi` | Kandidat dataset beserta label manusia, alasan, tingkat kesulitan, dan skor prioritas | Jumlah artikel |
| `prediksi_relevansi` | Riwayat setiap prediksi, tidak pernah ditimpa | Artikel dikali versi model |
| `snapshot_dataset_relevansi` | Susunan dataset yang dibekukan untuk satu eksperimen | Jumlah eksperimen |
| `item_snapshot_dataset_relevansi` | Anggota snapshot beserta split dan label saat dibekukan | Snapshot dikali sampel |
| `pelatihan_model_relevansi` | Satu baris per training run, konfigurasi, progres, metrik, artefak | Jumlah pelatihan |
| `versi_model_relevansi` | Versi model beserta status, checksum, dan metrik | Jumlah versi |
| `evaluasi_model_relevansi` | Hasil evaluasi per pasangan model dan snapshot | Model dikali snapshot |
| `versi_threshold_relevansi` | Ambang berversi, menggantikan dua nilai `.env` | Jarang |
| `versi_konteks_relevansi` | Definisi konteks berversi, aturan inklusi dan eksklusi | Jarang |
| `gerbang_mutu_relevansi` | Status gerbang per versi model, standar, hasil, dan alasan pencabutan | Jumlah versi |
| `uji_manual_relevansi` | Riwayat pengujian URL dan teks di tab Uji Model | Pemakaian admin |

Empat aturan integritas yang mengikat tabel di atas dan harus jadi constraint
database, bukan hanya kode:

1. **Tepat satu model boleh berstatus `production`.** Unique partial index,
   pola yang sama dengan `konteks_pantauan.utama`:

```sql
CREATE UNIQUE INDEX uq_model_relevansi_produksi
  ON versi_model_relevansi ((status)) WHERE status = 'production';
```

2. **Satu sampel hanya boleh muncul sekali dalam satu snapshot.** Unique
   `(snapshot_dataset_relevansi_id, sampel_relevansi_id)`. Sampel yang muncul
   dua kali dengan split berbeda adalah kebocoran yang paling sulit dilihat.

3. **Satu konfigurasi evaluasi hanya boleh punya satu hasil.** Unique
   `(configuration_hash)`. Dua baris metrik untuk konfigurasi identik berarti
   salah satunya salah, dan tidak ada cara tahu yang mana.

4. **Snapshot terkunci tidak boleh berubah.** Ditegakkan di service, bukan
   constraint, tetapi `locked_at IS NOT NULL` adalah syarat yang diperiksa
   setiap kali item snapshot hendak ditulis.

Aturan pertama adalah yang paling penting dan paling mudah dilanggar. Promosi
model berjalan lewat beberapa langkah, dan satu langkah yang gagal di tengah
tanpa transaksi meninggalkan dua model produksi sekaligus. Yang mana yang
dipakai lalu bergantung pada urutan baris, dan itu berarti hasil analisis
berubah tanpa ada yang mengubah apa pun.

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

### Menyetel ulang ambang relevansi

```sql
UPDATE analisis_sentimen a
SET relevan = p.probabilitas_relevan >= :ambang_relevan,
    skor_relevansi = p.probabilitas_relevan,
    versi_threshold_relevansi_id = :versi_ambang_baru
FROM prediksi_relevansi p
WHERE p.artikel_id = a.artikel_id
  AND p.versi_model_relevansi_id = :model_produksi
  AND a.relevan_manual IS NULL;
```

Kueri ini menggantikan penghitungan ulang cosine dari versi 1.5, dan menjaga
sifat yang membuat versi itu berharga: **mengubah ambang tidak memerlukan
inferensi ulang.** Syaratnya satu, probabilitas mentah harus sudah tersimpan
per artikel di `prediksi_relevansi`. Menyimpan hanya label akhir berarti setiap
percobaan ambang memaksa 4.806 inferensi, dan ambang yang mahal dicoba adalah
ambang yang tidak pernah benar-benar disetel.

Klausa `relevan_manual IS NULL` bukan optimasi. Ia janji F-13: koreksi manusia
tidak pernah tertimpa proses otomatis. Setiap kueri massal yang menyentuh kolom
relevansi harus membawanya, dan itulah satu baris yang paling mudah lupa
ditulis.

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
| 1.6 | Agustus 2026 | Laboratorium Model Relevansi, dokumen 10. **(a)** Sebelas tabel baru, ringkasannya di bagian 16 dan kolom lengkapnya di dokumen 10 bagian 16. **(b)** `analisis_sentimen`: kolom `versi_model_relevansi_id` dan `versi_threshold_relevansi_id`; `skor_relevansi` berganti arti menjadi probabilitas classifier, bukan cosine. **(c)** `artikel.embedding_relevansi` dan `konteks_pantauan.embedding` dipensiunkan lalu dihapus pada fase 1; `artikel.embedding` tetap untuk deduplikasi. **(d)** `konteks_pantauan.deskripsi_model` berganti peran dari teks yang di-embed menjadi kalimat konteks pada tokenizer. **(e)** `gold_set` dan `evaluasi_model` tetap ada untuk sentimen; bagian relevansinya digantikan tabel laboratorium, barisnya tidak dihapus. **(f)** Unique partial index yang menjamin tepat satu model relevansi berstatus produksi |
| 1.0 | Juli 2026 | Skema awal |
| 1.1 | Juli 2026 | Tabel `pemuatan`: `media_id` menjadi nullable; kolom baru `status_ekstraksi`, `arsip_teks`, `arsip_screenshot_path`, `arsip_diambil_at`, `email_pelapor`; nilai `google_form` pada `sumber_catatan` |
| 1.5 | Agustus 2026 | Penilai relevansi berpindah ke kemiripan makna. **(a)** `artikel`: kolom `embedding_relevansi vector(384)`, tanpa index HNSW karena selalu dibandingkan ke satu vektor yang sama. **(b)** `konteks_pantauan`: kolom `deskripsi_model` dan `embedding vector(384)`. **(c)** `analisis_sentimen`: `keyakinan_relevansi` diganti `skor_relevansi`, isinya cosine similarity dan bukan probabilitas. **(d)** Model `indobert-relevancy` dan endpoint `/relevancy` dihapus dari sistem |
| 1.4 | Agustus 2026 | Perombakan relevansi. **(a)** `artikel`: kolom metadata sumber `post_id_sumber`, `url_api_sumber`, `kategori_sumber`, `tag_sumber`, `diubah_sumber_at`; nilai `tidak_relevan` dan `perlu_review` masuk CHECK `status_proses`. **(b)** `analisis_sentimen`: kolom `relevan_manual`, `alasan_relevansi`, `sinyal_relevansi`, `konteks_versi`, dan kolom generated `relevan_efektif`; index `idx_analisis_konteks_label` beralih ke `relevan_efektif`. **(c)** `gold_set`: `label_gold` menjadi nullable dengan CHECK yang mewajibkannya hanya saat relevan, kolom `gold_set_versi` dan `split`. **(d)** `evaluasi_model`: kolom `minimal_sebutan`, `konteks_versi`, `gold_set_versi`, dan tiga metrik relevansi. **(e)** Seeder konteks: dua konteks tambahan dinonaktifkan, tidak dihapus. Grup duplikat tetap ekspresi `COALESCE(artikel_induk_id, id)`, bukan kolom baru |
| 1.3 | Agustus 2026 | Penyesuaian saat implementasi sprint 1. **(a)** Index unique `ringkasan_harian (tanggal, media_id, konteks_pantauan_id)` dan `kata_kunci_periode (konteks_pantauan_id, granularitas, periode_mulai, istilah)` dibuat dengan `NULLS NOT DISTINCT`. Baris agregat memakai NULL pada kolom-kolom itu, dan dengan perilaku default PostgreSQL (NULLS DISTINCT) `INSERT ... ON CONFLICT DO UPDATE` tidak pernah cocok sehingga baris duplikat menumpuk tiap 10 menit dan angka dashboard salah. **(b)** `users.peran` sengaja tanpa nilai default; user yang dibuat tanpa peran harus gagal keras, bukan diam-diam menjadi `superadmin`. **(c)** Tiga CHECK tambahan yang menegakkan aturan integritas yang sudah tertulis di dokumen ini tapi belum punya constraint: `artikel` (artikel_induk_id null persis saat status_dedup = `asli`), `pemuatan` (alasan_penolakan wajib saat status_verifikasi = `ditolak`), `gold_set` (ronde hanya 1 atau 2) |
| 1.2 | Agustus 2026 | Jembatan Google Form dibatalkan: `pemuatan.media_id` kembali NOT NULL, kolom `email_pelapor` dihapus, nilai `google_form` dihapus dari CHECK `sumber_catatan`. Kolom `user.telepon` bukan lagi untuk WhatsApp. Seeder media diisi 30 media dari lampiran A dokumen 01, seeder konteks pantauan diisi tiga konteks dari dokumen 01 bagian 9 |

Setiap penambahan tabel atau kolom yang tidak ada di dokumen ini dicatat di sini bersamaan dengan migration-nya.
