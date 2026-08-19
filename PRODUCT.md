# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Tiga peran pengguna, masing-masing dengan situasi pemakaian yang sangat berbeda.

**Admin Diskominfo (peran `superadmin`).** Staf yang mengelola sistem setiap hari kerja, sesi 20 sampai 60 menit, dari komputer kantor. Nyaman dengan spreadsheet, tanpa latar belakang teknis. Pekerjaannya: mendaftarkan media dan sumber RSS, memverifikasi klaim pemuatan dari media, mengoreksi label relevansi dan sentimen yang salah, serta menyiapkan rekap saat pimpinan meminta. Frustrasi terbesarnya adalah form panjang tanpa penyimpanan otomatis, tabel yang tidak bisa difilter, dan error tanpa penjelasan.

**Walikota dan staf khusus (peran `walikota`).** Membuka sistem dari ponsel, sering di sela kegiatan, sesi di bawah tiga menit. Tidak akan membaca manual, tidak akan mengisi form apa pun, tidak akan mencari menu tersembunyi. Butuh jawaban tiga pertanyaan dalam satu layar: berapa berita hari ini, sentimennya bagaimana dibanding minggu lalu, isu apa yang sedang naik. Berhenti memakai sistem kalau loading lebih dari empat detik, grafik tidak terbaca di layar 6 inci, atau angka terasa tidak masuk akal tanpa cara mengeceknya.

**Pengelola media partner (peran `media`).** Redaktur atau staf administrasi di media yang punya kontrak dengan Pemkot. Membuka sistem beberapa kali sebulan, terutama mendekati akhir periode kontrak. Butuh melihat berapa pemuatan yang sudah tercatat terhadap targetnya, dan melaporkan pemuatan yang belum terdeteksi sistem. Kesal kalau berita yang sudah dimuat tidak terhitung tanpa cara mengajukan koreksi.

## Product Purpose

SIMAK adalah sistem monitoring dan analisis sentimen media untuk Pemerintah Kota Kendari. Sistem mengumpulkan berita tentang Kendari secara otomatis, menilai relevansinya terhadap Pemkot, menganalisis sentimennya, memverifikasi realisasi kontrak kerja sama publikasi dengan media, dan memberi peringatan dini saat berita negatif melonjak.

Sistem ini menggantikan proses manual: staf mencari berita, menyalin tautan ke spreadsheet, lalu menyusun rekap bulanan. Cara lama menimbulkan tiga masalah yang produk ini ada untuk menutupnya. Verifikasi realisasi kontrak lambat dan mudah diperdebatkan karena tidak ada catatan tunggal yang bisa dirujuk kedua pihak. Berita negatif baru diketahui setelah menyebar. Tidak ada gambaran isu apa yang sedang ramai, karena rekap bulanan menghitung jumlah berita, bukan tema atau sentimennya.

Keberhasilan diukur dengan indikator berikut. Lebih dari 90% berita dari media terdaftar masuk sistem dalam 2 jam sejak publikasi. Rekap per media bisa dibuka kapan saja tanpa pekerjaan manual. Notifikasi berita negatif terkirim dalam 30 menit sejak terdeteksi. Satu halaman eksekutif bisa dibaca pimpinan di bawah dua menit. Waktu penyusunan rekap bulanan turun dari beberapa hari menjadi di bawah 30 menit. Peran walikota login minimal 12 kali per bulan.

## Positioning

Yang tidak bisa disalin produk media monitoring umum: sistem ini menilai relevansi dan sentimen terhadap satu konteks spesifik, yaitu Pemerintah Kota Kendari sebagai institusi, bukan sekadar mendeteksi kata "Kendari". Berita kriminal di Kendari, kegiatan Pemprov Sultra, atau acara perusahaan lokal bukan berita Pemkot, dan sistem dilatih untuk membedakannya.

