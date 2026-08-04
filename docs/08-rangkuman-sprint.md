# 08: Rangkuman Sprint

Ringkasan satu halaman dari [`07-roadmap.md`](07-roadmap.md). Detail tugas,
definition of done lengkap, jalur pemangkasan, dan daftar versi 2 ada di sana.

**9 tahapan: Sprint 0-8. Asumsi 1 pengembang 20 jam/minggu.**

| Sprint | Durasi | Fokus | Status |
|--------|--------|-------|--------|
| 0 | 1 minggu | Persiapan: uji feed, uji model, siapkan infra | selesai |
| 1 | 2 minggu | Fondasi, CRUD, `DataTable.vue` | selesai |
| 2 | 2 minggu | Crawler dan deduplikasi | selesai |
| 3 | 2 minggu | NLP dan gold set | selesai |
| 4 | 2 minggu | Dashboard eksekutif dan kontrak | selesai |
| 5 | 2 minggu | Portal media dan alert | selesai |
| 6 | 2 minggu | Penyederhanaan relevansi jadi satu konteks | selesai sebagian, sisanya pindah ke 8 |
| 8 | tanpa batas | Laboratorium Model Relevansi, fine-tuning IndoBERT | fase 1 selesai, fase 2 berjalan |
| 7 | 2 minggu | Pemantapan dan serah terima | belum, menunggu 8 |

Dua sprint tidak ada di rencana awal, dan keduanya lahir dari pengukuran.

Sprint 6 ditambahkan setelah evaluasi sprint 3 menunjukkan rancangan tiga
konteks tidak bisa mencapai presisi yang layak.

Sprint 8 ditambahkan setelah sprint 6 selesai diukur: presisi relevansi naik ke
69,9% dan berhenti di sana, di bawah target 80%. Yang menahannya bukan pilihan
model melainkan dataset yang kecil, dibuat dengan aturan lama, dan tanpa data
tahan. Sprint 8 membangun laboratorium untuk mengerjakan bagian itu, dan
**memblokir sentimen sampai selesai**. Urutannya 8 lalu 7, karena serah terima
menunggu angka yang bisa dipertanggungjawabkan. Dokumen 10.

---

## Sprint 0: Persiapan (1 minggu)

Bukan sprint penuh, tapi jangan dilewati.

- Jawab sembilan pertanyaan terbuka dokumen 01 bagian 9, sudah terjawab
- Uji satu per satu 30 URL RSS di lampiran A, catat mana yang hidup
- Verifikasi dua model IndoBERT bisa diunduh dan dijalankan
- Uji manual 20 artikel Kendari ke model sentimen
- Siapkan repo, VPS, domain, sertifikat, Laravel, PostgreSQL+pgvector, Redis

**Selesai bila:** aplikasi kosong terbuka lewat HTTPS di domain produksi, dan
skrip Python bisa melabeli satu artikel dari terminal.

**Gerbang:** akurasi model di bawah 60% berarti berhenti dan baca dokumen 05
bagian 8 sebelum lanjut.

## Sprint 1: Fondasi dan CRUD (2 minggu)

Tanpa NLP, tanpa grafik. Menyiapkan alat untuk lima sprint berikutnya.

- Seluruh migration dokumen 03 sekaligus, model, relasi, enum, seeder 30 media
  dan tiga konteks pantauan
- Middleware `peran` dan `tolak.tulis`, global scope pada model ber-`media_id`
- CRUD media dan sumber feed
- Tiga layout, token warna sentimen, komponen shadcn gelombang pertama
- `DataTable.vue` beserta toolbar, paginasi, faceted filter
- `BadgeSentimen.vue`, `KeadaanKosong.vue`, `useFormatAngka.ts`

**Selesai bila:** superadmin login dengan 2FA, menambah media dan sumber RSS,
lalu melihatnya di tabel yang bisa difilter, disortir, dan dipaginasi lewat URL.

**Prioritas:** `DataTable.vue` lebih dulu sampai nyaman dipakai. Sembilan
halaman bergantung padanya.

## Sprint 2: Crawler (2 minggu)

Sprint pertama yang menghasilkan sesuatu yang bisa didemokan.

- `PembacaRss`, `NormalisasiUrl`, `EkstraktorArtikel` dengan readability.php
- Command `crawl:feeds` dengan `withoutOverlapping()` dan `crawl:google-news`
- Job `AmbilIsiArtikel` dengan retry dan backoff
- Deduplikasi lapis 1 dan 2: URL kanonik dan simhash
- Log crawl, penonaktifan otomatis sumber setelah 5 kegagalan
- Validasi URL anti-SSRF, konfigurasi supervisor untuk worker queue
- Halaman daftar artikel, halaman log crawl, dashboard admin KPI dasar

**Selesai bila:** crawler berjalan otomatis 72 jam tanpa intervensi, tabel
artikel terisi dari minimal 10 media, duplikat tertandai benar.

**Gerbang:** periksa manual 50 artikel sebelum menutup sprint. Ekstraksi buruk
di sini merusak seluruh analisis sprint berikutnya.

## Sprint 3: NLP dan gold set (2 minggu)

Sprint terberat. Anggarkan waktu lebih.

- Layanan FastAPI tiga endpoint, `KlienNlp` di sisi Laravel
- Job `HitungEmbedding`, deduplikasi lapis 3 dengan pgvector
- Penyaring kata kunci per konteks, job `AnalisisRelevansi` dan `AnalisisSentimen`
- Logika `perlu_review`, kolom generated `label_efektif`
- CRUD konteks pantauan, halaman detail artikel dengan form koreksi label
- Ruang kerja pelabelan lengkap dengan pintasan keyboard
- Panduan pelabelan, lalu labeli 400 baris gold set (~8 jam)
- Command `evaluasi:model` dan halaman hasil evaluasi
- Setel ambang keyakinan dari gold set, ambang dedup dari 100 pasangan manual
- Command `hitung:ringkasan-harian`

