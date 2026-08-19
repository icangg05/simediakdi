# 07: Roadmap dan Rencana Sprint

SIMAK Kendari | Versi 1.0

---

## Asumsi perencanaan

Rencana ini mengasumsikan satu pengembang bekerja **20 jam per minggu** pada proyek ini. Kalau Anda bisa memberi 40 jam, bagi dua durasinya. Kalau hanya 10 jam, kalikan dua dan jangan kaget.

Satu sprint = dua minggu = sekitar 40 jam kerja.

Angka yang saya pakai sudah menyertakan waktu untuk hal yang tidak terlihat di daftar tugas: debugging, membaca dokumentasi, memperbaiki lingkungan lokal, dan mengerjakan ulang hal yang salah pada percobaan pertama. Kalau Anda merasa estimasinya berlebihan, itu memang niatnya. Estimasi solo yang optimistis adalah penyebab utama proyek sampingan tidak selesai.

**Total: 9 tahapan, sprint 0 sampai 8.** Sprint 6 ditambahkan setelah hasil
evaluasi sprint 3 menunjukkan rancangan tiga konteks tidak bisa mencapai
presisi yang layak. Sprint pemantapan bergeser menjadi sprint 7.

Sprint 8 ditambahkan 4 Agustus 2026 dan **durasinya sengaja tidak ditetapkan**.
Ia membangun Laboratorium Model Relevansi di dokumen 10, dan bagian terbesarnya
bukan menulis kode melainkan melabeli beberapa ribu artikel. Menetapkan dua
minggu untuk pekerjaan itu berarti menjanjikan sesuatu yang tidak dikendalikan
kecepatan mengetik. Yang ditetapkan adalah gerbang per fase, bukan tanggal.

Urutan pengerjaannya juga bukan 7 lalu 8. Sprint 7 adalah serah terima, dan
serah terima menunggu sistem yang angkanya bisa dipertanggungjawabkan. Sprint 8
dikerjakan lebih dulu.

## Sprint 0: Persiapan (1 minggu)

Bukan sprint penuh, tapi jangan dilewati.

- [x] ~~Dapatkan jawaban atas sembilan pertanyaan di dokumen 01 bagian 9~~, sudah terjawab, lihat dokumen 01 bagian 9
- [ ] Uji satu per satu URL RSS dari 30 media di lampiran A dokumen 01, catat mana yang hidup. Ikuti tabel strategi per jenis situs di dokumen 02: Blogger selalu punya feed bawaan, situs PHP dicek di path umum, situs SPA tanpa feed dicatat sebagai pengguna jalur portal. Untuk Tempo, Detikcom, dan Portal.id jangan pakai feed utuh, cukup feed daerah atau Google News berkata kunci
- [ ] Kalau memungkinkan, minta daftar sumber feed dari Diskominfo atau instansi yang sudah menjalankan agregasi media Sultra. Daftar yang sudah teruji menghemat pekerjaan paling membosankan di sprint ini
- [ ] Verifikasi kedua model IndoBERT bisa diunduh dan dijalankan, sesuai dokumen 05 bagian 1
- [ ] Uji manual 20 artikel Kendari terhadap model sentimen. **Kalau akurasinya di bawah 60%, hentikan dan baca dokumen 05 bagian 8 sebelum lanjut**
- [ ] Siapkan repo, VPS, domain, dan sertifikat
- [ ] Instal Laravel Vue Starter Kit, PostgreSQL dengan ekstensi vector, dan Redis

Kriteria selesai: aplikasi kosong bisa dibuka di domain produksi lewat HTTPS, dan skrip Python bisa memberi label sentimen pada satu artikel dari terminal.

Pemeriksaan model di awal ini adalah bagian terpenting dari sprint 0. Kalau ternyata modelnya tidak cocok, Anda mengetahuinya pada minggu pertama dengan biaya beberapa jam, bukan pada minggu kesembilan setelah seluruh pipeline dibangun.

---

## Sprint 1: Fondasi dan CRUD (2 minggu)

Tanpa NLP, tanpa grafik. Tujuannya menyiapkan alat yang mempercepat lima sprint berikutnya.

**Backend**
- [ ] Seluruh migration dari dokumen 03, sekaligus. Jangan bertahap
- [ ] Model Eloquent, relasi, enum, dan seeder: 30 media dari lampiran A dokumen 01, dan tiga konteks pantauan dari dokumen 01 bagian 9
- [ ] Enum peran, middleware `peran`, middleware `tolak.tulis`
- [ ] Global scope pada model yang punya `media_id`
- [ ] CRUD media dan sumber feed

