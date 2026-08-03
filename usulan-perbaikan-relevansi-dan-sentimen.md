# 09: Usulan Perbaikan Rancangan Relevansi dan Sentimen

**SIMEDIA Kendari | Usulan revisi rancangan 1.3**  
**Tanggal:** 3 Agustus 2026  
**Status:** Disetujui dan diterapkan ke dokumen 00 sampai 09 pada 4 Agustus 2026. Penilai relevansi memakai `multilingual-e5-small` sesuai bagian 8.1, bukan classifier terpisah. Empat penyimpangan beserta alasannya dicatat di dokumen yang bersangkutan: migrasi gold set tidak otomatis (bagian 12.1), relevansi tetap di tabel `analisis_sentimen`, kamus alias memakai tabel `entitas` yang sudah ada, dan grup duplikat berupa ekspresi bukan kolom baru.  
**Ruang lingkup:** Penyederhanaan konteks pantauan, penyaringan artikel, pemanfaatan metadata WordPress, AI relevansi lokal, gold set, evaluasi model, dan dampaknya terhadap aplikasi.

[PAGEBREAK]

## Daftar Isi

| Bagian 1-13 | Bagian 14-Lampiran |
|---|---|
| 1. Tujuan dokumen | 14. Evaluasi model |
| 2. Masalah pada rancangan saat ini | 15. Perubahan UI admin |
| 3. Keputusan rancangan baru | 16. Perubahan pada dokumen rancangan yang ada |
| 4. Definisi konteks utama | 17. Tahapan implementasi |
| 5. Pemisahan relevansi, topik, entitas, dan sentimen | 18. Pseudocode Laravel |
| 6. Alur pemrosesan artikel yang diusulkan | 19. Contoh keputusan relevansi |
| 7. Pemanfaatan WordPress, tag, dan kategori | 20. Risiko dan mitigasi |
| 8. AI gratis yang disarankan | 21. Kriteria penerimaan perubahan |
| 9. Bentuk input model relevansi | 22. Rekomendasi final |
| 10. Kontrak layanan NLP yang diusulkan | Lampiran A: Contoh kamus kelompok entitas |
| 11. Perubahan status dan database | Lampiran B: Format gold set baru |
| 12. Migrasi gold set lama | Lampiran C: Keputusan sebelum implementasi |
| 13. Rancangan sentimen setelah penyederhanaan |  |

[PAGEBREAK]

## 1. Tujuan dokumen

Dokumen ini mengusulkan perubahan terhadap rancangan SIMEDIA Kendari agar sistem lebih sesuai dengan tujuan produk yang sebenarnya: **menemukan semua artikel media yang secara substantif berhubungan dengan Pemerintah Kota Kendari, lalu menilai sentimennya**.

Rancangan awal memakai tiga konteks pantauan:

1. Pemerintah Kota Kendari.
2. Wali Kota Kendari.
3. Pelayanan publik dan infrastruktur Kota Kendari.

Pendekatan tersebut memberi fleksibilitas analisis, tetapi menyebabkan satu artikel harus diperiksa dan dilabeli berulang untuk tiga konteks. Untuk pengembang tunggal dan admin nonteknis, beban ini tidak sebanding dengan manfaat yang diperoleh pada versi pertama.

Perubahan utama yang diusulkan adalah:

> **Gunakan satu konteks utama “Pemerintah Kota Kendari” sebagai klasifikasi relevansi tingkat artikel. Topik, OPD, figur, dan jenis pelayanan diperlakukan sebagai metadata atau hasil ekstraksi otomatis, bukan konteks pelabelan terpisah.**

Dokumen ini menjadi bahan revisi untuk dokumen 00 sampai 08. Ia belum otomatis menggantikan keputusan lama sebelum disetujui dan diterapkan pada skema, kode, dan gold set.

---

## 2. Masalah pada rancangan saat ini

### 2.1 Tujuan produk dan struktur konteks belum sepenuhnya selaras

Tujuan operasional sistem adalah menangkap pemberitaan yang berkaitan dengan Pemerintah Kota Kendari. Namun, rancangan tiga konteks membuat sistem menjawab tiga pertanyaan sekaligus untuk satu artikel:

- Apakah artikel relevan terhadap Pemkot Kendari?
- Apakah artikel relevan terhadap Wali Kota Kendari?
- Apakah artikel relevan terhadap pelayanan publik dan infrastruktur?

Padahal untuk menentukan apakah artikel boleh masuk dashboard, pertanyaan yang dibutuhkan hanya:

> **Apakah artikel ini secara substantif berhubungan dengan Pemerintah Kota Kendari?**

### 2.2 Beban pelabelan terlalu besar

Dengan tiga konteks, satu artikel menghasilkan sampai tiga pasangan artikel-konteks. Sebanyak 470 label belum tentu berarti 470 artikel unik. Akibatnya:

- pelabelan lebih lama;
- kualitas label lebih mudah menurun karena kelelahan;
- distribusi kelas per konteks menjadi sangat timpang;
- evaluasi sulit dibaca ketika satu konteks hanya memiliki satu atau nol contoh pada kelas tertentu;
- admin harus memahami perbedaan yang terlalu halus antara konteks institusi, figur, dan layanan.

### 2.3 Penyaring relevansi terlalu permisif

Hasil evaluasi terakhir menunjukkan penyaring relevansi mempunyai recall tinggi, tetapi precision rendah. Artinya, sistem jarang melewatkan artikel yang relevan, tetapi terlalu banyak memasukkan artikel yang sebenarnya tidak membahas Pemerintah Kota Kendari.

Dampaknya lebih serius daripada sekadar angka evaluasi:

- jumlah berita di dashboard membengkak;
- berita umum yang hanya berlokasi di Kendari ikut dihitung;
- sentimen terhadap pihak lain dapat dianggap sebagai sentimen terhadap Pemkot;
- pimpinan kehilangan kepercayaan karena daftar artikel terasa tidak masuk akal.

### 2.4 Konteks “pelayanan publik dan infrastruktur” terlalu umum

Istilah seperti *jalan*, *pelayanan*, *pemerintah*, *pasar*, *sampah*, atau *air bersih* sering muncul dalam berita yang bukan kewenangan Pemerintah Kota Kendari. Contoh:

- jalan nasional;
- proyek Pemerintah Provinsi Sulawesi Tenggara;
- layanan instansi vertikal;
- kegiatan perusahaan atau organisasi masyarakat;
- kejadian umum di wilayah Kota Kendari.

Karena kata-katanya umum, model mudah menyatakan relevan walaupun hubungan dengan Pemkot hanya lemah atau tidak ada.

---

## 3. Keputusan rancangan baru

### 3.1 Satu konteks aktif

Versi pertama memakai satu konteks aktif:

| Properti | Nilai |
|---|---|
| Nama | Pemerintah Kota Kendari |
| Slug | `pemerintah-kota-kendari` |
| Tingkat klasifikasi | Artikel |
| Label relevansi | `relevan`, `tidak_relevan`, `perlu_review` |
| Sentimen | `negatif`, `netral`, `positif` |
| Status | Aktif dan menjadi konteks utama sistem |

Tabel konteks boleh tetap dipertahankan agar arsitektur tidak buntu. Namun, versi pertama hanya mengaktifkan satu konteks. Penambahan konteks baru ditunda sampai ada kebutuhan resmi dan sumber daya pelabelan yang memadai.

### 3.2 Wali Kota, OPD, dan pelayanan menjadi atribut artikel

Hal-hal berikut tidak lagi menjadi konteks pelabelan terpisah:

- Wali Kota dan Wakil Wali Kota;
- Sekretaris Daerah;
- OPD;
- kecamatan dan kelurahan;
- pelayanan publik;
- infrastruktur;
- program dan kegiatan;
- anggaran;
- kritik atau keluhan.

Semuanya menjadi salah satu dari:

1. **entitas**: misalnya Wali Kota, Dishub, Disdukcapil, Kecamatan Kadia;
2. **topik**: misalnya infrastruktur, kesehatan, pendidikan, sampah;
3. **kata kunci**: hasil ekstraksi frekuensi;
4. **tag/kategori sumber**: metadata dari WordPress atau media;
5. **atribut sentimen**: nada artikel terhadap Pemerintah Kota Kendari.

Dengan demikian, artikel cukup dilabeli sekali untuk relevansi dan sekali untuk sentimen.

---

## 4. Definisi konteks utama

### 4.1 Deskripsi untuk manusia

Artikel dinyatakan **relevan** apabila secara substantif membahas Pemerintah Kota Kendari sebagai institusi atau unsur yang berada dalam struktur, kewenangan, program, tanggung jawab, atau representasinya.

Cakupan meliputi:

- Pemerintah Kota Kendari sebagai institusi;
- Wali Kota dan Wakil Wali Kota Kendari dalam kapasitas jabatan;
- Sekretaris Daerah dan aparatur Pemerintah Kota Kendari;
- dinas, badan, kantor, bagian, kecamatan, kelurahan, UPTD, dan BLUD milik Pemerintah Kota Kendari;
- kebijakan, program, kegiatan, pelayanan, perizinan, anggaran, pembangunan, pengadaan, kerja sama, prestasi, masalah, kritik, keluhan, dan tanggapan resmi;
- peristiwa yang tidak menyebut Pemkot pada judul, tetapi isi utamanya membahas kewenangan atau tindakan Pemkot Kendari.

### 4.2 Deskripsi untuk model

> Tentukan apakah artikel secara substantif membahas Pemerintah Kota Kendari, Wali Kota atau Wakil Wali Kota dalam kapasitas jabatan, OPD dan unit kerja Pemerintah Kota Kendari, ASN Pemkot, kebijakan, program, anggaran, pelayanan publik, pembangunan, tindakan, prestasi, kritik, keluhan, atau masalah yang menjadi kewenangan atau tanggung jawab Pemerintah Kota Kendari. Jangan anggap relevan apabila Kendari hanya menjadi lokasi kejadian atau artikel hanya membahas Pemprov Sultra, pemerintah kabupaten lain, instansi vertikal, kepolisian, TNI, kampus, perusahaan, organisasi, kriminalitas umum, olahraga, atau masyarakat tanpa keterlibatan substantif Pemkot Kendari.

### 4.3 Aturan inklusi

Artikel termasuk relevan apabila memenuhi minimal satu kondisi berikut:

1. Pemerintah Kota Kendari menjadi subjek utama tindakan, kebijakan, atau evaluasi.
2. Wali Kota, Wakil Wali Kota, Sekda, atau pejabat Pemkot dibahas dalam kapasitas jabatan.
3. OPD atau unit kerja Pemkot menjadi pelaksana, penanggung jawab, objek kritik, atau pemberi layanan.
4. Artikel membahas program, anggaran, fasilitas, pelayanan, atau proyek yang menjadi kewenangan Pemkot Kendari.
5. Keluhan warga secara jelas diarahkan kepada Pemkot atau unitnya.
6. Pemkot memberi tanggapan, keputusan, klarifikasi, bantuan, sanksi, atau tindak lanjut yang menjadi bagian utama berita.
7. Artikel membahas hubungan DPRD Kota Kendari dengan kebijakan, anggaran, atau tindakan eksekutif Pemkot.

### 4.4 Aturan eksklusi

Artikel tidak relevan apabila:

1. Kata “Kendari” hanya menunjukkan lokasi kejadian.
2. Artikel membahas Pemerintah Provinsi Sulawesi Tenggara tanpa keterlibatan substantif Pemkot Kendari.
3. Artikel membahas kementerian, kantor wilayah, kepolisian, TNI, kejaksaan, pengadilan, Bea Cukai, BPS, kampus, BUMN, perusahaan, organisasi, atau komunitas secara mandiri.
4. Artikel membahas kriminalitas, kecelakaan, olahraga, hiburan, bisnis, atau acara umum di Kendari tanpa kaitan substantif dengan Pemkot.
5. Pemkot hanya muncul pada daftar tamu, alamat, ucapan, atau kalimat singkat yang bukan fokus berita.
6. Artikel membahas pemerintah kabupaten lain walaupun medianya berasal dari Kendari.

### 4.5 Kebijakan untuk DPRD Kota Kendari

DPRD Kota Kendari tidak otomatis dianggap identik dengan Pemerintah Kota Kendari. Gunakan aturan berikut:

- **Relevan** jika berita DPRD membahas APBD, perda, pengawasan, kritik, rekomendasi, persetujuan, atau hubungan yang langsung melibatkan kebijakan dan tindakan Pemkot.
- **Tidak relevan** jika berita hanya membahas kegiatan internal DPRD, kunjungan, organisasi fraksi, atau agenda legislatif yang tidak berhubungan dengan eksekutif Pemkot.