**Selesai bila:** seluruh artikel punya skor sentimen per konteks relevan, ada
satu baris `evaluasi_model` dengan F1 macro terukur, ambang keyakinan
ditetapkan dari data bukan tebakan.

**Gerbang:** F1 macro di bawah 0,65 berarti hentikan sprint 4 dan kerjakan
alternatif dokumen 05 bagian 8 lebih dulu.

**Urutan:** bangun ruang kerja pelabelan sebelum mulai melabeli.

## Sprint 4: Dashboard eksekutif dan kontrak (2 minggu)

- `BaseChart.vue`, `useTemaChart.ts`
- Lima grafik: tren volume, tren sentimen, donat, peringkat media, word cloud
- Tombol "Lihat sebagai tabel" pada setiap grafik
- `KartuKpi.vue`, `PemilihRentangTanggal.vue`, `PemilihKonteks.vue`
- Dashboard eksekutif, halaman sentimen, isu hangat, peringkat media, arsip
- Command `hitung:kata-kunci` dengan skor lonjakan
- CRUD kontrak, `ProgresKontrak.vue`, pencocokan otomatis artikel ke pemuatan
- CRUD pengguna, ekspor Excel

**Selesai bila:** dashboard render di bawah 2 detik pada 4G, terbaca di layar
375 piksel, angkanya cocok saat dihitung ulang manual dari tabel artikel.

**Catatan:** uji di ponsel sungguhan, bukan device toolbar browser.

## Sprint 5: Portal media dan alert (2 minggu)

- Dashboard portal, halaman berita saya, kontrak saya
- Halaman lapor: tempel URL, pratinjau, konfirmasi, banyak URL, jalur cadangan
  isian manual saat ekstraksi gagal
- Daftar "sudah tercatat otomatis" di atas form lapor
- Job `ArsipkanBuktiPemuatan` dengan Playwright, validasi domain URL vs pelapor
- `ArtikelPortalResource` tanpa field sentimen
- Antrean verifikasi pemuatan di panel admin
- 30 akun media dibuat dan disosialisasikan, Google Form dimatikan
- CRUD aturan alert, command `alert:periksa`, `PengirimTelegram`, riwayat alert
  dan pembatas pengiriman berulang
- Command `kontrak:periksa-tenggat`, CRUD entitas dengan aksi gabungkan
- Halaman pengaturan sistem
- Empat test wajib dokumen 02 bagian 9

**Selesai bila:** pengguna media bisa melaporkan pemuatan dan admin
memverifikasinya, alert lonjakan negatif terkirim ke Telegram, empat test hijau.

**Catatan:** minta chat ID grup Telegram Diskominfo di awal sprint. Test
scoping peran media adalah satu-satunya fitur yang kebocorannya jadi masalah
dengan pihak luar.

## Sprint 6: Penyederhanaan relevansi (2 minggu)

Perombakan rancangan, bukan fitur baru. Tiga konteks menjadi satu konteks utama
"Pemerintah Kota Kendari", relevansi menjadi keputusan biner per artikel, dan
Wali Kota serta pelayanan publik turun menjadi entitas dan topik.

- Nonaktifkan dua konteks tambahan, jangan dihapus. Halaman pelabelan menjadi
  satu keputusan per artikel
- **Penilai relevansi pindah ke `multilingual-e5-small`.** `indobert-relevancy`
  dan endpoint `/relevancy` dihapus. Skor menjadi cosine similarity terhadap
  vektor deskripsi konteks, dihitung PostgreSQL, sehingga menyetel ambang tidak
  memerlukan inferensi ulang. Dua ambang dikalibrasi dari validation set
- Kolom metadata sumber WordPress, kategori dan tag ditarik saat crawl
- Kamus entitas dan alias dilengkapi, termasuk kontras Pemprov dan instansi
  vertikal. `JendelaKonteks` untuk input model
- Gold set: `label_gold` nullable, kolom `gold_set_versi` dan `split`, migrasi
  249 label konteks utama, review ulang label dua konteks lama satu per satu
- Hard negative, test set dibekukan per grup duplikat, ronde 2 konsistensi
- Antrean `/admin/review`, koreksi relevansi manual dengan alasan wajib,
  `relevan_efektif`, halaman evaluasi dipisah dua tab
- Jalankan ulang analisis seluruh korpus, lalu `evaluasi:model`

**Selesai bila:** presisi relevansi minimal 80% dan recall minimal 85% pada
test set beku, gold set dihitung per artikel unik, tidak ada koreksi manual
yang tertimpa, evaluasi relevansi terpisah dari sentimen.

**Gerbang:** presisi masih di bawah 80% setelah semuanya berarti berhenti
menambah aturan tempelan. Kerjakan urutan di dokumen 05 bagian 8.

## Sprint 8: Laboratorium Model Relevansi (tanpa batas waktu)

Dikerjakan sebelum sprint 7. Spesifikasi lengkap di dokumen 10, fase dan
gerbangnya di dokumen 07.

Relevansi berpindah dari cosine e5 ke `apriandito/indobert-relevancy-classifier`
yang dilatih ulang dengan dataset lokal. e5 turun menjadi pendeteksi salinan.
**Analisis sentimen diblokir sejak hari pertama sprint ini** dan baru dibuka
pada fase 8, setelah gerbang mutu lulus.

- Fase 1: sebelas tabel, impor kandidat, tabel dataset, pelabelan cepat, audit label
- Fase 2: snapshot, split per grup duplikat, test terkunci, validator kebocoran, active learning. **Labeli sampai 1.500 artikel unik**, ini bagian terpanjang
- Fase 3: ekspor dataset, fine-tuning di FastAPI, progres, artefak berchecksum
- Fase 4: metrik, confusion matrix, analisis kesalahan, simulator ambang, konsistensi pelabel
- Fase 5: uji URL dan teks, status versi, warmup, promosi atomik, rollback
- Fase 6: standar gerbang, pencabutan otomatis, penjaga sentimen di dua tempat, audit sampling
- Fase 7: ulangi analisis kesalahan sampai gerbang lulus
- Fase 8: aktifkan kembali sentimen dan dashboard-nya