**Frontend**
- [ ] Tiga layout: LayoutAdmin, LayoutEksekutif, LayoutPortal
- [ ] Token warna sentimen di `app.css`
- [ ] Pasang komponen shadcn gelombang pertama sesuai dokumen 04 bagian A.8
- [ ] **`DataTable.vue` beserta toolbar, paginasi, dan faceted filter**
- [ ] `BadgeSentimen.vue`, `KeadaanKosong.vue`, `useFormatAngka.ts`
- [ ] Halaman daftar dan form media serta sumber feed

Definition of done: superadmin bisa login dengan 2FA, menambahkan media dan sumber RSS, lalu melihatnya di tabel yang bisa difilter, disortir, dan dipaginasi lewat URL.

Prioritas dalam sprint ini adalah `DataTable.vue`. Kerjakan sampai benar-benar nyaman dipakai sebelum menyentuh halaman lain. Sembilan halaman bergantung padanya, dan setiap kekurangan di komponen ini akan Anda tanggung sembilan kali.

---

## Sprint 2: Crawler (2 minggu)

Sprint ini menghasilkan hal yang bisa didemokan.

- [ ] `PembacaRss`, `NormalisasiUrl`, `EkstraktorArtikel` dengan readability.php
- [ ] Command `crawl:feeds` dengan `withoutOverlapping()`
- [ ] Job `AmbilIsiArtikel` dengan retry dan backoff
- [ ] Deduplikasi lapis 1 dan 2, yaitu URL kanonik dan simhash
- [ ] Log crawl dan penonaktifan otomatis sumber setelah 5 kegagalan
- [ ] Validasi URL anti-SSRF sesuai dokumen 06 bagian 7
- [ ] Command `crawl:google-news`
- [ ] Halaman daftar artikel dengan filter media, tanggal, dan status dedup
- [ ] Halaman log crawl
- [ ] Dashboard admin dengan KPI dasar, `IndikatorKesehatan`, dan kartu proporsi sumber data
- [ ] Konfigurasi supervisor untuk worker queue

Definition of done: crawler berjalan otomatis selama 72 jam tanpa intervensi, dan tabel artikel terisi dari minimal 10 media dengan duplikat yang tertandai benar.

Sebelum menutup sprint ini, periksa manual 50 artikel. Bandingkan judul dan isi hasil ekstraksi dengan halaman aslinya. Ekstraksi yang buruk pada tahap ini akan merusak seluruh analisis di sprint berikutnya, dan kesalahannya sulit dilacak kalau ditemukan belakangan.

---

## Sprint 3: NLP dan gold set (2 minggu)

Sprint terberat. Anggarkan waktu lebih dan jangan menumpuk tugas lain di dua minggu ini.

- [ ] Layanan FastAPI dengan tiga endpoint sesuai dokumen 02 bagian 6
- [ ] `KlienNlp` di sisi Laravel
- [ ] Job `HitungEmbedding`, deduplikasi lapis 3 dengan pgvector
- [ ] Penyaring kata kunci per konteks
- [ ] Job `AnalisisRelevansi` dan `AnalisisSentimen`
- [ ] Logika `perlu_review` dan kolom generated `label_efektif`
- [ ] CRUD konteks pantauan
- [ ] Halaman detail artikel dengan form koreksi label
- [ ] **Ruang kerja pelabelan sesuai dokumen 04 bagian C.3, lengkap dengan pintasan keyboard**
- [x] Tulis panduan pelabelan. Ditulis sebagai dokumen 09, dan perlu diperbarui lagi di sprint 6 karena pertanyaannya berubah dari tiga konteks menjadi satu keputusan relevansi
- [x] **Labeli 400 baris gold set.** Dikerjakan pengembang sendiri (dokumen 01 bagian 9 nomor 7), sekitar 8 jam dan sudah termasuk dalam anggaran sprint ini. Terlaksana 470 label terhadap tiga konteks. Hasilnya yang memicu perombakan di sprint 6: pembagian ke tiga konteks membuat tiap konteks kekurangan sampel per kelas, dan dua di antaranya tidak mencapai presisi yang layak
- [ ] Command `evaluasi:model`, halaman hasil evaluasi
- [ ] Setel ambang keyakinan berdasarkan hasil gold set
- [ ] Setel ambang deduplikasi dengan memeriksa 100 pasangan manual
- [ ] Command `hitung:ringkasan-harian`

Definition of done: seluruh artikel punya skor sentimen per konteks yang relevan, ada satu baris di tabel `evaluasi_model` dengan F1 macro terukur, dan ambang keyakinan sudah ditetapkan berdasarkan data bukan tebakan.

