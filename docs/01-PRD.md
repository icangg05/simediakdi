# 01 — Product Requirements Document

SIMEDIA Kendari | Versi 1.2

---

## 1. Latar belakang

Pemerintah Kota Kendari bekerja sama dengan sejumlah media untuk publikasi program dan kegiatan. Saat ini pemantauannya berjalan manual: staf Bagian Prokopim atau Diskominfo mencari berita, menyalin tautan ke spreadsheet, dan membuat rekap bulanan. Cara ini menimbulkan tiga masalah.

Pertama, verifikasi realisasi kontrak lambat dan mudah diperdebatkan. Ketika media mengklaim sudah memuat 40 berita, tidak ada catatan tunggal yang bisa dirujuk kedua pihak.

Kedua, berita negatif baru diketahui setelah menyebar. Pimpinan mendengar dari orang lain sebelum melihatnya di laporan.

Ketiga, tidak ada gambaran isu apa yang sedang ramai. Rekap bulanan menghitung jumlah berita, bukan tema atau nadanya.

## 2. Tujuan produk

| Tujuan | Indikator |
|--------|-----------|
| Mengumpulkan berita tentang Kendari secara otomatis | Lebih dari 90% berita dari media yang terdaftar masuk sistem dalam 2 jam sejak publikasi |
| Memverifikasi realisasi kerja sama publikasi | Rekap per media bisa dibuka kapan saja tanpa pekerjaan manual |
| Memberi peringatan dini berita negatif | Notifikasi terkirim dalam 30 menit sejak berita terdeteksi |
| Menyajikan gambaran sentimen dan isu | Satu halaman yang bisa dibaca pimpinan dalam waktu di bawah dua menit |

### Yang bukan tujuan

Sistem ini tidak menilai kinerja pemerintah dan tidak menghasilkan kesimpulan kebijakan. Sistem ini mengukur nada dan volume pemberitaan. Perbedaan itu harus jelas di UI, dan dokumen 04 mengatur cara menuliskannya.

## 3. Persona pengguna

### P1 — Admin Diskominfo (peran `superadmin`)

Staf yang mengelola sistem sehari-hari. Nyaman dengan komputer dan spreadsheet, tidak punya latar belakang teknis. Membuka sistem setiap hari kerja, sesi 20 sampai 60 menit.

Yang dia butuhkan: mendaftarkan media dan sumber RSS baru, memverifikasi klaim pemuatan dari media, memperbaiki label sentimen yang salah, dan menyiapkan rekap saat diminta pimpinan.

Yang membuatnya frustrasi: form panjang tanpa penyimpanan otomatis, tabel yang tidak bisa difilter, dan error tanpa penjelasan.

### P2 — Walikota dan staf khusus (peran `walikota`)

Membuka sistem dari ponsel, kadang di sela kegiatan. Sesi kurang dari tiga menit. Tidak akan pernah membaca manual, tidak akan mengisi form apa pun, dan tidak akan mencari menu tersembunyi.

Yang dia butuhkan: jawaban atas tiga pertanyaan dalam satu layar. Berapa berita hari ini. Nadanya bagaimana dibanding minggu lalu. Isu apa yang sedang naik.

Yang membuatnya berhenti memakai sistem: loading lebih dari empat detik, grafik yang tidak terbaca di layar 6 inci, atau angka yang terasa tidak masuk akal tanpa cara mengeceknya.

### P3 — Pengelola media partner (peran `media`)

Redaktur atau staf administrasi di media yang punya kontrak. Membuka sistem beberapa kali sebulan, terutama mendekati akhir periode kontrak.

Yang dia butuhkan: melihat berapa pemuatan yang sudah tercatat dari targetnya, dan melaporkan pemuatan yang belum terdeteksi sistem.

Yang membuatnya kesal: berita yang sudah dimuat tapi tidak terhitung, tanpa cara mengajukan koreksi.

## 4. Ruang lingkup

### Dalam lingkup versi 1

1. Pengumpulan berita otomatis dari RSS, scraping terbatas, dan Google News RSS
2. Deduplikasi berita yang disalin antar media
3. Analisis sentimen terhadap konteks yang bisa dikonfigurasi
4. Koreksi label manual oleh admin, dan pengukuran akurasi model
5. Ekstraksi kata kunci dan entitas untuk halaman isu hangat
6. Modul kontrak kerja sama dengan target dan realisasi
7. Pelaporan pemuatan oleh media, diverifikasi admin
8. Dashboard eksekutif untuk peran walikota
9. Notifikasi lonjakan berita negatif via Telegram
10. Ekspor rekap ke Excel dan PDF
11. Audit log seluruh aksi tulis