**Selesai bila:** seluruh kotak dokumen 10 bagian 26 tercentang, gerbang mutu
`passed`, dan angka sentimen dihitung dari artikel yang relevansinya terukur.

**Gerbang:** presisi, recall, F1, dan macro F1 minimal 0,85 pada test set
terkunci, `perlu_review` di bawah 15%, tanpa kebocoran duplikat, dan tanpa satu
pun label manual yang tertimpa. Menurunkan standar ini wajib beralasan dan
tercatat di audit log.

## Sprint 7: Pemantapan dan serah terima (2 minggu)

Sprint tanpa fitur baru. Ide fitur baru masuk daftar versi 2.

- Ekspor PDF ringkasan eksekutif
- Aksesibilitas: kontras, fokus, navigasi keyboard
- Keadaan kosong, loading, dan error di seluruh halaman
- Peninjauan pesan error agar menyebut tindakan yang bisa diambil
- Optimasi query, perbaiki apa pun di atas 300 ms
- Daftar periksa pengerasan dokumen 06 bagian 7
- Backup otomatis dan satu kali uji restore penuh
- Halaman kebijakan privasi, README setup dari nol, panduan pengguna per peran
- Pelatihan admin Diskominfo dua sesi
- Evaluasi model kedua setelah 3 bulan data, bandingkan dengan yang pertama
- Pastikan Google Form sudah mati

**Selesai bila:** orang lain bisa menyiapkan lingkungan pengembangan dari
README tanpa bertanya, backup pernah berhasil direstore, admin Diskominfo bisa
menambahkan sumber feed baru sendiri.

---

## Yang tidak boleh dipangkas

Kalau waktu habis, urutan pemangkasan ada di dokumen 07. Enam hal ini di luar
daftar itu dalam keadaan apa pun:

1. Gold set dan pengukuran akurasi
2. Deduplikasi
3. Status perlu review
4. Global scope peran media beserta test-nya
5. Backup dan satu kali uji restore
6. Responsivitas dashboard eksekutif di layar ponsel

### Penarikan arsip (`crawl:backfill`)

Ditambahkan di luar rencana sprint karena kelas negatif tidak bisa diukur tanpa
korpus yang jauh lebih besar. Setelah pengetat relevansi, artikel yang ditebak
negatif di konteks utama tinggal empat, dan semuanya sudah dilabeli. Pada laju
crawler RSS, mengumpulkan 40 negatif butuh sekitar dua pekan.

Hasil satu kali jalan, empat halaman per media: **4.415 artikel baru**, total
4.720, dalam hitungan menit. Enam media memberi nol: Telisik, Sibernas, dan
Sultra TV memblokir `/wp-json/` lewat robots, sedangkan Tempo, Detik, dan
Portal.id bukan WordPress atau nasional.

Inilah pemakaian WP REST yang tepat. Untuk artikel tunggal ia justru tiga kali
lebih lambat daripada mengunduh halamannya, tapi di sini satu permintaan
menggantikan lima puluh, dan itu jauh lebih ringan bagi situs medianya.

Dua hal yang muncul saat membangunnya:

1. **Logika pasca-ekstraksi dipindah ke `PenyelesaiArtikel`.** Sidik jari,
   deduplikasi, dan penerusan ke analisis dulu hanya ada di `AmbilIsiArtikel`.
   Menyalinnya ke backfill berarti dua jalur yang bisa menyimpang diam-diam,
   dan deduplikasi ada di daftar yang tidak boleh dipangkas.
2. **Bug transaksi PostgreSQL.** Menangkap pelanggaran unique lalu melanjutkan
   hanya bekerja di luar transaksi: begitu ada transaksi pembungkus, satu
   statement gagal meracuni seluruhnya dan penarikan berhenti di URL duplikat
   pertama. Diperbaiki dengan membungkus penyisipan dalam transaksi bersarang
   (SAVEPOINT).

## Sprint 4: dashboard eksekutif dan kontrak

Selesai. Panel eksekutif lima halaman, CRUD kontrak dengan pencocokan pemuatan
otomatis, CRUD pengguna, dan ekspor Excel.

**Definition of done terpenuhi dan terukur** pada korpus 4.806 artikel:

| Halaman | Waktu server | Kueri |
|---------|--------------|-------|
| `/eksekutif` | **123 ms** | 14 |
| `/eksekutif/sentimen` | 34 ms | 12 |
| `/eksekutif/isu` | 15 ms | 4 |
| `/eksekutif/media` | 12 ms | 4 |
| `/eksekutif/berita` | 27 ms | 8 |

Angka dashboard cocok persis saat dihitung ulang manual dari tabel artikel:
1.370 artikel, 53 negatif, 797 positif. Sama dari tabel ringkasan maupun dari
penghitungan langsung.

### Word cloud tidak dibangun: terhalang dependensi

Dokumen 04 bagian A.10 menyebut `echarts-wordcloud`. Paket itu, sampai versi
terbarunya (2.1.0), menuntut `echarts ^5.0.1`, sedangkan `vue-echarts@8` yang
dipakai starter kit menuntut `echarts ^6.0.0`. Keduanya saling meniadakan;
tidak ada kombinasi yang bisa dipasang tanpa menurunkan keduanya.

Penggantinya sudah ada di halaman isu: daftar "naik tajam" beserta skor
lonjakan, ditambah tabel seluruh istilah yang bisa diurutkan, masing-masing
membawa nada dominan dan tautan ke arsip berita tersaring. Dokumen 04 bagian
C.5 sendiri mengganti word cloud dengan daftar sepuluh teratas di layar sempit,
dengan alasan word cloud tidak terbaca di sana.