Aturan ini perlu ditulis di panduan pelabelan agar hasil konsisten.

---

## 5. Pemisahan relevansi, topik, entitas, dan sentimen

Empat konsep ini harus dipisahkan agar sistem mudah dipahami.

| Konsep | Pertanyaan | Contoh hasil | Cara memperoleh |
|---|---|---|---|
| Relevansi | Apakah artikel berhubungan substantif dengan Pemkot Kendari? | relevan | Klasifikasi biner AI + koreksi manusia |
| Topik | Artikel membahas bidang apa? | infrastruktur, kesehatan | Tag, kategori, aturan, atau klasifikasi otomatis |
| Entitas | Siapa atau unit apa yang disebut? | Wali Kota, Dishub | Ekstraksi entitas dan kamus alias |
| Sentimen | Bagaimana nada artikel terhadap Pemkot Kendari? | negatif | Model sentimen setelah artikel relevan |

Keuntungan pemisahan ini:

- satu artikel tidak perlu dilabeli berulang;
- topik dapat bertambah tanpa mengulang gold set;
- model relevansi mempunyai tujuan yang jelas;
- model sentimen hanya menerima artikel yang benar-benar berkaitan;
- dashboard tetap dapat difilter berdasarkan OPD, figur, atau topik.

---

## 6. Alur pemrosesan artikel yang diusulkan

```text
Sumber WordPress / RSS / scraping / Google News RSS
                         ↓
              Simpan kandidat artikel
                         ↓
     Normalisasi URL, deduplikasi, dan metadata
                         ↓
       Sinyal awal: judul, tag, kategori, alias
                         ↓
        AI relevansi konteks tunggal Pemkot
                         ↓
       ┌─────────────────┼─────────────────┐
       ↓                 ↓                 ↓
    relevan         perlu_review     tidak_relevan
       ↓                 ↓                 ↓
ambil/rapikan isi   antrean admin    tetap disimpan
       ↓
analisis sentimen terhadap Pemkot
       ↓
ekstraksi entitas, topik, dan kata kunci
       ↓
ringkasan dan dashboard
```

### 6.1 Prinsip penting

1. **Semua temuan dicatat sebagai kandidat.** Jangan membuang artikel sebelum ada jejak audit.
2. **Relevansi dijalankan sebelum sentimen.** Artikel tidak relevan tidak boleh mengotori grafik sentimen.
3. **Metadata WordPress adalah sinyal, bukan kebenaran akhir.** Media dapat memakai tag secara tidak konsisten.
4. **Keputusan manual selalu mengalahkan model.** Analisis ulang tidak boleh menimpa koreksi admin.
5. **Model dan threshold harus memiliki versi.** Evaluasi lama harus dapat direproduksi.
6. **Artikel duplikat dikelompokkan sebelum pembagian data evaluasi.** Salinan tidak boleh bocor antara train dan test.

---

## 7. Pemanfaatan WordPress, tag, dan kategori

Sekitar 90 persen media yang dipantau memakai WordPress. Hal ini dapat mengurangi biaya crawling dan memperkuat penyaringan awal.

### 7.1 Data yang diambil lebih dahulu

Untuk tahap kandidat, ambil metadata ringan:

- ID post sumber;
- URL dan URL kanonik;
- judul;
- excerpt/ringkasan;
- tanggal publikasi dan perubahan;
- kategori;
- tag;
- nama penulis jika tersedia;
- gambar utama;
- link API sumber;
- isi lengkap jika sudah tersedia tanpa permintaan tambahan.

Pada WordPress REST API, kategori dan tag sering tersedia sebagai ID. Sistem dapat mengambil nama term melalui endpoint term atau respons embed. Jika REST API tidak aktif, gunakan RSS dan parsing HTML sebagai jalur cadangan.

### 7.2 Tag sebagai sinyal kuat

Contoh tag atau kategori yang relatif kuat:

- Pemkot Kendari;
- Pemerintah Kota Kendari;
- Wali Kota Kendari;
- Wakil Wali Kota Kendari;
- Sekda Kota Kendari;
- nama OPD spesifik, misalnya Dishub Kendari atau Disdukcapil Kendari;
- nama program resmi Pemkot;
- kecamatan atau kelurahan yang disertai nama unit Pemkot.

Contoh sinyal lemah yang tidak boleh langsung dianggap relevan:

- Kendari;
- Sultra;
- pemerintah;
- daerah;
- pelayanan;
- jalan;
- masyarakat.

### 7.3 Tag tidak boleh menjadi keputusan akhir

Alasannya:

- sebagian media memakai tag hanya untuk SEO;
- satu artikel dapat diberi tag “Kendari” hanya karena lokasi;
- nama OPD dapat muncul pada tag walaupun hanya disebut sepintas;
- beberapa media tidak konsisten memasang tag;
- media dapat mengganti struktur atau kebiasaan tagging.

Karena itu, tag dipakai untuk:

- menaikkan prioritas kandidat;
- memilih artikel yang perlu diunduh penuh lebih dahulu;
- menambah fitur input model;
- membantu menjelaskan alasan prediksi kepada admin;
- mempercepat penyaringan aturan yang sangat jelas.

### 7.4 Skor routing awal, bukan skor AI

Sistem boleh memiliki skor aturan internal untuk menentukan jalur pemrosesan. Contoh rancangan awal:

| Sinyal | Bobot routing contoh |
|---|---:|
| Judul menyebut “Pemerintah Kota Kendari” atau “Pemkot Kendari” | +4 |
| Judul menyebut nama OPD Pemkot yang unik | +3 |
| Tag spesifik Pemkot atau OPD | +3 |
| Excerpt menyebut Pemkot dan tindakan/kewenangannya | +2 |
| Hanya menyebut “Kendari” | 0 |
| Judul jelas membahas instansi vertikal tanpa Pemkot | -3 |
| Kendari hanya lokasi acara atau kejadian | -2 |

Bobot di atas hanya contoh untuk routing, bukan probabilitas relevansi. Keputusan akhir tetap berasal dari model atau koreksi manusia.

---

## 8. AI gratis yang disarankan

