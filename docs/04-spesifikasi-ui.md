# 04: Spesifikasi UI dan Design System

SIMEDIA Kendari | Versi 1.0 | Tailwind CSS 4 + shadcn-vue

---

## Bagian A: Design System

### A.1 Prinsip

Tiga peran memakai satu design system tapi kepadatan yang berbeda.

**Admin** padat. Tabel dengan baris tinggi 40 piksel, teks 14 piksel, banyak informasi per layar. Pengguna melihat halaman ini setiap hari dan menghargai sedikit scroll.

**Eksekutif** lapang. Angka besar, sedikit elemen, satu keputusan per kartu. Pengguna melihatnya tiga menit dan harus paham tanpa membaca label kecil.

**Portal media** sederhana. Satu tugas utama per halaman.

Jangan bikin tiga tema warna. Satu palet, tiga tingkat kepadatan.

### A.2 Token warna

Definisikan di `resources/css/app.css` menggunakan sintaks Tailwind 4. Starter kit sudah menyediakan token dasar shadcn (`--background`, `--foreground`, `--primary`, dan seterusnya). Tambahkan token domain berikut.

```css
@theme {
  /* Sentimen. Satu-satunya sumber kebenaran untuk warna sentimen. */
  --color-sentimen-positif: oklch(0.62 0.14 155);
  --color-sentimen-positif-lembut: oklch(0.95 0.04 155);
  --color-sentimen-netral: oklch(0.60 0.02 250);
  --color-sentimen-netral-lembut: oklch(0.95 0.01 250);
  --color-sentimen-negatif: oklch(0.58 0.18 22);
  --color-sentimen-negatif-lembut: oklch(0.95 0.05 22);
  --color-sentimen-review: oklch(0.70 0.13 75);
  --color-sentimen-review-lembut: oklch(0.96 0.05 75);

  /* Tier media, untuk chip di peringkat media */
  --color-tier-nasional: oklch(0.50 0.14 265);
  --color-tier-regional: oklch(0.60 0.10 220);
  --color-tier-lokal: oklch(0.65 0.05 200);
}
```

Aturan mutlak: warna sentimen hanya boleh dibaca dari token ini, termasuk di dalam konfigurasi ECharts. Composable `useTemaChart()` membaca nilai token dari `getComputedStyle(document.documentElement)` dan menyuntikkannya ke opsi ECharts. Jangan pernah menulis kode heksadesimal di file grafik. Kalau warna negatif suatu saat perlu diubah, satu tempat saja yang disunting.

### A.3 Warna bukan satu-satunya penanda

Sekitar 8% pria mengalami kesulitan membedakan merah dan hijau, dan sistem ini akan diproyeksikan di layar rapat yang warnanya tidak akurat. Setiap indikator sentimen wajib membawa tiga hal: warna, ikon, dan teks.

| Sentimen | Warna | Ikon lucide | Teks |
|----------|-------|-------------|------|
| Positif | positif | `trending-up` | Positif |
| Netral | netral | `minus` | Netral |
| Negatif | negatif | `trending-down` | Negatif |
| Perlu review | review | `help-circle` | Perlu review |

Komponen `BadgeSentimen.vue` menerapkan ketiganya. Tidak ada tempat lain di aplikasi yang boleh merender indikator sentimen.

### A.4 Tipografi

| Peran | Elemen | Ukuran | Bobot |
|-------|--------|--------|-------|
| Semua | Body | 14px | 400 |
| Admin | Judul halaman | 20px | 600 |
| Admin | Sel tabel | 14px | 400 |
| Eksekutif | Angka utama kartu KPI | 40px di mobile, 48px di desktop | 600 |
| Eksekutif | Label KPI | 13px | 500, huruf kecil biasa |
| Eksekutif | Judul halaman | 24px | 600 |

Font: **IBM Plex Sans**, menggantikan font bawaan starter kit. Dipasang dari npm dan dilayani sendiri, bukan dari Google Fonts CDN, karena server instansi tidak selalu punya akses keluar yang stabil dan halaman tidak boleh bergantung pada host pihak ketiga.

