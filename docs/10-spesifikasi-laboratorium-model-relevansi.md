# 10: Spesifikasi Laboratorium Model Relevansi

**SIMAK Kendari | Versi 1.0**  
**Tanggal:** 4 Agustus 2026  
**Status:** Spesifikasi implementasi  
**Ruang lingkup:** Pengumpulan dataset, pelabelan relevansi, fine-tuning IndoBERT, evaluasi, pengujian manual, versioning model, promosi model, rollback, active learning, dan gerbang mutu sebelum analisis sentimen.

---

## 0. Hubungan dengan Paket Spesifikasi

Dokumen ini menggantikan keputusan relevansi pada revisi 1.5 paket spesifikasi.
Bagian ini mencatat apa yang berubah, karena isi dokumen berikutnya ditulis
seolah tidak ada penilai relevansi sebelumnya, padahal ada dan sedang jalan di
produksi.

### 0.1 Yang digantikan

Revisi 1.5 memindahkan penilaian relevansi ke cosine similarity antara vektor
artikel dan vektor deskripsi konteks, keduanya dari `intfloat/multilingual-e5-small`.
Pengukurannya berhenti di presisi 69,9%, di bawah target 80% yang ditetapkan
dokumen 05 bagian 7.1, apalagi di bawah 0,85 yang dituntut gerbang mutu di
bagian 12 dokumen ini.

Kesimpulan pengukuran itu tetap berlaku dan tidak dibantah di sini: yang
menaikkan presisi ke angka layak adalah pekerjaan dataset, bukan pergantian
model. Dokumen ini menerima kesimpulan tersebut lalu membangun alat untuk
mengerjakannya. Fine-tuning hanyalah muara dari pipeline dataset, bukan
harapan bahwa model yang berbeda akan menyelesaikan masalah label.

Perbedaannya dengan percobaan lama perlu ditegaskan. Yang dulu diukur adalah
`apriandito/indobert-relevancy-classifier` **apa adanya**, tanpa pelatihan
ulang, dan hasilnya hanya satu poin di atas aturan kata kunci. Yang dibangun
di sini adalah checkpoint yang sama setelah **dilatih ulang dengan dataset
lokal Kendari**. Keduanya bukan hal yang sama, dan kegagalan yang pertama
bukan bukti tentang yang kedua.

### 0.2 Nasib e5-small

`multilingual-e5-small` **tetap dipakai, khusus untuk deteksi salinan.** Ia
tidak lagi menentukan relevansi.

| Pemakaian | Status |
|---|---|
| `artikel.embedding`, deduplikasi lapis 3 | tetap, tidak berubah |
| `artikel.embedding_relevansi` | dipensiunkan |
| `konteks_pantauan.embedding` | dipensiunkan |
| Ambang `RELEVANSI_AMBANG_ATAS` dan `RELEVANSI_AMBANG_BAWAH` | dipensiunkan, digantikan `versi_threshold_relevansi` |

Dua kolom vektor yang dipensiunkan dihapus pada fase 1 bagian 27. Sampai
migration itu jalan, kolomnya dibiarkan terisi dan tidak dibaca siapa pun.

`konteks_pantauan.deskripsi_model` **tidak** dipensiunkan. Isinya berpindah
peran, dari teks yang di-embed menjadi teks konteks yang dipasangkan ke artikel
pada tokenizer, sesuai bagian 20.

`JendelaKonteks` juga tetap. Ia dibangun untuk memilih potongan isi yang
menyinggung Pemkot dari artikel panjang, dan kebutuhan itu identik untuk
classifier yang dibatasi 512 token. Yang berubah hanya tujuan keluarannya:
dulu ke `/embed`, sekarang ke `RelevanceInputBuilder`.

`PenyaringKataKunci` berhenti menjadi pengetat keputusan. Sinyalnya tetap
dihitung dan disimpan, tetapi sekarang hanya untuk dua hal: menjelaskan
keputusan ke admin, dan memberi skor prioritas antrean pelabelan pada bagian 8.
Selama belum ada model produksi, sinyal inilah satu-satunya yang mengurutkan
antrean.

### 0.3 Sentimen diblokir mulai sekarang

Diputuskan 4 Agustus 2026. Aturan bagian 1.1 diberlakukan segera, bukan setelah
laboratorium selesai dibangun.

Akibat langsungnya:

- `AnalisisSentimen` berhenti didispatch untuk artikel baru;
- dashboard eksekutif menampilkan keadaan "sentimen belum tersedia";
- alert lonjakan negatif berhenti;
- 4.806 artikel beserta seluruh hasil analisis lamanya tetap tersimpan utuh.

Konsekuensi yang harus diterima dengan sadar: dashboard sentimen kosong selama
dataset dikumpulkan dan model dilatih, dan itu bukan hitungan hari. Alternatifnya
adalah menampilkan sentimen atas artikel yang tiga dari sepuluh di antaranya
tidak membahas Pemkot, dan itu kesalahan yang tidak perlu dibaca angkanya untuk
disadari pimpinan, cukup dibaca judulnya.

Bagian sistem yang tidak bergantung pada model tetap hidup: volume, sumber,
kontrak, entitas, kata kunci, portal media, dan crawler.

### 0.4 Dokumen lain yang menyesuaikan

| Dokumen | Yang berubah |
|---|---|
| 00 | Changelog 1.6, tabel keputusan terkunci, daftar dokumen |
| 02 | Layanan NLP memuat tiga model lagi, endpoint `/relevancy/*` kembali, rantai job |
| 03 | Sebelas tabel laboratorium, kolom vektor relevansi dipensiunkan |
| 04 | Halaman `/admin/model-relevansi` dan delapan tabnya |
| 05 | Bagian 2, 3, 5, dan 7 ditulis ulang mengikuti dokumen ini |
| 06 | Izin laboratorium khusus superadmin, daftar aksi yang diaudit |
| 07 dan 08 | Sprint 8 |
| 09 | Kode alasan label diselaraskan dengan bagian 7.5 dan diberi versi |

---

## 1. Instruksi Utama untuk Implementasi

Bangun modul admin bernama **Laboratorium Model Relevansi** untuk melatih, menguji, mengevaluasi, dan mengelola model relevansi artikel SIMAK Kendari.

Modul ini hanya menjawab satu pertanyaan:

> **Apakah artikel secara substantif berhubungan dengan Pemerintah Kota Kendari?**

Gunakan satu konteks utama:

```text
Pemerintah Kota Kendari
```

Gunakan model awal:

```text
apriandito/indobert-relevancy-classifier
```

Model tersebut menjadi checkpoint awal. Versi berikutnya dilatih ulang menggunakan dataset lokal SIMAK Kendari agar memahami perbedaan antara:

- Pemerintah Kota Kendari dan Pemerintah Provinsi Sulawesi Tenggara;
- Pemkot dan instansi vertikal;
- Pemkot dan kepolisian/TNI;
- Pemkot dan DPRD Kota Kendari;
- Kendari sebagai objek pemberitaan dan Kendari yang hanya menjadi lokasi;
- penyebutan substantif dan penyebutan sepintas;
- OPD, pejabat, program, pelayanan, serta kewenangan Pemerintah Kota Kendari.

### 1.1 Aturan mutlak

> [!IMPORTANT]
> **JANGAN LANJUTKAN ARTIKEL KE ANALISIS SENTIMEN APABILA HASIL RELEVANSI BELUM DINYATAKAN LAYAK.**

Aturan ini berlaku pada dua tingkat.

#### Tingkat model

Analisis sentimen global harus berstatus **diblokir** apabila belum ada model relevansi produksi yang melewati seluruh gerbang mutu.

```text
Tidak ada model relevansi yang lolos quality gate
                         ↓
             modul sentimen diblokir
                         ↓
 artikel tetap dikumpulkan dan dilabeli untuk relevansi
```

#### Tingkat artikel

Satu artikel hanya boleh masuk antrean sentimen apabila:

1. model relevansi yang digunakan berstatus `production`;
2. model tersebut telah melewati quality gate;
3. label final artikel adalah `relevan`;
4. artikel tidak berstatus `perlu_review`;
5. tidak ada konflik antara label manual dan prediksi model;
6. label manual, bila tersedia, selalu menjadi keputusan final.

Artikel dengan status berikut **dilarang** masuk ke sentimen:

```text
belum_dianalisis
perlu_review
tidak_relevan
model_belum_lulus_gate
prediksi_kadaluarsa
konflik_label
```

### 1.2 Prioritas pengerjaan

Tidak ada batas waktu implementasi. Utamakan:

1. kebenaran alur data;
2. kualitas dan konsistensi dataset;
3. reproduktibilitas eksperimen;
4. evaluasi yang dapat dipercaya;
5. keamanan promosi model;
6. audit trail lengkap;
7. kemudahan penggunaan admin;
8. baru setelah itu optimasi performa dan tampilan.

Jangan mengaktifkan sentimen hanya karena fitur teknisnya sudah selesai. Sentimen baru boleh diaktifkan setelah relevansi memenuhi kriteria penerimaan dokumen ini.

---

## 2. Keputusan Produk

### 2.1 Satu keputusan relevansi per artikel

Setiap artikel mempunyai satu label final:

```text
relevan
tidak_relevan
```

Status operasional tambahan:

```text
belum_dilabeli
perlu_review
dikeluarkan
terkunci_test
```

`perlu_review` bukan label training. Semua sampel yang masuk snapshot dataset harus memiliki keputusan manusia final `relevan` atau `tidak_relevan`.

### 2.2 Definisi relevan

Artikel dinyatakan **relevan** apabila secara substantif membahas Pemerintah Kota Kendari sebagai institusi atau unsur yang berada dalam struktur, kewenangan, program, tanggung jawab, atau representasinya.

Cakupannya meliputi:

- Pemerintah Kota Kendari;
- Wali Kota dan Wakil Wali Kota dalam kapasitas jabatan;
- Sekretaris Daerah dan aparatur Pemkot;
- dinas, badan, bagian, kecamatan, kelurahan, UPTD, dan BLUD Pemkot;
- kebijakan, program, kegiatan, anggaran, pengadaan, pembangunan, pelayanan, perizinan, kerja sama, prestasi, kritik, keluhan, masalah, dan tanggapan resmi;
- artikel yang tidak menyebut Pemkot pada judul tetapi isi utamanya membahas kewenangan atau tindakan Pemkot;
- berita DPRD yang secara langsung membahas APBD, perda, pengawasan, kritik, rekomendasi, atau hubungan dengan eksekutif Pemkot.

### 2.3 Definisi tidak relevan

Artikel dinyatakan **tidak relevan** apabila:

- Kendari hanya menjadi lokasi kejadian;
- fokus artikel adalah Pemprov Sultra tanpa keterlibatan substantif Pemkot;
- fokus artikel adalah kementerian, kantor wilayah, Polri, TNI, kejaksaan, pengadilan, Bea Cukai, BPS, kampus, BUMN, perusahaan, organisasi, atau komunitas;
- membahas kriminalitas, kecelakaan, olahraga, hiburan, bisnis, atau acara umum tanpa kaitan substantif dengan Pemkot;
- Pemkot hanya muncul pada daftar tamu, ucapan, alamat, atau satu penyebutan singkat;
- membahas pemerintah kabupaten lain;
- membahas kegiatan internal DPRD yang tidak berhubungan dengan eksekutif Pemkot.

### 2.4 Pemisahan dari sentimen

Laboratorium Model Relevansi tidak mengelola label positif, netral, atau negatif.

```text
Relevansi  → menentukan artikel boleh masuk
Sentimen   → mengukur nada artikel yang sudah lolos
Topik      → menjelaskan bidang pembahasan
Entitas    → menjelaskan pihak yang disebut
```

Halaman ini dilarang mencampurkan statistik sentimen ke statistik relevansi.

---

## 3. Stack dan Batas Arsitektur

Gunakan stack proyek yang sudah ditetapkan:

| Komponen | Teknologi |
|---|---|
| Backend | Laravel 13 |
| Frontend | Inertia 3 + Vue 3 Composition API + TypeScript |
| UI | Tailwind CSS 4 + shadcn-vue |
| Database | PostgreSQL 16 |
| Queue | Laravel Queue + Redis |
| Monitoring queue | Laravel Horizon |
| NLP | FastAPI + Transformers + PyTorch CPU |
| Model awal | `apriandito/indobert-relevancy-classifier` |
| Penyimpanan model | direktori privat server, bukan `public/` |

Aturan arsitektur:

1. Laravel adalah satu-satunya komponen yang menulis ke PostgreSQL.
2. FastAPI menerima data, menjalankan training/inferensi/evaluasi, lalu mengembalikan hasil.
3. FastAPI tidak boleh langsung mengubah tabel aplikasi.
4. Semua pemanggilan NLP dari Laravel hanya melalui `App\Services\Nlp\NlpClient`.
5. Training tidak boleh berjalan di request HTTP biasa.
6. Training, evaluasi besar, prediksi ulang, dan promosi model berjalan melalui queue/job.
7. Model aktif harus dimuat sekali dan digunakan ulang, bukan dimuat untuk setiap artikel.
8. Seluruh timestamp disimpan UTC dan ditampilkan dalam `Asia/Makassar`.
9. Nama tabel dan kolom database memakai Bahasa Indonesia.
10. Nama class, method, dan variabel PHP/TypeScript memakai Bahasa Inggris.

---

## 4. Navigasi dan Route

Tambahkan menu admin:

```text
AI & Model
└── Laboratorium Relevansi
```

Route utama:

```text
GET /admin/model-relevansi
```

Gunakan tab berbasis query string agar halaman dapat dibuka langsung:

```text
/admin/model-relevansi?tab=ringkasan
/admin/model-relevansi?tab=dataset
/admin/model-relevansi?tab=snapshot
/admin/model-relevansi?tab=pelatihan
/admin/model-relevansi?tab=evaluasi
/admin/model-relevansi?tab=uji-model
/admin/model-relevansi?tab=versi-model
/admin/model-relevansi?tab=pengaturan
```

Seluruh filter, sort, pagination, dan tab harus berada pada URL.

---

## 5. Struktur Halaman

Laboratorium mempunyai delapan tab:

1. **Ringkasan**
2. **Dataset**
3. **Snapshot Dataset**
4. **Pelatihan**
5. **Evaluasi**
6. **Uji Model**
7. **Versi Model**
8. **Pengaturan**

Header halaman selalu menampilkan:

```text
Laboratorium Model Relevansi
Melatih dan memastikan artikel yang masuk benar-benar berkaitan dengan Pemerintah Kota Kendari.
```

Tambahkan badge status gerbang mutu:

```text
GATE RELEVANSI: DIBLOKIR
GATE RELEVANSI: PERLU REVIEW
GATE RELEVANSI: LULUS
```

Saat gate belum lulus, tampilkan banner merah yang tidak dapat ditutup:

> **Analisis sentimen diblokir. Model relevansi belum memenuhi standar produksi. Selesaikan pelabelan, pelatihan, dan evaluasi relevansi terlebih dahulu.**

---

## 6. Tab Ringkasan

### 6.1 Kartu dataset

Tampilkan:

- total kandidat hasil crawler;
- belum dilabeli;
- sudah dilabeli;
- relevan;
- tidak relevan;
- perlu review;
- dikeluarkan;
- anggota train;
- anggota validation;
- anggota test terkunci;
- jumlah hard positive;
- jumlah hard negative;
- jumlah kelompok duplikat.

### 6.2 Keseimbangan kelas

Tampilkan komposisi:

```text
Relevan          49,2%
Tidak relevan    50,8%
```

Status distribusi:

```text
seimbang
perlu_perhatian
timpang
```

Peringatan muncul apabila salah satu kelas kurang dari 35% pada dataset train.

### 6.3 Status model produksi

Tampilkan:

- nama versi model;
- base model;
- snapshot dataset;
- versi konteks;
- versi threshold;
- tanggal training;
- tanggal aktivasi;
- precision relevan;
- recall relevan;
- F1 relevan;
- macro F1;
- false positive;
- false negative;
- persentase `perlu_review`;
- waktu inferensi rata-rata;
- status quality gate.

### 6.4 Ringkasan antrean kerja

Tampilkan daftar pekerjaan yang paling penting:

```text
128 artikel belum dilabeli
34 konflik prediksi dan label manusia
19 artikel confidence dekat threshold
12 sumber belum terwakili pada test set
8 kelompok duplikat belum dirapikan
1 model kandidat menunggu persetujuan
```

Masing-masing menjadi tautan ke filter dataset yang sesuai.

### 6.5 Grafik

Gunakan grafik sederhana dan terpisah:

- pertumbuhan jumlah dataset per minggu;
- distribusi label;
- precision/recall/F1 per versi model;
- false positive dan false negative per sumber;
- proporsi artikel yang masuk `perlu_review` dari waktu ke waktu.

Jangan menampilkan grafik sentimen pada modul ini.

---

## 7. Tab Dataset

### 7.1 Sumber dataset

Dataset berasal dari:

1. artikel hasil crawler otomatis;
2. artikel yang diuji melalui URL lalu disimpan;
3. teks manual yang disimpan sebagai sampel;
4. false positive produksi;
5. false negative produksi;
6. konflik antara model produksi dan model kandidat;
7. data impor CSV/JSON yang tervalidasi;
8. migrasi gold set lama.

Semua hasil crawler dicatat sebagai kandidat, tetapi tidak otomatis menjadi data training.

### 7.2 Tabel dataset

Kolom:

| Kolom | Isi |
|---|---|
| Pilih | Checkbox batch action |
| Artikel | Judul, excerpt singkat, tautan sumber |
| Media | Nama media |
| Tanggal | Tanggal publikasi |
| Tag/kategori | Metadata sumber |
| Prediksi aktif | Label dan confidence model produksi |
| Label manusia | Relevan/tidak relevan/belum |
| Keselarasan | Benar/salah/belum dapat dinilai |
| Kesulitan | normal/hard_positive/hard_negative |
| Split | belum/train/validation/test |
| Duplikat | induk/salinan/group ID |
| Versi model | Model terakhir yang memprediksi |
| Pelabel | Pengguna terakhir |
| Diubah | Waktu perubahan |
| Aksi | Buka, labeli, edit, keluarkan |

### 7.3 Filter wajib

- pencarian judul/isi;
- status label;
- label manusia;
- prediksi model;
- prediksi salah;
- false positive;
- false negative;
- confidence minimum dan maksimum;
- dekat threshold;
- media;
- tanggal publikasi;
- kategori;
- tag;
- split;
- tingkat kesulitan;
- duplicate group;
- versi model;
- sumber dataset;
- pelabel;
- sudah/tidak pernah direview ulang.

Filter cepat:

```text
Belum dilabeli
Prediksi salah
False positive
False negative
Confidence rendah
Konflik antar-model
Hard negative
Hard positive
Test terkunci
```

### 7.4 Mode pelabelan cepat

Buka artikel dalam drawer atau halaman fokus.

Tampilkan:

- judul;
- media dan tanggal;
- canonical URL;
- excerpt;
- tag dan kategori;
- isi artikel;
- context window yang dikirim ke model;
- entitas/alias yang ditemukan;
- prediksi model;
- confidence;
- probabilitas kedua kelas;
- versi model;
- threshold saat prediksi;
- label manusia sebelumnya;
- riwayat koreksi.

Aksi utama:

```text
[ Relevan ] [ Tidak Relevan ] [ Lewati ]
```

Shortcut:

```text
R = Relevan
T = Tidak relevan
S = Lewati
E = Edit alasan
```

Setelah penyimpanan berhasil, buka sampel berikutnya tanpa reload penuh.

### 7.5 Alasan label

Alasan tidak relevan:

```text
lokasi_saja
pemprov_sultra
instansi_vertikal
polri_tni
kampus
perusahaan_organisasi
kriminalitas_umum
olahraga_hiburan
pemerintah_daerah_lain
pemkot_disebut_sepintas
tidak_ada_kewenangan_pemkot
lainnya
```

Alasan relevan:

```text
institusi_pemkot
wali_kota_wakil_wali_kota
opd_unit_kerja
kebijakan_program
pelayanan_publik
anggaran_pengadaan
pembangunan_infrastruktur
kritik_keluhan
respons_tindak_lanjut
hubungan_dprd_pemkot
lainnya
```

Alasan wajib ketika:

- admin mengubah label lama;
- label manusia berbeda dari prediksi confidence tinggi;
- sampel berada pada test set;
- sampel dikeluarkan dari dataset;
- sampel ditandai hard case.

### 7.6 Batch action

Sediakan:

- tandai relevan;
- tandai tidak relevan;
- masukkan antrean review;
- tandai hard positive;
- tandai hard negative;
- keluarkan dari dataset;
- gabungkan duplicate group;
- tetapkan sumber dataset;
- ekspor hasil filter.

Batch label harus meminta konfirmasi dan tidak boleh diterapkan pada test set terkunci tanpa alasan.

### 7.7 Autosave dan audit

- Label disimpan segera setelah dipilih.
- Tampilkan status `menyimpan`, `tersimpan`, atau `gagal`.
- Kesalahan tidak boleh menghilangkan input pengguna.
- Simpan nilai sebelum dan sesudah, pengguna, waktu, dan alasan.
- Label manual tidak boleh ditimpa job prediksi ulang.

---

## 8. Active Learning dan Prioritas Pelabelan

Sistem membuat antrean prioritas otomatis berdasarkan skor.

Prioritaskan artikel yang:

1. confidence paling dekat threshold;
2. diprediksi berbeda oleh model produksi dan kandidat;
3. sebelumnya salah diprediksi;
4. tag menyebut Pemkot tetapi model memilih tidak relevan;
5. tidak memiliki alias Pemkot tetapi model memilih relevan;
6. berasal dari media yang performanya rendah;
7. berasal dari sumber baru;
8. tidak terwakili pada train/validation/test;
9. termasuk pola Pemprov, Polri/TNI, instansi vertikal, dan lokasi saja;
10. merupakan hard positive tanpa kata “Pemkot” pada judul;
11. mengalami perubahan prediksi setelah upgrade model;
12. berada pada kelompok duplikat dengan label yang tidak konsisten.

Simpan `priority_score` dan komponen pembentuknya agar antrean dapat dijelaskan.

Contoh:

```json
{
  "near_threshold": 30,
  "model_disagreement": 25,
  "underrepresented_source": 15,
  "hard_case_pattern": 20,
  "new_source": 10,
  "total": 100
}
```

---

## 9. Tab Snapshot Dataset

### 9.1 Tujuan snapshot

Snapshot adalah susunan dataset yang dibekukan untuk satu eksperimen. Label baru setelah snapshot dibuat tidak boleh diam-diam mengubah eksperimen lama.

### 9.2 Form pembuatan snapshot

Field:

```text
Nama snapshot
Deskripsi
Versi panduan pelabelan
Jumlah relevan
Jumlah tidak relevan
Persentase train
Persentase validation
Persentase test
Random seed
Strategi sampling
Tanggal batas artikel
Sertakan hard cases
Sertakan media baru
```

Strategi sampling:

```text
balanced
natural_distribution
balanced_with_hard_cases
custom
```

Default rekomendasi:

```text
Train       80%
Validation  10%
Test        10%
Seed        42
```

### 9.3 Kesiapan data

Tampilkan tiga tingkat kesiapan:

```text
belum_layak
layak_eksperimen
layak_produksi
```

Rekomendasi target:

| Tingkat | Artikel unik berlabel | Minimal per kelas | Test terkunci |
|---|---:|---:|---:|
| Eksperimen | 500 | 200 | 100 |
| Fine-tuning awal | 1.500 | 600 | 300 |
| Kandidat produksi | 3.000 | 1.200 | 500 |
| Sangat matang | 5.000+ | 2.000 | 750+ |

Jumlah bukan satu-satunya syarat. Snapshot tetap ditolak bila data hanya terdiri dari contoh mudah atau terlalu banyak salinan.

### 9.4 Aturan pembagian

1. Semua anggota satu `duplicate_group_id` harus masuk split yang sama.
2. Rilis pers yang sama tidak boleh tersebar antara train dan test.
3. Test set tidak boleh dipakai memilih threshold.
4. Test set tidak boleh ikut training.
5. Validation digunakan memilih checkpoint dan threshold.
6. Pertimbangkan split waktu agar test berisi artikel yang lebih baru.
7. Simpan random seed dan hash daftar sampel.
8. Split tidak boleh berubah setelah snapshot dikunci.

### 9.5 Pemeriksaan kebocoran

Sebelum snapshot dikunci, periksa:

- URL sama;
- canonical URL sama;
- content hash sama;
- SimHash dekat;
- duplicate group sama;
- judul dan isi hampir identik;
- rilis pers yang sama;
- artikel hasil salin media lain.

Jika kebocoran ditemukan, tombol kunci snapshot dinonaktifkan.

### 9.6 Test set terkunci

Perubahan label pada test set:

- hanya superadmin;
- wajib alasan;
- membuat versi snapshot baru;
- tidak mengubah hasil evaluasi historis;
- tercatat di audit log.

---

## 10. Tab Pelatihan

### 10.1 Base model

Pilihan default:

```text
apriandito/indobert-relevancy-classifier
```

Field tetap tersedia agar eksperimen mendatang dapat membandingkan checkpoint lain, tetapi produksi awal harus memakai model tersebut sampai ada bukti evaluasi lokal bahwa model lain lebih baik.

### 10.2 Form eksperimen

Field utama:

```text
Nama eksperimen
Base model
Snapshot dataset
Deskripsi tujuan
Versi konteks
Versi panduan pelabelan
```

Konfigurasi training:

```text
Epoch
Batch size
Learning rate
Weight decay
Warmup ratio
Maximum sequence length
Gradient accumulation
Class weighting
Early stopping patience
Random seed
Metric checkpoint utama
```

Default awal yang dapat diuji:

```text
Epoch                   3
Batch size              8
Learning rate           1e-5
Weight decay            0.01
Warmup ratio            0.10
Maximum sequence length 512
Gradient accumulation   2
Class weighting         aktif
Early stopping patience 2
Random seed             42
Metric utama            F1 relevan
```

Nilai di atas adalah preset awal, bukan hardcode permanen.

### 10.3 Preset

Sediakan preset:

```text
Aman
Seimbang
Eksperimen
Kustom
```

Preset hanya mengisi form. Semua nilai final tetap disimpan pada `training_run`.

### 10.4 Pemeriksaan sebelum training

Training hanya dapat dimulai bila:

- snapshot terkunci;
- dua kelas tersedia;
- tidak ada data tanpa label;
- tidak ada data duplikat lintas split;
- validation tersedia;
- test tersedia;
- test set terkunci;
- konteks dan panduan pelabelan mempunyai versi;
- disk cukup;
- layanan training sehat;
- tidak ada training lain yang memakai resource eksklusif;
- nama versi belum dipakai;
- hash snapshot valid.

### 10.5 Alur training

```text
Admin membuat training run
            ↓
Laravel memvalidasi snapshot
            ↓
Job mengekspor dataset terkontrol
            ↓
FastAPI memulai training
            ↓
Checkpoint terbaik dipilih dari validation
            ↓
Model dievaluasi pada test terkunci
            ↓
Artefak dan metrik dikembalikan
            ↓
Laravel menyimpan versi sebagai candidate
```

### 10.6 Status training

```text
menunggu
validasi_data
mengekspor_dataset
mempersiapkan_model
melatih
validasi_epoch
memilih_checkpoint
mengevaluasi_test
menyimpan_artefak
selesai
gagal
dibatalkan
```

### 10.7 Progress

Tampilkan:

- status;
- epoch saat ini;
- batch saat ini;
- persentase;
- elapsed time;
- perkiraan selesai;
- training loss;
- validation loss;
- precision relevan validation;
- recall relevan validation;
- F1 relevan validation;
- learning rate;
- penggunaan RAM;
- alasan berhenti awal.

### 10.8 Artefak training

Simpan:

```text
model weights
tokenizer
config.json
label mapping
training arguments
metrics.json
confusion_matrix.json
classification_report.json
snapshot manifest
context definition
labeling guideline version
requirements lock
runtime information
log training
```

Hitung checksum setiap artefak.

### 10.9 Kegagalan

Training gagal harus menyimpan:

- tahap terakhir;
- pesan ringkas untuk admin;
- traceback teknis;
- waktu gagal;
- penggunaan resource terakhir;
- apakah aman untuk retry;
- konfigurasi eksperimen;
- snapshot yang dipakai.

Retry membuat run baru yang terhubung ke run sebelumnya, bukan menghapus riwayat.

---

## 11. Tab Evaluasi

### 11.1 Metrik wajib

Tampilkan:

- precision kelas relevan;
- recall kelas relevan;
- F1 kelas relevan;
- precision kelas tidak relevan;
- recall kelas tidak relevan;
- F1 kelas tidak relevan;
- macro F1;
- weighted F1;
- accuracy;
- false positive;
- false negative;
- true positive;
- true negative;
- persentase perlu review;
- waktu inferensi rata-rata;
- p50, p95, dan p99 latency;
- jumlah sampel test.

Accuracy tidak boleh menjadi metrik utama.

### 11.2 Confusion matrix

Tampilkan tabel dan penjelasan manusia:

```text
False positive:
Artikel tidak relevan yang salah dimasukkan sebagai relevan.

False negative:
Artikel relevan yang gagal ditangkap model.
```

Setiap angka dapat diklik untuk membuka daftar artikelnya.

### 11.3 Evaluasi per kelompok

Hitung metrik per:

- media;
- tipe sumber;
- bulan publikasi;
- kategori WordPress;
- pola alasan label;
- artikel hard case;
- artikel DPRD;
- artikel Pemprov;
- artikel Polri/TNI;
- artikel instansi vertikal;
- artikel lokasi saja;
- artikel tanpa kata Pemkot pada judul;
- artikel dengan OPD;
- panjang teks;
- rentang confidence.

### 11.4 Analisis kesalahan

Sediakan tab kecil:

```text
False Positive
False Negative
Confidence Tinggi tetapi Salah
Model Berbeda Pendapat
Regresi dari Versi Lama
```

Tampilkan ringkasan pola:

```text
31% false positive berasal dari Pemprov Sultra
24% berasal dari Polri/TNI
18% karena Kendari hanya lokasi
12% karena Pemkot disebut sepintas
```

Pola dihasilkan dari alasan label manusia, bukan penjelasan generatif yang tidak dapat diverifikasi.

### 11.5 Simulasi threshold

Sediakan slider dan input angka untuk threshold relevan.

Setiap perubahan menampilkan simulasi:

```text
Threshold 0,45 → Precision 86%, Recall 94%, F1 90%
Threshold 0,55 → Precision 90%, Recall 91%, F1 90,5%
Threshold 0,65 → Precision 94%, Recall 84%, F1 88,7%
```

Aturan:

1. threshold dipilih dari validation set;
2. test set hanya digunakan untuk laporan akhir;
3. perubahan simulasi tidak mengubah produksi;
4. penyimpanan threshold membuat versi baru;
5. model produksi menyimpan pasangan model version + threshold version.

### 11.6 Perbandingan versi

Pilih maksimal tiga versi model.

Bandingkan:

- seluruh metrik;
- confusion matrix;
- jumlah prediksi berubah;
- artikel yang membaik;
- artikel yang mengalami regresi;
- latency;
- ukuran model;
- kebutuhan RAM;
- threshold;
- snapshot dataset;
- base model.

### 11.7 Konsistensi pelabel

Sediakan fitur pelabelan ulang acak:

1. pilih 40 atau lebih sampel;
2. sembunyikan label lama;
3. admin melabeli ulang;
4. hitung persentase kesesuaian;
5. hitung Cohen’s kappa;
6. tampilkan kasus berbeda;
7. minta pembaruan panduan bila perlu.

Model tidak boleh dianggap stabil jika konsistensi label manusianya rendah.

---

## 12. Quality Gate Relevansi

### 12.1 Tujuan

Quality gate mencegah sentimen dijalankan di atas data yang salah.

Sentimen tidak boleh menjadi jalan pintas untuk menyembunyikan relevansi yang buruk.

### 12.2 Status gate

```text
blocked
needs_review
passed
revoked
```

### 12.3 Syarat minimum gate produksi

Default awal yang disarankan:

| Metrik | Syarat minimum |
|---|---:|
| Precision relevan | ≥ 0,85 |
| Recall relevan | ≥ 0,85 |
| F1 relevan | ≥ 0,85 |
| Macro F1 | ≥ 0,85 |
| Precision pada sumber utama | ≥ 0,80 |
| Recall pada sumber utama | ≥ 0,80 |
| Perlu review | ≤ 15% |
| False positive confidence tinggi | ≤ 3% dari test |
| Kebocoran duplikat lintas split | 0 |
| Label manual tertimpa model | 0 |
| Test set terkunci | wajib |
| Konsistensi pelabel | ≥ 0,85 |

Target yang diinginkan sebelum sistem dianggap matang:

```text
Precision relevan ≥ 0,90
Recall relevan    ≥ 0,90
F1 relevan        ≥ 0,90
Macro F1          ≥ 0,90
```

Semua nilai dibuat configurable, tetapi perubahan standar gate harus memiliki alasan, pengguna, waktu, dan audit log.

### 12.4 Syarat non-metrik

Gate hanya dapat lulus jika:

- model berstatus `candidate`;
- evaluasi memakai test snapshot terkunci;
- snapshot mempunyai jumlah dan keragaman yang cukup;
- tidak ada kebocoran duplikat;
- tidak ada error kritis pipeline;
- model dapat dimuat ulang setelah restart;
- hasil inferensi deterministik dalam toleransi yang ditentukan;
- rollback model tersedia;
- prediksi menyimpan versi model dan threshold;
- panduan pelabelan aktif mempunyai versi;
- admin telah meninjau false positive dan false negative utama;
- model kandidat tidak memiliki regresi kritis pada media utama.