“Gratis” dalam rancangan ini berarti model dapat diunduh dan dijalankan sendiri tanpa biaya API per artikel. Tetap ada biaya server, RAM, CPU, penyimpanan, dan pemeliharaan.

### 8.1 Tahap awal: embedding semantik

Model yang disarankan untuk baseline:

- `intfloat/multilingual-e5-small`

Kegunaan:

- menghitung kemiripan antara deskripsi konteks Pemkot dan isi artikel;
- membantu deduplikasi semantik;
- membantu pencarian artikel serupa;
- dapat dijalankan lokal melalui Python dan FastAPI;
- lebih ringan daripada memakai LLM generatif untuk setiap artikel.

Cara kerja:

1. Bentuk satu teks konteks sebagai `query`.
2. Bentuk representasi artikel sebagai `passage`.
3. Hitung embedding keduanya.
4. Hitung cosine similarity.
5. Tentukan dua threshold dari validation set:
   - di atas threshold tinggi: relevan;
   - di bawah threshold rendah: tidak relevan;
   - di antara keduanya: perlu review.

Skor cosine bukan probabilitas. Nilai threshold tidak boleh ditetapkan hanya dari perkiraan; threshold harus dipilih dari gold set lokal Kendari.

### 8.2 Tahap stabil: IndoBERT binary relevance classifier

Setelah koreksi admin cukup banyak, model yang lebih tepat adalah IndoBERT yang di-fine-tune untuk satu tugas:

> Input artikel → `relevan` atau `tidak_relevan` terhadap Pemerintah Kota Kendari.

Basis model yang dapat diuji:

- `indobenchmark/indobert-base-p1`;
- model bahasa Indonesia lain yang kompatibel dengan Transformers dan dapat dijalankan lokal.

Keunggulan classifier hasil fine-tuning:

- belajar langsung dari gaya media lokal;
- dapat mengenali perbedaan Pemkot, Pemprov, instansi vertikal, dan lokasi Kendari;
- output kelas lebih mudah dikalibrasi;
- lebih cocok untuk produksi dibanding zero-shot generatif.

### 8.3 Zero-shot hanya untuk eksperimen

Model NLI multibahasa dapat dipakai untuk prototipe sebelum data tersedia. Namun, zero-shot tidak dijadikan jalur produksi utama karena:

- lebih lambat;
- hasil dapat berubah karena formulasi label;
- belum tentu memahami istilah lokal dan singkatan OPD;
- sulit mengalahkan model kecil yang sudah dilatih dengan koreksi lokal.

### 8.4 Rekomendasi akhir model

| Fase | Model/pendekatan | Tujuan |
|---|---|---|
| 1 | Aturan judul, tag, kategori, dan kamus alias | Routing cepat dan transparan |
| 2 | `multilingual-e5-small` | Baseline relevansi dan pengumpulan hard cases |
| 3 | IndoBERT binary classifier | Model produksi setelah data cukup |
| 4 | Embedding tetap dipertahankan | Deduplikasi dan pencarian semantik |

Tidak disarankan memakai Gemini, OpenAI, atau LLM eksternal untuk setiap artikel sebagai jalur utama. Biaya, batas kuota, latensi, ketergantungan internet, dan konsistensi output akan lebih sulit dikendalikan.

Sebelum model dipasang ke produksi, periksa kembali model card, lisensi, ukuran, kebutuhan RAM, dan kompatibilitas versi `transformers` yang dipakai.

---

## 9. Bentuk input model relevansi

### 9.1 Jangan hanya memakai isi penuh secara mentah

Artikel panjang sering terpotong pada batas token. Jika model hanya menerima bagian awal, kalimat yang benar-benar menjelaskan hubungan dengan Pemkot mungkin tidak terbaca.

Bentuk input yang disarankan:

```text
Judul: ...
Kategori: ...
Tag: ...
Ringkasan: ...
Potongan isi terkait: ...
Entitas terdeteksi: ...
```

### 9.2 Context window

Cari penyebutan berikut:

- Pemerintah Kota Kendari;
- Pemkot Kendari;
- Wali Kota/Wakil Wali Kota Kendari;
- nama OPD dan alias;
- nama pejabat yang sudah terhubung ke jabatan;
- nama program resmi;
- istilah kewenangan Pemkot.

Ambil:

- dua kalimat sebelum penyebutan;
- kalimat penyebutan;
- dua kalimat setelah penyebutan.

Gabungkan dengan judul, excerpt, kategori, dan tag sampai batas token tercapai.

### 9.3 Kamus alias

Sistem memerlukan tabel alias yang dapat dikelola, misalnya:

| Entitas baku | Alias contoh |
|---|---|
| Pemerintah Kota Kendari | Pemkot Kendari, Pemkot Kdi, Pemerintah Kota |
| Wali Kota Kendari | Walikota Kendari, Wali Kota, Wali Kota Kdi |
| Dinas Perhubungan Kota Kendari | Dishub Kendari, Dishub Kota Kendari |
| Dinas Kependudukan dan Pencatatan Sipil | Disdukcapil Kendari, Dukcapil Kendari |
| Satuan Polisi Pamong Praja | Satpol PP Kendari, Pol PP Kendari |

Alias membantu pencarian context window, ekstraksi entitas, filter dashboard, dan penjelasan prediksi.

---

## 10. Kontrak layanan NLP yang diusulkan

### 10.1 Endpoint relevansi

```http
POST /relevancy
```

Contoh request:

```json
{
  "articles": [
    {
      "id": 101,
      "title": "Pemkot Kendari memperbaiki drainase di Kadia",
      "excerpt": "...",
      "categories": ["Kendari", "Infrastruktur"],
      "tags": ["Pemkot Kendari", "Drainase"],
      "text_windows": ["..."]
    }
  ],
  "context_version": "pemkot-kendari-v2"
}
```

Contoh response:

```json
{
  "results": [
    {
      "id": 101,
      "label": "relevan",
      "score": 0.91,
      "review_required": false,
      "matched_signals": ["title:pemkot kendari", "tag:pemkot kendari"],
      "model_version": "relevancy-indobert-2.0.0"
    }
  ]
}
```

### 10.2 Aturan output

- `score` harus didokumentasikan: probabilitas terkalibrasi atau similarity.
- `matched_signals` bukan penjelasan penuh AI, tetapi sinyal yang benar-benar ditemukan oleh sistem.
- `review_required` ditentukan dari threshold validation set.
- Laravel tetap menjadi satu-satunya komponen yang menyimpan hasil ke database.
- Model tidak boleh menimpa label manual.