Bangun ruang kerja pelabelan **sebelum** mulai melabeli. Terasa seperti menunda pekerjaan sesungguhnya, tapi melabeli 400 baris lewat halaman admin biasa memakan waktu tiga kali lebih lama dan Anda akan berhenti di baris ke-120.

Kalau F1 macro di bawah 0,65, hentikan sprint 4 dan kerjakan alternatif di dokumen 05 bagian 8 lebih dulu. Membangun dashboard di atas model yang tidak akurat berarti membangun sesuatu yang akan diabaikan.

---

## Sprint 4: Dashboard eksekutif dan kontrak (2 minggu)

- [ ] `BaseChart.vue` dan `useTemaChart.ts`
- [ ] Lima komponen grafik: tren volume, tren sentimen, donat, peringkat media, word cloud
- [ ] Tombol "Lihat sebagai tabel" pada setiap grafik
- [ ] `KartuKpi.vue`, `PemilihRentangTanggal.vue`, `PemilihKonteks.vue`
- [ ] Dashboard eksekutif sesuai wireframe di dokumen 04 bagian C.1
- [ ] Halaman sentimen, isu hangat, peringkat media, dan arsip berita
- [ ] Command `hitung:kata-kunci` dengan skor lonjakan
- [ ] CRUD kontrak dan `ProgresKontrak.vue`
- [ ] Pencocokan otomatis artikel ke pemuatan kontrak
- [ ] CRUD pengguna
- [ ] Ekspor Excel

Definition of done: dashboard eksekutif selesai render di bawah 2 detik pada koneksi 4G, terbaca di layar 375 piksel, dan angkanya cocok saat dihitung ulang manual dari tabel artikel.

Uji halaman ini di ponsel sungguhan, bukan hanya di device toolbar browser. Ukuran sentuh, kecepatan jaringan nyata, dan perilaku scroll berbeda, dan halaman inilah yang paling menentukan apakah sistem dianggap berhasil.

---

## Sprint 5: Portal media dan alert (2 minggu)

- [x] Dashboard portal, halaman berita saya, kontrak saya
- [x] Halaman lapor sesuai wireframe dokumen 04 bagian C.6: tempel URL, pratinjau, konfirmasi, dukungan banyak URL, jalur cadangan isian manual saat ekstraksi gagal
- [x] Daftar "sudah tercatat otomatis" di atas form lapor
- [x] Job `ArsipkanBuktiPemuatan` dengan Playwright untuk tangkapan layar
- [x] Validasi domain URL terhadap media pelapor
- [x] `ArtikelPortalResource` tanpa field sentimen
- [x] Antrean verifikasi pemuatan di panel admin
- [ ] Buat 30 akun media dan sosialisasikan portal. **Perintahnya siap (`pengguna:buat-akun-media`), pembuatan dan sosialisasinya butuh manusia.** Google Form dimatikan setelah semua media punya akun dan berhasil melapor sekali
- [x] CRUD aturan alert, command `alert:periksa`, `PengirimTelegram`. **Chat ID grup masih kosong, satu-satunya bagian yang tersisa.** Minta chat ID grup Telegram Diskominfo di awal sprint ini, isi ke `.env`, dan uji kirim satu pesan sebelum menulis logika aturan
- [x] Riwayat alert dan pembatas pengiriman berulang
- [x] Command `kontrak:periksa-tenggat`
- [x] CRUD entitas dengan aksi gabungkan, pencocokan kamus entitas
- [x] Halaman pengaturan sistem. Menampilkan nilai efektif, penyuntingannya lewat `.env` sesuai jalur pemangkasan di bawah
- [x] **Empat test wajib** dari dokumen 02 bagian 9

Definition of done: pengguna media bisa melaporkan pemuatan dan admin memverifikasinya, alert lonjakan negatif terkirim ke Telegram, dan empat test wajib hijau.

Kerjakan test scoping peran media dengan sungguh-sungguh. Ini satu-satunya fitur di sistem yang, kalau bocor, menimbulkan masalah dengan pihak luar dan bukan hanya ketidaknyamanan internal.

---

## Sprint 6: Penyederhanaan relevansi (2 minggu)

Sprint ini tidak ada di rencana awal. Ia lahir dari hasil evaluasi sprint 3:
presisi relevansi 57,0% dan 51,1% pada dua dari tiga konteks, dan satu konteks
yang F1-nya tidak bisa dibaca sama sekali karena gold set-nya tidak punya
sampel negatif. Rinciannya di dokumen 01 bagian 9 dan dokumen 05.