### Di luar lingkup versi 1

- Media sosial (Twitter/X, Instagram, TikTok, komentar YouTube). Ini proyek terpisah dengan tantangan berbeda dan biaya API yang tidak kecil.
- Media cetak dan siaran TV/radio yang tidak punya versi online. Hanya bisa dicatat manual, dan itu kembali ke masalah awal.
- Media monitoring value atau PR value dalam rupiah. Butuh rate card tiap media yang jarang tersedia dan sering tidak akurat.
- Analisis sentimen pada komentar pembaca.
- Prediksi atau peramalan tren.
- Aplikasi mobile native. Web responsif sudah cukup.
- Integrasi SSO pemerintah, kecuali Diskominfo mewajibkannya di kemudian hari.
- Jembatan Google Form. Google Form yang berjalan sekarang tetap dipakai apa adanya sampai portal siap, lalu dimatikan. Tidak ada webhook, tidak ada masa transisi paralel (keputusan Diskominfo, lihat bagian 9).
- Notifikasi WhatsApp. Telegram saja.

## 5. Kebutuhan fungsional

Penomoran dipakai sebagai acuan di dokumen 04 dan 07. Prioritas: **W** wajib versi 1, **S** sebaiknya ada, **T** tunda.

### 5.1 Pengumpulan data

| ID | Kebutuhan | Prioritas |
|----|-----------|-----------|
| F-01 | Sistem menarik artikel dari daftar sumber RSS pada interval yang bisa diatur per sumber | W |
| F-02 | Sistem mengekstrak judul, isi, penulis, tanggal publikasi, dan gambar utama dari halaman artikel | W |
| F-03 | Sistem mengenali artikel yang sudah pernah masuk berdasarkan URL, hash isi, dan kemiripan makna | W |
| F-04 | Sistem menandai artikel duplikat sebagai turunan dari artikel pertama, tanpa menghapusnya | W |
| F-05 | Sistem menarik hasil Google News RSS untuk kata kunci yang dikonfigurasi, guna menangkap media di luar daftar | W |
| F-06 | Sistem mencatat setiap eksekusi crawl beserta jumlah temuan dan error | W |
| F-07 | Sistem menonaktifkan sumber otomatis setelah lima kegagalan berturut-turut dan memberi tahu admin | S |
| F-08 | Admin dapat menambah sumber scraping dengan CSS selector untuk situs tanpa RSS | S |
| F-09 | Admin dapat memasukkan artikel manual lewat URL, dan sistem mengekstrak isinya | S |

### 5.2 Analisis

| ID | Kebutuhan | Prioritas |
|----|-----------|-----------|
| F-10 | Sistem menilai relevansi artikel terhadap setiap konteks pantauan sebelum menilai sentimen | W |
| F-11 | Sistem memberi label sentimen negatif, netral, atau positif terhadap konteks tertentu, beserta skor keyakinan | W |
| F-12 | Sistem menandai hasil dengan keyakinan di bawah ambang sebagai "perlu review" dan tidak menampilkannya sebagai fakta | W |
| F-13 | Admin dapat mengoreksi label, dan koreksi selalu mengalahkan hasil model | W |
| F-14 | Sistem menyimpan versi model pada setiap hasil analisis | W |
| F-15 | Admin dapat mengelola daftar konteks pantauan | W |
| F-16 | Sistem menghitung frekuensi kata kunci per periode dan skor lonjakannya | W |
| F-17 | Sistem mengekstrak entitas berupa nama orang, OPD, lokasi, dan program | S |
| F-18 | Admin dapat menggabungkan entitas duplikat dan mengelola aliasnya | S |
| F-19 | Sistem menyimpan gold set berlabel manusia dan menghitung metrik akurasi model | W |
| F-20 | Clustering topik otomatis | T |

### 5.3 Kontrak dan realisasi