```bash
npm i @fontsource/ibm-plex-sans
```

```css
/* resources/css/app.css, di atas @theme */
@import "@fontsource/ibm-plex-sans/400.css";
@import "@fontsource/ibm-plex-sans/500.css";
@import "@fontsource/ibm-plex-sans/600.css";

@theme {
  --font-sans: "IBM Plex Sans", ui-sans-serif, system-ui, sans-serif;
}
```

Cukup tiga bobot: 400, 500, 600. Tabel tipografi di atas tidak memakai bobot lain, dan setiap bobot tambahan menambah unduhan yang tidak dipakai. Ambil varian latin saja.

IBM Plex Sans punya angka tabular bawaan, tapi tetap set `font-variant-numeric: tabular-nums` pada angka kartu KPI dan sel tabel angka agar nilainya tidak bergeser saat berubah.

Format angka Indonesia: pemisah ribuan titik, desimal koma. Buat satu composable `useFormatAngka()` dan pakai di semua tempat. Persentase selalu satu angka desimal.

### A.5 Kepadatan dan spasi

| Peran | Padding kartu | Jarak antar kartu | Tinggi baris tabel |
|-------|---------------|-------------------|--------------------|
| Admin | 16px | 16px | 40px |
| Eksekutif | 24px | 20px | 56px |
| Portal | 20px | 16px | 48px |

### A.6 Dark mode

Starter kit sudah membawa tiga mode: light, dark, dan mengikuti sistem. Berlaku untuk seluruh aplikasi.

Satu pengecualian: halaman ekspor PDF selalu light. Dashboard eksekutif default-nya light saat pertama kali dibuka, karena hasil tangkapan layarnya kemungkinan akan ditempel ke laporan atau slide.

### A.7 Bahasa dan nada

- Bahasa Indonesia baku, tanpa singkatan yang tidak umum.
- Kalimat aktif. "Sistem menemukan 12 berita baru", bukan "Ditemukan 12 berita baru".
- Angka disajikan bersama pembandingnya. "48 berita, naik 12 dari minggu lalu" mengalahkan "48 berita".
- Setiap hasil model diberi label sebagai hasil analisis otomatis, bukan sebagai fakta. Gunakan kalimat seperti "Analisis otomatis: cenderung negatif" alih-alih "Berita ini negatif".
- Pesan error menyebut tindakan yang bisa diambil pengguna. "Sumber RSS tidak bisa dihubungi. Periksa URL-nya atau coba lagi nanti", bukan "Terjadi kesalahan".

### A.8 Komponen shadcn-vue yang dipakai

Tambahkan lewat CLI. Yang tidak ada di daftar ini jangan dipasang sampai benar-benar dibutuhkan.

**Wajib sejak sprint 1:** button, card, input, label, table, badge, dropdown-menu, dialog, select, separator, skeleton, sonner, tooltip, avatar, sidebar (bawaan starter kit), breadcrumb, pagination.

**Sprint 2 dan 3:** tabs, popover, calendar, range-calendar, checkbox, switch, textarea, alert, alert-dialog, command, scroll-area, progress, sheet (drawer filter di mobile), collapsible.

**Sprint 4 dan 5:** radio-group, form (integrasi vee-validate), hover-card, toggle-group, empty (state kosong).

Total sekitar 30 komponen. Semuanya masuk ke `resources/js/components/ui` dan menjadi milik repo Anda.

### A.9 Komponen buatan sendiri