Disetujui 4 Agustus 2026, dikerjakan sampai selesai.

Dikerjakan **sebelum** serah terima, bukan sesudah. Menyerahkan sistem yang
separuh isi dashboardnya tidak membahas Pemkot berarti menyerahkan masalah,
dan pengetatannya mengubah setiap angka historis sehingga lebih baik terjadi
sekali sebelum ada yang memakai angkanya.

**Fase 1: sederhanakan konteks**
- [x] Nonaktifkan konteks Wali Kota dan Pelayanan publik lewat seeder, jangan dihapus
- [x] Pastikan `AnalisisRelevansi` berjalan sekali per artikel
- [x] Hapus konteks Wali Kota dan Pelayanan publik. Sempat hanya dinonaktifkan, tapi baris nonaktif tetap terbaca admin dan membingungkan. 221 label gold set-nya diarsipkan ke `storage/app/private/` lebih dulu
- [x] Halaman pelabelan: pemilih konteks hanya muncul kalau konteksnya lebih dari satu, kartu konteks diganti pertanyaan yang benar-benar dijawab pelabel

**Fase 2: metadata sumber dan kamus alias**
- [ ] Migration kolom `kategori_sumber`, `tag_sumber`, `post_id_sumber`, `url_api_sumber`, `diubah_sumber_at`
- [ ] Tarik kategori dan tag saat crawl dan backfill, terjemahkan ID term menjadi nama
- [ ] Lengkapi kamus `entitas` beserta aliasnya: Pemkot, pimpinan, seluruh OPD, kecamatan, kelurahan
- [ ] Tambahkan kontras yang harus dikenali: Pemprov Sultra, Polda, Kejari, Bea Cukai, BPS, kanwil, kampus
- [ ] `JendelaKonteks`, potongan dua kalimat sebelum dan sesudah tiap sebutan

**Fase 3: perbaiki gold set**
- [ ] Migration `label_gold` nullable, kolom `gold_set_versi` dan `split`
- [ ] Migrasikan 249 label konteks utama menjadi gold set relevansi biner
- [ ] Review ulang label dari dua konteks lama, satu per satu, jangan digabung otomatis
- [ ] Tambahkan hard negative: artikel Pemprov, instansi vertikal, dan Kendari sebagai lokasi
- [ ] Tetapkan `split` per grup duplikat, bekukan test set
- [ ] Ronde 2 atas 40 baris acak untuk mengukur konsistensi pelabel

**Fase 3b: pindahkan penilai relevansi ke e5-small**

> **Ditutup 4 Agustus 2026, hasilnya dibawa ke sprint 8.** Perpindahan ini
> selesai dikerjakan dan diukur: presisi naik dari 57,0% ke 69,9%, masih di
> bawah target 80%. Kesimpulannya, yang menahan bukan pilihan model melainkan
> dataset. Karena itu penilai relevansi berpindah lagi ke classifier hasil
> fine-tuning di sprint 8, dan cosine e5 tinggal mengerjakan deteksi salinan.
> Tiga tugas yang belum selesai di bawah ini tidak dibuang, dua di antaranya
> pindah ke sprint 8 dan satu tetap di sini.
- [x] Lepas `indobert-relevancy` dari layanan NLP, hapus endpoint `/relevancy`
- [x] Ganti model embedding dari MiniLM ke `intfloat/multilingual-e5-small`
- [x] Migration `artikel.embedding_relevansi`, `konteks_pantauan.deskripsi_model` dan `embedding`
- [x] `JendelaKonteks` membentuk teks terfokus, `HitungEmbedding` menghitung dua vektor sekali panggil
- [x] Vektor konteks dihitung sekali dari `deskripsi_model`, berawalan `query:`
- [x] `AnalisisRelevansi` menjadi kueri pgvector, bukan panggilan HTTP
- [x] Perintah `nlp:hitung-ulang-vektor` beserta backfill 4.806 artikel lama
- [x] Enam tes mengunci tiga cabang keputusan relevansi
- [~] ~~Kalibrasi dua ambang dari validation set~~. Dibatalkan. Ambang cosine dipensiunkan, digantikan `versi_threshold_relevansi` di sprint 8
- [ ] **Ukur ulang ambang deduplikasi.** `DEDUP_AMBANG_COSINE=0.92` disetel untuk MiniLM. Vektor e5 tidak sebanding, jadi angka itu tidak lagi berlaku sampai diperiksa dengan 100 pasangan manual. **Tetap wajib**, karena deduplikasi tetap memakai e5 dan justru menjadi satu-satunya tugasnya sekarang
- [~] ~~Ukur ulang apakah pengetat sebutan masih menambah presisi di atas cosine~~. Dijawab dengan melepasnya. Pengetat turun menjadi sinyal prioritas antrean, alasannya di dokumen 05 bagian 5.2