| ID | Kebutuhan | Prioritas |
|----|-----------|-----------|
| F-21 | Admin dapat mencatat kontrak per media dengan periode, nilai, dan target jumlah pemuatan | W |
| F-22 | Sistem menghitung realisasi pemuatan terhadap target secara otomatis dari artikel yang terkumpul | W |
| F-23 | Media dapat melaporkan pemuatan dengan menempel URL saja. Sistem mengekstrak judul dan tanggal, menampilkan pratinjau, dan media mengonfirmasi dengan satu tombol | W |
| F-24 | Admin dapat memverifikasi atau menolak laporan pemuatan beserta alasannya | W |
| F-25 | Sistem menampilkan sisa hari dan sisa target untuk kontrak yang masih aktif | S |
| F-26 | Sistem memberi tahu admin saat kontrak akan berakhir dalam 14 hari dengan realisasi di bawah 80% | S |
| F-48 | Halaman lapor menampilkan lebih dulu daftar artikel media tersebut yang sudah terdeteksi crawler dan sudah terhitung ke kontrak, sehingga hanya sisanya yang perlu dilaporkan | W |
| F-49 | Form lapor menerima banyak URL sekaligus, satu per baris, dan menampilkan hasil per URL: berhasil, sudah tercatat, atau gagal diekstrak | S |
| F-50 | Sistem menolak URL yang domainnya tidak cocok dengan domain media pelapor, dengan pesan yang jelas | W |
| F-51 | Kalau ekstraksi URL gagal, form melebar menjadi isian manual berisi judul, tanggal, dan tangkapan layar. Kegagalan ekstraksi tidak menghalangi verifikasi kontrak | W |
| F-52 | Saat laporan dikonfirmasi, sistem mengarsipkan teks hasil ekstraksi dan tangkapan layar halaman yang diambil sistem sendiri, beserta waktunya, sebagai bukti permanen | W |
| F-54 | Dashboard admin menampilkan proporsi artikel dari crawler dibanding laporan mandiri, dengan peringatan saat laporan mandiri melewati 40% | W |

### 5.4 Penyajian

| ID | Kebutuhan | Prioritas |
|----|-----------|-----------|
| F-27 | Dashboard eksekutif menampilkan jumlah berita hari ini, komposisi sentimen, dan tiga isu teratas dalam satu layar | W |
| F-28 | Grafik tren volume berita per hari dengan rentang waktu yang bisa dipilih | W |
| F-29 | Grafik tren sentimen sebagai area bertumpuk per hari | W |
| F-30 | Peringkat media berdasarkan jumlah berita dan komposisi sentimennya | W |
| F-31 | Halaman isu hangat berisi kata kunci naik, entitas teratas, dan word cloud | W |
| F-32 | Daftar berita dengan filter media, tanggal, sentimen, konteks, dan pencarian teks | W |
| F-33 | Halaman detail berita menampilkan skor per konteks dan riwayat koreksinya | W |
| F-34 | Ekspor rekap periode ke Excel | W |
| F-35 | Ekspor ringkasan eksekutif ke PDF satu halaman | S |
| F-36 | Seluruh halaman peran walikota terbaca di layar selebar 375 piksel | W |

### 5.5 Notifikasi

| ID | Kebutuhan | Prioritas |
|----|-----------|-----------|
| F-37 | Admin dapat membuat aturan alert dengan ambang dan jendela waktu | W |
| F-38 | Sistem mengirim notifikasi Telegram saat aturan terpicu | W |
| F-39 | Sistem menyimpan riwayat alert dan status pengirimannya | W |
| F-40 | Sistem membatasi pengiriman agar satu aturan tidak memicu lebih dari sekali per jendela waktu | W |

### 5.6 Pengguna dan akses

| ID | Kebutuhan | Prioritas |
|----|-----------|-----------|
| F-42 | Tiga peran dengan hak akses berbeda sesuai dokumen 06 | W |
| F-43 | Pengguna peran media hanya dapat melihat data medianya sendiri | W |
| F-44 | Peran walikota tidak dapat melakukan aksi tulis apa pun | W |
| F-45 | Seluruh aksi tulis tercatat di audit log beserta pelaku, waktu, dan nilai sebelum dan sesudah | W |
| F-46 | Admin dapat menonaktifkan akun tanpa menghapusnya | W |
| F-47 | 2FA wajib untuk peran superadmin dan walikota | W |

## 6. Kebutuhan non-fungsional

**Performa.** Dashboard eksekutif harus selesai render di bawah 2 detik pada koneksi 4G. Agregasi tidak dihitung saat request; semuanya dibaca dari tabel ringkasan yang diperbarui oleh scheduled job. Halaman daftar berita dengan filter apa pun harus di bawah 1 detik untuk 100 ribu baris.

**Ketersediaan.** Target 99% per bulan pada jam kerja. Kalau layanan NLP mati, crawler tetap jalan dan artikel menumpuk di queue tanpa data hilang.

