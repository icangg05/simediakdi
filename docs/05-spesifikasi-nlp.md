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

Tiga model, masing-masing satu tugas.

```text
RSS / WordPress API / scrape
        ↓
Simpan artikel sebagai kandidat
judul, URL, tanggal, excerpt, tag, kategori
        ↓
Unduh dan bersihkan isi artikel
        ↓
multilingual-e5-small: ukur kemiripan dengan artikel sebelumnya
        ↓
   salinan? ── ya ──→ tandai salinan, berhenti (tidak dianalisis ulang)
        │
       tidak
        ↓
masuk dataset relevansi sebagai kandidat berlabel `belum_dilabeli`
        ↓
ada model relevansi produksi yang lolos gerbang mutu?
        │
       tidak ──→ status `model_belum_lulus_gate`, BERHENTI
        │        artikel tetap dikumpulkan dan menunggu dilabeli
       ya
        ↓
IndoBERT relevansi hasil fine-tuning
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

Tiga hal yang tidak terlihat di bagan dan perlu diketahui:

- **Salinan berhenti sebelum relevansi.** Berita yang sama disalin banyak media,
  dan salinan yang ke-10 tidak menambah informasi apa pun. Menganalisis semuanya
  hanya menambah biaya. Pada korpus 4.806 artikel, 13,8% adalah salinan.
  **Berhenti di sini hanya berlaku untuk analisis.** Barisnya tetap utuh dan
  tetap dihitung sebagai realisasi kontrak medianya, lihat dokumen 03 bagian 10.
- **Cabang "tidak ada model produksi" adalah keadaan awal, bukan galat.** Sejak
  4 Agustus 2026 tidak ada model relevansi yang lolos gerbang, jadi seluruh
  artikel baru berhenti di sana. Sentimen diblokir sampai laboratorium
  menghasilkan model yang lulus. Dokumen 10 bagian 0.3 dan 1.1.
- **Yang menentukan tiga cabang itu probabilitas classifier**, dibandingkan
  dengan ambang berversi di database. Di atas ambang relevan berarti relevan,
  di dalam pita review berarti perlu review, sisanya tidak relevan.

### 2.1 Model yang dipakai

| Model | Tugas | Dilatih ulang? |
|-------|-------|---|
| `intfloat/multilingual-e5-small` | Deteksi salinan | Tidak |
| `apriandito/indobert-relevancy-classifier` | Relevansi | Ya, dengan dataset lokal Kendari |
| `apriandito/indobert-sentiment-classifier` | Sentimen, hanya untuk artikel relevan | Tidak |

**Relevansi kembali menjadi classifier tersendiri sejak revisi 1.6, dan kali ini
dilatih ulang.** Yang diukur pada revisi 1.5 adalah checkpoint bawaan apa adanya,
dan hasilnya hanya satu poin presisi di atas aturan kata kunci. Yang dibangun
sekarang adalah checkpoint yang sama setelah dilatih dengan label Kendari.
Kegagalan yang pertama bukan bukti tentang yang kedua.

**Cosine e5 tidak lagi menentukan relevansi.** Ia berhenti di presisi 69,9%,
dan yang menahannya bukan pilihan model melainkan dataset: 249 label, dibuat
dengan aturan tiga konteks yang sudah tidak berlaku, tanpa data tahan, dan
tanpa hard negative yang disengaja. Laboratorium di dokumen 10 dibangun untuk
mengerjakan bagian itu. Fine-tuning adalah muaranya, bukan jalan pintasnya.

Satu sifat dari revisi 1.5 sengaja dipertahankan meski modelnya berganti:
**mengubah ambang tidak boleh memerlukan inferensi ulang.** Itu sebabnya
`prediksi_relevansi` menyimpan probabilitas mentah, bukan hanya label akhir.
Kueri penyetelannya ada di dokumen 03 bagian query agregasi.

### 2.2 Satu vektor, bukan dua

Revisi 1.5 menyimpan dua vektor per artikel, satu untuk deduplikasi dan satu
untuk relevansi. Yang kedua tidak dipakai lagi dan dihapus pada fase 1
laboratorium.

Yang tersisa adalah `artikel.embedding`, vektor isi penuh untuk deteksi
salinan, beserta index HNSW-nya. Bagian ini tidak berubah sama sekali dan tidak
perlu disentuh.

Ambang deduplikasi tetap harus diukur ulang. `DEDUP_AMBANG_COSINE=0.92`
disetel untuk MiniLM, sedangkan vektor e5 tidak sebanding dengannya. Tugas itu
tertinggal dari sprint 6 dan tidak ikut hilang bersama perpindahan penilai
relevansi.

### 2.3 Fine-tuning relevansi

Bukan lagi daftar versi 2. Ia menjadi jalur utama, dan seluruh pipeline-nya
dispesifikasikan di dokumen 10: dataset, snapshot, pelatihan, evaluasi,
versioning, promosi, dan rollback.

Syarat jumlah data yang menentukan kapan pelatihan pertama masuk akal:

| Tingkat | Artikel unik berlabel | Minimal per kelas | Test terkunci |
|---|---:|---:|---:|
| Eksperimen | 500 | 200 | 100 |
| Fine-tuning awal | 1.500 | 600 | 300 |
| Kandidat produksi | 3.000 | 1.200 | 500 |

Posisi sekarang: 249 label yang bisa dimigrasikan, dan itu belum cukup bahkan
untuk tingkat eksperimen. Pekerjaan terdekat adalah pelabelan, bukan pelatihan.

Sentimen tetap memakai model bawaan tanpa pelatihan ulang. F1 macro terukur
0,7361 pada 470 label gold set ronde 1.

LLM eksternal (Gemini, OpenAI, dan sejenisnya) tidak dipakai sebagai jalur
utama per artikel. Biaya per artikel, kuota, latensi, ketergantungan akses
keluar server, dan keluaran yang berubah antar versi model semuanya sulit
dikendalikan untuk sistem yang angkanya dilaporkan ke pimpinan.

## 3. Bentuk input relevansi

Classifier relevansi menerima pasangan: kalimat konteks dan representasi
artikel. Keduanya harus dibentuk dengan sengaja, karena model menilai persis
apa yang diberikan.

Bentuk input adalah bagian sistem yang paling mudah berubah diam-diam dan
paling mahal akibatnya. Model yang dilatih dengan satu susunan lalu dipakai
dengan susunan lain akan tetap mengeluarkan angka, tampak wajar, dan salah.
Karena itu `RelevanceInputBuilder` punya versi, versinya ikut tersimpan di
artefak training, dan perubahannya mewajibkan evaluasi ulang. Dokumen 10
bagian 20.

### 3.1 Sisi konteks

Isi `konteks_pantauan.deskripsi_model`, dikelola sebagai baris berversi di
`versi_konteks_relevansi`. Untuk model, kalimatnya pendek dan persis:

```text
Pemerintah Kota Kendari
```

Aturan inklusi dan eksklusi yang panjang tetap disimpan pada versi konteks,
tetapi tujuannya adalah panduan pelabel dan penjelasan ke admin, bukan teks
yang dikirim ke tokenizer. Sejak revisi 1.6 teks ini tidak di-embed lagi.

Perubahan definisi konteks mengubah status gerbang mutu menjadi `needs_review`
sampai model dievaluasi ulang. Model yang dilatih di bawah satu definisi tidak
otomatis berlaku di bawah definisi lain.

### 3.2 Sisi artikel, dihitung sekali per artikel

Susunannya:

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

Setiap prediksi menyimpan `input_hash`, `input_tokens`, dan `input_truncated`.
Yang ketiga bukan hiasan: artikel yang terpotong dinilai dari separuh isinya,
dan tanpa penanda itu, kesalahan yang sebenarnya berasal dari pemotongan akan
dibaca sebagai kesalahan model lalu "diperbaiki" dengan melatih ulang.

## 4. Sinyal metadata WordPress

27 dari 30 media partner memakai WordPress dan sudah dipanen lewat
`/wp-json/` pada `crawl:backfill`.

**Kategori dan tag belum benar-benar ditarik.** Kolom `kategori_sumber` dan
`tag_sumber` masih tercantum di rencana sprint 6 fase 2 dan belum dibuat, jadi
seluruh bagian ini adalah rancangan, bukan keadaan sistem sekarang. Ditulis
apa adanya di sini karena dokumen yang menyatakan sesuatu sudah jalan padahal
belum akan menyesatkan orang yang membangun di atasnya, dan itu sudah sempat
terjadi: komponen prioritas berbasis tag ditulis lebih dulu lalu harus dilepas
setelah kolomnya ternyata tidak ada.

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

## 5. Ambang dan sinyal kata kunci

### 5.1 Ambang berversi, bukan di .env

Keluaran classifier adalah probabilitas softmax, jadi angka 0,82 memang berarti
model memberi bobot 0,82 pada kelas relevan. Itu tetap tidak membuatnya boleh
ditebak: probabilitas yang tidak terkalibrasi bisa menumpuk di 0,95 tanpa
akurasi yang sepadan. Ambang dipilih dari validation set, tidak pernah dari
test set.

| Rentang probabilitas relevan | Keputusan |
|--------------|-----------|
| Di atas `relevant_threshold` | Relevan, lanjut ke sentimen bila gerbang lulus |
| Di dalam pita `review_lower_bound` sampai `review_upper_bound` | Perlu review, masuk antrean admin |
| Sisanya | Tidak relevan, keluar dari alur |

Cara memilihnya:

1. Hitung probabilitas untuk seluruh baris validation set.
2. Pilih ambang relevan pada titik precision kelas relevan mencapai 0,85.
3. Pasang pita review di sekitar ambang itu, lebarnya ditentukan berapa banyak
   artikel yang sanggup ditinjau admin per hari.
4. Ukur berapa persen artikel jatuh di dalam pita. Gerbang mutu menuntut di
   bawah 15%. Pita yang lebih lebar memang lebih aman, tetapi antrean yang
   tidak pernah habis sama saja dengan tidak ada peninjauan.
5. Baru setelah itu jalankan sekali di test set beku, dan laporkan angka itu.

**Nilai ini disimpan sebagai baris `versi_threshold_relevansi`, bukan di `.env`.**
Perubahan versi 1.6, dan alasannya bukan kerapian. Ambang di `.env` tidak punya
alasan, pemilik, dan tanggal, sehingga tidak ada cara menjawab mengapa angkanya
0,62 dan siapa yang menurunkannya. Ambang juga berpasangan dengan model:
mempromosikan model baru tanpa mengganti ambangnya adalah salah satu cara
tercepat merusak produksi. `.env` tinggal menjadi nilai bootstrap darurat.

Model produksi selalu menunjuk pasangan versi model dan versi ambang sekaligus,
dan rollback mengembalikan keduanya. Dokumen 10 bagian 15.2 dan 14.5.

### 5.2 Kata kunci turun menjadi sinyal

Sampai revisi 1.5, kata kunci konteks bertugas sebagai pengetat: artikel baru
dinyatakan relevan kalau kata kuncinya muncul di judul atau minimal tiga kali
di isi. Pengetat itu **dilepas** pada revisi 1.6, dan tugasnya diserahkan ke
model.

Alasannya bukan bahwa aturannya buruk. Justru sebaliknya, pengukuran sprint 6
menunjukkan aturan kata kunci sendirian mencapai presisi 56,0% sementara model
lama di atasnya hanya menambah satu poin. Masalahnya, aturan itu menutupi
kesalahan model dari pengukuran. Selama pengetat memotong sebagian keluaran,
tidak ada cara tahu apakah model membaik, karena angka akhir yang terlihat
selalu campuran keduanya. Model yang dilatih untuk mengerjakan tugas ini harus
dinilai atas tugas itu sendiri.

Sinyalnya tetap dihitung dengan bobot yang sama seperti bagian 4, dan tetap
disimpan di `sinyal_relevansi`, untuk dua hal.

Pertama, menjelaskan keputusan ke admin di halaman detail artikel. Kedua,
mengurutkan antrean pelabelan. Yang kedua justru paling berharga sekarang:
selama belum ada model produksi, sinyal inilah satu-satunya yang menentukan
artikel mana yang paling layak dilabeli lebih dulu, dan bertentangan antara
sinyal dan prediksi adalah salah satu komponen `priority_score` di dokumen 10
bagian 8.

`RELEVANSI_MINIMAL_SEBUTAN` dipensiunkan bersama `RELEVANSI_AMBANG_ATAS` dan
`RELEVANSI_AMBANG_BAWAH`.

## 6. Gold set

> **Versi 1.6:** untuk relevansi, bagian ini digantikan pipeline dataset di
> dokumen 10 bagian 7 sampai 9. Aturannya tidak berubah, tempatnya yang
> berubah: `sampel_relevansi` menggantikan `gold_set`, dan pembagian data
> menjadi snapshot terkunci dengan manifest hash. Yang di bawah tetap berlaku
> untuk sentimen dan tetap menjadi ringkasan prinsipnya.

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
| Presisi relevansi | minimal 0,85 |
| Recall relevansi | minimal 0,85 |
| F1 relevansi | minimal 0,85 |
| Macro F1 relevansi | minimal 0,85 |
| Artikel `perlu_review` | di bawah 15% |
| Koreksi manual yang tertimpa analisis ulang | 0 kasus |

Angka naik dari 80% pada revisi 1.6, dan ini bukan optimisme. Selama relevansi
berupa ambang atas skor cosine, 80% adalah batas yang realistis dari alat yang
dipakai. Begitu modelnya dilatih dengan label sendiri, standar yang sama
menjadi terlalu longgar: satu dari lima artikel yang salah masuk tetap terlihat
pimpinan. Daftar syarat lengkapnya, termasuk yang bukan metrik, ada di dokumen
10 bagian 12.3 dan 12.4, dan **gerbang itu memblokir sentimen**, bukan sekadar
memberi peringatan.

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
7. **Sentimen tidak pernah berjalan di atas relevansi yang belum terbukti.**
   Selama gerbang mutu belum `passed`, tidak ada artikel yang masuk antrean
   sentimen, dan penjaganya ada di dua tempat: dispatcher dan job. Satu
   penjaga akan terlewat suatu hari, dan hari itu tidak akan ada yang sadar.

## Changelog

| Versi | Tanggal | Perubahan |
|-------|---------|-----------|
| 1.1 | Agustus 2026 | Relevansi menjadi classifier hasil fine-tuning, mengikuti dokumen 10. **(a)** Bagian 2: tiga model, cabang "belum ada model produksi", sentimen diblokir. **(b)** Bagian 2.2: dua vektor menjadi satu, e5 tinggal untuk deteksi salinan. **(c)** Bagian 2.3: fine-tuning naik dari daftar versi 2 menjadi jalur utama, beserta syarat jumlah data. **(d)** Bagian 3: input menjadi pasangan konteks dan artikel, `RelevanceInputBuilder` berversi. **(e)** Bagian 5: ambang menjadi baris berversi di database, pengetat kata kunci dilepas dan turun menjadi sinyal prioritas. **(f)** Bagian 7.1: gerbang naik dari 0,80 ke 0,85. **(g)** Aturan ketujuh pada bagian 9 |
| 1.0 | Agustus 2026 | Dokumen dibuat, menutup rujukan menggantung dari dokumen 00, 02, 07, dan 08. Isinya mengikuti keputusan konteks tunggal pada usulan revisi 1.3 |