**Fase 4: ukur ulang dan stabilkan**
- [x] Antrean `/admin/review` beserta urutan prioritasnya, artikel terdekat ambang atas lebih dulu
- [x] Kartu relevansi di detail artikel: skor kemiripan, alasan keputusan, dan penegasan bahwa itu bukan persentase keyakinan
- [x] Penunjuk kemajuan antrean analisis di dashboard admin, menyegarkan sendiri selama masih ada pekerjaan
- [x] Field `deskripsi_model` masuk form konteks. Sebelumnya field penentu relevansi itu tidak ada di layar sama sekali, sehingga admin menyunting deskripsi lain sambil mengira itu mengubah perilaku model
- [x] Jalankan ulang relevansi atas seluruh korpus dengan ambang 0,83 dan 0,84
- [ ] Kolom versi dan metrik relevansi di `evaluasi_model`, halaman evaluasi dipisah dua tab
- [ ] Koreksi relevansi manual dengan alasan wajib, dan `relevan_efektif`
- [x] `evaluasi:model` ulang setelah sentimen selesai dihitung: presisi relevansi 57,0% ke 69,9%, F1 sentimen 0,7375

Definition of done: presisi relevansi minimal 80% dan recall minimal 85% pada
test set yang dibekukan, gold set dihitung per artikel unik, tidak ada satu pun
koreksi manual yang tertimpa analisis ulang, dan halaman evaluasi menampilkan
relevansi terpisah dari sentimen.

Kalau presisi tetap di bawah 80% setelah fase 4, jangan menambah aturan
tempelan. Baca dokumen 05 bagian 8 dan kerjakan urutannya.

---

## Sprint 8: Laboratorium Model Relevansi (tanpa batas waktu)

Dikerjakan **sebelum** sprint 7. Spesifikasi lengkapnya di dokumen 10, dan
bagian ini hanya daftar fase beserta gerbangnya. Jangan melewati fase sebelum
fondasi fase sebelumnya stabil.

Dua keputusan yang berlaku sejak hari pertama sprint ini:

- **Sentimen diblokir.** `AnalisisSentimen` berhenti didispatch, dashboard
  sentimen menampilkan keadaan belum tersedia, alert lonjakan negatif berhenti.
  Data lama tetap tersimpan utuh. Dokumen 10 bagian 0.3.
- **e5-small turun menjadi pendeteksi salinan.** Relevansi berpindah ke
  `apriandito/indobert-relevancy-classifier` yang dilatih ulang dengan dataset
  lokal.

**Fase 1: fondasi data**
- [x] Sebelas tabel dokumen 10 bagian 16, termasuk unique partial index satu model produksi. **Model Eloquent baru empat**, yaitu yang benar-benar dipakai fase ini: `SampelRelevansi`, `PrediksiRelevansi`, `VersiModelRelevansi`, `GerbangMutuRelevansi`. Tujuh sisanya dibuat saat fasenya tiba
- [x] Hapus `artikel.embedding_relevansi` dan `konteks_pantauan.embedding`. `nlp:hitung-ulang-vektor` ikut disederhanakan menjadi satu vektor
- [x] Sentimen diblokir: penjaga di `AnalisisRelevansi` dan di dalam `AnalisisSentimen`, ditambah keadaan "belum tersedia" di dashboard eksekutif, halaman sentimen, dan badge di halaman isu
- [x] `ImporArtikelKeDatasetRelevansi` masuk rantai job, perintah `relevance:import-crawled` untuk korpus lama. 3.887 kandidat masuk dari 4.137 artikel asli
- [x] Migrasikan label relevansi dari `gold_set` menjadi `sampel_relevansi`. Terlaksana 250 baris, 65 relevan dan 185 tidak relevan
- [x] Tabel dataset dengan seluruh filter di query string, beserta filter cepat
- [x] Mode pelabelan cepat beserta pintasan R/T/S, kode alasan, dan aturan alasan wajib
- [x] Audit label lewat activity log: nilai sebelum, sesudah, pelaku, waktu
- [ ] **Kolom `kategori_sumber` dan `tag_sumber` ternyata tidak pernah dibuat**, padahal dokumen 03 changelog 1.4 dan dokumen 05 bagian 4 menyebutnya sudah ada. Sprint 6 fase 2 yang mengerjakannya. Sampai itu selesai, komponen prioritas berbasis tag dilepas dan kedua kolom di `sampel_relevansi` selalu kosong