Sistem juga menyatukan dua hal yang biasanya terpisah: pemantauan pemberitaan dan verifikasi kontrak kerja sama publikasi. Satu artikel yang tertangkap crawler sekaligus menjadi bukti realisasi kontrak, dan bukti itu diarsipkan sistem sendiri berupa teks hasil ekstraksi dan tangkapan layar bertanggal, sehingga tetap sah saat audit walaupun media menghapus artikelnya setelah pembayaran cair.

## Operating Context

Tiga panel dalam satu aplikasi, dipisahkan per grup route: `/admin` untuk superadmin, `/eksekutif` untuk walikota, `/portal` untuk media.

Pengumpulan berita berjalan otomatis sebagai scheduled command: RSS, scraping terbatas dengan CSS selector, dan Google News RSS untuk menangkap media di luar daftar. Deduplikasi tiga lapis mengenali salinan berdasarkan URL, hash isi, dan kemiripan makna, lalu menautkannya ke artikel asli tanpa menghapusnya. Analisis berjalan asinkron lewat queue, sehingga kalau layanan AI mati crawler tetap jalan dan artikel menumpuk tanpa data hilang.

Alur kerja admin berputar di sekitar tiga ritual. Verifikasi laporan pemuatan dari media, dengan target lebih dari 95% terverifikasi dalam 3 hari kerja. Koreksi label relevansi dan sentimen di ruang kerja pelabelan dan antrean perlu review, yang sekaligus menjadi bahan latih gold set. Penyusunan rekap saat pimpinan meminta, diekspor ke Excel atau PDF satu halaman.

Alur media adalah satu tugas berulang: menempel URL berita yang belum terdeteksi crawler, melihat pratinjau judul dan tanggal, lalu mengonfirmasi dengan satu tombol. Target waktu dari tempel sampai konfirmasi di bawah 15 detik. Halaman lapor menampilkan lebih dulu artikel yang sudah terdeteksi dan sudah terhitung, sehingga hanya sisanya yang perlu dilaporkan.

Ada 30 media partner terdaftar. Tiga di antaranya nasional (Tempo, Detikcom, Portal.id) dan feed utuhnya tidak ditarik agar tidak menenggelamkan angka volume. Zona waktu operasional `Asia/Makassar` (WITA, UTC+8), disimpan sebagai UTC dan dikonversi di layer tampilan.

## Capabilities and Constraints

**Kemampuan yang sudah ada.** Pengumpulan otomatis dan deduplikasi. Klasifikasi relevansi biner tingkat artikel terhadap satu konteks aktif. Analisis sentimen negatif, netral, positif terhadap konteks tersebut. Koreksi label manual yang selalu mengalahkan hasil model. Gold set berlabel manusia dan pengukuran akurasi. Ekstraksi kata kunci, entitas, dan skor lonjakan. Modul kontrak dengan target dan realisasi otomatis. Portal pelaporan pemuatan dengan arsip bukti permanen. Dashboard eksekutif. Alert Telegram dengan rate limit per jendela waktu. Ekspor Excel dan PDF. Audit log seluruh aksi tulis.

**Dua jalur AI hidup berdampingan.** Penyedia klasifikasi dapat dipilih lewat pengaturan: Gemini (jalur LLM eksternal, dengan rotasi kunci dan antrean) atau IndoBERT lokal yang di-fine-tune dengan dataset Kendari. Keduanya adalah kebenaran produk, bukan salah satu menggantikan yang lain. Dokumen `docs/00-README.md` dan `docs/05` masih mencatat LLM eksternal sebagai keputusan yang tidak dipakai, dan bagian itu sudah tidak berlaku. Setiap hasil analisis menyimpan versi modelnya.

**Gerbang mutu relevansi tidak lagi berlaku.** Aturan yang memblokir tampilnya sentimen sampai model relevansi lolos ambang mutu (tercatat berlaku 4 Agustus 2026 di dokumen 10 bagian 12) sudah dibatalkan. Dashboard sentimen menampilkan angka, bukan keadaan belum tersedia.