---

## 11. Perubahan status dan database

### 11.1 Status artikel

Status pemrosesan yang disarankan:

```text
mentah
metadata_selesai
menunggu_relevansi
perlu_review
relevan
tidak_relevan
menunggu_sentimen
selesai
gagal
```

### 11.2 Kolom relevansi

Minimal simpan:

| Kolom | Fungsi |
|---|---|
| `status_relevansi` | relevan, tidak relevan, perlu review |
| `skor_relevansi` | skor keluaran model |
| `label_relevansi_manual` | koreksi manusia, nullable |
| `alasan_koreksi` | alasan admin mengubah label |
| `model_relevansi_versi` | versi model |
| `konteks_versi` | versi definisi konteks |
| `threshold_versi` | versi konfigurasi threshold |
| `sinyal_relevansi` | JSON sinyal judul/tag/alias |
| `direview_oleh` | pengguna yang memeriksa |
| `direview_pada` | waktu review |

### 11.3 Metadata sumber

Tambahkan atau pastikan tersimpan:

- `source_post_id`;
- `source_api_url`;
- `source_categories` JSON;
- `source_tags` JSON;
- `source_excerpt`;
- `source_modified_at`;
- `canonical_url`;
- `content_hash`;
- `duplicate_parent_id`.

### 11.4 Artikel tidak relevan tetap disimpan

Artikel tidak relevan tidak tampil pada dashboard utama, tetapi tetap disimpan karena berguna untuk:

- audit crawler;
- hard negative training data;
- evaluasi perubahan model;
- pencarian kesalahan false negative;
- pengukuran berapa banyak kandidat yang disaring.

Retensi dapat dibatasi jika kapasitas menjadi masalah, tetapi keputusan penghapusan harus terpisah dari hasil model.

---

## 12. Migrasi gold set lama

### 12.1 Menggabungkan tiga konteks menjadi label artikel

Untuk data lama:

```text
Jika artikel relevan pada minimal satu konteks lama → relevan terhadap konteks utama.
Jika artikel tidak relevan pada seluruh konteks lama → tidak relevan.
```

Penggabungan dilakukan berdasarkan artikel unik, bukan berdasarkan baris pasangan artikel-konteks.

### 12.2 Kasus yang harus direview ulang

Review manual diperlukan apabila:

- label tiga konteks saling bertentangan secara tidak masuk akal;
- artikel hanya dianggap relevan karena konteks pelayanan yang terlalu umum;
- Pemkot hanya disebut sepintas;
- artikel berhubungan dengan Pemprov, instansi vertikal, atau lokasi Kendari;
- label sentimen antar-konteks berbeda;
- artikel merupakan salinan dari berita lain.

### 12.3 Gold set baru

Gold set relevansi menjadi biner:

```text
0 = tidak relevan
1 = relevan
```

Status `perlu_review` bukan label gold set. Itu adalah keputusan operasional berdasarkan ketidakpastian model. Semua sampel gold set harus memiliki keputusan manusia final.

### 12.4 Komposisi data

Jangan hanya mengambil sampel acak dari produksi. Gunakan gabungan:

- contoh relevan yang jelas;
- contoh tidak relevan yang jelas;
- hard negatives dari false positive model;
- artikel yang hanya memakai Kendari sebagai lokasi;
- artikel Pemprov Sultra;
- artikel instansi vertikal;
- artikel DPRD dengan dan tanpa hubungan ke Pemkot;
- artikel yang menyebut pejabat atau OPD secara singkat;
- artikel kritik tanpa menyebut “Pemkot” pada judul;
- artikel duplikat dan rilis pers.

Target awal yang realistis:

- 300 sampai 500 artikel unik untuk baseline evaluasi;
- minimal 150 relevan dan 150 tidak relevan;
- tambah data bertahap dari koreksi admin;
- satu test set permanen yang tidak dipakai memilih threshold atau melatih model.

### 12.5 Pemisahan data

Gunakan pembagian berbasis grup duplikat:

- semua salinan satu berita masuk split yang sama;
- berita dari rilis yang sama tidak boleh tersebar antara train dan test;
- pertimbangkan split berdasarkan waktu untuk mengukur generalisasi ke berita baru;
- simpan versi gold set pada setiap evaluasi.

---

## 13. Rancangan sentimen setelah penyederhanaan

### 13.1 Satu sasaran sentimen

Sentimen dinilai terhadap **Pemerintah Kota Kendari sebagai konteks utama**, bukan terhadap nada artikel secara umum.

Contoh:

- “Pemkot memperbaiki jalan setelah keluhan warga” dapat bersifat campuran. Jika fokus utama adalah perbaikan dan respons, bisa positif atau netral sesuai panduan.
- “Warga kembali mengeluhkan jalan rusak dan Pemkot belum bertindak” adalah negatif terhadap Pemkot.
- “Wali Kota menghadiri rapat koordinasi” adalah netral bila tidak ada evaluasi keberhasilan atau kritik.
- “Program Pemkot meraih penghargaan nasional” adalah positif.

### 13.2 Definisi label

| Label | Definisi |
|---|---|
| Positif | Ada pujian, keberhasilan, manfaat, dukungan, perbaikan, prestasi, atau hasil baik yang jelas terhadap Pemkot |
| Negatif | Ada kritik, kegagalan, kerugian, keluhan, konflik, masalah, dugaan pelanggaran, atau ketidakpuasan yang diarahkan kepada Pemkot |
| Netral | Pelaporan faktual tanpa evaluasi positif atau negatif yang dominan |

### 13.3 Artikel campuran

Aturan awal:

1. Nilai sentimen terhadap Pemkot, bukan emosi keseluruhan berita.
2. Utamakan fokus judul, lead, dan porsi pembahasan.
3. Jika dua nada sama kuat dan tidak dapat ditentukan, masukkan `perlu_review`.
4. Jangan memaksa confidence rendah menjadi fakta pada dashboard.
5. Simpan label manual jika admin mengoreksi hasil.

---

## 14. Evaluasi model

### 14.1 Metrik relevansi

Untuk penyaring relevansi, gunakan:

- precision kelas relevan;
- recall kelas relevan;
- F1 kelas relevan;
- confusion matrix;
- false positive per sumber;
- false negative per sumber;
- persentase artikel `perlu_review`;
- precision setelah review manusia;
- waktu rata-rata review.

Priority sistem ini adalah mengurangi artikel tidak relevan tanpa kehilangan terlalu banyak artikel penting.

Target awal yang disarankan:

| Metrik | Target awal |
|---|---:|
| Precision relevansi keseluruhan | minimal 80% |
| Precision per kelompok sumber utama | minimal 75% |
| Recall relevansi | minimal 85% |
| F1 relevansi | minimal 0,80 |
| Artikel perlu review | di bawah 20% setelah stabil |
| Koreksi manual yang tertimpa analisis ulang | 0 kasus |

Target dapat dinaikkan setelah gold set dan model stabil.

### 14.2 Metrik sentimen

Gunakan:

- macro F1 sebagai metrik utama;
- F1 per kelas;
- confusion matrix;
- distribusi label;
- calibration error bila confidence ditampilkan;
- proporsi `perlu_review`;
- evaluasi per sumber dan per topik.

Accuracy tidak boleh menjadi satu-satunya ukuran karena distribusi positif, netral, dan negatif dapat tidak seimbang.

### 14.3 Konsistensi pelabel

Minimal:

1. Ambil 40 artikel secara acak.
2. Sembunyikan label lama.
3. Labeli ulang setelah beberapa hari.
4. Hitung persentase kesesuaian dan Cohen’s kappa.
5. Bahas kasus yang berbeda dan perbarui panduan.

Jika memungkinkan, minta pelabel kedua untuk sebagian sampel. Akurasi model tidak dapat diharapkan melampaui konsistensi label manusia secara stabil.

### 14.4 Kalibrasi threshold

Threshold relevansi dan sentimen dipilih dari validation set, bukan dari test set. Catat:

- versi model;
- versi gold set;
- versi konteks;
- nilai threshold;
- tanggal evaluasi;
- metrik per kelas;
- jumlah sampel;
- hash konfigurasi evaluasi.

Hindari riwayat evaluasi ganda untuk konfigurasi yang sama.

---

## 15. Perubahan UI admin

### 15.1 Halaman pelabelan

Satu artikel menampilkan:

- judul dan sumber;
- excerpt;
- kategori dan tag;
- potongan kalimat yang memicu relevansi;
- entitas yang ditemukan;
- prediksi relevansi dan skor;
- tombol `Relevan`, `Tidak relevan`, dan `Lewati`;
- setelah relevan, pilihan sentimen `Negatif`, `Netral`, `Positif`;
- alasan koreksi opsional atau wajib untuk kasus tertentu.

Tidak ada lagi tiga kartu konteks untuk satu artikel.

### 15.2 Antrean review

Prioritaskan:

1. confidence paling dekat threshold;
2. sumber baru;
3. artikel negatif;
4. artikel dengan konflik antara tag dan AI;
5. artikel yang menyebut Pemkot hanya sekali;
6. artikel dengan jangkauan atau tier media tinggi.

### 15.3 Halaman evaluasi

Tampilkan terpisah:

- evaluasi relevansi;
- evaluasi sentimen;
- distribusi gold set;
- metrik per sumber;
- metrik per topik;
- 20 false positive terbesar;
- 20 false negative terbesar;
- perbandingan versi model;
- konsistensi pelabel.

Hindari mencampur jumlah label pasangan konteks dengan jumlah artikel unik.

---

## 16. Perubahan pada dokumen rancangan yang ada

### 16.1 Dokumen 00: README

Ubah keputusan teknis:

- konteks awal dari tiga menjadi satu konteks utama;
- model relevansi dijelaskan sebagai binary relevance classifier tingkat artikel;
- embedding dipakai untuk baseline, deduplikasi, dan pencarian semantik;
- fine-tuned IndoBERT menjadi target model produksi setelah data cukup.

### 16.2 Dokumen 01: PRD

Perubahan yang disarankan:

- F-10: sistem menilai apakah artikel secara substantif relevan terhadap Pemerintah Kota Kendari sebelum sentimen.
- F-11: sistem memberi sentimen terhadap Pemerintah Kota Kendari hanya untuk artikel relevan.
- F-12: hasil relevansi atau sentimen yang tidak pasti masuk status perlu review.
- F-15: admin mengelola definisi konteks utama dan kamus alias; multi-konteks tambahan ditunda.
- F-17/F-18: entitas dan alias menjadi cara membedakan Wali Kota, OPD, lokasi, dan program tanpa konteks tambahan.
- F-19: gold set menyimpan label relevansi artikel dan sentimen final.
- bagian konteks awal: ganti tabel tiga konteks menjadi satu konteks utama beserta aturan inklusi dan eksklusi.
- metrik sukses: tambahkan precision dan recall relevansi.

### 16.3 Dokumen 02: Spesifikasi teknis

Perubahan yang disarankan:

- `AnalisisRelevansi` hanya berjalan sekali per artikel, bukan sekali untuk setiap konteks aktif;
- endpoint `/relevancy` menerima metadata WordPress dan context windows;
- pipeline menyimpan kandidat tidak relevan;
- job ekstraksi isi dapat memakai dua tahap: metadata ringan lalu isi penuh;
- tambahkan kamus alias dan sinyal term;
- threshold mempunyai versi konfigurasi;
- embedding baseline dan classifier produksi dapat berjalan melalui kontrak endpoint yang sama.

### 16.4 Dokumen 03: Skema database

Periksa dan revisi:

- relasi hasil relevansi per artikel;
- kolom metadata kategori/tag sumber;
- label manual dan audit koreksi;
- versi model, konteks, threshold, dan gold set;
- tabel alias entitas;
- tabel atau JSON sinyal relevansi;
- pengelompokan duplikat untuk data split.

### 16.5 Dokumen 04: Spesifikasi UI

Ubah halaman:

- pelabelan dari tiga konteks menjadi satu keputusan relevansi;
- tampilkan tag/kategori dan potongan konteks;
- tambahkan antrean perlu review;
- pisahkan evaluasi relevansi dan sentimen;
- tampilkan jumlah artikel unik, bukan jumlah pasangan artikel-konteks.

### 16.6 Dokumen 05: Spesifikasi NLP

Revisi utama:

- definisi tugas relevansi biner;
- format data training;
- strategi hard negative;
- context window;
- pembagian data berbasis grup duplikat;
- baseline embedding;
- fine-tuning IndoBERT;
- calibration dan threshold;
- aturan sentimen konteks tunggal;
- metrik dan versioning evaluasi.

### 16.7 Dokumen 07 dan 08: Roadmap dan sprint

Tambahkan pekerjaan:

- migrasi gold set lama;
- pengambilan metadata WordPress;
- pembangunan kamus alias;
- baseline embedding;
- antrean review;
- pengumpulan hard negatives;
- fine-tuning classifier setelah data cukup;
- evaluasi ulang sebelum dashboard dianggap siap dipakai pimpinan.

---

## 17. Tahapan implementasi

### Fase 1: Sederhanakan konteks

- Nonaktifkan dua konteks tambahan.
- Pertahankan satu konteks `pemerintah-kota-kendari`.
- Ubah UI pelabelan menjadi satu keputusan relevansi.
- Pastikan artikel tidak dianalisis tiga kali.

**Hasil:** beban label turun sekitar dua pertiga untuk relevansi.

### Fase 2: Metadata WordPress dan kamus alias

- Ambil kategori dan tag.
- Normalisasi term menjadi huruf kecil dan bentuk baku.
- Buat alias Pemkot, pejabat, dan OPD.
- Simpan sinyal yang cocok.

**Hasil:** sistem mempunyai fitur yang transparan sebelum AI.

### Fase 3: Baseline AI relevansi

- Jalankan embedding lokal.
- Tentukan threshold rendah dan tinggi dari validation set.
- Masukkan skor menengah ke antrean review.
- Catat false positive dan false negative.

**Hasil:** penyaringan otomatis dapat dipakai tanpa menunggu fine-tuning.

### Fase 4: Perbaiki gold set

- Gabungkan label lama menjadi artikel unik.
- Review kasus konflik.
- Tambahkan hard negatives.
- Bekukan test set.
- Lakukan label ulang untuk mengukur konsistensi.

**Hasil:** evaluasi menjadi dapat dipercaya.

### Fase 5: Fine-tune IndoBERT

- Latih binary classifier.
- Gunakan class weighting bila distribusi tidak seimbang.
- Pilih checkpoint berdasarkan F1/precision-recall, bukan accuracy saja.
- Kalibrasi confidence.

**Hasil:** model memahami pola media lokal dan perbedaan antar-instansi.

### Fase 6: Stabilkan produksi

- Bandingkan baseline dan classifier.
- Tetapkan threshold produksi.
- Aktifkan monitoring drift mingguan.
- Pertahankan manual override.
- Perbarui dokumentasi dan nomor versi.

---

## 18. Pseudocode Laravel

```php
public function processArticle(Article $article): void
{
    $metadata = $this->metadataExtractor->extract($article);
    $signals = $this->relevanceSignals->match($metadata);

    $article->update([
        'source_categories' => $metadata->categories,
        'source_tags' => $metadata->tags,
        'relevance_signals' => $signals,
        'processing_status' => 'menunggu_relevansi',
    ]);

    $result = $this->nlpClient->classifyRelevance(
        article: $article,
        contextVersion: config('nlp.relevance_context_version'),
    );

    if ($article->manual_relevance_label !== null) {
        return; // keputusan manusia tidak boleh ditimpa
    }

    $article->saveModelRelevance($result);

    if ($result->requiresReview()) {
        $article->update(['processing_status' => 'perlu_review']);
        return;
    }

    if ($result->isNotRelevant()) {
        $article->update(['processing_status' => 'tidak_relevan']);
        return;
    }

    AnalyzeSentiment::dispatch($article->id)->onQueue('nlp');
}
```

---

## 19. Contoh keputusan relevansi

| Judul/skenario | Label | Alasan |
|---|---|---|
| Pemkot Kendari meluncurkan layanan perizinan daring | Relevan | Program Pemkot menjadi fokus |
| Wali Kota Kendari menghadiri Musrenbang | Relevan | Figur dibahas dalam kapasitas jabatan |
| Warga Kadia mengeluhkan sampah yang belum diangkut DLHK | Relevan | Keluhan terhadap layanan OPD Pemkot |
| DPRD menyoroti rendahnya serapan APBD Pemkot | Relevan | Pengawasan langsung terhadap eksekutif dan anggaran Pemkot |
| Bea Cukai Kendari menyita rokok ilegal | Tidak relevan | Instansi vertikal; Kendari adalah lokasi/unit kantor |
| Polda Sultra mengungkap kasus di Kendari | Tidak relevan | Tidak ada keterlibatan substantif Pemkot |
| Pemprov Sultra membangun jalan di Kota Kendari | Tidak relevan | Pelaksana dan kewenangan adalah Pemprov, kecuali Pemkot menjadi bagian utama |
| Festival musik berlangsung di Kendari | Tidak relevan | Lokasi saja, tanpa keterlibatan Pemkot |
| Pemkot hadir sebagai salah satu dari puluhan tamu | Tidak relevan | Penyebutan sepintas |
| Pemerintah pusat menyerahkan bantuan melalui Pemkot Kendari | Relevan | Pemkot menjadi pelaksana atau penyalur penting |

---

## 20. Risiko dan mitigasi

| Risiko | Mitigasi |
|---|---|
| Satu konteks terlalu luas | Perjelas aturan inklusi/eksklusi dan gunakan topik/entitas untuk rincian |
| Tag media tidak konsisten | Jadikan tag sinyal, bukan keputusan akhir |
| Model embedding terlalu banyak false positive | Gunakan dua threshold, hard negatives, lalu fine-tune classifier |
| Artikel penting tidak menyebut “Pemkot” | Gunakan kamus OPD, pejabat, program, dan context window |
| Pemprov dianggap Pemkot | Tambahkan contoh kontras dan hard negatives khusus Pemprov |
| Artikel lokasi Kendari ikut masuk | Tambahkan aturan “lokasi saja” dan contoh training |
| Label manusia tidak konsisten | Panduan, label ulang, dan pelabel kedua sebagian sampel |
| Perubahan model membuat angka historis berubah | Simpan versi model, konteks, threshold, dan hasil evaluasi |
| Koreksi admin tertimpa job | Manual override menjadi aturan integritas dan automated test |
| Semua artikel bergantung AI | Gunakan aturan metadata sebagai fallback dan simpan antrean saat NLP mati |

