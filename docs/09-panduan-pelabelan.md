# 09 — Panduan Pelabelan Gold Set

SIMEDIA Kendari | Versi 1.0

---

> **Penyimpangan dari dokumen 04.** Bagian C.3 menetapkan `r` untuk "tidak
> relevan". Diganti `4` karena `r` bertabrakan dengan Ctrl+R: penekanannya
> memblokir muat ulang halaman sekaligus menyimpan label tanpa disadari
> pelabel. Angka 1–4 juga lebih cepat karena berderet di satu baris.
>
> **Catatan asal.** Dokumen 07 meminta panduan yang menjawab "enam pertanyaan di
> dokumen 05 bagian 7". Berkas `05-spesifikasi-nlp.md` tidak ada di paket
> spesifikasi. Enam pertanyaan di bawah dirumuskan ulang dari keputusan yang
> benar-benar dihadapi saat melabeli, bukan disalin dari dokumen 05. Kalau
> dokumen itu muncul kemudian dan pertanyaannya berbeda, ganti bagian ini dan
> **labeli ulang** baris yang terpengaruh — gold set yang dilabeli dengan dua
> aturan berbeda tidak mengukur apa pun.

## Mengapa panduan ini ada

Gold set dilabeli satu orang. Tanpa aturan tertulis, keputusan pada baris ke-20
dan baris ke-380 akan berbeda, dan yang terukur bukan akurasi model melainkan
perubahan suasana hati pelabel.

Baca sekali sampai habis sebelum melabeli baris pertama. Saat ragu di tengah
jalan, kembali ke sini — jangan memutuskan sendiri lalu lupa.

## Aturan induk

**Nilai nada terhadap konteks, bukan nada berita secara umum.**

Berita tentang bencana yang ditangani cepat oleh Pemkot bernada suram secara
umum, tapi terhadap konteks "Pemerintah Kota Kendari" nadanya positif. Yang
dinilai selalu: *bagaimana berita ini membuat pembaca memandang konteks itu.*

---

## Enam pertanyaan yang sering muncul

### 1. Artikel menyebut konteks sekali lewat, tanpa membahasnya

**Tidak relevan.**

Berita panen padi di Konawe yang menyebut "Wali Kota Kendari turut hadir" tidak
membahas Wali Kota. Penyebutan bukan pembahasan.

Ujinya: kalau kalimat yang memuat konteks itu dihapus, apakah beritanya masih
utuh? Kalau ya, tidak relevan.

### 2. Berita kritik pihak lain, Pemkot hanya menanggapi

**Relevan, dan nadanya dinilai dari posisi Pemkot dalam berita itu.**

"Warga protes jalan rusak, Pemkot berjanji memperbaiki pekan depan" → negatif.
Keluhannya tertuju pada Pemkot, janji perbaikan tidak menghapus itu.

"Warga protes proyek provinsi, Pemkot memfasilitasi mediasi" → netral atau
positif, tergantung apakah Pemkot digambarkan menyelesaikan atau menghindar.

### 3. Siaran pers Pemkot yang dimuat apa adanya

**Nilai apa adanya, seperti berita lain.**

Siaran pers hampir selalu positif, dan itu memang fakta pemberitaannya —
sistem ini mengukur nada yang sampai ke pembaca, bukan ketulusan sumbernya.
Jangan menurunkan label hanya karena curiga isinya humas.

Kalau nanti perlu memisahkan, itu pekerjaan deteksi siaran pers di daftar
versi 2, bukan pekerjaan pelabel.

### 4. Berita netral yang memuat satu kalimat kritik

**Netral, kecuali kritiknya jadi inti berita.**

Satu kalimat "sebagian warga menilai sosialisasinya kurang" di dalam berita
peresmian tidak membuat beritanya negatif. Yang menentukan: apa yang diingat
pembaca setelah membaca judul dan dua paragraf pertama.

Kalau kritik itu ada di judul, ia inti berita.

### 5. Berita kriminal atau kecelakaan di wilayah Kota Kendari

**Tidak relevan terhadap konteks institusi**, kecuali Pemkot ikut disorot.

"Rumah warga di Baito terbakar" tidak relevan terhadap "Pemerintah Kota
Kendari". Berita yang sama dengan tambahan "Damkar Kota Kendari terlambat tiba"
menjadi relevan dan negatif.

Aparat penegak hukum bukan Pemkot. Berita penggeledahan oleh kejaksaan terhadap
anggota DPRD provinsi tidak relevan terhadap konteks Pemkot Kendari.

### 6. Pelabel benar-benar tidak yakin

**Pilih netral, lalu tulis alasannya di kolom catatan.**

Jangan menghabiskan lebih dari 30 detik untuk satu artikel. Baris yang ragu
lebih berguna sebagai netral bercatatan daripada sebagai keputusan yang dipaksakan
— catatan itu yang nanti dibaca saat menelusuri kenapa angka akurasi tertentu
muncul.

Kalau lebih dari satu dari sepuluh artikel terasa ragu, aturan di dokumen ini
yang kurang, bukan pelabelnya. Tambahkan pertanyaan ketujuh.

---

## Siapa yang melabeli