### 12.5 Efek gate

Jika `blocked`, `needs_review`, atau `revoked`:

```text
AnalyzeSentiment job tidak boleh didispatch
Queue sentimen dipause
Tombol aktifkan sentimen dinonaktifkan
Dashboard sentimen menampilkan status belum tersedia
Alert sentimen tidak dijalankan
Agregasi sentimen tidak diperbarui
```

Jika `passed`:

```text
Artikel final relevan dapat masuk antrean sentimen
Artikel perlu_review tetap ditahan
Artikel tidak relevan tidak pernah masuk sentimen
```

### 12.6 Pencabutan gate otomatis

Gate berubah menjadi `revoked` bila:

- model produksi tidak dapat dimuat;
- checksum artefak berubah;
- threshold aktif hilang;
- error inferensi melewati ambang;
- monitoring drift menunjukkan penurunan besar;
- audit sampling produksi berada di bawah standar;
- model baru dipromosikan tetapi health check gagal;
- definisi konteks berubah tanpa evaluasi ulang.

Saat dicabut, sentimen langsung diblokir untuk artikel baru. Data lama tidak dihapus.

---

## 13. Tab Uji Model

### 13.1 Mode input

Sediakan tiga mode:

```text
URL Artikel
Teks Manual
Artikel dari Dataset
```

### 13.2 Uji URL

Alur:

1. admin menempel URL;
2. Laravel memvalidasi URL;
3. sistem mengambil halaman dengan aturan crawler;
4. ekstrak judul, excerpt, tag, kategori, dan isi;
5. tampilkan pratinjau;
6. admin menjalankan prediksi;
7. tampilkan hasil;
8. admin dapat menyimpan sebagai dataset.

Jika ekstraksi gagal, tampilkan form manual tanpa menghapus URL.

### 13.3 Uji teks

Field:

```text
Judul
Excerpt
Kategori
Tag
Isi artikel
```

### 13.4 Hasil pengujian

Tampilkan:

```text
Label: RELEVAN
Confidence: 92,4%
Probabilitas relevan: 92,4%
Probabilitas tidak relevan: 7,6%
Threshold aktif: 60%
Model: simak-relevancy-v1.3
Base model: apriandito/indobert-relevancy-classifier
Versi konteks: pemkot-kendari-v3
Input token: 486/512
Teks terpotong: ya/tidak
Waktu inferensi: 482 ms
Gate model: passed
```

Tampilkan juga:

- metadata yang dikirim ke model;
- context window;
- alias yang terdeteksi;
- peringatan bila teks terpotong;
- peringatan bila confidence dekat threshold.

### 13.5 Bandingkan model

Admin dapat memilih:

```text
Model produksi
Model kandidat
Versi arsip
```

Tampilkan hasil berdampingan.

### 13.6 Feedback

Aksi:

```text
[ Prediksi Benar ] [ Prediksi Salah ] [ Simpan ke Dataset ]
```

Jika salah, wajib memilih label benar dan alasan.

Sampel otomatis ditandai:

```text
hard_negative
```

atau:

```text
hard_positive
```

sesuai jenis kesalahan.

Prediksi pada halaman uji tidak otomatis mengubah artikel produksi.

---

## 14. Tab Versi Model

### 14.1 Status versi

```text
draft
training
candidate
production
archived
failed
rejected
```

Hanya satu model relevansi boleh berstatus `production`.

### 14.2 Informasi versi

- nama versi;
- base model;
- training run;
- snapshot dataset;
- threshold version;
- context version;
- guideline version;
- metrics;
- checksum artefak;
- ukuran model;
- runtime;
- tanggal dibuat;
- pembuat;
- tanggal aktivasi;
- alasan promosi;
- alasan arsip/penolakan.

### 14.3 Promosi model

Tombol:

```text
Jadikan Kandidat
Jalankan Evaluasi Ulang
Minta Persetujuan
Jadikan Produksi
Tolak Kandidat
Arsipkan
Rollback
```

Promosi ke produksi memerlukan:

1. quality gate lulus;
2. evaluasi test terkunci;
3. perbandingan dengan model produksi;
4. health check artefak;
5. uji inferensi sampel tetap;
6. alasan promosi;
7. konfirmasi eksplisit superadmin.

### 14.4 Deployment aman

Gunakan strategi:

```text
muat model kandidat
        ↓
health check
        ↓
uji sampel tetap
        ↓
ganti pointer active model secara atomik
        ↓
health check ulang
        ↓
arsipkan model lama sebagai rollback target
```

Jika salah satu tahap gagal, model lama tetap aktif.

### 14.5 Rollback

Rollback:

- tidak menghapus versi baru;
- mengubah pointer model aktif;
- mengaktifkan threshold yang sesuai model lama;
- mencatat alasan;
- menjalankan health check;
- tidak mengubah prediksi historis;
- dapat memicu prediksi ulang artikel yang belum masuk sentimen.

---

## 15. Tab Pengaturan

### 15.1 Konteks utama

Simpan versi definisi konteks:

```text
Nama
Slug
Deskripsi manusia
Deskripsi model
Aturan inklusi
Aturan eksklusi
Tanggal berlaku
Status
```

Perubahan konteks membuat model produksi wajib dievaluasi ulang. Gate berubah menjadi `needs_review` sampai evaluasi selesai.

### 15.2 Threshold

Simpan sebagai versi database, bukan hanya `.env`:

```text
relevant_threshold
review_lower_bound
review_upper_bound
source_overrides
created_by
reason
activated_at
```

`.env` hanya boleh menjadi nilai bootstrap darurat.

### 15.3 Standar quality gate

Field dapat diubah superadmin, tetapi:

- nilai default mengikuti dokumen ini;
- tidak boleh diturunkan tanpa alasan;
- perubahan dicatat;
- model produksi dievaluasi ulang;
- sentimen diblokir sementara bila perubahan membuat hasil belum tervalidasi.

### 15.4 Retensi

Atur retensi untuk:

- artefak training gagal;
- log training;
- artikel tidak relevan;
- hasil prediksi lama;
- snapshot;
- model arsip.

Model yang pernah menjadi produksi dan snapshot test tidak boleh dihapus melalui proses retensi otomatis.

---

## 16. Model Data

Gunakan migration terpisah dan foreign key yang jelas.

### 16.1 `sampel_relevansi`

```text
id
artikel_id nullable
sumber_dataset
judul
excerpt nullable
isi
url nullable
media_id nullable
tanggal_publikasi nullable
kategori_sumber jsonb nullable
tag_sumber jsonb nullable
metadata_sumber jsonb nullable
label_manual nullable
alasan_label nullable
tingkat_kesulitan nullable
status_label
priority_score default 0
priority_reasons jsonb nullable
duplicate_group_id nullable
is_excluded default false
excluded_reason nullable
labeled_by nullable
labeled_at nullable
last_reviewed_at nullable
created_at
updated_at
```

Enum konsep:

```text
label_manual: relevan | tidak_relevan
status_label: belum_dilabeli | sudah_dilabeli | perlu_review | dikeluarkan | terkunci_test
tingkat_kesulitan: normal | hard_positive | hard_negative
sumber_dataset: crawler | url_test | manual_text | production_error | import | migrated_gold_set
```

### 16.2 `prediksi_relevansi`

```text
id
sampel_relevansi_id nullable
artikel_id nullable
versi_model_relevansi_id
versi_threshold_relevansi_id
versi_konteks_relevansi_id
label_prediksi
probabilitas_relevan
probabilitas_tidak_relevan
confidence
review_required
input_hash
input_tokens
input_truncated
inference_ms
sinyal jsonb nullable
predicted_at
created_at
```

Jangan menimpa prediksi lama. Prediksi baru membuat baris baru.

### 16.3 `snapshot_dataset_relevansi`

```text
id
nama
versi
deskripsi
status
strategi_sampling
random_seed
versi_panduan_label
manifest_hash
total_relevan
total_tidak_relevan
total_train
total_validation
total_test
created_by
locked_by nullable
locked_at nullable
created_at
updated_at
```

Status:

```text
draft
validating
locked
invalidated
archived
```

### 16.4 `item_snapshot_dataset_relevansi`

```text
id
snapshot_dataset_relevansi_id
sampel_relevansi_id
split
duplicate_group_id nullable
label_at_snapshot
content_hash
created_at
```

Unique:

```text
(snapshot_dataset_relevansi_id, sampel_relevansi_id)
```

### 16.5 `pelatihan_model_relevansi`