| Komponen | Dipakai di | Fungsi |
|----------|------------|--------|
| `DataTable.vue` | 9 halaman | Tabel dengan filter, sort, paginasi berbasis server. Lihat dokumen 02 bagian 4 |
| `BadgeSentimen.vue` | Sepanjang aplikasi | Indikator sentimen dengan warna, ikon, dan teks |
| `PenandaPerluReview.vue` | Detail artikel, daftar artikel | Menjelaskan bahwa keyakinan model rendah |
| `KartuKpi.vue` | Dashboard eksekutif dan portal | Angka besar, label, dan pembanding periode sebelumnya |
| `KartuArtikel.vue` | Feed berita eksekutif | Judul, media, waktu, badge sentimen, tautan keluar |
| `ProgresKontrak.vue` | Admin dan portal | Progress bar realisasi terhadap target, sisa hari |
| `PemilihRentangTanggal.vue` | Sepanjang aplikasi | Preset 7 hari, 30 hari, bulan ini, kustom |
| `PemilihKonteks.vue` | Halaman analisis | Select konteks pantauan aktif. Sejak versi 1.4 hanya ada satu konteks aktif, jadi komponen ini menyembunyikan dirinya sendiri saat pilihannya tinggal satu. Jangan dihapus, dan jangan pula dirender sebagai dropdown berisi satu opsi |
| `KartuRelevansi.vue` | Detail artikel, antrean review | Keputusan relevansi, skor, chip sinyal, potongan kalimat pemicu, dan dua tombol koreksi |
| `BaseChart.vue` | Semua grafik | Wrapper ECharts yang menerapkan tema dan menangani resize |
| `KeadaanKosong.vue` | Semua tabel | Pesan dan tindakan saat data belum ada |
| `IndikatorKesehatan.vue` | Dashboard admin | Titik hijau, kuning, atau merah untuk status crawler dan layanan NLP |

### A.10 Aturan grafik

Semua grafik lewat `BaseChart.vue`. Aturannya:

1. Sumbu Y untuk jumlah artikel selalu mulai dari nol. Memotong sumbu membesar-besarkan perubahan dan akan membuat orang salah menyimpulkan.
2. Grafik tren sentimen memakai area bertumpuk dengan urutan tetap dari bawah: positif, netral, negatif. Urutan yang berubah antar halaman membuat pembaca salah baca.
3. Setiap grafik punya keadaan kosong dan keadaan loading berupa skeleton, bukan spinner.
4. Setiap grafik bisa diunduh sebagai PNG. ECharts sudah menyediakannya lewat toolbox.
5. Grafik yang mengandung data "perlu review" memisahkannya sebagai kategori tersendiri, bukan memasukkannya ke netral. Menyembunyikan ketidakpastian di dalam netral adalah cara termudah membuat dashboard yang menyesatkan.
6. Tinggi grafik di mobile maksimal 240 piksel. Legend dipindah ke bawah, label sumbu X dirotasi atau dijarangkan.

Daftar grafik dan jenisnya:

| Grafik | Jenis ECharts | Halaman |
|--------|---------------|---------|
| Tren volume berita | Line dengan area, dataZoom di desktop | Eksekutif, admin |
| Tren sentimen | Stacked area | Eksekutif |
| Komposisi sentimen | Donat dengan angka di tengah | Eksekutif, portal |
| Peringkat media | Horizontal bar bertumpuk per sentimen | Eksekutif, admin |
| Word cloud kata kunci | wordCloud (`echarts-wordcloud`) | Isu hangat |
| Kata kunci naik | Horizontal bar dengan skor lonjakan | Isu hangat |
| Heatmap jam publikasi | Heatmap hari terhadap jam | Admin, opsional |
| Entitas teratas | Horizontal bar | Isu hangat |

---

## Bagian B: Inventaris halaman

Total 24 halaman. Kolom "Sprint" merujuk dokumen 07.

### B.1 Panel admin, prefix `/admin`