**Kapasitas.** Rancang untuk 300 artikel per hari dan 200 sumber. Itu sekitar 110 ribu artikel per tahun, jauh di bawah batas nyaman PostgreSQL.

**Keamanan.** Lihat dokumen 06. Ringkasnya: HTTPS wajib, 2FA untuk dua peran, rate limit login, audit log lengkap, backup harian dengan uji restore bulanan.

**Kepatuhan hak cipta.** Sistem menyimpan isi artikel untuk keperluan analisis, tapi UI hanya menampilkan judul, ringkasan maksimal 300 karakter, dan tautan ke sumber. Tidak ada halaman yang menampilkan isi berita utuh.

**Rawat.** Satu orang harus bisa memahami seluruh basis kode setelah tiga bulan tidak menyentuhnya. Konsekuensinya: hindari abstraksi yang tidak dipakai lebih dari dua kali, dan tulis komentar pada setiap query agregasi yang tidak jelas maksudnya.

## 7. Metrik keberhasilan

Ukur tiga bulan setelah sistem dipakai.

| Metrik | Target |
|--------|--------|
| Cakupan berita | Lebih dari 90% berita dari media terdaftar tertangkap sistem, diukur dengan audit sampel manual 100 berita |
| Akurasi sentimen | F1 macro minimal 0,75 pada gold set Kendari |
| Tingkat duplikat lolos | Kurang dari 5% berita ganda terhitung sebagai berita berbeda |
| Adopsi peran walikota | Login minimal 12 kali per bulan |
| Waktu penyusunan rekap bulanan | Dari beberapa hari menjadi di bawah 30 menit |
| Klaim pemuatan yang diverifikasi | Lebih dari 95% terverifikasi dalam 3 hari kerja |
| Proporsi laporan mandiri | Di bawah 40% dari total artikel. Di atas itu berarti crawler kurang menjangkau dan angka sentimen mulai bias |
| Waktu melapor satu URL di portal | Di bawah 15 detik dari tempel sampai konfirmasi |

Metrik akurasi sentimen adalah yang paling penting secara politis. Kalau tidak punya angkanya, satu hasil analisis yang tidak disukai cukup untuk membuat seluruh sistem diabaikan.

## 8. Risiko

| Risiko | Dampak | Mitigasi |
|--------|--------|----------|
| Akurasi sentimen dianggap tidak dapat dipercaya | Sistem tidak dipakai pimpinan | Gold set sejak sprint 3, tampilkan akurasi di UI, sediakan status "perlu review", izinkan koreksi manual |
| Media mengubah struktur HTML atau mematikan RSS | Data berhenti masuk tanpa disadari | Log crawl per eksekusi, alert saat sumber gagal lima kali, dashboard kesehatan sumber |
| Duplikasi rilis Antara membuat volume terlihat berlebihan | Angka di dashboard tidak dipercaya | Tiga lapis deduplikasi, dan tampilkan jumlah asli serta jumlah salinan secara terpisah |
| Media menyesuaikan gaya tulisan agar terbaca positif | Data sentimen kehilangan makna dalam beberapa bulan | Peran media tidak melihat skor sentimen. Keputusan produk, bukan teknis |
| Sentimen dihitung hanya dari laporan mandiri media | Hasil selalu terlihat positif karena media tidak melaporkan berita kritis sebagai realisasi kontrak | Crawler tetap sumber utama untuk analisis sentimen. Laporan mandiri hanya untuk realisasi kontrak. Pantau lewat metrik proporsi sumber (F-54) |
| Media menghapus atau mengubah artikel setelah pembayaran cair | Tidak ada bukti saat audit | Arsip teks dan tangkapan layar diambil sistem sendiri saat laporan dikonfirmasi (F-52) |
| Akun walikota dipakai bersama atau bocor | Kebocoran data dan hilangnya jejak audit | 2FA wajib, activity log, sesi kedaluwarsa 30 hari, tanpa hak tulis |
| Pengembang tunggal sakit atau pindah kerja | Proyek berhenti | Dokumentasi ini, test untuk alur kritis, README setup yang bisa dijalankan orang lain |
| Ruang lingkup melebar ke media sosial di tengah jalan | Versi 1 tidak pernah selesai | Bagian "di luar lingkup" di atas dipakai sebagai jawaban tertulis |
| Kewajiban hosting di Pusat Data Nasional muncul belakangan | Perlu migrasi dan mungkin tanpa akses internet keluar | Sudah dikonfirmasi tidak wajib (bagian 9 nomor 3). Kalau berubah, crawler dan layanan NLP dipisah ke VPS ber-internet, aplikasi dan database pindah ke PDN |
| Pelabelan gold set dikerjakan pengembang sendiri | Bias pelabelan tidak terdeteksi karena tidak ada pelabel kedua | Tulis panduan pelabelan sebelum mulai (dokumen 05 bagian 7), labeli ulang 40 baris acak seminggu kemudian, dan laporkan kesesuaiannya sebagai batas atas akurasi yang wajar |