**Gerbang fase:** dataset dapat dilabeli konsisten dan tidak ada label manual yang tertimpa job prediksi ulang. **Terpenuhi**, dijaga 18 test di `DatasetRelevansiTest` dan `GerbangMutuRelevansiTest`.

Dua angka yang perlu dibaca sebelum fase 2. Pertama, 250 label yang ada berbanding 26% relevan dan 74% tidak relevan, jadi kartu keseimbangan langsung berstatus timpang dan kelas relevan yang harus dikejar lebih dulu. Kedua, 2.312 dari 3.887 kandidat berskor prioritas nol, artinya tidak menyebut Pemkot sama sekali; melabelinya berurutan dari atas berarti hampir seluruh waktu habis di artikel yang jawabannya sudah jelas.

**Fase 2: snapshot dan kualitas dataset**
- [ ] Snapshot, split train/validation/test per grup duplikat, manifest hash
- [ ] `RelevanceSplitValidator` dan laporan kebocoran
- [ ] Test set terkunci, perubahannya wajib beralasan dan membuat versi baru
- [ ] Laporan kesiapan data, tiga tingkat
- [ ] Active learning dasar, `priority_score` beserta komponennya
- [ ] **Labeli sampai minimal 1.500 artikel unik, 600 per kelas.** Inilah pekerjaan terpanjang di sprint ini

**Gerbang fase:** snapshot terkunci dapat direproduksi dan tidak memiliki kebocoran duplikat.

**Fase 3: pipeline pelatihan**
- [x] `RelevanceInputBuilder` berversi, satu-satunya tempat susunan teks model ditulis
- [x] Ekspor snapshot terkunci menjadi JSONL per split, memakai label yang dibekukan
- [x] Endpoint `/relevancy/training-runs`, status, dan batal. Pelatihan jalan di thread latar
- [x] Checkpoint terbaik dipilih dari validation, artefak beserta checksum per berkas
- [x] Progres, riwayat termasuk yang gagal, penanganan galat
- [ ] Evaluasi ulang atas snapshot lain, dan perbandingan antar versi. Masuk fase 4

**Gerbang fase:** pelatihan yang sama dapat direproduksi dari snapshot dan konfigurasi yang sama.

Tiga temuan yang mengubah rencana, semuanya dari mencoba menjalankannya:

1. **Base model-nya BERT large**, bukan base. Akibatnya di dokumen 02 bagian
   deployment: pelatihan tidak muat di server produksi 8 GB, jadi dilatih di
   mesin pengembangan lalu artefaknya disalin.
2. **Kalimat konteks harus pendek.** `deskripsi_model` masih berisi paragraf
   aturan dari era e5, dan itu memakan 137 dari 256 token pada setiap sampel
   tanpa membedakan apa pun, menyisakan 116 token untuk artikel yang butuh 264.
   Diringkas menjadi `Pemerintah Kota Kendari`, dan `RelevanceInputBuilder`
   sekarang menolak konteks di atas 120 huruf alih-alih memotongnya diam-diam.
3. **Kedua container harus berbagi direktori dataset.** Kontrak pelatihan
   mengirim lokasi berkas, bukan isinya, dan itu mengandaikan satu filesystem
   seperti di produksi. Di compose keduanya terpisah, jadi `storage/app/private`
   dipasang ke path yang sama persis di container NLP.

**Fase 4: evaluasi**
- [ ] Metrik lengkap, confusion matrix yang bisa diklik, evaluasi per media dan per pola kasus
- [ ] Analisis kesalahan, simulator ambang, perbandingan versi
- [ ] Ronde konsistensi pelabel, minimal 40 sampel, hitung Cohen's kappa

**Gerbang fase:** hasil evaluasi dapat menjelaskan secara spesifik jenis kesalahan model.

**Fase 5: uji model dan versioning**
- [ ] Uji URL, uji teks, feedback benar/salah, simpan sebagai hard case
- [ ] Status versi, warmup, promosi atomik, rollback

**Gerbang fase:** model kandidat dapat diuji tanpa memengaruhi produksi dan rollback berhasil.

**Fase 6: gerbang mutu**
- [ ] Standar gerbang, laporan, pencabutan otomatis
- [ ] Penjaga sentimen di dispatcher dan di dalam job
- [ ] Audit sampling produksi mingguan