| Rute | Halaman | Komponen utama | Sprint |
|------|---------|----------------|--------|
| `/admin` | Dashboard admin | KartuKpi, IndikatorKesehatan, tabel artikel terbaru, daftar sumber bermasalah, kartu proporsi sumber data (F-54) dengan peringatan saat laporan mandiri di atas 40% | 2 |
| `/admin/artikel` | Daftar artikel | DataTable dengan filter media, tanggal, sentimen, konteks, status dedup, perlu review | 2 |
| `/admin/artikel/{id}` | Detail artikel | Metadata, skor per konteks, form koreksi label, daftar salinan, riwayat aktivitas | 3 |
| `/admin/media` | Daftar media | DataTable | 1 |
| `/admin/media/create`, `/{id}/edit` | Form media | Form dengan vee-validate | 1 |
| `/admin/media/{id}` | Detail media | Statistik, daftar sumber feed, daftar kontrak | 2 |
| `/admin/sumber-feed` | Daftar sumber | DataTable dengan indikator kesehatan dan tombol uji koneksi | 1 |
| `/admin/sumber-feed/create`, `/{id}/edit` | Form sumber | Form dengan pratinjau hasil uji ambil | 1 |
| `/admin/konteks` | Konteks pantauan | DataTable, drag untuk urutan, penanda konteks utama | 3 |
| `/admin/kontrak` | Daftar kontrak | DataTable dengan ProgresKontrak per baris | 4 |
| `/admin/kontrak/create`, `/{id}/edit` | Form kontrak | Unggah berkas, pemilih periode | 4 |
| `/admin/kontrak/{id}` | Detail kontrak | Progres, daftar pemuatan, tombol ekspor | 4 |
| `/admin/verifikasi` | Antrean verifikasi pemuatan | DataTable, pratinjau bukti, tombol setujui dan tolak | 5 |
| `/admin/entitas` | Daftar entitas | DataTable, aksi gabungkan | 5 |
| `/admin/pelabelan` | Ruang kerja gold set | Satu artikel per layar, tombol pintasan keyboard | 3 |
| `/admin/review` | Antrean perlu review | Artikel dengan keyakinan dekat ambang, satu per layar, urutan berprioritas | 6 |
| `/admin/evaluasi` | Hasil evaluasi model | Dua tab terpisah, relevansi dan sentimen. Confusion matrix, grafik F1 antar waktu, 20 false positive dan 20 false negative terbesar | 3 |
| `/admin/model-relevansi` | Laboratorium Model Relevansi | Delapan tab lewat query string: ringkasan, dataset, snapshot, pelatihan, evaluasi, uji-model, versi-model, pengaturan. Wireframe dan komponennya di dokumen 10 bagian 5 sampai 18 | 8 |
| `/admin/alert` | Aturan alert | DataTable dan form | 5 |
| `/admin/alert/riwayat` | Riwayat alert | DataTable | 5 |
| `/admin/pengguna` | Pengguna | DataTable dan form | 4 |
| `/admin/log-crawl` | Log crawl | DataTable | 2 |
| `/admin/pengaturan` | Pengaturan sistem | Form ambang keyakinan, konfigurasi Telegram, kata kunci Google News | 5 |

### B.2 Panel eksekutif, prefix `/eksekutif`

Lima halaman. Semuanya read-only. Semuanya harus terbaca di 375 piksel.

| Rute | Halaman | Sprint |
|------|---------|--------|
| `/eksekutif` | Dashboard utama | 3 |
| `/eksekutif/sentimen` | Analisis sentimen mendalam | 4 |
| `/eksekutif/isu` | Isu hangat | 4 |
| `/eksekutif/media` | Peringkat media | 4 |
| `/eksekutif/berita` | Arsip berita | 4 |

### B.3 Panel media, prefix `/portal`

| Rute | Halaman | Sprint |
|------|---------|--------|
| `/portal` | Dashboard media | 5 |
| `/portal/berita` | Berita saya | 5 |
| `/portal/kontrak` | Kontrak saya | 5 |
| `/portal/lapor` | Lapor pemuatan | 5 |
| `/portal/profil` | Profil dan kontak PIC | 5 |

---

## Bagian C: Wireframe deskriptif

Hanya untuk lima halaman yang paling berpengaruh. Sisanya mengikuti pola DataTable atau form standar.

### C.1 Dashboard eksekutif, `/eksekutif`

Halaman paling penting di seluruh sistem. Rancang untuk mobile lebih dulu, lalu lebarkan.

**Susunan mobile, dari atas ke bawah:**

1. **Header.** Nama sistem, sapaan singkat, dan `PemilihRentangTanggal` dengan default 7 hari terakhir. Pemilih tanggal berupa tombol yang membuka sheet dari bawah, bukan dropdown kecil.