```text
id
nama
base_model
snapshot_dataset_relevansi_id
versi_konteks_relevansi_id
versi_panduan_label
parent_run_id nullable
status
progress
current_epoch nullable
current_step nullable
total_steps nullable
configuration jsonb
runtime_info jsonb nullable
metrics_validation jsonb nullable
metrics_test jsonb nullable
artifact_manifest jsonb nullable
artifact_path nullable
error_summary nullable
error_trace_path nullable
created_by
started_at nullable
finished_at nullable
created_at
updated_at
```

### 16.6 `versi_model_relevansi`

```text
id
nama
versi
base_model
pelatihan_model_relevansi_id nullable
snapshot_dataset_relevansi_id
versi_threshold_relevansi_id nullable
versi_konteks_relevansi_id
status
artifact_path
artifact_checksum
metrics jsonb
runtime_info jsonb nullable
quality_gate_status
quality_gate_report jsonb nullable
promoted_by nullable
promotion_reason nullable
activated_at nullable
archived_at nullable
created_at
updated_at
```

### 16.7 `evaluasi_model_relevansi`

```text
id
versi_model_relevansi_id
snapshot_dataset_relevansi_id
versi_threshold_relevansi_id
configuration_hash
metrics jsonb
confusion_matrix jsonb
classification_report jsonb
per_source_metrics jsonb nullable
per_group_metrics jsonb nullable
error_analysis jsonb nullable
status
started_at
finished_at nullable
created_by
created_at
```

Unique:

```text
(configuration_hash)
```

Hindari evaluasi ganda untuk konfigurasi identik.

### 16.8 `versi_threshold_relevansi`

```text
id
nama
relevant_threshold
review_lower_bound
review_upper_bound
source_overrides jsonb nullable
reason
status
created_by
activated_at nullable
created_at
updated_at
```

### 16.9 `versi_konteks_relevansi`

```text
id
nama
versi
slug
deskripsi_manusia
deskripsi_model
aturan_inklusi jsonb
aturan_eksklusi jsonb
status
created_by
activated_at nullable
created_at
updated_at
```

### 16.10 `gerbang_mutu_relevansi`

```text
id
versi_model_relevansi_id
status
standar jsonb
hasil jsonb
failed_checks jsonb nullable
approved_by nullable
approved_at nullable
revoked_by nullable
revoked_at nullable
revocation_reason nullable
created_at
updated_at
```

### 16.11 `uji_manual_relevansi`

```text
id
tipe_input
url nullable
judul nullable
excerpt nullable
isi nullable
extracted_metadata jsonb nullable
versi_model_relevansi_id
hasil_prediksi jsonb
feedback_label nullable
feedback_reason nullable
saved_as_sample_id nullable
created_by
created_at
```

### 16.12 Index penting

Tambahkan index pada:

```text
sampel_relevansi(status_label)
sampel_relevansi(label_manual)
sampel_relevansi(media_id, tanggal_publikasi)
sampel_relevansi(duplicate_group_id)
sampel_relevansi(priority_score desc)
prediksi_relevansi(artikel_id, predicted_at desc)
prediksi_relevansi(versi_model_relevansi_id, label_prediksi)
item_snapshot_dataset_relevansi(snapshot_dataset_relevansi_id, split)
pelatihan_model_relevansi(status)
versi_model_relevansi(status)
evaluasi_model_relevansi(versi_model_relevansi_id)
```

---

## 17. Backend Laravel

### 17.1 Models

```text
RelevanceSample
RelevancePrediction
RelevanceDatasetSnapshot
RelevanceDatasetSnapshotItem
RelevanceTrainingRun
RelevanceModelVersion
RelevanceEvaluation
RelevanceThresholdVersion
RelevanceContextVersion
RelevanceQualityGate
RelevanceManualTest
```

### 17.2 Controllers

```text
Admin/RelevanceLabController
Admin/RelevanceDatasetController
Admin/RelevanceDatasetLabelController
Admin/RelevanceSnapshotController
Admin/RelevanceTrainingController
Admin/RelevanceEvaluationController
Admin/RelevanceManualTestController
Admin/RelevanceModelVersionController
Admin/RelevanceQualityGateController
Admin/RelevanceSettingsController
```

### 17.3 Services

```text
App/Services/Relevance/RelevanceDatasetService.php
App/Services/Relevance/RelevanceLabelingService.php
App/Services/Relevance/RelevanceSnapshotService.php
App/Services/Relevance/RelevanceSplitValidator.php
App/Services/Relevance/RelevanceTrainingService.php
App/Services/Relevance/RelevanceEvaluationService.php
App/Services/Relevance/RelevanceQualityGateService.php
App/Services/Relevance/RelevanceModelPromotionService.php
App/Services/Relevance/RelevanceActiveLearningService.php
App/Services/Relevance/RelevanceInputBuilder.php
App/Services/Nlp/NlpClient.php
```

### 17.4 Jobs

```text
ImportArticleToRelevanceDataset
RecalculateRelevancePriority
CreateRelevanceDatasetSnapshot
ValidateRelevanceDatasetSnapshot
StartRelevanceTraining
PollRelevanceTrainingStatus
EvaluateRelevanceModel
RunRelevanceQualityGate
WarmupRelevanceModel
PromoteRelevanceModel
RollbackRelevanceModel
RepredictArticleRelevance
RepredictDatasetWithCandidateModel
AuditProductionRelevance
```

### 17.5 Commands

```text
relevance:import-crawled
relevance:recalculate-priority
relevance:validate-snapshot
relevance:train
relevance:evaluate
relevance:run-gate
relevance:audit-production
relevance:repredict
relevance:health
```

### 17.6 Policies

Hanya `superadmin` yang boleh:

- mengubah label;
- membuat snapshot;
- memulai training;
- mengubah threshold;
- mempromosikan model;
- rollback;
- mengubah quality gate;
- mengubah konteks;
- menghapus artefak.

Peran `walikota` dan `media` tidak mempunyai akses ke laboratorium.

---

## 18. Frontend Vue

Struktur yang disarankan:

```text
resources/js/pages/admin/model-relevansi/
├── Index.vue
├── tabs/
│   ├── RingkasanTab.vue
│   ├── DatasetTab.vue
│   ├── SnapshotTab.vue
│   ├── PelatihanTab.vue
│   ├── EvaluasiTab.vue
│   ├── UjiModelTab.vue
│   ├── VersiModelTab.vue
│   └── PengaturanTab.vue
├── components/
│   ├── RelevanceGateBanner.vue
│   ├── RelevanceMetricCard.vue
│   ├── RelevanceDatasetTable.vue
│   ├── RelevanceLabelDrawer.vue
│   ├── RelevanceLabelButtons.vue
│   ├── RelevanceReasonSelect.vue
│   ├── DatasetReadinessCard.vue
│   ├── SnapshotLeakageReport.vue
│   ├── TrainingProgress.vue
│   ├── TrainingLogViewer.vue
│   ├── RelevanceConfusionMatrix.vue
│   ├── RelevanceErrorTable.vue
│   ├── ThresholdSimulator.vue
│   ├── ModelComparison.vue
│   ├── ManualTestForm.vue
│   ├── ManualTestResult.vue
│   ├── ModelPromotionDialog.vue
│   └── ModelRollbackDialog.vue
└── types.ts
```

UI harus:

- fokus pada satu tugas;
- menggunakan teks Bahasa Indonesia;
- mempunyai empty state jelas;
- mempunyai skeleton loading;
- mempunyai error state yang menjelaskan tindakan berikutnya;
- mendukung keyboard pada pelabelan;
- tidak menyembunyikan filter penting;
- menampilkan angka artikel unik, bukan jumlah pasangan konteks;
- tidak mencampur sentimen.

---

## 19. Kontrak FastAPI

Base URL:

```text
http://127.0.0.1:8001
```

### 19.1 Health

```http
GET /health
```

Response:

```json
{
  "status": "ok",
  "active_relevance_model": "simak-relevancy-v1.3",
  "base_model": "apriandito/indobert-relevancy-classifier",
  "model_loaded": true,
  "quality_gate": "passed",
  "device": "cpu",
  "service_version": "1.0.0"
}
```

### 19.2 Inferensi relevansi

```http
POST /relevancy/predict
```

Request:

```json
{
  "model_version": "simak-relevancy-v1.3",
  "context_version": "pemkot-kendari-v3",
  "items": [
    {
      "id": 101,
      "title": "Pemkot Kendari memperbaiki drainase di Kadia",
      "excerpt": "...",
      "categories": ["Kendari", "Infrastruktur"],
      "tags": ["Pemkot Kendari", "Drainase"],
      "text": "..."
    }
  ]
}
```

Response:

```json
{
  "model_version": "simak-relevancy-v1.3",
  "results": [
    {
      "id": 101,
      "label": "relevan",
      "probabilities": {
        "relevan": 0.924,
        "tidak_relevan": 0.076
      },
      "confidence": 0.924,
      "input_tokens": 486,
      "input_truncated": false,
      "inference_ms": 482
    }
  ]
}
```

### 19.3 Mulai training

```http
POST /relevancy/training-runs
```

Request berisi:

- run ID dari Laravel;
- lokasi dataset export;
- base model;
- konfigurasi;
- output directory sementara;
- callback token/internal secret.

Response:

```json
{
  "accepted": true,
  "run_id": 77,
  "status": "menunggu"
}
```

### 19.4 Status training

```http
GET /relevancy/training-runs/{run_id}
```

### 19.5 Batalkan training

```http
POST /relevancy/training-runs/{run_id}/cancel
```

### 19.6 Evaluasi

```http
POST /relevancy/evaluate
```

### 19.7 Muat model kandidat

```http
POST /relevancy/models/{version}/warmup
```

### 19.8 Aktifkan model

```http
POST /relevancy/models/{version}/activate
```

Aktivasi hanya dipanggil oleh proses promosi Laravel setelah quality gate lulus.

### 19.9 Rollback

```http
POST /relevancy/models/{version}/rollback
```

### 19.10 Keamanan internal

- bind ke `127.0.0.1`;
- request mempunyai internal shared secret;
- Laravel memvalidasi response schema;
- timeout dan retry dikonfigurasi;
- endpoint training tidak dapat dipanggil publik;
- path artefak divalidasi agar tidak terjadi path traversal.

---

## 20. Bentuk Input Model

Gunakan pasangan konteks dan artikel sesuai tokenizer model relevansi.

Konteks:

```text
Pemerintah Kota Kendari
```

Representasi artikel:

```text
Judul: ...
Kategori: ...
Tag: ...
Ringkasan: ...
Isi: ...
```

Aturan:

1. prioritaskan judul dan lead;
2. bersihkan navigasi, iklan, footer, rekomendasi berita, dan boilerplate;
3. simpan versi extractor;
4. jangan memasukkan HTML mentah;
5. catat bila input terpotong;
6. pertahankan format yang sama pada training dan production;
7. perubahan input builder memerlukan evaluasi ulang model;
8. simpan `input_hash` untuk reproduksi.

Untuk artikel panjang, bentuk context window dari penyebutan:

- Pemkot Kendari;
- Pemerintah Kota Kendari;
- nama Wali Kota/Wakil Wali Kota;
- OPD;
- pejabat;
- program resmi;
- istilah kewenangan Pemkot.

Gabungkan judul, excerpt, dan context window hingga batas token.

---

## 21. Integrasi dengan Pipeline Artikel

Alur final:

```text
Crawler menemukan artikel
          ↓
Simpan kandidat + metadata
          ↓
Normalisasi URL dan deduplikasi
          ↓
Bangun input relevansi
          ↓
Model relevansi produksi
          ↓
┌───────────────┬────────────────┬────────────────┐
relevan         perlu_review     tidak_relevan
    ↓                ↓                 ↓
cek gate         antrean admin    simpan dan berhenti
    ↓
gate passed?
    ├── tidak → tahan artikel, jangan sentimen
    └── ya
          ↓
     antrean sentimen
```

Pseudocode:

```php
public function handle(Article $article): void
{
    $prediction = $this->relevanceService->predict($article);

    if ($article->manual_relevance_label !== null) {
        $finalLabel = $article->manual_relevance_label;
    } else {
        $finalLabel = $prediction->operationalLabel();
    }

    $article->storeRelevancePrediction($prediction, $finalLabel);

    if (! $this->qualityGate->isPassed()) {
        $article->update([
            'processing_status' => 'model_belum_lulus_gate',
        ]);

        return;
    }

    if ($finalLabel === 'perlu_review') {
        $article->update([
            'processing_status' => 'perlu_review',
        ]);

        return;
    }

    if ($finalLabel === 'tidak_relevan') {
        $article->update([
            'processing_status' => 'tidak_relevan',
        ]);

        return;
    }

    $article->update([
        'processing_status' => 'menunggu_sentimen',
    ]);

    AnalyzeSentiment::dispatch($article->id)->onQueue('nlp');
}
```

Tambahkan guard kedua di `AnalyzeSentiment` agar job tetap menolak berjalan bila dipanggil secara tidak sengaja.

```php
if (! $qualityGate->isPassed()) {
    return;
}

if (! $article->hasFinalRelevantLabel()) {
    return;
}
```

---

## 22. Monitoring Produksi

### 22.1 Audit sampling

Setiap minggu:

1. ambil sampel artikel prediksi relevan;
2. ambil sampel artikel prediksi tidak relevan;
3. labeli manual;
4. hitung precision dan recall estimasi produksi;
5. bandingkan dengan test set;
6. masukkan kesalahan ke active learning.

### 22.2 Drift

Pantau:

- distribusi confidence;
- proporsi relevan/tidak relevan;
- proporsi perlu review;
- error per media;
- istilah/entitas baru;
- perubahan panjang artikel;
- media baru;
- perubahan tag/kategori;
- perubahan ekstraktor isi;
- penurunan audit sampling.

### 22.3 Alarm

Kirim peringatan admin bila:

- persentase perlu review melonjak;
- proporsi relevan berubah ekstrem;
- confidence rata-rata turun;
- audit precision/recall di bawah gate;
- model gagal dimuat;
- latency melewati batas;
- error inferensi meningkat;
- model version pada artikel tidak sesuai active model.

Alarm drift tidak langsung melatih model. Ia membuat tugas review dan dapat mencabut gate bila kritis.

---

## 23. Import dan Export

### 23.1 Import

Format CSV/JSON minimal:

```text
external_id
judul
isi
label
url
media
tanggal_publikasi
alasan_label
```

Validasi:

- label hanya `relevan` atau `tidak_relevan`;
- isi tidak kosong;
- encoding UTF-8;
- deteksi duplikat;
- preview sebelum commit;
- hasil import memiliki laporan baris berhasil/gagal.

### 23.2 Export

Sediakan export:

- hasil filter dataset;
- snapshot manifest;
- classification report;
- false positive;
- false negative;
- perbandingan model;
- konfigurasi training;
- laporan quality gate.

Jangan menyertakan artefak model melalui export publik biasa.

---

## 24. Audit Trail

Audit wajib untuk:

- membuat/mengubah label;
- batch label;
- mengubah alasan;
- mengeluarkan sampel;
- mengubah test set;
- membuat/mengunci snapshot;
- memulai/membatalkan training;
- mengubah threshold;
- mengubah konteks;
- mengubah quality gate;
- mempromosikan model;
- rollback;
- menghapus artefak;
- mengaktifkan atau memblokir sentimen.

Simpan:

```text
actor
aksi
entity_type
entity_id
nilai_sebelum
nilai_sesudah
alasan
ip_address
user_agent
created_at
```

---

## 25. Pengujian Otomatis

### 25.1 Feature test wajib

1. Superadmin dapat membuka laboratorium.
2. Peran media dan walikota mendapat 403.
3. Label manual tidak tertimpa prediksi ulang.
4. Sampel tanpa label tidak masuk snapshot terkunci.
5. Duplicate group tidak dapat tersebar antara train dan test.
6. Test set terkunci tidak dapat diubah tanpa alasan.
7. Training tidak mulai bila snapshot invalid.
8. Model tidak dapat dipromosikan bila gate gagal.
9. Hanya satu model berstatus production.
10. Rollback mengaktifkan model dan threshold yang sesuai.
11. Prediksi historis tidak tertimpa.
12. Artikel tidak relevan tidak pernah dispatch sentimen.
13. Artikel perlu review tidak pernah dispatch sentimen.
14. Artikel relevan tidak dispatch sentimen bila gate belum lulus.
15. Job sentimen menolak artikel yang tidak memenuhi guard.
16. Perubahan konteks mengubah gate menjadi `needs_review`.
17. Pencabutan gate menghentikan sentimen baru.
18. Evaluasi konfigurasi identik tidak dibuat ganda.
19. Filter dataset tersimpan dalam query string.
20. Audit log tercatat untuk seluruh aksi kritis.

### 25.2 Unit test wajib

```text
RelevanceInputBuilderTest
RelevanceSplitValidatorTest
RelevanceQualityGateServiceTest
RelevanceModelPromotionServiceTest
RelevanceActiveLearningServiceTest
RelevanceThresholdSimulationTest
```

### 25.3 Integration test FastAPI