Tiga pilihan, perlu diputuskan manusia:

1. Terima daftar peringkat sebagai pengganti tetap.
2. Turunkan `echarts` ke 5.x dan `vue-echarts` ke 7.x. Seluruh grafik lain harus
   diuji ulang, demi satu visual yang dokumennya sendiri sebut tidak terbaca di
   ponsel.
3. Tunggu `echarts-wordcloud` mendukung echarts 6.

## Sprint 5: portal media dan alert

Definition of done terpenuhi: pengguna media bisa melaporkan pemuatan dan admin
memverifikasinya, aturan alert dinilai dan terkirim beserta pembatas
pengiriman berulangnya, dan empat test wajib hijau. **206 tes, 821 asersi.**

### Yang diuji, bukan yang diasumsikan

Scoping peran media adalah satu-satunya fitur yang kebocorannya jadi masalah
dengan pihak luar, jadi ia diuji dari dua arah: model (`Kontrak::count()` dan
`Pemuatan::count()` sebagai pengguna media A) dan halaman (`/portal/kontrak`
hanya memuat kontrak sendiri). Melapor ke `kontrak_id` milik media lain
mengembalikan 404, bukan tersimpan diam-diam.

Test wajib nomor 3 ditulis ulang. Sebelumnya berupa daftar enam rute yang
diketik tangan, dan daftar seperti itu berhenti mencerminkan aplikasi pada rute
pertama yang lupa ditambahkan. Sekarang seluruh rute tulis di grup `admin/`
dibaca dari router. Parameter yang belum punya data uji membuat tes **gagal**,
bukan dilewati, sehingga resource baru memaksa perakitan datanya ditulis.
Cakupannya naik dari 6 rute ke 24. Perubahan ini langsung menemukan bahwa
`Route::resource('media')` menunggalkan parameternya menjadi `{medium}`.

### Terukur pada korpus 4.806 artikel

| Halaman | Waktu server | Kueri |
|---|---|---|
| `/portal` | 67 ms | 10 |
| `/portal/berita` | 22 ms | 6 |
| `/portal/kontrak` | 9 ms | 6 |
| `/portal/lapor` | 8 ms | 5 |
| `/admin/pemuatan` | 7 ms | 5 |
| `/admin/entitas` | 20 ms | 5 |
| `/admin/alert` | 16 ms | 5 |

Pencocokan entitas atas seluruh korpus: **14 detik untuk 4.137 artikel**, 3.279
di antaranya memuat setidaknya satu entitas dari kamus tujuh entitas awal.

| Entitas | Artikel | Sebutan |
|---|---|---|
| Kota Kendari | 2.715 | 28.093 |
| Sulawesi Tenggara | 2.185 | 11.703 |
| Pemerintah Kota Kendari | 888 | 3.849 |
| Wali Kota Kendari | 773 | 3.939 |
| Dinas Kesehatan | 105 | 221 |
| Dinas Komunikasi dan Informatika | 96 | 169 |
| Dinas Pekerjaan Umum | 79 | 127 |

### Entitas memakai kamus, bukan model NER

Dokumen 01 F-17 tidak menyebut caranya. Yang dipilih adalah pencocokan kamus
dengan alias, dan alasannya bukan kemalasan. Entitas yang dipantau Pemkot
adalah daftar tertutup dan pendek: nama pejabat, OPD, kelurahan, program.
Daftarnya sudah diketahui, berubah beberapa kali setahun, dan salah tulisnya
bisa langsung diperbaiki admin lewat alias. Model NER berbahasa Indonesia akan
menambah satu model lagi ke layanan NLP, salah mengeja nama lokal yang tidak
ada di data latihnya, dan kesalahannya tidak bisa dikoreksi tanpa melatih
ulang.

Kamus kalah pada nama yang belum terdaftar. Itu diterima: yang belum terdaftar
memang belum dipantau siapa pun.

Dua batas yang diuji: pencocokan berhenti di batas kata (tanpa itu "Kendari"
ikut terhitung di dalam "Kendarian"), dan alias di bawah tiga huruf dibuang
("PU" akan muncul di dalam ribuan kata biasa).

### Layanan arsip dipisah dari layanan NLP

`ArsipkanBuktiPemuatan` butuh Playwright, dan godaannya adalah menambahkannya
ke container `nlp` yang sudah ada. Ditolak karena alasan operasional, bukan
estetika: layanan NLP berjalan satu worker karena modelnya memakan 1,5 GB
memori, dan satu render Chromium yang memakan CPU beberapa detik akan menahan
antrean analisis sentimen di belakangnya.

Container `arsip` berisi 40 baris FastAPI di atas image resmi Playwright.
Terbukti bekerja pada situs partner sungguhan: `telisik.id` menghasilkan PNG
1280x12242 piksel.

Jobnya menurunkan mutu dengan anggun. Bukti teks dari ekstraksi jauh lebih
penting daripada gambar, dan kegagalan gambar tidak boleh membuang bukti teks
yang sudah di tangan. Kegagalan keduanya pun tetap menulis `arsip_diambil_at`,
karena halaman yang sudah mati justru kasus yang paling perlu tercatat
waktunya.

### Halaman pengaturan menampilkan nilai, tidak menyuntingnya

Dokumen 04 bagian B meminta form yang bisa mengubah ambang keyakinan dari
layar. Yang dibangun adalah jalur cadangan yang disediakan dokumen 07 sendiri
(bagian jalur pemangkasan): nilainya diubah lewat `.env`.

Alasannya bukan waktu. Ambang keyakinan mengubah setiap angka dashboard secara
surut, termasuk untuk periode yang sudah dilaporkan ke pimpinan, dan dokumen 06
bagian 5 mewajibkan perubahannya tercatat lengkap dengan nilai sebelum dan
sesudah. Menyimpannya di database berarti membangun tabel pengaturan,
invalidasi cache untuk seluruh proses worker, dan pencatatan audit tersendiri,
agar nilai yang sudah diukur dan didokumentasikan bisa diubah sambil lalu.
Lewat `.env`, perubahannya melewati deploy dan tercatat di git bersama
alasannya.