2. **Empat kartu KPI, dua kolom dua baris.** Masing-masing memuat angka besar, label, dan pembanding periode sebelumnya beserta arahnya.
   - Berita masuk
   - Sentimen negatif, dengan persentase terhadap total
   - Sentimen positif
   - Media aktif memuat

   Kartu negatif berubah latar menjadi `sentimen-negatif-lembut` saat proporsinya melewati 40%. Jangan berubah merah menyala, jangan berkedip. Perubahan halus sudah cukup menarik perhatian dan tidak terasa mengancam.

3. **Kartu peringatan, muncul kondisional.** Tampil hanya kalau ada alert belum dibaca dalam 24 jam terakhir. Isi: jumlah peringatan, ringkasan yang terbaru, tautan ke halaman sentimen. Kalau tidak ada, kartu ini tidak dirender sama sekali. Jangan tampilkan kartu kosong bertuliskan "Tidak ada peringatan", karena itu menghabiskan ruang layar yang berharga.

4. **Grafik tren sentimen.** Stacked area, tinggi 240 piksel, legend di bawah.

5. **Tiga isu teratas.** Bukan word cloud di halaman ini. Tiga baris, masing-masing memuat nama isu, jumlah artikel, badge sentimen dominan, dan lonjakannya. Word cloud disimpan untuk halaman isu.

6. **Lima berita terbaru.** `KartuArtikel`, dengan tautan ke arsip berita.

7. **Footer transparansi.** Satu baris teks kecil: "Analisis otomatis, akurasi terukur 0,79 F1 macro pada 400 artikel uji (evaluasi 24 Juli 2026)". Angkanya diambil dari baris terbaru `evaluasi_model`.

Baris terakhir itu terlihat sepele dan justru menentukan apakah sistem dipercaya. Ketika ada satu label yang jelas salah, dan itu pasti terjadi, baris ini yang membuat pengguna berpikir "sistemnya memang tidak sempurna dan itu diakui" alih-alih "sistemnya ngawur".

**Susunan desktop:** empat kartu KPI menjadi satu baris, grafik tren mengisi dua pertiga lebar dengan donat komposisi di sepertiga sisanya, isu teratas dan berita terbaru berdampingan.

**Keadaan sentimen belum tersedia, berlaku sejak 4 Agustus 2026.** Selama
gerbang mutu relevansi belum `passed`, tidak ada angka sentimen yang boleh
tampil di halaman ini. Yang ditampilkan sebagai gantinya:

- kartu KPI negatif dan positif diganti satu kartu penjelas, bukan angka nol dan bukan skeleton yang berputar selamanya;
- grafik tren sentimen dan donat komposisi tidak dirender;
- kartu peringatan tidak muncul, karena alert sentimen memang berhenti;
- KPI berita masuk, media aktif memuat, isu teratas, dan berita terbaru tetap tampil apa adanya. Semuanya tidak bergantung pada model.

Teksnya menyebut keadaan dan alasannya, bukan istilah teknis:

> **Analisis sentimen belum tersedia.** Model penilai relevansi berita sedang
> disiapkan. Angka sentimen baru ditampilkan setelah pengujiannya memenuhi
> standar, supaya yang dinilai benar-benar berita tentang Pemerintah Kota
> Kendari.

Godaan terbesar di sini adalah menampilkan angka lama supaya halaman tidak
terlihat kosong. Jangan. Angka lama dihitung dari relevansi yang presisinya
69,9%, dan menampilkannya tanpa penanda berarti tetap menyampaikan hal yang
sudah diketahui salah, hanya dengan tanggal yang lebih tua.

### C.2 Detail artikel, `/admin/artikel/{id}`

Halaman tempat admin memperbaiki kesalahan model. Tujuan desainnya: koreksi harus selesai dalam bawah 15 detik.

Susunan dua kolom di desktop.