- model dapat dimuat;
- label mapping benar;
- batch prediction benar;
- input panjang ditangani;
- response schema stabil;
- training progress dapat dibaca;
- cancel training bekerja;
- artefak mempunyai checksum;
- aktivasi model atomic;
- rollback berhasil;
- service restart memuat model produksi yang benar.

### 25.4 Golden test

Simpan sekumpulan artikel tetap yang mewakili:

- Pemkot jelas;
- OPD jelas;
- kritik tanpa kata Pemkot di judul;
- Pemprov;
- Polri/TNI;
- instansi vertikal;
- lokasi saja;
- DPRD relevan;
- DPRD tidak relevan;
- penyebutan sepintas;
- artikel panjang;
- artikel duplikat.

Golden test dijalankan sebelum promosi model.

---

## 26. Definition of Done

Modul dianggap selesai hanya jika:

### Dataset

- [ ] Artikel crawler otomatis dapat masuk kandidat dataset.
- [ ] Admin dapat melihat jumlah relevan dan tidak relevan.
- [ ] Admin dapat memfilter semua status penting.
- [ ] Admin dapat melabeli dan memperbaiki label.
- [ ] Riwayat perubahan label tersimpan.
- [ ] Hard positive dan hard negative dapat ditandai.
- [ ] Duplicate group dikelola.
- [ ] Active learning menghasilkan antrean prioritas.

### Snapshot

- [ ] Snapshot dapat dibuat dan dikunci.
- [ ] Train, validation, dan test dipisahkan.
- [ ] Kebocoran duplikat lintas split dicegah.
- [ ] Test set terkunci.
- [ ] Snapshot mempunyai manifest hash.

### Pelatihan

- [ ] Fine-tuning dapat dimulai dari halaman admin.
- [ ] Training berjalan asinkron.
- [ ] Progress dan error dapat dipantau.
- [ ] Checkpoint terbaik dipilih berdasarkan validation.
- [ ] Artefak dan konfigurasi tersimpan.
- [ ] Riwayat training tidak hilang.

### Evaluasi

- [ ] Precision, recall, F1, macro F1, dan confusion matrix tersedia.
- [ ] False positive dan false negative dapat dibuka.
- [ ] Evaluasi per media dan pola kasus tersedia.
- [ ] Threshold dapat disimulasikan.
- [ ] Model dapat dibandingkan.
- [ ] Konsistensi pelabel dapat diukur.

### Uji model

- [ ] URL dapat ditempel dan diekstrak.
- [ ] Teks manual dapat diuji.
- [ ] Hasil menampilkan label, confidence, probabilitas, model, threshold, token, dan latency.
- [ ] Prediksi dapat dikonfirmasi benar/salah.
- [ ] Sampel salah dapat disimpan sebagai hard case.
- [ ] Model produksi dan kandidat dapat dibandingkan.

### Versioning

- [ ] Model mempunyai status draft/candidate/production/archived.
- [ ] Hanya satu model production.
- [ ] Promosi memerlukan gate lulus.
- [ ] Rollback tersedia.
- [ ] Prediksi menyimpan versi model dan threshold.
- [ ] Konteks dan panduan pelabelan mempunyai versi.

### Gerbang relevansi

- [ ] Gate mempunyai status blocked/needs_review/passed/revoked.
- [ ] Gate dinilai otomatis dari metrik dan syarat non-metrik.
- [ ] Sentimen diblokir saat gate belum lulus.
- [ ] Artikel perlu review tidak masuk sentimen.
- [ ] Artikel tidak relevan tidak masuk sentimen.
- [ ] Guard sentimen tersedia di dispatcher dan job.
- [ ] Pencabutan gate langsung menghentikan sentimen baru.

---

## 27. Urutan Implementasi yang Disarankan

Tidak ada batas waktu. Jangan melewati fase sebelum fondasi fase sebelumnya stabil.

### Fase 1: Fondasi data

- migration dan model;
- import artikel crawler;
- tabel dataset;
- pelabelan cepat;
- audit label;
- duplicate group;
- alasan label.

**Gerbang fase:** dataset dapat dilabeli konsisten dan tidak ada label manual yang tertimpa.

### Fase 2: Snapshot dan kualitas dataset

- snapshot;
- train/validation/test;
- test terkunci;
- leakage validator;
- readiness report;
- active learning dasar.

**Gerbang fase:** snapshot terkunci dapat direproduksi dan tidak memiliki kebocoran duplikat.

### Fase 3: Training pipeline

- export dataset;
- FastAPI training;
- progress;
- checkpoint;
- artefak;
- riwayat training;
- error handling.

**Gerbang fase:** training yang sama dapat direproduksi dari snapshot dan konfigurasi yang sama.

### Fase 4: Evaluasi

- metrik;
- confusion matrix;
- false positive/negative;
- per media;
- threshold simulator;
- perbandingan model;
- konsistensi pelabel.

**Gerbang fase:** hasil evaluasi dapat menjelaskan secara spesifik jenis kesalahan model.

### Fase 5: Uji model dan versioning

- uji URL;
- uji teks;
- feedback;
- candidate/production;
- warmup;
- promosi atomic;
- rollback.

**Gerbang fase:** model kandidat dapat diuji tanpa memengaruhi produksi dan rollback berhasil.

### Fase 6: Quality gate

- standar gate;
- laporan gate;
- blokir sentimen;
- guard ganda;
- pencabutan gate;
- audit sampling.

**Gerbang fase:** tidak ada jalur kode yang memungkinkan sentimen berjalan ketika relevansi belum layak.

### Fase 7: Perbaikan model sampai layak

Ulangi:

```text
analisis kesalahan
      ↓
tambah hard cases
      ↓
perbaiki label/panduan
      ↓
buat snapshot baru
      ↓
fine-tune
      ↓
evaluasi
      ↓
quality gate
```

Jangan melanjutkan ke sentimen selama gate belum `passed`.

### Fase 8: Aktifkan sentimen

Fase ini baru dimulai setelah:

- model relevansi produksi aktif;
- quality gate lulus;
- audit sampling awal berhasil;
- false positive dan false negative kritis sudah ditinjau;
- rollback siap;
- seluruh test guard sentimen lulus.

---

## 28. Kriteria Keputusan Akhir

### Relevansi belum oke

Kondisi berikut berarti **belum oke**:

- precision, recall, atau F1 di bawah gate;
- terlalu banyak false positive dari Pemprov, Polri/TNI, instansi vertikal, atau lokasi saja;
- artikel penting Pemkot sering menjadi false negative;
- test set kecil, bocor, atau tidak beragam;
- label manusia tidak konsisten;
- prediksi tidak menyimpan versi;
- model tidak dapat di-rollback;
- confidence tidak terkalibrasi;
- media utama mempunyai performa buruk;
- model kandidat belum diuji pada artikel baru;
- threshold dipilih dari test set;
- duplicate group tersebar lintas split.

Tindakan:

```text
BLOKIR SENTIMEN
PERBAIKI DATASET
LATIH ULANG
EVALUASI ULANG
```

### Relevansi oke

Relevansi baru dianggap layak bila:

- seluruh syarat quality gate lulus;
- false positive dan false negative utama telah direview;
- test set terkunci dan beragam;
- model lebih baik atau setidaknya tidak mengalami regresi kritis dari model lama;
- audit sampling produksi sesuai hasil test;
- promosi dan rollback teruji;
- admin menyetujui aktivasi;
- gate berstatus `passed`.

Setelah itu saja artikel relevan boleh diteruskan ke model sentimen.

---

## 29. Pernyataan Penutup

Laboratorium Model Relevansi adalah fondasi kepercayaan SIMAK Kendari.

Urutan produk tidak boleh dibalik:

```text
RELEVANSI YANG VALID
        ↓
SENTIMEN YANG BERMAKNA
        ↓
DASHBOARD YANG DAPAT DIPERCAYA
```

Apabila relevansi masih menghasilkan banyak artikel salah, analisis sentimen tidak memberikan nilai. Ia hanya memberi label positif, netral, atau negatif pada kumpulan artikel yang sejak awal belum benar.

Karena itu, keputusan final rancangan ini adalah:

> **Selesaikan, ukur, dan stabilkan model relevansi terlebih dahulu. Jangan mengaktifkan pipeline sentimen sebelum quality gate relevansi lulus.**

---

## Changelog

| Versi | Tanggal | Perubahan |
|-------|---------|-----------|
| 1.0 | 4 Agustus 2026 | Dokumen dibuat. Bagian 0 ditambahkan saat dokumen masuk paket spesifikasi: mencatat apa yang digantikan dari revisi 1.5, nasib `multilingual-e5-small`, dan keputusan memblokir sentimen mulai hari itu juga |