Halamannya menampilkan nilai efektif, kunci environment yang mengaturnya, dan
kolom "diukur dari" supaya angka-angka itu tidak terbaca sebagai selera.

### Bug yang ditemukan sambil jalan

**Seluruh pesan hasil aksi tidak pernah tampil.** `HandleInertiaRequests`
membagikan `flash.sukses` dan `flash.galat` sejak sprint 1, tapi tidak ada satu
komponen pun yang merendernya. Artinya setiap `->with('sukses', ...)` di
seluruh controller sprint 1 sampai 4 tidak terlihat, dan menyimpan kontrak
terasa seperti tidak terjadi apa-apa. Toast dipasang terpusat di `AppLayout`,
galat bertahan lebih lama karena isinya menyebut tindakan yang perlu diambil.

**Aturan alert yang gagal kirim mencoba lagi tiap 15 menit.** Versi pertama
hanya menyetel `dipicu_terakhir_at` saat pengiriman berhasil. Telegram yang
menolak dengan "chat not found" akan menghasilkan baris riwayat gagal yang sama
setiap kali scheduler jalan. Sekarang ditandai terpicu apa pun hasilnya, dan
ada tes yang menjaganya.

### Pengukuran pertama setelah relevansi pindah ke kemiripan makna

Diukur pada 249 label gold set konteks utama, ronde 1, setelah seluruh 4.802
artikel berisi dihitung ulang vektornya dengan `multilingual-e5-small`.

| Aturan | Presisi | Recall | F1 |
|---|---|---|---|
| Semua dianggap relevan | 26,1% | 100% | 0,414 |
| Model lama, IndoBERT relevansi ditambah pengetat | 57,0% | 93,8% | 0,709 |
| Pengetat kata kunci saja | 56,0% | 93,8% | 0,701 |
| Cosine >= 0,84 saja | 56,5% | 80,0% | 0,662 |
| Pengetat dan cosine >= 0,83 | 62,8% | 90,8% | **0,742** |
| Pengetat dan cosine >= 0,84 | 70,8% | 78,5% | **0,745** |
| Pengetat dan cosine >= 0,845 | 78,9% | 69,2% | 0,738 |

**Model relevansi lama ternyata hampir tidak menyumbang apa-apa.** Pengetat kata
kunci sendirian mencapai 56,0% presisi dengan recall 93,8%; menambahkan
IndoBERT relevansi di atasnya hanya menaikkan presisi satu poin, ke 57,0%,
dengan recall yang sama persis. Selama ini yang bekerja adalah aturan kata
kuncinya, bukan modelnya. Melepas model itu praktis tidak berbiaya.

**Cosine menyumbang sinyal yang benar-benar baru.** Digabung dengan pengetat,
F1 naik dari 0,709 ke 0,745, dan itu kenaikan yang tidak bisa dicapai oleh
keduanya sendiri-sendiri.

**Target belum tercapai.** Presisi 80% dengan recall 85% tidak terjangkau pada
data ini: menaikkan ambang ke 0,86 memang membawa presisi ke 78,6% tapi recall
runtuh ke 16,9%. Titik terbaik yang masuk akal sekarang ada di sekitar 0,83
sampai 0,84.

Tiga peringatan yang harus dibaca bersama angka di atas:

1. **Tidak ada data tahan.** Ambang di tabel ini dipilih dengan melihat seluruh
   249 label, jadi angkanya optimistis. Ambang produksi harus dipilih dari
   validation set dan dilaporkan dari test set beku, sesuai dokumen 05 bagian 5.1.
2. **Labelnya dibuat dengan aturan lama.** Gold set ini dilabeli saat masih ada
   tiga konteks. Sebagian keputusannya bisa berubah di bawah definisi konteks
   tunggal, dan pengukuran ini perlu diulang setelah pelabelan ulang di sprint 6
   fase 3.
3. **Sebaran skornya rapat.** Artikel relevan bermedian 0,848 dan tidak relevan
   0,829, dengan rentang yang hampir seluruhnya bertumpang tindih. Ini wajar
   untuk e5, yang skornya memang terkumpul di kisaran sempit, tapi berarti
   ambangnya peka: selisih 0,005 menggeser presisi belasan poin.

Kesimpulan sementara: perpindahan ini menguntungkan, tapi bukan karena e5 lebih
pintar dari IndoBERT. Ia menguntungkan karena satu model bisa dilepas tanpa
kehilangan apa pun, dan karena skornya kini bisa disetel ulang tanpa inferensi.
Yang menaikkan presisi ke angka yang layak masih pekerjaan gold set, bukan
pekerjaan model.

### Hasil setelah ambang dipasang dan korpus dinilai ulang

Ambang 0,84 dan 0,83 dipasang, lalu seluruh 4.137 artikel asli dinilai ulang.
Hasil `evaluasi:model` atas 250 label gold set konteks utama:

| Metrik relevansi | Model lama | Sekarang |
|---|---:|---:|
| Presisi | 57,0% | **69,9%** |
| Recall | 93,8% | 78,5% |
| F1 | 0,709 | **0,739** |
| Salah dianggap relevan | 107 | **22** |
| Relevan yang terlewat | 4 | 14 |

| Metrik sentimen | Nilai |
|---|---:|
| F1 macro | 0,7375 |
| Akurasi | 79,0% |
| F1 negatif | 0,889 (16 sampel) |
| F1 netral | 0,500 (6 sampel) |
| F1 positif | 0,824 (31 sampel) |

Sebaran korpus setelah penilaian ulang, dari 4.137 artikel asli:

| Hasil | Jumlah | Porsi |
|---|---:|---:|
| Relevan, sudah dinilai sentimennya | 1.115 | 27,0% |
| Perlu review, menunggu manusia | 746 | 18,0% |
| Tidak relevan | 2.276 | 55,0% |