---

## 21. Kriteria penerimaan perubahan

Perubahan dianggap selesai apabila:

- [ ] Hanya satu konteks utama aktif pada versi pertama.
- [ ] Satu artikel hanya memiliki satu keputusan relevansi utama.
- [ ] Wali Kota, OPD, pelayanan, dan infrastruktur tampil sebagai topik atau entitas.
- [ ] Kategori dan tag WordPress tersimpan.
- [ ] Kendari sebagai lokasi saja tidak otomatis dianggap relevan.
- [ ] Artikel Pemprov dan instansi vertikal mempunyai hard negative yang cukup.
- [ ] Artikel tidak relevan tetap tercatat tetapi tidak masuk dashboard utama.
- [ ] Hasil confidence rendah masuk antrean perlu review.
- [ ] Koreksi manual tidak pernah tertimpa analisis ulang.
- [ ] Gold set dihitung berdasarkan artikel unik.
- [ ] Data duplikat tidak tersebar antara train dan test.
- [ ] Evaluasi relevansi dan sentimen dipisahkan.
- [ ] Precision relevansi mencapai minimal 80% pada test set awal.
- [ ] Recall relevansi mencapai minimal 85% pada test set awal.
- [ ] Macro F1 sentimen tetap menjadi metrik utama.
- [ ] Setiap evaluasi menyimpan versi model, konteks, threshold, dan gold set.

---

## 22. Rekomendasi final

Untuk versi pertama SIMEDIA Kendari, rancangan paling sederhana dan paling sesuai tujuan adalah:

1. Gunakan **satu konteks utama: Pemerintah Kota Kendari**.
2. Jadikan relevansi sebagai **klasifikasi biner tingkat artikel**.
3. Gunakan tag dan kategori WordPress sebagai **sinyal awal**, bukan keputusan final.
4. Jalankan AI relevansi sebelum sentimen.
5. Mulai dengan **embedding lokal** agar cepat diuji dan tetap gratis per artikel.
6. Kumpulkan koreksi admin sebagai hard negatives dan training data.
7. Setelah data cukup, fine-tune **IndoBERT binary classifier** untuk produksi.
8. Pertahankan embedding untuk deduplikasi dan pencarian semantik.
9. Jadikan Wali Kota, OPD, pelayanan, dan infrastruktur sebagai topik atau entitas otomatis.
10. Tampilkan hasil yang tidak pasti sebagai `perlu_review`, bukan sebagai fakta.

Penyederhanaan ini tidak membuang kemampuan analisis. Sebaliknya, ia memisahkan tanggung jawab sistem dengan lebih jelas: **relevansi menentukan artikel yang masuk, sentimen mengukur nadanya, dan topik/entitas menjelaskan isi pemberitaannya.**

---

## Lampiran A: Contoh kamus kelompok entitas

### A.1 Institusi utama

- Pemerintah Kota Kendari
- Pemkot Kendari
- Pemerintah Kota
- Pemkot Kdi

### A.2 Pimpinan

- Wali Kota Kendari
- Walikota Kendari
- Wakil Wali Kota Kendari
- Sekretaris Daerah Kota Kendari
- Sekda Kendari

Nama orang tidak boleh berdiri sendiri sebagai sinyal permanen. Nama harus dihubungkan ke jabatan dengan masa berlaku karena pejabat dapat berganti.

### A.3 Unit kerja

- seluruh dinas;
- seluruh badan;
- sekretariat daerah dan bagiannya;
- Satpol PP;
- kecamatan;
- kelurahan;
- UPTD;
- BLUD;
- puskesmas milik Pemkot jika masuk ruang lingkup;
- sekolah milik Pemkot jika masuk ruang lingkup.

### A.4 Kontras yang harus dikenali

- Pemerintah Provinsi Sulawesi Tenggara;
- Pemprov Sultra;
- Polda Sultra;
- Polresta Kendari;
- Korem/Kodim/TNI;
- Kejari Kendari;
- Pengadilan;
- Bea Cukai Kendari;
- BPS Kota Kendari;
- Kantor Imigrasi Kendari;
- Kementerian dan kantor wilayah;
- Universitas Halu Oleo dan kampus lain;
- BUMN/BUMD yang bukan unit Pemkot;
- pemerintah kabupaten di Sulawesi Tenggara.

---

## Lampiran B: Format gold set baru

```csv
article_id,title,source,published_at,relevance_label,sentiment_label,duplicate_group,labeler,labeled_at,gold_set_version,notes
101,"Pemkot Kendari ...","Media A","2026-08-03",1,"positif","dup-001","admin-1","2026-08-03T10:00:00+08:00","2.0","program Pemkot menjadi fokus"
102,"Bea Cukai Kendari ...","Media B","2026-08-03",0,,"dup-002","admin-1","2026-08-03T10:05:00+08:00","2.0","instansi vertikal"
```

Aturan:

- `sentiment_label` hanya wajib jika `relevance_label = 1`;
- artikel tidak relevan tidak diberi sentimen;
- satu `duplicate_group` tidak boleh masuk dua split berbeda;
- seluruh label final memiliki pelabel dan waktu;
- perubahan definisi konteks menaikkan `gold_set_version`.

---

## Lampiran C: Keputusan yang perlu disetujui sebelum implementasi

1. Apakah DPRD Kota Kendari dimasukkan hanya jika berkaitan langsung dengan eksekutif Pemkot? **Rekomendasi: ya.**
2. Apakah sekolah dan puskesmas milik Pemkot dimasukkan sebagai unit Pemkot? **Rekomendasi: ya, jika kewenangannya jelas.**
3. Apakah BUMD Kota Kendari otomatis masuk? **Rekomendasi: masukkan jika kepemilikan dan hubungan dengan Pemkot menjadi bagian substansial berita.**
4. Berapa lama artikel tidak relevan disimpan? **Rekomendasi awal: minimal satu tahun atau sampai kebijakan retensi ditetapkan.**
5. Siapa yang menjadi pelabel kedua untuk audit sampel? **Belum ditentukan dalam dokumen sumber.**
6. Kapan model boleh dinaikkan dari baseline embedding ke classifier? **Rekomendasi: setelah tersedia minimal 300-500 artikel unik yang telah direview dan test set permanen.**

