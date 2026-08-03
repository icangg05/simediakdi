# 05: Spesifikasi NLP

SIMEDIA Kendari | Versi 1.0

---

Dokumen ini dirujuk sejak versi pertama paket spesifikasi tapi tidak pernah
ditulis. Akibatnya tiga hal menggantung: gerbang sprint 0 ("baca dokumen 05
bagian 8"), panduan pelabelan yang akhirnya ditulis ulang sebagai dokumen 09,
dan rencana cadangan kalau F1 macro di bawah 0,65. Ketiganya ditutup di sini.

Isinya hanya bagian NLP. Kontrak HTTP layanannya ada di dokumen 02 bagian 6,
skema penyimpanannya di dokumen 03, dan aturan pelabelan manusia di dokumen 09.

## 1. Dua tugas, bukan satu

| Tugas | Pertanyaan | Tingkat | Keluaran |
|-------|-----------|---------|----------|
| Relevansi | Apakah artikel membahas Pemerintah Kota Kendari secara substantif? | Artikel | `relevan`, `tidak_relevan`, `perlu_review` |
| Sentimen | Bagaimana nada artikel terhadap Pemerintah Kota Kendari? | Artikel yang relevan saja | `negatif`, `netral`, `positif` |

Topik, entitas, dan kata kunci bukan tugas klasifikasi. Ketiganya hasil
ekstraksi dan pencocokan kamus, dan tidak pernah menjadi konteks pelabelan
terpisah. Alasannya di dokumen 01 bagian 9.

Relevansi selalu berjalan sebelum sentimen. Model sentimen tetap mengeluarkan
label untuk artikel yang tidak relevan, label itu masuk agregasi, dan grafik
dashboard terisi angka yang tidak berhubungan dengan Pemkot Kendari.

## 2. Alur pemrosesan

Dua model, masing-masing satu tugas.

```text
RSS / WordPress API / scrape
        ↓
Simpan artikel sebagai kandidat
judul, URL, tanggal, excerpt, tag, kategori
        ↓
Unduh dan bersihkan isi artikel
        ↓
multilingual-e5-small
   ├── ukur relevansi terhadap konteks Pemkot Kendari
   └── ukur kemiripan dengan artikel sebelumnya
        ↓
   salinan? ── ya ──→ tandai salinan, berhenti (tidak dianalisis ulang)
        │
       tidak
        ↓
┌────────────────┬────────────────┬────────────────────┐
relevan          perlu review     tidak relevan
↓                ↓                ↓
IndoBERT         antrean admin    tetap disimpan,
sentiment                         tidak masuk dashboard
↓
positif / netral / negatif
↓
entitas, topik, kata kunci
↓
Dashboard
```

Dua hal yang tidak terlihat di bagan dan perlu diketahui:

- **Salinan berhenti sebelum relevansi.** Berita yang sama disalin banyak media,
  dan salinan yang ke-10 tidak menambah informasi apa pun. Menganalisis semuanya
  hanya menambah biaya. Pada korpus 4.806 artikel, 13,8% adalah salinan.
  **Berhenti di sini hanya berlaku untuk analisis.** Barisnya tetap utuh dan
  tetap dihitung sebagai realisasi kontrak medianya, lihat dokumen 03 bagian 10.
- **Yang menentukan tiga cabang itu satu angka.** Skor kemiripan makna terhadap
  deskripsi konteks. Di atas ambang atas berarti relevan, di bawah ambang bawah
  berarti tidak relevan, di antaranya berarti perlu review.

### 2.1 Model yang dipakai

| Model | Tugas |
|-------|-------|
| `intfloat/multilingual-e5-small` | Relevansi dan deteksi salinan |
| `apriandito/indobert-sentiment-classifier` | Sentimen, hanya untuk artikel relevan |

`apriandito/indobert-relevancy` **tidak lagi dipakai**. Relevansi kini dihitung
dari kemiripan makna, bukan dari classifier terpisah. Tiga akibatnya:

1. Layanan NLP memuat dua model, bukan tiga.
2. Tidak ada lagi endpoint `/relevancy`. Skor dihitung di PostgreSQL dari
   vektor yang sudah tersimpan, memakai operator `<=>` milik pgvector.
3. Mengubah ambang tidak memerlukan inferensi ulang. Seluruh korpus dinilai
   ulang dengan satu kueri SQL dalam hitungan detik, bukan jam.

Poin ketiga yang paling menentukan. Ambang akan disetel berkali-kali sampai
presisi tercapai, dan menunggu inferensi ulang 4.806 artikel setiap kali
menyetel satu angka membuat pekerjaan itu tidak pernah selesai.

### 2.2 Dua vektor, bukan satu

Deduplikasi butuh gambaran artikel seutuhnya. Relevansi butuh gambaran yang
terfokus pada bagian yang menyinggung Pemkot. Satu vektor tidak bisa melayani
keduanya: kalau dipakai vektor isi penuh, dua artikel berbeda yang sama-sama
membahas Pemkot akan terlihat seperti salinan.

Karena itu satu artikel menyimpan dua vektor. Biayanya sekitar 1,5 KB per
artikel, dan keduanya dihasilkan model yang sama dalam satu kali panggilan.

### 2.3 Fine-tuning IndoBERT untuk relevansi

Masuk daftar versi 2, dikerjakan hanya kalau cosine tidak mencapai presisi 80%
setelah ambang disetel dan hard negative terkumpul. Perlu sekitar 1.000
keputusan manusia sebelum masuk akal dikerjakan.

Sentimen tetap memakai model bawaan tanpa pelatihan ulang. F1 macro terukur
0,7361 pada 470 label gold set ronde 1.

LLM eksternal (Gemini, OpenAI, dan sejenisnya) tidak dipakai sebagai jalur
utama per artikel. Biaya per artikel, kuota, latensi, ketergantungan akses
keluar server, dan keluaran yang berubah antar versi model semuanya sulit
dikendalikan untuk sistem yang angkanya dilaporkan ke pimpinan.

## 3. Bentuk input relevansi

Cosine similarity membandingkan dua teks. Keduanya harus dibentuk dengan
sengaja, karena model membandingkan persis apa yang diberikan.

### 3.1 Sisi konteks, dihitung sekali

Teks deskripsi konteks di dokumen 01 bagian 9, diberi awalan `query:` sesuai
ketentuan e5. Hasilnya satu vektor yang disimpan di baris
`konteks_pantauan`, bukan dihitung ulang tiap artikel.

Vektor ini berubah hanya kalau deskripsi konteksnya berubah. Kalau itu
terjadi, `RELEVANSI_KONTEKS_VERSI` naik dan seluruh skor relevansi dihitung
ulang.

### 3.2 Sisi artikel, dihitung sekali per artikel

Diberi awalan `passage:`, susunannya:

```text
Judul: ...
Kategori: ...
Tag: ...
Ringkasan: ...
Potongan isi terkait: ...
Entitas terdeteksi: ...
```

Mengirim isi artikel mentah dari huruf pertama adalah kesalahan yang mahal.
Model dipotong di 512 token, dan kalimat yang benar-benar menjelaskan hubungan
dengan Pemkot sering berada di paragraf keenam.

Potongan isi terkait dibentuk dengan jendela konteks. Cari penyebutan Pemkot,
Wali Kota, nama OPD, alias, nama pejabat yang terhubung ke jabatan, atau nama
program resmi. Untuk tiap temuan ambil dua kalimat sebelum, kalimat
penyebutannya, dan dua kalimat sesudah. Gabungkan sampai batas token tercapai.

Kamus alias memakai tabel `entitas` yang sudah ada, kolom `alias` (F-18).
Tidak ada tabel kamus kedua. Pencocokannya sudah berhenti di batas kata dan
membuang alias di bawah tiga huruf, dua aturan yang lahir dari kesalahan nyata
("Kendari" terhitung di dalam "Kendarian", "PU" terhitung di ribuan kata biasa).

## 4. Sinyal metadata WordPress

27 dari 30 media partner memakai WordPress dan sudah dipanen lewat
`/wp-json/` pada `crawl:backfill`. Kategori dan tag ikut ditarik dan disimpan
di kolom `kategori_sumber` dan `tag_sumber`.

Tag dipakai sebagai sinyal, tidak pernah sebagai keputusan akhir. Sebagian
media memasang tag untuk SEO, memberi tag "Kendari" hanya karena lokasi, dan
tidak konsisten antar penulis.

| Sinyal | Bobot routing |
|--------|--------------:|
| Judul menyebut "Pemerintah Kota Kendari" atau "Pemkot Kendari" | +4 |
| Judul menyebut nama OPD Pemkot yang unik | +3 |
| Tag spesifik Pemkot atau OPD | +3 |
| Ringkasan menyebut Pemkot beserta tindakan atau kewenangannya | +2 |
| Hanya menyebut "Kendari" | 0 |
| Judul membahas instansi vertikal tanpa Pemkot | -3 |
| Kendari hanya lokasi acara atau kejadian | -2 |

Skor ini menentukan urutan antrean dan artikel mana yang diunduh penuh lebih
dulu. Ia bukan probabilitas relevansi, dan tidak pernah disimpan sebagai
`skor_relevansi`.

## 5. Dua ambang dan pengetat sebutan

### 5.1 Dua ambang

Skor cosine berkisar 0 sampai 1 dan **bukan probabilitas**. Angka 0,82 tidak
berarti "82% yakin". Ia hanya berarti lebih mirip daripada 0,79. Karena itu
ambangnya tidak boleh ditebak, harus dipilih dari validation set.

| Rentang skor | Keputusan |
|--------------|-----------|
| Di atas ambang atas | Relevan, lanjut ke sentimen |
| Di antara dua ambang | Perlu review, masuk antrean admin |
| Di bawah ambang bawah | Tidak relevan, keluar dari alur |

Cara memilihnya:

1. Hitung skor untuk seluruh baris validation set.
2. Pilih **ambang atas** pada titik presisi mencapai 80%.
3. Pilih **ambang bawah** pada titik recall mencapai 85%.
4. Ukur berapa persen artikel jatuh di antara keduanya. Kalau lebih dari 20%,
   antrean review akan lebih panjang daripada yang sanggup dikerjakan admin,
   dan ambangnya perlu dirapatkan walau presisinya sedikit turun.
5. Baru setelah itu jalankan sekali di test set beku, dan laporkan angka itu.

Disimpan sebagai `RELEVANSI_AMBANG_ATAS` dan `RELEVANSI_AMBANG_BAWAH`.

Nilai awal sebelum diukur: jangan dipasang sama sekali. Jalankan sistem dalam
mode "semua masuk antrean review" selama pengukuran pertama. Memasang angka
tebakan lalu lupa menggantinya adalah cara paling umum sebuah ambang menjadi
permanen tanpa pernah diukur.

### 5.2 Pengetat sebutan tetap dipakai

Cosine sendirian tidak cukup, dan alasannya justru datang dari cara input
dibentuk di bagian 3.2. Teks artikel yang dikirim ke model **disusun dari
potongan kalimat di sekitar sebutan Pemkot**. Artinya artikel yang menyebut
Pemkot satu kali sepintas akan menghasilkan teks yang isinya hampir seluruhnya
tentang Pemkot, lalu mendapat skor cosine tinggi. Justru kesalahan yang paling
ingin dihindari.

Pengetatnya sama seperti yang sudah terukur pada model lama: kata kunci konteks
harus muncul di judul, atau minimal tiga kali di isi. Diukur terhadap 254 label
manusia dengan separuh data ditahan:

| Aturan | Presisi | Recall | F1 |
|--------|---------|--------|-----|
| Model apa adanya | 54,2% | 100% | 0,703 |
| Kata kunci di judul saja | 92,3% | 46,2% | 0,615 |
| Kata kunci di judul atau 400 huruf awal | 65,1% | 78,8% | 0,713 |
| **Kata kunci di judul atau minimal 3 kali di isi** | **80,0%** | **92,3%** | **0,857** |
| Kata kunci di judul atau minimal 4 kali di isi | 80,0% | 76,9% | 0,784 |

Varian minimal 4 kali menang pada separuh data yang dipakai memilih dan kalah
pada data tahan. Tanpa data tahan, varian yang salah yang akan dipasang.

Disetel lewat `RELEVANSI_MINIMAL_SEBUTAN`, bawaan 3.

**Angka di tabel ini diukur pada model lama, bukan pada cosine.** Ia dipakai
sebagai titik awal, bukan sebagai bukti. Ukur ulang ketiga varian terhadap
skor cosine di sprint 6, dan kalau ternyata pengetatnya tidak lagi menambah
presisi, matikan. Aturan yang tidak lagi bekerja tapi tetap dipasang adalah
utang yang menyamar sebagai kehati-hatian.

## 6. Gold set

### 6.1 Bentuk

Relevansi biner pada tingkat artikel:

```text
0 = tidak relevan
1 = relevan
```

`perlu_review` bukan label gold set. Itu keputusan operasional berdasarkan
ketidakpastian model. Setiap baris gold set punya keputusan manusia final.

Label sentimen hanya diisi kalau `relevan_gold = true`. Artikel tidak relevan
tidak diberi sentimen, dan barisnya tetap disimpan karena hard negative adalah
data latih yang paling berharga.

### 6.2 Komposisi

Sampel acak dari produksi saja tidak cukup. Yang harus ada:

- contoh relevan yang jelas;
- contoh tidak relevan yang jelas;
- hard negative dari false positive model;
- artikel yang memakai Kendari hanya sebagai lokasi;
- artikel Pemprov Sultra;
- artikel instansi vertikal (kepolisian, kejaksaan, Bea Cukai, BPS, kanwil);
- artikel DPRD Kota Kendari, dengan dan tanpa hubungan ke eksekutif;
- artikel yang menyebut pejabat atau OPD sepintas;
- artikel kritik yang judulnya tidak menyebut Pemkot;
- artikel salinan dan siaran pers.

Target: 300 sampai 500 artikel unik, minimal 150 relevan dan 150 tidak relevan.

### 6.3 Pembagian data

Dibagi per grup duplikat, bukan per baris. Grup duplikat adalah
`COALESCE(artikel_induk_id, id)`. Seluruh salinan satu berita masuk split yang
sama. Tanpa aturan ini, rilis Antara yang sama muncul di train dan di test, dan
angka evaluasinya bohong ke atas.

Satu test set dibekukan dan tidak pernah dipakai memilih ambang atau melatih
model. Ambang dipilih dari validation set.

## 7. Metrik dan gerbang

### 7.1 Relevansi

Presisi kelas relevan adalah metrik utama. Sistem ini lebih dirugikan oleh
artikel yang tidak seharusnya masuk daripada oleh artikel yang terlewat, karena
artikel keliru yang lolos akan terlihat pimpinan dan merusak kepercayaan pada
seluruh angka.

| Metrik | Target |
|--------|-------:|
| Presisi relevansi | minimal 80% |
| Recall relevansi | minimal 85% |
| F1 relevansi | minimal 0,80 |
| Artikel `perlu_review` | di bawah 20% setelah stabil |
| Koreksi manual yang tertimpa analisis ulang | 0 kasus |

Laporkan juga false positive dan false negative per sumber. Satu media dengan
gaya penulisan tertentu bisa menyumbang sebagian besar kesalahan, dan itu tidak
terlihat pada angka gabungan.

**Recall relevansi tidak boleh dibaca dari sampel mode terarah.** Mode
"kemungkinan relevan" hanya menampilkan artikel yang model anggap relevan,
sehingga artikel relevan yang model lewatkan hampir tidak pernah sampai ke
pelabel. Angka 95,6% pada evaluasi lama terkena bias ini. Presisi tidak.

### 7.2 Sentimen

Macro F1 metrik utama, karena distribusi negatif, netral, dan positif sangat
timpang (negatif hanya 4,5% dari pasangan relevan). Accuracy sendirian akan
terlihat bagus dengan model yang tidak pernah menebak negatif.

Laporkan juga F1 per kelas beserta jumlah sampelnya. F1 sempurna dari tiga
sampel bukan pengukuran, dan pernah menaikkan F1 macro proyek ini dari 0,7361
yang jujur menjadi 0,7998 yang menyesatkan.

Gerbang: F1 macro minimal 0,65. Di bawah itu, baca bagian 8.

### 7.3 Konsistensi pelabel

Gold set dilabeli satu orang, jadi batas atas akurasi yang wajar diminta dari
model adalah konsistensi pelabel dengan dirinya sendiri. Ukur dengan melabeli
ulang 40 baris acak seminggu kemudian tanpa melihat label lama, lalu hitung
persentase kesesuaian dan Cohen's kappa. Kalau manusia hanya konsisten 82%,
F1 macro 0,80 adalah hasil yang sangat baik, bukan kekurangan.

### 7.4 Versi yang disimpan pada setiap evaluasi

Versi model, versi definisi konteks, versi gold set, nilai ambang, nilai
`minimal_sebutan`, jumlah sampel, dan tanggal. Tanpa ini, evaluasi bulan lalu
tidak bisa direproduksi dan perbandingan antar versi model tidak berarti.

Versi ambang per artikel sengaja tidak disimpan. Ambang berlaku global, ada di
`.env`, dan perubahannya melewati deploy sehingga tercatat di git bersama
alasannya. Menyimpannya per baris berarti membayar satu kolom di tabel terbesar
untuk informasi yang sudah ada di tempat lain.

## 8. Kalau F1 macro di bawah 0,65

Gerbang ini ada supaya dashboard tidak dibangun di atas model yang angkanya
akan dibantah di rapat pertama. Kalau kena, kerjakan berurutan dan berhenti
begitu angkanya lewat.

1. **Periksa gold set lebih dulu, bukan modelnya.** Kalau kesesuaian ronde 1
   dan ronde 2 di bawah 0,75, yang rusak adalah aturan pelabelan. Perbaiki
   dokumen 09, labeli ulang baris yang terpengaruh, ukur lagi. Melatih model
   pada label yang tidak konsisten membuang waktu berminggu-minggu.
2. **Periksa distribusi kelas.** F1 macro merata-ratakan tiga kelas dengan
   bobot sama, jadi satu kelas dengan lima sampel bisa menjatuhkan seluruh
   angka. Kumpulkan minimal 20 sampel per kelas lewat mode terarah sebelum
   menyimpulkan modelnya buruk.
3. **Periksa ekstraksi isi.** Artikel yang isinya terpotong, tercampur menu
   navigasi, atau kosong akan dinilai model apa adanya. Ambil 50 artikel dengan
   prediksi salah dan baca isi tersimpannya. Kalau lebih dari seperlimanya
   rusak, masalahnya di crawler.
4. **Turunkan cakupan, bukan standar.** Sentimen hanya ditampilkan untuk
   artikel dengan keyakinan tinggi, sisanya masuk `perlu_review` dan diputuskan
   admin. Dashboard tetap jujur, hanya volumenya berkurang.
5. **Fine-tune classifier sentimen** dengan gold set dan koreksi admin yang
   sudah terkumpul. Baru langkah kelima karena butuh data paling banyak dan
   waktu paling lama.
6. **Kalau semuanya gagal, jangan tampilkan sentimen.** Sistem tetap berguna
   sebagai pemantau volume, sumber, isu, dan entitas, semuanya tidak bergantung
   pada model. Menampilkan angka sentimen yang diketahui tidak akurat merusak
   kepercayaan pada bagian sistem yang sebenarnya benar.

## 9. Aturan yang tidak boleh dilanggar

1. Layanan NLP tidak menyentuh database. Ia menerima teks dan mengembalikan
   angka. Laravel satu-satunya yang menyimpan.
2. Koreksi manusia selalu mengalahkan model, dan analisis ulang tidak pernah
   menimpanya.
3. Artikel tidak relevan tetap disimpan. Ia bahan hard negative dan bukti
   berapa banyak kandidat yang tersaring.
4. Model dan ambang punya versi, dan versinya ikut tersimpan di setiap baris
   evaluasi.
5. Artikel duplikat dikelompokkan sebelum data dibagi.
6. Hasil dengan keyakinan rendah ditampilkan sebagai `perlu_review`, tidak
   pernah sebagai fakta.

## Changelog

| Versi | Tanggal | Perubahan |
|-------|---------|-----------|
| 1.0 | Agustus 2026 | Dokumen dibuat, menutup rujukan menggantung dari dokumen 00, 02, 07, dan 08. Isinya mengikuti keputusan konteks tunggal pada usulan revisi 1.3 |