Artikel yang salah masuk dashboard turun dari 107 menjadi 22 pada gold set.
Harganya sepuluh artikel relevan yang kini terlewat, dan itu pertukaran yang
disengaja: artikel keliru yang lolos akan terlihat pimpinan dan merusak
kepercayaan pada seluruh angka, sedangkan artikel yang terlewat hilang tanpa
terlihat. Meski begitu, yang terlewat tidak boleh dianggap gratis, dan 746
artikel di antrean review adalah tempat sebagiannya bisa diselamatkan.

**Presisi 69,9% masih di bawah target 80%.** Itu sudah diperkirakan. Yang
menaikkannya bukan lagi pekerjaan model melainkan pekerjaan gold set:
pelabelan ulang dengan definisi konteks tunggal, ditambah hard negative untuk
artikel Pemprov, instansi vertikal, dan Kendari sebagai lokasi. Sprint 6 fase 3.

### Keputusan yang diambil dari angka ini, 4 Agustus 2026

Kalimat terakhir di atas dijadikan rencana, dan rencananya menjadi sprint 8.

Kalau yang menaikkan presisi memang pekerjaan dataset, maka yang perlu dibangun
adalah alat untuk mengerjakan dataset, bukan penilai relevansi ketiga. Itulah
isi dokumen 10: pelabelan yang cepat dan tercatat, snapshot yang bisa
direproduksi, test set yang terkunci, dan evaluasi yang bisa menunjuk jenis
kesalahannya. Fine-tuning adalah muara dari alat itu, bukan penggantinya.

Tiga akibat yang diputuskan bersamaan:

1. **Relevansi berpindah ke classifier hasil fine-tuning.** Bukan pembatalan
   atas kesimpulan di atas. Yang dulu diukur adalah checkpoint bawaan tanpa
   pelatihan, dan itu memang hanya menambah satu poin. Yang dilatih dengan
   label Kendari sendiri belum pernah diukur sama sekali.
2. **e5-small turun menjadi pendeteksi salinan.** Perannya di relevansi selesai.
   Deduplikasi tidak berubah, dan pengukuran ulang `DEDUP_AMBANG_COSINE` tetap
   utang yang harus dibayar.
3. **Sentimen diblokir sampai gerbang mutu lulus.** Ini yang paling mahal, dan
   diambil dengan sadar. Tiga dari sepuluh artikel di dashboard tidak membahas
   Pemkot, dan menampilkan analisis nada atas kumpulan itu berarti melaporkan
   angka yang sudah diketahui salah. Dashboard yang kosong bisa dijelaskan,
   dashboard yang salah tidak.

Angka di seluruh bagian ini tetap dipertahankan apa adanya. Ia menjadi
pembanding dasar: model hasil fine-tuning yang tidak mengalahkan presisi 69,9%
tidak layak dipromosikan, dan tanpa catatan ini tidak akan ada yang tahu
apakah pekerjaan berbulan-bulan itu benar-benar menghasilkan sesuatu.

### Dua bug yang ditemukan sambil merapikan

**Kata kunci halaman isu hangat tidak pernah dihitung ulang dengan benar.**
`PenghitungKataKunci` memakai `upsert`, sehingga istilah yang tidak lagi lolos
saringan tidak pernah terhapus. Ketika daftar kata umum diperpanjang, "melalui"
dan "serta" tetap duduk di peringkat teratas selamanya karena tidak ada baris
baru yang menimpanya. Sekarang baris periode dihapus lebih dulu, lalu ditulis
ulang. Halaman yang melaporkan "melalui" sebagai isu yang sedang naik tidak
akan dipercaya untuk hal lain apa pun.

**Daftar kata umum terlalu pendek untuk korpus 4.800 artikel.** Enam istilah
teratas semuanya kata sambung. Daftarnya diperpanjang dan angka murni dibuang,
karena "2026" muncul di hampir setiap berita dan selalu naik ke puncak padahal
tahun bukan isu.

## Yang masih menunggu pekerjaan manusia

1. **Ronde 2 gold set.** 40 baris acak dilabeli ulang seminggu setelah ronde 1
   tanpa melihat label lama, untuk mengukur konsistensi pelabel. Angkanya
   adalah batas atas yang wajar diminta dari model, kalau manusia hanya
   konsisten 82% dengan dirinya sendiri, F1 0,74 justru hasil yang baik.
   Tampil sendiri di `/admin/evaluasi`.
2. ~~**Perketat kata kunci dua konteks.**~~ Diserap sprint 6. Mempersempit
   "dinas", "opd", "pasar", dan "sampah" tetap dikerjakan, tapi bukan lagi
   sebagai tambalan atas dua konteks yang akan dinonaktifkan. Kata kunci yang
   sama pindah ke konteks utama beserta aturan inklusi dan eksklusinya.
3. ~~**Label kelas negatif untuk Wali Kota.**~~ Hilang dengan sendirinya.
   Konteks itu dinonaktifkan di sprint 6, dan artikel tentang Wali Kota tetap
   terlacak lewat entitas. Kelas negatif kini hanya perlu cukup sampel pada
   satu konteks, bukan tiga.
4. **Chat ID grup Telegram Diskominfo.** Seluruh jalur alert sudah jadi dan
   teruji, termasuk pencatatan kegagalan pengiriman. Yang kosong hanya
   `TELEGRAM_BOT_TOKEN` dan `TELEGRAM_CHAT_ID`. Isi di `.env`, lalu tekan
   "Kirim pesan uji" di `/admin/alert` sebelum membuat aturan pertama.
   Halaman itu menampilkan peringatan selama keduanya masih kosong, karena
   aturan yang terpicu benar tanpa penerima adalah kegagalan diam.