## 9. Jawaban Diskominfo atas pertanyaan terbuka

Sembilan pertanyaan pembuka sudah dijawab. Bagian ini menyimpan jawabannya beserta konsekuensinya pada pekerjaan.

| # | Pertanyaan | Jawaban | Konsekuensi |
|---|------------|---------|-------------|
| 1 | Berapa media partner dan siapa saja | 30 media, daftar di lampiran A | Di bawah batas 60, alokasi sprint 1 tetap |
| 2 | Ada dokumen kontrak | Tidak ada | Tabel `kontrak` tetap berbasis jumlah pemuatan per periode. Tabel anak `kontrak_target` tidak dibangun |
| 3 | Wajib PDN atau boleh cloud | Tidak wajib PDN, hosting di server sendiri atau server kantor | Arsitektur satu server tetap berlaku. Pastikan server punya akses internet keluar dan port keluar tidak difilter, karena crawler dan unduhan model bergantung padanya |
| 4 | Pemilik akun walikota | Satu peran `walikota`, boleh banyak user | Sesuai rancangan. Setiap staf khusus dapat akun sendiri, tidak ada akun bersama. Tidak ada perubahan skema |
| 5 | Sentimen dinilai terhadap apa | Diserahkan ke pengembang, pilih yang relevan dengan tujuan sistem | Tiga konteks awal ditetapkan di bawah |
| 6 | Media diberi akun | Ya, satu akun per media untuk menginput link berita | Portal media dan sprint 5 tetap penuh |
| 7 | Siapa melabeli gold set | Pengembang | 8 jam pelabelan masuk hitungan sprint 3, sudah dianggarkan di dokumen 07 |
| 8 | Siapa menerima dan mengatur alert Telegram | Superadmin Diskominfo | Chat ID disimpan di `.env` (`TELEGRAM_CHAT_ID`), hanya superadmin yang boleh mengelola aturan alert. Nomor grup diisi saat sprint 5 |
| 9 | Tenggat dari luar | Tidak ada | Tidak ada prioritas S yang perlu dipangkas di muka. Urutan pemangkasan di dokumen 07 tetap dipakai kalau jadwal melar |

Dua keputusan tambahan dari sesi yang sama:

- **Google Form tidak dijembatani.** Form yang berjalan sekarang tetap jalan sendiri sampai portal siap, lalu dimatikan. F-53 dan seluruh endpoint webhook dihapus dari lingkup.
- **Notifikasi WhatsApp tidak dipakai.** F-41 dihapus, bukan ditunda. Telegram satu-satunya kanal alert.

### Konteks pantauan awal (jawaban nomor 5)

Tiga konteks yang dipasang lewat seeder, karena ketiganya langsung melayani tujuan di bagian 2. Sisanya ditambahkan admin sendiri lewat F-15 saat ada kebutuhan nyata.

| Konteks | Deskripsi untuk model | Alasan |
|---------|----------------------|--------|
| Pemerintah Kota Kendari | Kebijakan, program, layanan, dan aparatur Pemerintah Kota Kendari | Konteks utama. Semua angka di dashboard eksekutif bertumpu pada ini |
| Wali Kota Kendari | Wali Kota dan Wakil Wali Kota Kendari sebagai pejabat publik | Peringatan dini berita negatif paling sering menyangkut figur, bukan institusi. Sentimennya sering berbeda dari konteks institusi |
| Pelayanan publik dan infrastruktur Kota Kendari | Jalan, drainase, sampah, air bersih, pasar, dan layanan administrasi kependudukan | Tema yang paling banyak memancing berita kritis di media lokal, dan yang paling bisa ditindaklanjuti OPD |

Konteks per OPD tidak dibuat di versi 1. Butuh 30-an konteks, dan volume berita per OPD terlalu kecil untuk menghasilkan tren yang berarti. Halaman entitas (F-17) sudah menjawab pertanyaan "OPD mana yang disebut" tanpa biaya itu.

---