**Narasi eksekutif adalah bagian tetap produk.** Fitur yang sedang dibangun (`app/Ai/Agents/AnalisEksekutif.php`, `app/Console/Commands/BuatNarasiEksekutif.php`, tabel `narasi_eksekutif`) bukan percobaan. Perlakukan sebagai kemampuan produk yang berhak atas ruang di dashboard eksekutif.

**Batasan tetap.** Satu konteks pantauan aktif: Pemerintah Kota Kendari. Wali Kota dan pelayanan publik turun menjadi entitas dan topik, barisnya tetap ada di tabel tapi dinonaktifkan. Peran media tidak boleh melihat skor sentimen, ini keputusan produk agar media tidak menyesuaikan gaya tulisan. Peran walikota tidak dapat melakukan aksi tulis apa pun. Crawler tetap sumber utama analisis sentimen, laporan mandiri hanya untuk realisasi kontrak, dan proporsi laporan mandiri di atas 40% memicu peringatan di dashboard admin.

**Kepatuhan hak cipta.** Isi artikel disimpan untuk analisis, tapi UI hanya menampilkan judul, ringkasan maksimal 300 karakter, dan tautan ke sumber. Tidak boleh ada halaman yang menampilkan isi berita utuh.

**Performa yang mengikat desain.** Dashboard eksekutif selesai render di bawah 2 detik pada koneksi 4G. Agregasi tidak dihitung saat request, semuanya dibaca dari tabel ringkasan yang diperbarui scheduled job. Daftar berita dengan filter apa pun di bawah 1 detik untuk 100 ribu baris. Kapasitas rancangan 300 artikel per hari dan 200 sumber.

**Keamanan.** HTTPS wajib. 2FA wajib untuk peran superadmin dan walikota. Rate limit login. Sesi walikota kedaluwarsa 30 hari. Akun dinonaktifkan, tidak dihapus. Pengguna peran media hanya melihat data medianya sendiri lewat global scope.

**Stack yang sudah terkunci.** Laravel dengan Inertia, Vue 3, dan TypeScript. Tailwind CSS 4 dengan konfigurasi CSS-first. Komponen shadcn-vue, kodenya ada di repo sehingga boleh dimodifikasi. Grafik memakai ECharts via `vue-echarts`. PostgreSQL dengan pgvector. Redis untuk queue. Layanan NLP FastAPI sebagai proses terpisah.

**Yang di luar lingkup versi 1.** Media sosial. Media cetak dan siaran tanpa versi online. Media monitoring value dalam rupiah. Sentimen pada komentar pembaca. Prediksi tren. Aplikasi mobile native, karena web responsif sudah cukup. SSO pemerintah. Notifikasi WhatsApp, Telegram satu-satunya kanal alert.

## Brand Commitments

Nama produk: **SIMAK**, kepanjangannya Sistem Monitoring dan Analisis Kendari. Tagline: "Membaca Pemberitaan, Mengawal Kendari Semakin Maju." Nama teknis repo tetap `simedia`, termasuk nama basis data, berkas konfigurasi deploy, dan berkas lambang.

Warna utama: **`#163F6C`**, navy pekat, setara `oklch(0.364 0.090 253.3)`. Ditetapkan pengguna pada 10 Agustus 2026 sebagai warna brand yang mengikat. Ini keputusan brand pertama proyek, karena sebelumnya token `--primary` masih memakai nilai bawaan starter kit yaitu nyaris hitam. Warna pendampingnya belum ditetapkan.

Font **IBM Plex Sans**, dipasang dari npm dan dilayani sendiri. Ini permintaan Diskominfo dan bersifat mengikat. Tidak lewat Google Fonts CDN, agar sistem tidak bergantung pada akses internet keluar server.

Bahasa antarmuka pengguna: **Bahasa Indonesia**, tanpa kecuali. Nama tabel dan kolom database juga Bahasa Indonesia, karena akan dibaca staf Diskominfo saat audit atau serah terima. Nama kelas, method, dan variabel PHP/TypeScript tetap Bahasa Inggris mengikuti konvensi framework.