**Gerbang fase:** tidak ada satu pun jalur kode yang memungkinkan sentimen berjalan ketika relevansi belum layak.

**Fase 7: perbaiki model sampai layak**

Ulangi: analisis kesalahan, tambah hard case, perbaiki label dan panduan,
snapshot baru, fine-tune, evaluasi, gerbang mutu.

**Gerbang fase:** seluruh syarat dokumen 10 bagian 12.3 dan 12.4 terpenuhi.

**Fase 8: aktifkan kembali sentimen**
- [ ] Model relevansi produksi aktif dan gerbang `passed`
- [ ] Audit sampling awal berhasil, false positive dan false negative kritis sudah ditinjau
- [ ] Rollback teruji, seluruh test penjaga sentimen hijau
- [ ] Jalankan ulang relevansi atas korpus, lalu sentimen atas artikel yang lolos
- [ ] Buka kembali dashboard sentimen dan alert

Definition of done: seluruh kotak di dokumen 10 bagian 26 tercentang, gerbang
mutu berstatus `passed`, dan angka sentimen di dashboard dihitung dari artikel
yang relevansinya sudah terukur, bukan diasumsikan.

Peringatan yang paling mudah dilanggar di sprint ini: **jangan menurunkan
standar gerbang supaya model terlihat lulus.** Nilainya memang bisa diubah, dan
itu memang disengaja, tetapi setiap penurunan wajib punya alasan tertulis dan
tercatat di audit log. Gerbang yang diturunkan sampai model apa pun lolos sama
saja dengan tidak punya gerbang, hanya dengan lebih banyak kode.

---

## Sprint 7: Pemantapan dan serah terima (2 minggu)

Sprint tanpa fitur baru. Setiap kali Anda tergoda menambahkan satu fitur kecil di sini, tulis saja di daftar versi 2.

- [ ] Ekspor PDF ringkasan eksekutif
- [ ] Pemeriksaan aksesibilitas: kontras, fokus, navigasi keyboard
- [ ] Keadaan kosong, loading, dan error di seluruh halaman
- [ ] Peninjauan pesan error agar menyebut tindakan yang bisa diambil
- [ ] Optimasi query. Aktifkan log query lambat, perbaiki apa pun di atas 300 ms
- [ ] Daftar periksa pengerasan di dokumen 06 bagian 7
- [ ] Backup otomatis dan **satu kali uji restore penuh**
- [ ] Halaman kebijakan privasi
- [ ] README setup yang bisa dijalankan orang lain dari nol
- [ ] Panduan pengguna singkat, satu halaman per peran, dengan tangkapan layar
- [ ] Pelatihan admin Diskominfo, dua sesi
- [ ] Evaluasi model kedua setelah 3 bulan data, bandingkan dengan yang pertama
- [ ] Pastikan Google Form sudah dimatikan dan tidak ada media yang masih memakainya

Definition of done: orang lain bisa menyiapkan lingkungan pengembangan dari README tanpa bertanya kepada Anda, backup pernah berhasil direstore, dan admin Diskominfo bisa menambahkan sumber feed baru sendiri tanpa bantuan.

---

## Jalur pemangkasan kalau waktu habis

Kalau ada tenggat dan Anda tertinggal, pangkas dengan urutan ini. Daftar disusun dari yang paling aman dibuang.

| Urutan | Yang dipangkas | Dampak |
|--------|----------------|--------|
| 1 | Ekspor PDF | Ekspor Excel sudah menutupi kebutuhan |
| 2 | Halaman entitas dan pencocokan kamus | Halaman isu masih jalan dengan kata kunci saja |
| 3 | Heatmap jam publikasi | Grafik pelengkap, tidak ada yang menanyakannya |
| 4 | Sumber tipe scrape | Cukup RSS ditambah Google News. Situs tanpa RSS ditambahkan manual |
| 5 | Halaman pengaturan sistem | Ubah nilai lewat `.env`, meskipun kurang nyaman |
| 6 | Portal media seluruhnya | Google Form lama tetap hidup dan admin memasukkan hasilnya manual lewat `input_admin`. Ini memangkas hampir satu sprint penuh, tapi menunda pemenuhan janji akun media ke 30 media |
| 7 | CRUD aturan alert | Buat satu aturan lewat seeder dan ubah lewat database bila perlu |

**Yang tidak boleh dipangkas dalam keadaan apa pun:**