## Lampiran A — Daftar media partner

30 media, sesuai penyerahan Diskominfo. Kolom tier menentukan pembobotan di peringkat media. Kolom jalur diisi saat sprint 0 setelah tiap situs diuji, ikuti tabel strategi di dokumen 02 bagian 8.

| # | Nama | URL | Tier |
|---|------|-----|------|
| 1 | Sultra TV | https://www.sultratv.id/ | regional |
| 2 | Sultra Demo | https://sultrademo.or.id/ | regional |
| 3 | Kendari Pos | https://kendaripos.fajar.co.id/ | lokal |
| 4 | Radar Kendari | https://radarkendari.com | lokal |
| 5 | Kolom Rakyat | https://kolomrakyat.com | lokal |
| 6 | Tempo | https://www.tempo.id/ | nasional |
| 7 | Trijaya Kendari | https://www.trijayakendari.com | lokal |
| 8 | Telisik | https://telisik.id/ | regional |
| 9 | Kendari Info | https://kendariinfo.com/ | lokal |
| 10 | Detikcom | https://www.detik.com | nasional |
| 11 | Britakita | https://britakita.net/ | lokal |
| 12 | Perdetik News | https://perdetiknews.com/ | lokal |
| 13 | Galeri Sultra | https://galerisultra.com/ | regional |
| 14 | Radar Sultra | https://radarsultra.co/ | regional |
| 15 | Figur Sultra | https://figursultra.com/ | regional |
| 16 | Lensa Timur | https://www.lensatimur.id/ | regional |
| 17 | Koran Headline | https://koranheadline.com/ | lokal |
| 18 | Mediatama Sultra | https://mediatamasultra.com/ | regional |
| 19 | Kisahan | https://kisahan.id/ | lokal |
| 20 | Sultranesia | https://sultranesia.com/ | regional |
| 21 | Sibernas | https://sibernas.id | lokal |
| 22 | Tajuk Info | https://tajukinfo.com | lokal |
| 23 | Teras Sultra | https://www.terassultra.com | regional |
| 24 | Lontara Sultra | https://www.lontarasultra.com | regional |
| 25 | Sultra Merdeka | https://www.sultramerdeka.com | regional |
| 26 | Metro Kendari | https://metrokendari.com | lokal |
| 27 | Portal.id | https://portal.id/ | nasional |
| 28 | Informasi Sultra | https://informasisultra.com | regional |
| 29 | Kongkrit Post | https://kongkritpost.com/ | lokal |
| 30 | Mitra Nusantara | https://mitranusantara.id/ | regional |

Tiga catatan yang mempengaruhi crawler:

1. **Tempo, Detikcom, dan Portal.id nasional.** Jangan tarik feed utuhnya, karena isinya didominasi berita di luar Kendari dan akan menenggelamkan angka volume. Untuk ketiganya pakai feed kategori daerah kalau ada, dan andalkan Google News RSS dengan kata kunci Kendari. Kalau tetap kebanjiran, saring dengan pencocokan kata kunci sebelum artikel disimpan.
2. **Kendari Pos berada di subdomain `kendaripos.fajar.co.id`.** Kolom `domain` di tabel media harus menyimpan subdomain lengkap, bukan `fajar.co.id`, agar artikel Fajar lain tidak ikut tercocokkan.
3. **Verifikasi ulang tier saat sprint 0.** Tier di atas adalah dugaan awal dari cakupan situsnya. Kalau salah, yang terpengaruh hanya pembobotan peringkat media, bukan angka sentimen.

## 10. Definisi istilah

| Istilah | Arti dalam sistem ini |
|---------|----------------------|
| Artikel | Satu halaman berita di satu media, dikenali dari URL kanoniknya |
| Artikel asli | Artikel pertama yang masuk untuk satu isi yang sama |
| Salinan | Artikel dengan isi yang sangat mirip artikel yang sudah ada, ditautkan ke artikel asli |
| Konteks pantauan | Deskripsi topik singkat yang menjadi sasaran penilaian sentimen |
| Relevansi | Apakah artikel benar-benar membahas konteks tertentu |
| Sentimen | Nada artikel terhadap satu konteks, bukan nada artikel secara umum |
| Perlu review | Hasil model dengan keyakinan di bawah ambang, belum boleh dianggap fakta |
| Pemuatan | Satu artikel yang diakui sebagai realisasi kontrak |
| Skor lonjakan | Perbandingan frekuensi kata kunci periode ini terhadap rata-rata periode sebelumnya |