5. **30 akun media dan sosialisasi portal.** `pengguna:buat-akun-media`
   membuat satu akun per media aktif dan mencetak kata sandinya sekali.
   Menjalankannya, mengirim kredensial lewat kanal yang aman, dan memastikan
   tiap media berhasil melapor sekali adalah pekerjaan manusia. Google Form
   lama dimatikan setelah itu, bukan sebelumnya.

## Posisi saat ini

**Sprint 0 sampai 3 selesai.** Definition of done sprint 3 tercapai sepenuhnya:
seluruh artikel punya skor sentimen per konteks relevan, ada baris
`evaluasi_model` dengan F1 macro terukur (**0,7361**, di atas gerbang 0,65), dan
ambang keyakinan ditetapkan dari data.

Korpus 4.806 artikel dari 27 media, 4.137 dianalisis penuh, 665 (13,8%)
terdeteksi salinan. Gold set ronde 1 berisi 470 label dengan ketiga konteks
melewati 50 label relevan.

**Sprint 0 sampai 5 selesai.** Sprint 6 berikutnya: penyederhanaan relevansi.
Baru setelah itu sprint 7, pemantapan dan serah terima.

Urutannya sengaja begitu. Pengetatan relevansi mengubah setiap angka yang sudah
tampil di dashboard, termasuk untuk periode yang sudah dilihat orang, dan lebih
baik itu terjadi sekali sebelum serah terima daripada sebulan sesudahnya.

Ronde 2 gold set dan keputusan word cloud masuk ke sprint 6, bukan lagi
pekerjaan menggantung.

Layanan NLP (`nlp/`) sudah ada dan berjalan. Dokumen `05-spesifikasi-nlp.md`
**akhirnya ditulis** pada revisi 1.4, termasuk rencana alternatif kalau F1
macro di bawah 0,65 (bagian 8) yang selama ini dirujuk tapi tidak pernah ada.
Panduan pelabelan tetap di `09-panduan-pelabelan.md`.

### Hasil evaluasi final (470 label gold set, ronde 1)

Gold set lengkap: 249 label di konteks utama, 97 di Wali Kota, 124 di Pelayanan
publik, masing-masing melewati 50 label relevan.

**Sentimen: lolos gerbang.** F1 macro **0,7361**, akurasi 78,7%, dari 174
sampel relevan.

| Kelas | F1 | Sampel |
|-------|-----|--------|
| Negatif | 0,810 | 20 |
| Netral | 0,543 | 21 |
| Positif | 0,856 | 133 |

F1 macro **turun** dari 0,7998 ke 0,7361, dan itu justru perbaikan: angka lama
disokong F1 negatif 1,000 dari tiga sampel, yang bukan pengukuran. Sekarang
kelas negatif punya 20 sampel dan F1-nya 0,810, angka yang bisa
dipertanggungjawabkan.

**Kesalahan utama model: 28 artikel positif ditebak netral.** Presisi kelas
netral hanya 39% karena model membuang hasil yang ragu ke sana. Arahnya meredam,
bukan melebih-lebihkan, relatif aman untuk peringatan dini, tapi berarti angka
positif di dashboard cenderung dikecilkan.

**Akurasi seragam antar konteks:** 79,0% / 78,5% / 78,7%. Model tidak pilih
kasih terhadap konteks tertentu.

**Presisi relevansi justru timpang, dan angka gabungan menyembunyikannya:**

| Konteks | Sampel | neg/net/pos | Akurasi | Presisi relevansi |
|---------|--------|-------------|---------|-------------------|
| Pemerintah Kota Kendari | 62 | 16/6/40 | 79,0% | 57,0% |
| Wali Kota Kendari | 65 | 0/1/64 | 78,5% | **87,7%** |
| Pelayanan publik dan infrastruktur | 47 | 4/14/29 | 78,7% | **51,1%** |

Gabungannya 63,2%, tidak menggambarkan satu pun dari ketiganya. Penyebabnya
daftar kata kunci: frasa spesifik seperti "wali kota kendari" jarang muncul
kebetulan, sedangkan "dinas", "pasar", atau "sampah" sering muncul sambil lalu.
Dua konteks dengan kata kunci umum perlu diperketat lewat `/admin/konteks`.

**F1 macro per konteks tidak boleh dibaca apa adanya.** Wali Kota tercatat
0,3368 padahal akurasinya 78,5%, gold set-nya tidak punya satu pun sampel
negatif, dan F1 macro merata-ratakan tiga kelas dengan bobot sama. Command dan
halaman evaluasi kini menandai kasus ini dengan tanda bintang beserta
penjelasannya.

**Catatan tentang recall relevansi (95,6%).** Angka ini terlalu optimistis:
sebagian besar label tambahan dikumpulkan lewat mode terarah "kemungkinan
relevan", yang hanya menampilkan artikel yang model anggap relevan, jadi
artikel relevan yang model lewatkan hampir tidak pernah terlihat pelabel.
Presisi tidak terkena bias ini.

### Hasil evaluasi pertama (254 label gold set, ronde 1)

**Sentimen: lolos gerbang.** F1 macro **0,7998**, akurasi 81,3%, dari 48 sampel
relevan di konteks utama.

| Kelas | F1 | Sampel |
|-------|-----|--------|
| Negatif | 1,000 | **3** |
| Netral | 0,526 | 5 |
| Positif | 0,873 | 40 |

Angka 0,7998 harus dibaca dengan dua peringatan:

1. **F1 negatif sempurna dari tiga sampel bukan pengukuran.** Ia menaikkan F1
   macro yang merata-ratakan tiga kelas dengan bobot sama. Tanpa kelas itu,
   rata-rata netral dan positif hanya **0,70**, masih lolos, tapi marginnya
   jauh lebih tipis daripada yang terlihat.
2. **Kesalahan utama model: 9 artikel positif ditebak netral.** Model cenderung
   meredam, bukan melebih-lebihkan. Untuk sistem peringatan dini itu arah yang
   relatif aman, tapi berarti angka positif di dashboard cenderung dikecilkan.