- Gold set dan pengukuran akurasi. Tanpa angka ini, dashboard sentimen bisa dibantah siapa pun dan sistem kehilangan kredibilitasnya dalam satu rapat
- Presisi relevansi. Sentimen yang akurat atas artikel yang salah tetap salah, dan inilah kesalahan yang paling cepat terlihat pimpinan karena ia tidak perlu membaca angka untuk menyadarinya, cukup membaca judulnya
- Gerbang mutu relevansi beserta kedua penjaganya. Memangkas ini berarti memangkas satu-satunya hal yang mencegah dashboard menampilkan angka yang sudah diketahui salah
- Test set yang terkunci dan bebas kebocoran duplikat. Alat ukur yang bocor menghasilkan angka bagus yang tidak berarti apa pun, dan itu lebih berbahaya daripada tidak mengukur sama sekali
- Deduplikasi. Tanpa ini seluruh angka salah dan Anda kehilangan kepercayaan lebih cepat daripada dengan fitur yang kurang
- Status perlu review. Menyembunyikan ketidakpastian membuat sistem menyatakan hal yang tidak diketahuinya
- Global scope peran media dan test-nya. Ini soal kebocoran data ke pihak luar
- Backup dan satu kali uji restore
- Responsivitas dashboard eksekutif di layar ponsel

---

## Daftar versi 2

Tulis di sini setiap ide yang muncul selama pengembangan. Fungsi utamanya bukan mencatat rencana, tapi memberi tempat menaruh gagasan sehingga Anda tidak mengerjakannya sekarang.

- Media sosial: X, Instagram, komentar YouTube
- BERTopic setelah 12 bulan data terkumpul
- ~~IndoBERT binary classifier hasil fine-tuning untuk relevansi~~. **Naik menjadi sprint 8**, lihat dokumen 10. Syarat yang dulu ditulis di sini, sekitar 1.000 keputusan manusia, ternyata tepat dan sekarang menjadi gerbang fase 2
- ~~Perbandingan berdampingan antara cosine e5-small dan classifier hasil fine-tuning~~. Masuk fase 4 sprint 8 sebagai pembanding dasar. Model baru yang tidak mengalahkan cosine 69,9% tidak layak dipromosikan
- Konteks pantauan kedua, kalau Diskominfo benar-benar meminta. Biayanya bukan inferensi, melainkan satu putaran pelabelan penuh
- Fine-tuning model sentimen dengan gold set yang sudah tumbuh
- Perbandingan dengan kota lain di Sulawesi Tenggara
- Ringkasan mingguan otomatis dikirim ke Telegram
- Peringkat jurnalis, bukan hanya media
- Sentimen per kecamatan
- Model NER menggantikan pencocokan kamus
- Media monitoring value dalam rupiah, kalau rate card tersedia
- Deteksi berita yang bersumber dari siaran pers Pemkot
- Ringkasan berbentuk kalimat/naratif per periode, butuh LLM. Bangun hanya kalau pimpinan memintanya, karena daftar isi teratas yang bisa diklik lebih mudah dipertanggungjawabkan
- Rentang ringkasan panjang (3, 6, 12 bulan) untuk kata kunci. Butuh granularitas `bulanan` di tabel `kata_kunci_periode` dan job penghitungnya. Grafik volume, sentimen, dan entitas untuk rentang panjang sudah siap sejak versi 1 tanpa tambahan; hanya kata kunci yang perlu ini

---

## Tanda peringatan selama pengembangan

Empat gejala yang menandakan proyek mulai menyimpang. Kalau salah satu muncul, berhenti dan tangani sebelum lanjut.

**Anda menunda sprint 3.** Pipeline NLP adalah bagian paling tidak familier dan paling mudah ditunda dengan mengerjakan halaman CRUD yang nyaman. Kalau sprint 3 tergeser dua kali, kemungkinan besar Anda menghindarinya. Kerjakan bagian terkecilnya hari itu juga: panggil model dari terminal untuk satu artikel.

**Gold set belum mulai di akhir sprint 3.** Ini pertanda paling kuat proyek akan gagal secara politis meskipun berhasil secara teknis. Pelabelan tidak menghasilkan apa pun yang terlihat, jadi mudah ditunda selamanya. Labeli 20 baris hari ini.

**Anda menambahkan tabel yang tidak ada di dokumen 03 tanpa mencatatnya.** Satu kali tidak masalah. Lima kali berarti skema di kepala Anda sudah berbeda dari dokumen, dan enam bulan lagi tidak ada yang bisa dipercaya.

**Anda mulai memasang library kedua untuk hal yang sudah ada solusinya.** Chart library kedua, komponen UI kedua, atau paket permission yang belum dibutuhkan. Setiap tambahan seperti ini melawan alasan Anda memilih satu paradigma sejak awal.