**Kolom kiri, dua pertiga:**
- Judul artikel sebagai tautan ke sumber aslinya, dengan ikon tautan keluar
- Baris metadata: nama media, penulis, waktu publikasi, jumlah kata
- Ringkasan maksimal 300 karakter. **Tidak ada isi artikel utuh di halaman ini**, sesuai aturan hak cipta di dokumen 01 bagian 6
- Tombol "Buka artikel asli"
- Kalau artikel ini punya salinan: daftar media yang menyalinnya beserta waktunya

**Kolom kanan, sepertiga:**
- **Kartu relevansi di paling atas.** Keputusan relevan atau tidak relevan, skor kemiripan, daftar sinyal yang ditemukan sistem (`sinyal_relevansi`) sebagai chip kecil, dan potongan kalimat yang memicunya. Skor ditulis sebagai "kemiripan 0,82", **jangan pernah sebagai persentase keyakinan**. Ia cosine similarity, bukan probabilitas, dan menampilkannya sebagai "82% yakin" membuat admin menyimpulkan hal yang tidak dikatakan angka itu. Dua tombol koreksi: `Relevan` dan `Tidak relevan`. Mengubahnya ke tidak relevan menyembunyikan kartu sentimen, karena artikel yang tidak membahas Pemkot tidak punya nada terhadap Pemkot
- Field alasan koreksi, **wajib** saat admin membalik keputusan relevansi model. Ini satu-satunya koreksi di sistem yang alasannya wajib, karena tiap pembalikan adalah calon hard negative dan tanpa alasan tertulis ia tidak bisa dipakai memperbaiki model
- Kartu sentimen di bawahnya, hanya kalau artikel relevan. Memuat `BadgeSentimen`, bar tiga skor probabilitas, dan `PenandaPerluReview` bila berlaku
- Di bawah badge sentimen: tiga tombol koreksi, Negatif, Netral, Positif. Satu klik langsung menyimpan lewat request Inertia partial reload, tanpa dialog konfirmasi
- Kartu kategori dan tag sumber, ditandai jelas sebagai metadata dari media, bukan penilaian sistem
- Field catatan koreksi opsional, tampil setelah label diubah
- Kalau sudah pernah dikoreksi: baris kecil bertuliskan siapa dan kapan, dengan tombol batalkan koreksi
- Kartu kecil berisi versi model dan waktu analisis

Alasan tanpa dialog konfirmasi: admin akan mengoreksi puluhan label per sesi, dan dialog per koreksi menambah beberapa menit kerja setiap hari. Sebagai gantinya, sediakan toast dengan tombol batalkan yang bertahan lima detik, dan catat semuanya di activity log.

### C.3 Ruang kerja pelabelan, `/admin/pelabelan`

Halaman ini yang menentukan gold set jadi atau tidak. Kalau melabeli 400 artikel terasa menyakitkan, pekerjaannya tidak akan selesai dan Anda tidak akan punya angka akurasi.

Satu artikel memenuhi layar. Tanpa sidebar, tanpa tabel.

- Progres di atas: "Artikel 47 dari 400". Angkanya **artikel unik**, bukan pasangan artikel-konteks. Mencampur keduanya membuat progres terlihat tiga kali lebih maju daripada kenyataannya
- Judul dan ringkasan artikel
- Kategori dan tag dari sumber, ditampilkan sebagai chip. Sering inilah yang paling cepat menjawab apakah artikel membahas Pemkot atau hanya berlokasi di Kendari
- Potongan kalimat di sekitar sebutan Pemkot, OPD, atau pejabat, dengan sebutannya ditebalkan
- Pertanyaan pertama: apakah artikel ini secara substantif membahas Pemerintah Kota Kendari? Tombol Relevan dan Tidak relevan
- Kalau relevan, pertanyaan kedua: bagaimana nadanya terhadap Pemkot? Tombol Negatif, Netral, Positif
- Field catatan opsional, kecuali pada artikel yang dinyatakan tidak relevan padahal model menyatakan relevan. Di kasus itu catatan diminta, karena baris itulah hard negative yang paling berguna