**Relevansi: sempat tidak terukur sama sekali, lalu diperbaiki.**

| Metrik | Model saja | Setelah pengetat |
|--------|-----------|------------------|
| Presisi | 46,6% | **75,4%** |
| Recall | 92,3% | 88,5% |
| F1 | 0,619 | **0,814** |
| Salah dianggap relevan | 55 dari 254 | **15** |
| Relevan yang terlewat | 4 dari 254 | 6 |

Sebelum diperbaiki, separuh artikel yang masuk grafik sebenarnya tidak membahas
konteksnya. Biayanya dua artikel relevan tambahan yang terlewat, pertukaran
yang sepadan, tapi perlu diingat: artikel yang terlewat hilang dari analisis
tanpa jejak, sedangkan artikel yang lolos keliru masih bisa dikoreksi admin
lewat halaman detail.

**Menaikkan ambang keyakinan tidak menolong.** Diuji 0,55 sampai 0,999: presisi
hanya bergerak 47,4% → 48,1%. Median keyakinan pada keputusan yang benar dan
yang salah sama-sama 1,000, model sama yakinnya saat keliru.

Penyebabnya definisi, bukan kalibrasi: model menganggap artikel yang menyebut
Kendari sekali lewat sebagai relevan, sedangkan panduan pelabelan menghitungnya
tidak relevan (pertanyaan nomor 1).

**Sudah diperbaiki dengan pengetat berbasis frekuensi kata kunci.** Sepuluh
varian diuji terhadap 254 label manusia, dengan **separuh data ditahan dan tidak
pernah dilihat saat memilih**:

| Aturan | Presisi | Recall | F1 |
|--------|---------|--------|-----|
| Model apa adanya | 54,2% | 100% | 0,703 |
| Kata kunci di judul saja | 92,3% | 46,2% | 0,615 |
| Kata kunci di judul ATAU 400 huruf awal | 65,1% | 78,8% | 0,713 |
| **Kata kunci di judul ATAU ≥3× di isi** | **80,0%** | **92,3%** | **0,857** |
| Kata kunci di judul ATAU ≥4× di isi | 80,0% | 76,9% | 0,784 |

Varian ≥4× terlihat lebih baik pada separuh yang dipakai memilih (F1 0,830) tapi
lebih buruk pada data tahan (0,784), tanda overfit. Tanpa data tahan, varian yang
salah yang akan dipasang.

Hipotesis awal "kata kunci di paragraf pertama" juga kalah (0,713). Frekuensi
lebih menentukan daripada posisi.

Disetel lewat `RELEVANSI_MINIMAL_SEBUTAN`, bawaan 3. Konteks tanpa kata kunci
tidak diketatkan sama sekali.

**Bug yang ditemukan bersamaan:** `RELEVANSI_AMBANG_KEYAKINAN` ada di `.env`,
di `config/nlp.php`, di dokumen 02, dan ditampilkan di halaman detail artikel,
tapi tidak pernah diterapkan di mana pun. Relevansi diputuskan argmax murni.
Karena pengujian menunjukkan ambang tidak berpengaruh pada model ini, nilainya
dibiarkan tapi statusnya dicatat di sini agar tidak dikira sudah bekerja.

### Audit feed 30 media (tugas sprint 0)

27 dari 30 punya feed hidup, seluruhnya `/feed` bawaan WordPress kecuali
Telisik yang memakai `/feed/rss`. Daftarnya disimpan di `SumberFeedSeeder`.

| Media | Masalah | Jalur pengganti |
|-------|---------|-----------------|
| Tempo | Tidak ada feed yang bisa dipakai | Belum ada, lihat catatan Google News |
| Detikcom | Tidak ada feed yang bisa dipakai | Belum ada, lihat catatan Google News |
| Sibernas | Tidak ada feed di jalur lazim maupun tautan halaman depan | Portal pelaporan mandiri (sprint 5) |

**F-05 terpenuhi lewat jalur lain.** `news.google.com/robots.txt` melarang
seluruh path untuk `User-agent: *`, termasuk `/rss/search`, sehingga jalur
Google News tertutup selama kewajiban menghormati robots dipegang. Sumbernya
tetap ada di database tapi dinonaktifkan beserta alasannya.

Penggantinya: **feed milik media nasional sendiri, disaring kata kunci sebelum
artikel disimpan**, cara yang sudah diantisipasi dokumen 01 lampiran A catatan
1. Keduanya diuji dan tidak melarang bot ini:

| Sumber | Item per tarikan | Saringan |
|--------|------------------|----------|
| `rss.tempo.co/nasional` | 50 | `Kendari` |
| `news.detik.com/rss` | 100 | `Kendari` |

Penyaringan dilakukan dari judul dan ringkasan feed, bukan dari isi halaman:
mengunduh seratus artikel nasional untuk membuang sembilan puluh delapan justru
banjir yang mau dihindari. Feed yang seluruh isinya tersaring tidak dihitung
sebagai kegagalan, hari tanpa liputan Kendari itu wajar, dan menghitungnya
gagal akan menonaktifkan sumbernya setelah lima hari sepi.

Sibernas tetap tanpa feed; jalurnya portal pelaporan mandiri di sprint 5.

Temuan pengukuran yang mengubah nilai awal di dokumen:

| Nilai | Dokumen | Dipakai | Alasan |
|-------|---------|---------|--------|
| `DEDUP_AMBANG_SIMHASH` | 4 | 12 | Near-duplicate terukur 8-10 bit, berita berbeda 30-34 bit |
| `SENTIMEN_AMBANG_KEYAKINAN` | 0,60 | 0,90 | Sebaran keyakinan bimodal: 0,60-0,67 lalu kosong lalu ≥0,998 |
| `app.timezone` | Asia/Makassar | UTC | WITA menggeser setiap timestamp 8 jam; konversi lewat `App\Support\Waktu` |