Dark mode sudah menjadi bagian sistem, bawaan starter kit.

## Evidence on Hand

**Ada dan boleh dipakai.** Paket spesifikasi lengkap di `docs/01` sampai `docs/10`, mencakup PRD, spesifikasi teknis, skema database, spesifikasi UI dan design system, spesifikasi NLP, matriks akses, roadmap, rangkuman sprint, panduan pelabelan, dan spesifikasi laboratorium model relevansi. Daftar 30 media partner nyata beserta URL dan tier ada di lampiran A dokumen 01. Gold set berlabel manusia beserta metrik akurasi terukur. Jawaban resmi Diskominfo atas sembilan pertanyaan pembuka, tercatat di dokumen 01 bagian 9.

**Tidak ada dan tidak boleh dikarang.** Tidak ada dokumen kontrak formal dari Diskominfo, sehingga tabel kontrak berbasis jumlah pemuatan per periode saja. Tidak ada testimoni, studi kasus, liputan pers, atau angka adopsi nyata. Sistem belum dipakai pengguna sungguhan, masih tahap pengembangan, jadi jangan menampilkan klaim jumlah pengguna, jam terbang, atau kepuasan. Tidak ada rate card media, sehingga nilai monitoring dalam rupiah tidak bisa dihitung dan memang di luar lingkup. Angka akurasi model hanya boleh dikutip dari hasil evaluasi yang benar-benar tercatat, tidak boleh dibulatkan ke atas atau diperkirakan.

## Product Principles

1. **Sistem mengukur sentimen dan volume pemberitaan, bukan menilai kinerja pemerintah.** Perbedaan itu wajib jelas di antarmuka. Tidak ada halaman yang boleh terbaca sebagai kesimpulan kebijakan.

2. **Angka yang tidak bisa dipercaya lebih buruk daripada angka yang tidak ada.** Hasil model dengan keyakinan di bawah ambang ditandai perlu review dan tidak ditampilkan sebagai fakta. Setiap angka di dashboard harus punya jalan untuk dicek asalnya, karena satu angka yang terasa janggal tanpa cara memverifikasinya cukup untuk membuat pimpinan berhenti memakai sistem.

3. **Sesi walikota diukur dalam detik, bukan menit.** Tiga pertanyaan terjawab dalam satu layar ponsel selebar 375 piksel. Tidak ada form, tidak ada menu tersembunyi, tidak ada grafik yang butuh zoom.

4. **Koreksi manusia selalu mengalahkan hasil model.** Admin harus punya jalur cepat memperbaiki label yang salah, dan koreksi itu tercatat beserta pelakunya. Ini yang membuat sistem tetap dipakai saat model keliru.

5. **Setiap aksi tulis meninggalkan jejak.** Audit log lengkap dengan pelaku, waktu, nilai sebelum dan sesudah. Bukti pemuatan diarsipkan sistem sendiri, bukan diunggah pihak yang berkepentingan.

6. **Basis kode harus bisa dipahami satu orang setelah tiga bulan tidak menyentuhnya.** Hindari abstraksi yang tidak dipakai lebih dari dua kali. Ini berlaku juga untuk komponen antarmuka dan token desain.

## Accessibility & Inclusion

Kontras minimal 4,5:1 untuk teks, diperiksa pada mode terang dan gelap, termasuk token warna sentimen.

Warna tidak boleh menjadi satu-satunya penanda. Status sentimen, verifikasi, dan kesehatan sumber wajib punya penanda kedua berupa ikon, label teks, atau bentuk.

Setiap grafik di panel eksekutif menyediakan tombol "Lihat sebagai tabel", karena ECharts tidak dapat dibaca screen reader. Tombol ini sekaligus melayani pengguna yang ingin menyalin angkanya ke laporan.

Navigasi keyboard dan indikator fokus wajib berfungsi di seluruh panel admin, karena itu panel yang dipakai setiap hari kerja.

Seluruh halaman peran walikota wajib terbaca di layar selebar 375 piksel.