Tidak ada lagi tiga kartu konteks untuk satu artikel, dan tidak ada lagi
pemilih konteks di kanan atas. Satu artikel, satu keputusan relevansi, dan
paling banyak satu keputusan sentimen.
- **Label model disembunyikan sampai pelabel memutuskan.** Ini bukan detail kecil. Kalau pelabel melihat jawaban model lebih dulu, ia akan cenderung menyetujuinya, dan gold set Anda berhenti mengukur apa pun. Setelah pelabel memilih, baru tampilkan apa kata model sebagai umpan balik.
- Pintasan keyboard: `1` negatif, `2` netral, `3` positif, `4` tidak relevan, `←` artikel sebelumnya. Cantumkan pintasannya di layar. Dokumen 09 menjelaskan mengapa `4` dan bukan `r`.

Dengan tata letak ini, satu artikel memakan waktu sekitar 20 detik dan 400 artikel selesai dalam kurang lebih dua jam kerja terfokus, jauh di bawah perkiraan delapan jam di dokumen 01. Tanpa pintasan keyboard dan tanpa penyembunyian label model, angkanya kembali ke delapan jam dan hasilnya lebih buruk.

### C.3.1 Antrean perlu review, `/admin/review`

Tata letaknya meminjam C.3, tapi isinya artikel produksi, bukan gold set.
Keputusan di sini langsung mengubah dashboard, sedangkan keputusan di halaman
pelabelan hanya mengubah penggaris. Beri warna header yang berbeda supaya
keduanya tidak tertukar.

Urutan antreannya, dari yang paling dulu disodorkan:

1. keyakinan paling dekat ambang;
2. artikel dari sumber yang baru ditambahkan;
3. artikel yang ditebak negatif;
4. artikel yang tag sumbernya bertentangan dengan keputusan model;
5. artikel yang menyebut Pemkot hanya sekali;
6. artikel dari media bertier tinggi.

Urutan ini bukan selera. Artikel yang keyakinannya di tengah adalah tempat
model paling sering salah, dan artikel negatif dari media besar adalah yang
paling cepat sampai ke pimpinan lewat jalur lain.

Tampilkan jumlah antrean di sidebar. Kalau `perlu_review` melewati 20% dari
artikel harian, yang bermasalah ambangnya, bukan admin yang kurang rajin.

### C.4 Kontrak saya, `/portal/kontrak`

- `ProgresKontrak` besar di atas: realisasi terhadap target, persentase, sisa hari
- Tabel pemuatan yang tercatat: tanggal, judul, sumber pencatatan, status verifikasi
- Tombol menonjol "Lapor pemuatan baru"
- Bagian terpisah untuk laporan yang ditolak, beserta alasannya, dan tombol perbaiki

**Halaman ini tidak menampilkan skor sentimen.** Ini keputusan produk di dokumen 01 bagian 8, bukan kelalaian. Kalau media bisa melihat nilai sentimennya, sebagian akan menyesuaikan gaya penulisan agar terbaca positif oleh model, dan dalam beberapa bulan data sentimen Anda mengukur kepatuhan terhadap model, bukan nada pemberitaan. Tulis catatan ini sebagai komentar di kode controller-nya, karena enam bulan dari sekarang akan ada yang meminta fiturnya ditambahkan.

### C.5 Isu hangat, `/eksekutif/isu`

- `PemilihRentangTanggal` dan `PemilihKonteks` di atas
- Tab: Kata kunci, Entitas
- Tab kata kunci: word cloud di atas, lalu tabel kata kunci dengan kolom istilah, jumlah artikel, skor lonjakan, dan sentimen dominan. Klik satu istilah membuka arsip berita yang sudah terfilter
- Tab entitas: bar horizontal per jenis entitas, dikelompokkan orang, OPD, lokasi, program

Word cloud di mobile diganti daftar sepuluh teratas. Word cloud di layar sempit tidak terbaca dan hanya menghabiskan bandwidth.

### C.6 Lapor pemuatan, `/portal/lapor`

Prinsip halaman ini: satu isian wajib, sisanya kerja sistem. Menggantikan empat field Google Form lama. Target waktu dari tempel URL sampai konfirmasi di bawah 15 detik.