**Anda, bukan AI dan bukan orang lain.** Gold set adalah penggaris untuk
mengukur model. Kalau yang melabeli juga sebuah model, yang terukur adalah
kesesuaian dua model — bukan akurasi terhadap penilaian manusia — dan angka F1
yang dilaporkan ke Diskominfo runtuh begitu ada yang menanyakan asalnya.

Dokumen 01 bagian 9 nomor 7 sudah menetapkan ini, dan bagian risiko di dokumen
yang sama justru mengkhawatirkan bias karena hanya ada satu pelabel. Menambah
AI sebagai pelabel membuat pengukurannya melingkar.

## Konteks mana yang dilabeli

Satu artikel dinilai **terpisah terhadap tiap konteks**, karena nadanya memang
bisa berbeda. Berita "Wali Kota dikritik soal sampah" negatif terhadap *Wali
Kota Kendari* dan terhadap *Pelayanan publik*, tapi bisa saja tidak relevan
terhadap konteks lain.

Bagi 400 label seperti ini:

| Konteks | Target | Alasan |
|---------|--------|--------|
| Pemerintah Kota Kendari | ~250 | Konteks utama, seluruh angka dashboard eksekutif bertumpu padanya. Paling banyak artikel yang relevan |
| Wali Kota Kendari | ~75 | |
| Pelayanan publik dan infrastruktur | ~75 | |

Kerjakan konteks utama sampai selesai dulu, baru pindah. Berpindah-pindah
konteks tiap beberapa artikel memaksa Anda mengganti kerangka penilaian
terus-menerus, dan konsistensinya turun.

`evaluasi:model` hanya memakai baris yang Anda tandai **relevan**. Melabeli
banyak di konteks yang jarang relevan menghasilkan sedikit baris yang bisa
diukur — itu sebabnya pembagiannya tidak rata.

## Cara kerja

1. Buka `/admin/pelabelan`. Pilih konteks di kanan atas.
2. Baca judul dan dua paragraf pertama. Itu cukup.
3. Tekan `1` negatif, `2` netral, `3` positif, `4` tidak relevan. Keempatnya
   berderet di satu baris angka, jadi satu tangan menjangkau semuanya.
4. Tebakan model baru muncul **setelah** Anda memutuskan. Kalau berbeda,
   jangan mengubah keputusan — perbedaan itulah yang sedang diukur.

Target 20 detik per artikel. 400 baris selesai dalam sekitar dua jam terfokus,
dipecah menjadi beberapa sesi.

### Mode pengambilan artikel

Pemilih di kanan atas menentukan artikel mana yang disodorkan. Angka dalam
kurung adalah sisa yang tersedia.

| Mode | Untuk apa |
|------|-----------|
| **Acak** | Akurasi keseluruhan. Hanya mode ini yang sampelnya mewakili semua artikel |
| **Kemungkinan relevan** | Menghemat waktu di konteks sempit — melewati artikel yang model nilai tidak relevan |
| **Ditebak negatif** | Mengumpulkan cukup contoh negatif untuk mengukur F1 negatif |
| **Perlu review** | Artikel yang model sendiri ragu |

Kerjakan **mode acak lebih dulu sampai 200 label** di konteks utama. Itu yang
menghasilkan angka akurasi yang bisa dipertanggungjawabkan.

Tiga mode lain memilih artikel berdasarkan tebakan model, jadi sampelnya bias.
Label dari sana berguna mengukur F1 per kelas, tapi **jangan dipakai menghitung
akurasi keseluruhan** — laporkan terpisah. Peringatan yang sama muncul di layar
saat mode terarah aktif.

Alasan mode negatif ada: pada korpus nyata, berita negatif hanya **4,5%** dari
pasangan relevan. 250 label acak hanya memuat sekitar tujuh contoh negatif, dan
F1 negatif dari tujuh contoh tidak berarti apa-apa — padahal F1 macro yang jadi
gerbang merata-ratakan ketiga kelas dengan bobot sama.

### Memperbaiki yang sudah dilabeli

- `←` dan `→` menelusuri riwayat mundur dan maju. Riwayatnya dibaca dari
  database, jadi tetap ada setelah halaman dimuat ulang atau dilanjutkan besok.
- Tombol **Sudah dilabeli** di kanan atas membuka 20 label terakhir beserta
  judulnya. Klik salah satu untuk langsung membukanya.
- Saat artikel lama dibuka, tombol pilihan Anda dulu tampil menyala dan muncul
  pita peringatan di atas. Memilih ulang menimpa label lama, tidak menambah
  baris baru.
- Tombol **Kembali ke antrean** mengembalikan Anda ke artikel yang belum
  dilabeli.

## Ronde 2

Seminggu setelah ronde 1 selesai, labeli ulang 40 baris acak sebagai ronde 2
tanpa melihat label ronde 1.

Kesesuaian antara kedua ronde adalah **batas atas akurasi yang wajar diminta
dari model**. Kalau manusia hanya konsisten 82% dengan dirinya sendiri,
menuntut model melewati angka itu tidak masuk akal, dan F1 macro 0,80 justru
hasil yang sangat baik.

Angkanya tampil sendiri di `/admin/evaluasi`.

## Setelah selesai

```bash
php artisan evaluasi:model
```

Hasilnya masuk tabel `evaluasi_model` dan tampil di `/admin/evaluasi`.

F1 macro di bawah 0,65 berarti berhenti — jangan bangun dashboard di atas model
yang angkanya akan dibantah di rapat pertama.