**Bagian atas: yang sudah tercatat otomatis.** Sebelum form, tampilkan daftar artikel media ini yang sudah terdeteksi crawler dan sudah dihitung ke kontrak dalam 30 hari terakhir (F-48). Judulnya: "Berita berikut sudah tercatat otomatis, tidak perlu dilaporkan lagi". Fungsinya ganda: mengurangi laporan ganda, dan menunjukkan bahwa sistem bekerja untuk media, bukan menambah beban mereka.

**Form utama:**

1. Satu textarea bertuliskan "Tempel tautan berita, satu per baris" (F-49). Kontrak tujuan dipilih otomatis kalau media hanya punya satu kontrak aktif; dropdown muncul hanya kalau lebih dari satu.
2. Tombol "Periksa". Sistem memvalidasi domain (F-50), mengecek duplikat terhadap `url_kanonik`, dan mengekstrak tiap halaman.
3. Hasil per URL tampil sebagai daftar kartu pratinjau dengan tiga kemungkinan:
   - **Berhasil**: judul dan tanggal hasil ekstraksi tampil untuk dicek sekilas. Media tidak mengetik apa pun.
   - **Sudah tercatat**: badge abu-abu, tautan ke baris pemuatan yang sudah ada. Tidak membuat baris baru.
   - **Gagal diekstrak**: kartu melebar menjadi isian manual berisi judul, tanggal, dan unggah tangkapan layar (F-51). Hanya kasus ini yang meminta isian tambahan.
4. Field keterangan opsional, terlipat di balik tautan "Tambah catatan".
5. Satu tombol "Kirim semua". Setelah terkirim, toast konfirmasi dan daftar masuk ke tabel dengan status menunggu verifikasi.

Setelah konfirmasi, job pengarsipan berjalan di latar belakang (F-52). Media tidak melihat dan tidak menunggu proses ini.

**Yang tidak ada di halaman ini**: skor sentimen, sesuai C.4. Dan tidak ada field "nama media" atau "pilih media", karena identitas datang dari akun yang login. Kalau Anda merasa perlu menambahkan field, periksa dulu apakah sistem sebenarnya sudah tahu jawabannya.

---

## Bagian D: Keadaan kosong dan error

Setiap halaman daftar butuh empat keadaan, dan mengabaikannya adalah penyebab paling umum aplikasi terasa belum jadi.

| Keadaan | Yang ditampilkan |
|---------|------------------|
| Loading | Skeleton berbentuk seperti isi yang akan datang, bukan spinner |
| Kosong karena belum ada data | Penjelasan dan tombol tindakan. Contoh: "Belum ada sumber feed. Tambahkan RSS media partner untuk mulai mengumpulkan berita." |
| Kosong karena filter | "Tidak ada berita yang cocok dengan filter ini" dan tombol reset filter |
| Error | Penjelasan singkat, tombol coba lagi, dan kode error untuk dilaporkan |

Bedakan kosong-karena-belum-ada-data dengan kosong-karena-filter. Menyatukan keduanya membuat admin baru berpikir sistemnya rusak padahal ia hanya salah memilih tanggal.

## Bagian E: Aksesibilitas

Sistem pemerintah dibuka dengan berbagai perangkat dan kemampuan. Empat hal berikut tidak mahal untuk dikerjakan dan sulit ditambal belakangan.

1. Kontras minimal 4,5:1 untuk teks. Periksa token sentimen terhadap latar terang dan gelap.
2. Setiap elemen interaktif punya `:focus-visible` yang terlihat. Starter kit sudah menyediakannya, jangan dihapus dengan `outline-none`.
3. Seluruh alur admin bisa dijalankan dengan keyboard saja.
4. Grafik punya alternatif tabel. ECharts tidak dapat dibaca screen reader, jadi sediakan tombol "Lihat sebagai tabel" pada setiap grafik di panel eksekutif. Tombol ini juga berguna bagi pengguna yang ingin menyalin angkanya ke laporan.
