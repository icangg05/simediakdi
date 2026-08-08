# Implementasi Dashboard Eksekutif SIMEDIA Kendari

## 1. Tujuan Dokumen

Dokumen ini menjadi panduan implementasi Dashboard Eksekutif SIMEDIA Kendari untuk role Eksekutif, khususnya akun Wali Kota.

Dashboard harus membantu pimpinan memahami kondisi pemberitaan Pemerintah Kota Kendari secara cepat tanpa perlu membaca seluruh artikel satu per satu.

Pertanyaan utama yang harus dapat dijawab dashboard:

1. Bagaimana kondisi pemberitaan Pemerintah Kota Kendari saat ini?
2. Apakah nada pemberitaan cenderung positif, netral, atau negatif?
3. Apa topik yang paling sering dibicarakan?
4. Topik apa yang sedang meningkat?
5. Isu apa yang perlu mendapat perhatian pimpinan?
6. OPD mana yang paling banyak diberitakan?
7. Bagaimana kondisi sekarang dibanding periode sebelumnya?
8. Berita apa yang paling representatif untuk kondisi tersebut?

Dashboard bukan dashboard operator. Dashboard harus menjadi dashboard pengambilan keputusan.

---

# 2. Kondisi Sistem Saat Ini

Arsitektur implementasi disesuaikan dengan kondisi SIMEDIA saat ini.

Tidak menggunakan:

- `intfloat/multilingual-e5-small`
- vector database
- semantic clustering berbasis embedding
- similarity search berbasis vector
- inference server embedding tambahan

Komponen yang digunakan:

- Laravel sebagai backend utama
- MySQL sebagai database
- proses klasifikasi relevansi yang sudah tersedia
- proses analisis sentimen yang sudah tersedia
- queue untuk proses analisis AI
- Gemini untuk interpretasi tingkat eksekutif
- cache untuk mempercepat dashboard

Prinsip utama:

```text
AI tidak menghitung statistik.

Laravel dan MySQL menghitung:
- jumlah berita
- persentase
- tren
- perbandingan
- ranking
- jumlah sumber media

AI hanya membuat:
- nama topik
- ringkasan topik
- executive summary
- rangkuman nada pemberitaan
- penjelasan isu yang perlu diperhatikan
```

---

# 3. Sumber Data Dashboard

Dashboard Eksekutif hanya menggunakan artikel yang sudah dinyatakan relevan terhadap Pemerintah Kota Kendari.

Artikel tidak relevan tidak dimasukkan ke dalam:

- total pemberitaan
- grafik sentimen
- topik utama
- perbandingan periode
- isu perhatian
- OPD paling banyak diberitakan
- ringkasan eksekutif

Contoh filter dasar:

```php
Article::query()
    ->where('relevance_status', 'relevant');
```

Sesuaikan nama kolom dengan struktur database SIMEDIA yang sebenarnya.

Sentimen yang digunakan:

```text
positive
neutral
negative
```

Kategori `not_relevant` tidak ikut dalam perhitungan sentimen Dashboard Eksekutif.

---

# 4. Pilihan Periode

Gunakan empat pilihan periode:

```text
Hari Ini
7 Hari
30 Hari
3 Bulan
```

Definisi:

## Hari Ini

```text
start = awal hari ini
end   = akhir hari ini
```

## 7 Hari

```text
start = hari ini dikurangi 6 hari
end   = akhir hari ini
```

## 30 Hari

```text
start = hari ini dikurangi 29 hari
end   = akhir hari ini
```

## 3 Bulan

Untuk tahap awal, gunakan maksimal 90 hari.

```text
start = hari ini dikurangi 89 hari
end   = akhir hari ini
```

Gunakan rolling period, bukan bulan kalender.

Contoh:

Jika hari ini 9 Agustus 2026:

```text
7 Hari:
3 Agustus 2026 sampai 9 Agustus 2026

30 Hari:
11 Juli 2026 sampai 9 Agustus 2026

3 Bulan:
12 Mei 2026 sampai 9 Agustus 2026
```

---

# 5. Periode Pembanding

Setiap pilihan periode harus memiliki periode sebelumnya dengan panjang yang sama.

Contoh:

```text
7 hari sekarang
dibandingkan
7 hari sebelumnya
```

```text
30 hari sekarang
dibandingkan
30 hari sebelumnya
```

```text
90 hari sekarang
dibandingkan
90 hari sebelumnya
```

Untuk Hari Ini:

```text
hari ini
dibandingkan
kemarin
```

Tujuannya agar dashboard tidak hanya menampilkan kondisi statis, tetapi juga arah perubahan.

---

# 6. Susunan Dashboard

Urutan halaman yang direkomendasikan:

```text
1. Header dan pilihan periode
2. Executive Summary
3. KPI utama
4. Distribusi sentimen
5. Tren sentimen
6. Topik utama
7. Isu yang perlu diperhatikan
8. Rangkuman nada pemberitaan
9. OPD paling banyak diberitakan
10. Perbandingan dengan periode sebelumnya
11. Berita representatif
12. Sumber media
```

---

# 7. Header Dashboard

Contoh:

```text
Dashboard Eksekutif

Kondisi pemberitaan Pemerintah Kota Kendari

[ Hari Ini ] [ 7 Hari ] [ 30 Hari ] [ 3 Bulan ]
```

Tambahkan informasi:

```text
Terakhir diperbarui: 9 Agustus 2026, 01:00 WITA
```

Pilihan periode dapat dikirim melalui query parameter:

```text
/executive/dashboard?period=today

/executive/dashboard?period=7d

/executive/dashboard?period=30d

/executive/dashboard?period=90d
```

---

# 8. Executive Summary

Executive Summary merupakan bagian pertama yang dibaca Wali Kota.

Contoh:

```text
Pemberitaan Pemerintah Kota Kendari dalam tujuh hari terakhir masih
didominasi nada positif. Sorotan terbesar berasal dari pelayanan publik,
kegiatan pemerintah, dan program kebersihan lingkungan.

Namun, pemberitaan mengenai pengelolaan parkir mengalami peningkatan dan
menjadi salah satu sumber pemberitaan negatif yang paling sering muncul
dibandingkan periode sebelumnya.
```

Di bawah ringkasan utama tampilkan tiga poin:

```text
Nada umum:
Cenderung Positif

Topik dominan:
Pelayanan publik dan kebersihan kota

Perlu perhatian:
Pemberitaan mengenai pengelolaan parkir meningkat
```

Gemini boleh membuat bagian ini.

Gemini tidak boleh menghitung angkanya sendiri.

---

# 9. KPI Utama

Batasi sekitar 5 sampai 6 KPI.

## 9.1 Total Pemberitaan Relevan

Contoh:

```text
312
Berita Relevan

Naik 18% dibanding periode sebelumnya
```

## 9.2 Positif

```text
58%
Positif

Turun 2,3 poin persentase
```

## 9.3 Netral

```text
29%
Netral

Naik 1,2 poin persentase
```

## 9.4 Negatif

```text
13%
Negatif

Naik 1,1 poin persentase
```

## 9.5 Media Aktif

```text
26
Media memberitakan Pemkot
```

## 9.6 Intensitas Pemberitaan

```text
44,5
Rata-rata berita per hari
```

Untuk persentase sentimen, gunakan istilah `poin persentase` ketika membandingkan proporsi.

Contoh benar:

```text
Negatif naik dari 9% menjadi 13%.

Naik 4 poin persentase.
```

Bukan:

```text
Naik 4%.
```

---

# 10. Distribusi Sentimen

Gunakan donut chart.

Contoh:

```text
Positif  58%
Netral   29%
Negatif  13%
```

Rumus:

```php
$sentimentTotal = $positive + $neutral + $negative;

$positivePercentage = $sentimentTotal > 0
    ? ($positive / $sentimentTotal) * 100
    : 0;
```

Lakukan hal yang sama untuk netral dan negatif.

Tidak relevan tidak masuk denominator.

---

# 11. Tren Sentimen

Untuk periode 7 hari, 30 hari, dan 90 hari, tampilkan tren per hari.

Contoh data frontend:

```json
[
  {
    "date": "2026-08-03",
    "positive": 21,
    "neutral": 8,
    "negative": 3
  },
  {
    "date": "2026-08-04",
    "positive": 18,
    "neutral": 11,
    "negative": 5
  }
]
```

Grafik dapat menampilkan:

```text
Positif
Netral
Negatif
```

Gunakan line chart.

Untuk Hari Ini, bila jumlah data cukup, tren dapat ditampilkan per jam.

Jika tidak cukup, cukup tampilkan komposisi hari itu.

---

# 12. Istilah yang Digunakan di UI

Jangan menggunakan:

```text
Sentimen masyarakat
```

Gunakan:

```text
Nada pemberitaan
Sentimen pemberitaan
Kondisi pemberitaan media
```

SIMEDIA menganalisis pemberitaan media, bukan opini seluruh masyarakat Kendari.

---

# 13. Topik yang Sering Dibicarakan

Topik tidak boleh hanya berupa keyword seperti:

```text
Sampah
Parkir
Pendidikan
Kesehatan
```

Topik harus berupa kalimat singkat yang menjelaskan pembahasan.

Contoh:

```text
Upaya Pemkot Kendari meningkatkan kebersihan lingkungan dan penanganan drainase.
```

```text
Pengelolaan parkir mulai mendapat sorotan dan keluhan dalam sejumlah pemberitaan.
```

```text
Program pelayanan kesehatan Pemkot banyak diberitakan setelah sejumlah kegiatan masyarakat.
```

Tampilkan maksimal:

```text
5 sampai 8 topik
```

---

# 14. Cara Membuat Topik Tanpa E5

Karena SIMEDIA saat ini tidak menggunakan E5, proses topik dilakukan menggunakan Gemini.

Namun jangan mengirim seluruh isi ribuan artikel sekaligus.

Gunakan:

```text
judul
ringkasan pendek
sentimen
sumber media
tanggal
article_id
```

Contoh input:

```json
[
  {
    "id": 1201,
    "title": "Pemkot Kendari Gelar Gerakan Irigasi Bersih",
    "summary": "Pemerintah Kota Kendari bersama instansi terkait melakukan kegiatan pembersihan irigasi.",
    "sentiment": "positive",
    "source": "Media A"
  },
  {
    "id": 1202,
    "title": "Pemkot Tingkatkan Pembersihan Drainase",
    "summary": "Pembersihan dilakukan untuk mengurangi genangan dan menjaga kebersihan lingkungan.",
    "sentiment": "positive",
    "source": "Media B"
  }
]
```

Prompt Gemini meminta:

```text
Kelompokkan artikel yang membahas isu atau peristiwa yang sama.

Jangan membuat kelompok hanya berdasarkan satu kata.

Nama setiap topik harus berupa kalimat singkat yang menjelaskan isu.

Jangan mengubah article_id.

Satu artikel hanya boleh masuk ke satu topik utama.

Jika sebuah artikel tidak cocok dengan topik lain, boleh dibuat sebagai topik
tersendiri jika cukup penting.
```

Output:

```json
[
  {
    "title": "Upaya Pemkot Kendari meningkatkan kebersihan lingkungan dan penanganan drainase",
    "summary": "Sejumlah media memberitakan kegiatan pembersihan saluran dan lingkungan yang dilakukan pemerintah kota.",
    "article_ids": [1201, 1202]
  }
]
```

Setelah itu Laravel menghitung statistik setiap topik.

---

# 15. Jangan Meminta Gemini Menghitung Statistik Topik

Setelah Gemini mengembalikan:

```json
{
  "article_ids": [1201, 1202, 1208, 1210]
}
```

Laravel menghitung:

```text
jumlah artikel
jumlah media
jumlah positif
jumlah netral
jumlah negatif
persentase
```

Contoh query:

```php
$articles = Article::query()
    ->whereIn('id', $topic['article_ids'])
    ->get();

$articleCount = $articles->count();

$positiveCount = $articles
    ->where('sentiment', 'positive')
    ->count();

$neutralCount = $articles
    ->where('sentiment', 'neutral')
    ->count();

$negativeCount = $articles
    ->where('sentiment', 'negative')
    ->count();

$sourceCount = $articles
    ->pluck('source_id')
    ->unique()
    ->count();
```

---

# 16. Struktur Topik di Dashboard

Contoh card:

```text
Pengelolaan parkir mulai mendapat sorotan dan keluhan

21 berita
5 media

Positif   19%
Netral    24%
Negatif   57%

Tren: meningkat

[Lihat berita terkait]
```

Klik topik membuka:

```text
/executive/topics/{topic}
```

atau:

```text
/executive/topics/{topic_id}
```

---

# 17. Rangkuman Nada Pemberitaan

Buat tiga bagian.

## Positif

Contoh:

```text
Pemberitaan positif terutama berasal dari kegiatan pelayanan publik,
program pemerintah, kegiatan Wali Kota, serta kolaborasi Pemkot dengan
berbagai lembaga.
```

## Netral

Contoh:

```text
Pemberitaan netral didominasi informasi agenda pemerintahan, penyampaian
program, pengumuman layanan, dan kegiatan kelembagaan.
```

## Negatif

Contoh:

```text
Pemberitaan negatif terutama berkaitan dengan pengelolaan parkir,
kebersihan lingkungan, dan kritik terhadap sejumlah pelayanan publik.
```

Gemini bertugas membuat bahasa rangkuman.

Backend menyediakan data pendukungnya.

---

# 18. Isu yang Perlu Diperhatikan

Bagian ini penting untuk akun Wali Kota.

Jangan menampilkan semua berita negatif sebagai isu penting.

Sebuah isu menjadi prioritas jika memenuhi beberapa indikator:

```text
jumlah berita negatif cukup tinggi
muncul di beberapa media
meningkat dibanding periode sebelumnya
muncul beberapa hari berturut-turut
berkaitan langsung dengan layanan atau kebijakan Pemkot
```

Contoh:

```text
Pengelolaan Parkir

12 berita negatif
5 media berbeda
3 hari berturut-turut
naik dibanding periode sebelumnya

Prioritas: Tinggi
```

---

# 19. Issue Priority Score

Untuk tahap pertama gunakan scoring sederhana.

Contoh:

```php
$score = 0;

if ($negativeArticleCount >= 3) {
    $score += 2;
}

if ($negativeArticleCount >= 7) {
    $score += 2;
}

if ($sourceCount >= 3) {
    $score += 2;
}

if ($growthPercentage >= 30) {
    $score += 2;
}

if ($consecutiveDays >= 2) {
    $score += 1;
}

if ($consecutiveDays >= 4) {
    $score += 1;
}
```

Klasifikasi:

```text
0 sampai 2   = rendah
3 sampai 5   = sedang
6 ke atas    = tinggi
```

Nilai dapat dituning setelah sistem memiliki data aktual.

---

# 20. OPD Paling Banyak Diberitakan

Jika SIMEDIA sudah memiliki data OPD atau relasi artikel ke OPD, tampilkan ranking.

Contoh:

| OPD | Berita | Positif | Netral | Negatif |
|---|---:|---:|---:|---:|
| Diskominfo | 48 | 72% | 25% | 3% |
| Dishub | 41 | 29% | 32% | 39% |
| DLHK | 35 | 34% | 31% | 35% |
| Dinkes | 29 | 68% | 27% | 5% |

Jika metadata OPD belum tersedia, bagian ini dapat ditunda ke tahap berikutnya.

Jangan meminta Gemini menebak OPD dari data yang tidak tersedia.

---

# 21. Sumber Media

Tampilkan media yang paling sering memberitakan Pemerintah Kota Kendari.

Contoh:

```text
Kendari Pos      31 berita
Zonasultra       27 berita
Media Kendari    25 berita
Britakita        19 berita
```

Tambahkan komposisi sentimen bila dibutuhkan.

Fungsi bagian ini:

```text
mengetahui media yang paling aktif
melihat penyebaran isu
mengetahui apakah isu hanya muncul di satu media atau sudah lintas media
```

---

# 22. Berita Representatif

Jangan hanya menampilkan berita terbaru.

Kelompokkan menjadi:

## Perlu Diperhatikan

Artikel negatif atau isu prioritas tinggi.

## Pemberitaan Positif Utama

Artikel positif dengan isu penting atau penyebaran media tinggi.

## Berita Terbaru

Artikel relevan terbaru secara kronologis.

Contoh card:

```text
NEGATIF

DPRD Soroti Pengelolaan Parkir di Kawasan X

4 media membahas isu serupa
2 jam lalu

[Lihat berita]
```

---

# 23. Struktur Database Baru

Tidak wajib langsung membuat semuanya.

Namun struktur berikut direkomendasikan.

---

# 24. Tabel `daily_media_metrics`

Tujuan:

Menyimpan agregasi harian agar query 30 hari dan 90 hari tetap ringan.

Contoh migration:

```php
Schema::create('daily_media_metrics', function (Blueprint $table) {
    $table->id();

    $table->date('metric_date')->unique();

    $table->unsignedInteger('total_relevant')->default(0);

    $table->unsignedInteger('positive_count')->default(0);
    $table->unsignedInteger('neutral_count')->default(0);
    $table->unsignedInteger('negative_count')->default(0);

    $table->unsignedInteger('source_count')->default(0);

    $table->timestamps();
});
```

Tidak perlu menyimpan persentase karena dapat dihitung saat dibutuhkan.

---

# 25. Tabel `executive_summaries`

Contoh:

```php
Schema::create('executive_summaries', function (Blueprint $table) {
    $table->id();

    $table->string('period_type');
    $table->date('start_date');
    $table->date('end_date');

    $table->string('overall_tone')->nullable();
    $table->string('headline')->nullable();

    $table->text('summary')->nullable();

    $table->json('key_points')->nullable();
    $table->json('attention_required')->nullable();
    $table->json('sentiment_summary')->nullable();

    $table->unsignedInteger('article_count')->default(0);

    $table->string('ai_provider')->nullable();
    $table->string('ai_model')->nullable();

    $table->timestamp('generated_at')->nullable();

    $table->timestamps();

    $table->unique([
        'period_type',
        'start_date',
        'end_date'
    ]);
});
```

`period_type`:

```text
today
7d
30d
90d
```

---

# 26. Tabel `executive_topics`

Contoh:

```php
Schema::create('executive_topics', function (Blueprint $table) {
    $table->id();

    $table->string('period_type');

    $table->date('start_date');
    $table->date('end_date');

    $table->string('title');
    $table->text('summary')->nullable();

    $table->unsignedInteger('article_count')->default(0);

    $table->unsignedInteger('positive_count')->default(0);
    $table->unsignedInteger('neutral_count')->default(0);
    $table->unsignedInteger('negative_count')->default(0);

    $table->unsignedInteger('source_count')->default(0);

    $table->string('dominant_sentiment')->nullable();
    $table->string('trend')->nullable();

    $table->unsignedInteger('priority_score')->default(0);
    $table->string('priority_level')->nullable();

    $table->json('article_ids');

    $table->timestamp('generated_at')->nullable();

    $table->timestamps();
});
```

Untuk tahap awal, menyimpan `article_ids` sebagai JSON masih cukup.

Jika nanti relasi topik menjadi fitur besar, pindahkan ke pivot.

---

# 27. Pivot Topik di Tahap Lanjutan

Jika dibutuhkan:

```php
Schema::create('executive_topic_article', function (Blueprint $table) {
    $table->id();

    $table->foreignId('executive_topic_id')
        ->constrained()
        ->cascadeOnDelete();

    $table->foreignId('article_id')
        ->constrained()
        ->cascadeOnDelete();

    $table->timestamps();

    $table->unique([
        'executive_topic_id',
        'article_id'
    ]);
});
```

Untuk MVP tidak wajib.

---

# 28. Service Periode

Buat service:

```text
app/Services/Executive/ExecutivePeriodService.php
```

Tanggung jawab:

```text
menerjemahkan today, 7d, 30d, 90d
menentukan start_date
menentukan end_date
menentukan periode pembanding
```

Contoh interface:

```php
final class ExecutivePeriodService
{
    public function resolve(string $period): array
    {
        // return current dan previous period
    }
}
```

Contoh hasil:

```php
[
    'current' => [
        'start' => $start,
        'end' => $end,
    ],

    'previous' => [
        'start' => $previousStart,
        'end' => $previousEnd,
    ],
];
```

---

# 29. Analytics Service

Buat:

```text
app/Services/Executive/ExecutiveAnalyticsService.php
```

Tugas:

```text
mengambil artikel relevan
menghitung total
menghitung sentimen
menghitung persentase
menghitung tren
menghitung media aktif
menghitung perubahan periode
mengambil OPD
mengambil berita representatif
```

Contoh:

```php
final class ExecutiveAnalyticsService
{
    public function getMetrics(
        CarbonInterface $start,
        CarbonInterface $end
    ): array {
        //
    }

    public function getSentimentTrend(
        CarbonInterface $start,
        CarbonInterface $end
    ): array {
        //
    }

    public function comparePeriods(
        array $current,
        array $previous
    ): array {
        //
    }
}
```

---

# 30. Topic Service

Buat:

```text
app/Services/Executive/ExecutiveTopicService.php
```

Tanggung jawab:

```text
mengambil artikel representatif
menyiapkan payload ke Gemini
meminta Gemini membuat kelompok topik
memvalidasi article_ids
menghitung statistik topik di backend
menyimpan topik
```

Penting:

Gemini hanya boleh mengembalikan ID yang diberikan.

Validasi:

```php
$allowedIds = collect($inputArticles)
    ->pluck('id')
    ->map(fn ($id) => (int) $id)
    ->all();

$returnedIds = collect($aiTopics)
    ->pluck('article_ids')
    ->flatten()
    ->map(fn ($id) => (int) $id)
    ->all();

$invalidIds = array_diff($returnedIds, $allowedIds);

if (! empty($invalidIds)) {
    throw new RuntimeException(
        'AI mengembalikan article_id yang tidak valid.'
    );
}
```

---

# 31. Executive Summary Service

Buat:

```text
app/Services/Executive/ExecutiveSummaryService.php
```

Input:

```text
statistik periode
perbandingan periode
topik utama
isu perhatian
OPD
artikel representatif
```

Output:

```text
headline
summary
overall_tone
key_points
attention_required
sentiment_summary
```

---

# 32. Jangan Kirim Semua Artikel ke Gemini

Untuk 7 hari mungkin jumlah artikel masih kecil.

Namun untuk 30 hari dan 90 hari jangan mengirim seluruh isi artikel.

Gunakan artikel representatif.

Contoh strategi:

```text
maksimal 60 sampai 100 artikel sebagai input topik
```

Prioritas pemilihan:

```text
1. artikel negatif
2. artikel dari media berbeda
3. artikel terbaru
4. artikel dengan judul yang tidak duplikat
5. artikel dari topik atau OPD berbeda
```

Jika jumlah artikel masih terlalu besar, gunakan ringkasan harian.

---

# 33. Hierarchical Summary untuk Periode Panjang

Untuk 90 hari jangan meminta Gemini membaca seluruh artikel.

Gunakan:

```text
Artikel
  |
  v
Ringkasan Harian
  |
  v
Ringkasan Mingguan
  |
  v
Ringkasan 30 Hari
  |
  v
Ringkasan 90 Hari
```

Dengan ini biaya Gemini tetap terkendali.

Tahap awal bisa lebih sederhana:

```text
90 hari
  |
  v
ambil statistik database
  |
  v
ambil topik harian atau mingguan yang sudah tersedia
  |
  v
Gemini membuat ringkasan 90 hari
```

---

# 34. Prompt Gemini untuk Topik

Contoh system prompt:

```text
Anda adalah analis media Pemerintah Kota Kendari.

Tugas Anda mengelompokkan artikel berdasarkan isu atau pembahasan yang sama.

Aturan:

1. Gunakan hanya data yang diberikan.
2. Jangan membuat article_id baru.
3. Jangan mengubah article_id.
4. Satu artikel hanya boleh masuk ke satu topik utama.
5. Nama topik harus berupa kalimat singkat yang menjelaskan isu.
6. Jangan membuat nama topik berupa satu atau dua keyword.
7. Jangan menggabungkan artikel hanya karena memiliki kata yang sama.
8. Kelompokkan berdasarkan kesamaan makna, peristiwa, kebijakan, program,
   kritik, masalah, atau isu yang benar-benar sama.
9. Jangan menghitung statistik.
10. Jangan membuat kesimpulan yang tidak didukung artikel.

Contoh nama topik yang benar:

"Pengelolaan parkir mulai mendapat sorotan dan keluhan dalam sejumlah pemberitaan."

Contoh nama topik yang salah:

"Parkir"

Kembalikan JSON sesuai schema yang diberikan.
```

---

# 35. Schema Output Topik

Gunakan structured output jika provider mendukung.

Contoh:

```json
{
  "topics": [
    {
      "title": "string",
      "summary": "string",
      "article_ids": [1, 2, 3]
    }
  ]
}
```

Laravel kemudian menambahkan:

```text
article_count
positive_count
neutral_count
negative_count
source_count
dominant_sentiment
trend
priority_score
priority_level
```

---

# 36. Prompt Gemini untuk Executive Summary

Contoh:

```text
Anda adalah analis media untuk Dashboard Eksekutif Pemerintah Kota Kendari.

Buat ringkasan kondisi PEMBERITAAN MEDIA mengenai Pemerintah Kota Kendari.

Penting:

1. Jangan menyebut data ini sebagai sentimen masyarakat.
2. Gunakan istilah pemberitaan, nada pemberitaan, atau sentimen pemberitaan.
3. Gunakan hanya data yang diberikan.
4. Jangan menghitung ulang statistik.
5. Jangan menciptakan fakta.
6. Jangan memberi penilaian politik.
7. Hindari bahasa dramatis.
8. Prioritaskan perubahan yang signifikan.
9. Jelaskan topik dominan.
10. Jelaskan faktor utama pemberitaan positif.
11. Jelaskan faktor utama pemberitaan negatif.
12. Soroti isu yang meningkat.
13. Jika tidak ada isu yang cukup penting, nyatakan bahwa tidak ada perhatian khusus.
14. Ringkasan utama maksimal 2 paragraf.
15. Key points maksimal 4 poin.
16. Gunakan bahasa Indonesia yang formal, ringkas, dan mudah dipahami pimpinan.

Kembalikan JSON sesuai schema.
```

---

# 37. Schema Executive Summary

```json
{
  "overall_tone": "positive",
  "headline": "Pemberitaan Pemkot Kendari masih didominasi sentimen positif",
  "summary": "Ringkasan kondisi pemberitaan.",
  "key_points": [
    "Poin pertama",
    "Poin kedua",
    "Poin ketiga"
  ],
  "attention_required": [
    {
      "topic": "Judul isu",
      "reason": "Alasan isu perlu diperhatikan"
    }
  ],
  "sentiment_summary": {
    "positive": "Ringkasan nada positif",
    "neutral": "Ringkasan nada netral",
    "negative": "Ringkasan nada negatif"
  }
}
```

Nilai `overall_tone`:

```text
positive
neutral
negative
mixed
```

---

# 38. Queue

Jangan generate topik dan executive summary langsung saat Wali Kota membuka dashboard.

Gunakan queue.

Contoh jobs:

```text
GenerateDailyExecutiveMetrics
GenerateExecutiveTopics
GenerateExecutiveSummary
```

Folder:

```text
app/Jobs/Executive/
```

Contoh:

```text
app/Jobs/Executive/GenerateExecutiveTopics.php
app/Jobs/Executive/GenerateExecutiveSummary.php
```

---

# 39. Kapan Job Dijalankan

Untuk tahap awal:

```text
setelah batch analisis artikel selesai
```

atau:

```text
setiap 15 sampai 30 menit jika terdapat artikel relevan baru
```

Jangan regenerasi jika tidak ada perubahan data.

Simpan fingerprint.

Contoh:

```text
period
article_count
max_article_id
latest_article_updated_at
```

Jika nilainya sama dengan generasi sebelumnya, tidak perlu memanggil Gemini.

---

# 40. Cache

Gunakan cache key seperti:

```text
executive:dashboard:today:2026-08-09
executive:dashboard:7d:2026-08-09
executive:dashboard:30d:2026-08-09
executive:dashboard:90d:2026-08-09
```

Topik:

```text
executive:topics:7d:2026-08-09
```

Summary:

```text
executive:summary:7d:2026-08-09
```

Cache dapat dihapus ketika:

```text
artikel baru selesai dianalisis
sentimen berubah
relevansi berubah
topik selesai diregenerasi
summary selesai diregenerasi
```

---

# 41. API Dashboard

Contoh endpoint:

```text
GET /api/executive/dashboard?period=7d
```

Response:

```json
{
  "period": {
    "type": "7d",
    "start": "2026-08-03",
    "end": "2026-08-09"
  },

  "summary": {},

  "metrics": {
    "total_articles": 312,
    "active_sources": 26,
    "average_articles_per_day": 44.57
  },

  "sentiment": {
    "positive": {
      "count": 181,
      "percentage": 58.01
    },
    "neutral": {
      "count": 90,
      "percentage": 28.85
    },
    "negative": {
      "count": 41,
      "percentage": 13.14
    }
  },

  "comparison": {},

  "sentiment_trend": [],

  "topics": [],

  "attention_items": [],

  "top_opds": [],

  "top_sources": [],

  "representative_articles": {}
}
```

---

# 42. Controller

Contoh:

```text
app/Http/Controllers/Executive/ExecutiveDashboardController.php
```

Controller hanya mengorkestrasi service.

Jangan taruh seluruh logika analitik di controller.

Contoh:

```php
final class ExecutiveDashboardController
{
    public function __invoke(
        Request $request,
        ExecutiveDashboardService $dashboardService
    ) {
        $period = $request->string('period', '7d')->toString();

        return response()->json(
            $dashboardService->build($period)
        );
    }
}
```

---

# 43. ExecutiveDashboardService

Buat facade service:

```text
app/Services/Executive/ExecutiveDashboardService.php
```

Tugas:

```text
resolve periode
ambil metrics
ambil comparison
ambil sentiment trend
ambil topik
ambil executive summary
ambil issue attention
ambil OPD
ambil media
ambil berita representatif
```

Dengan ini controller tetap tipis.

---

# 44. Authorization

Dashboard hanya bisa diakses role tertentu.

Contoh:

```text
executive
walikota
super_admin
```

Gunakan policy atau middleware.

Contoh:

```php
Route::middleware([
    'auth',
    'role:executive|walikota|super_admin',
])->group(function () {
    Route::get(
        '/executive/dashboard',
        ExecutiveDashboardController::class
    );
});
```

Sesuaikan dengan library permission yang digunakan SIMEDIA.

---

# 45. Realtime

Tidak perlu seluruh dashboard menggunakan websocket pada tahap awal.

Yang perlu realtime:

```text
status data baru
waktu terakhir update
jumlah artikel hari ini
```

Sedangkan:

```text
topik
executive summary
rangkuman sentimen
```

cukup diperbarui melalui queue.

Tujuannya agar biaya AI tidak meningkat karena refresh halaman.

---

# 46. Loading State

Saat AI summary belum tersedia:

```text
Ringkasan sedang diperbarui berdasarkan berita terbaru.
```

Tetap tampilkan:

```text
KPI
grafik
jumlah berita
sentimen
media
```

Dashboard tidak boleh kosong hanya karena summary AI belum selesai.

---

# 47. Fallback Bila Gemini Gagal

Jika Gemini gagal:

```text
1. gunakan executive summary terakhir
2. tandai waktu generated_at
3. statistik tetap menggunakan data terbaru
4. log error
5. retry melalui queue
```

Contoh UI:

```text
Ringkasan AI terakhir diperbarui pukul 00:45 WITA.
Statistik di bawah menggunakan data terbaru.
```

---

# 48. Log AI

Simpan log minimal:

```text
provider
model
period
jumlah artikel input
token usage jika tersedia
durasi
status
error
generated_at
```

Tujuan:

```text
monitor biaya
monitor error
monitor kualitas
audit proses
```

---

# 49. Validasi Output AI

Jangan percaya output Gemini secara langsung.

Validasi:

```text
JSON valid
field wajib tersedia
article_id valid
panjang topik masuk akal
jumlah topik tidak berlebihan
sentiment value valid
tidak ada ID yang tidak dikenal
```

Jika gagal:

```text
reject response
retry maksimal sesuai kebijakan queue
gunakan data lama sebagai fallback
```

---

# 50. Batas Jumlah Topik

Rekomendasi:

```text
Hari Ini:
maksimal 5 topik

7 Hari:
maksimal 7 topik

30 Hari:
maksimal 8 topik

90 Hari:
maksimal 8 topik
```

Tujuannya agar dashboard tidak terlalu padat.

---

# 51. Topic Ranking

Setelah topik dibuat Gemini, ranking dilakukan backend.

Contoh score:

```php
$topicScore =
    ($articleCount * 1.0)
    + ($sourceCount * 2.0)
    + ($negativeCount * 0.5);
```

Untuk `Topik Utama`, prioritaskan:

```text
volume
jumlah media
keterkinian
```

Untuk `Perlu Perhatian`, gunakan priority score terpisah yang lebih berat ke sentimen negatif dan pertumbuhan.

---

# 52. Tren Topik

Tanpa E5, tren topik tidak perlu terlalu kompleks di MVP.

Cara sederhana:

Gemini menghasilkan topik untuk periode sekarang.

Untuk setiap topik, backend atau AI dapat mencari kecocokan terhadap topik periode sebelumnya menggunakan judul dan ringkasan.

Tahap awal bahkan cukup:

```text
new
stable
increasing
decreasing
```

Jika keakuratan belum baik, tampilkan tren hanya berdasarkan volume artikel yang dikelompokkan dalam periode tersebut.

Jangan memaksakan fitur tren topik jika datanya belum stabil.

---

# 53. Deduplikasi

Tanpa E5, jika SIMEDIA sudah memiliki sistem deduplikasi berita, gunakan hasil tersebut.

Jika belum, untuk Dashboard Eksekutif tahap awal:

```text
jangan membuat deduplikasi semantik sebagai dependency wajib
```

Sistem tetap dapat berjalan.

Gemini hanya perlu diberi data judul yang telah dibatasi agar duplikasi tidak mendominasi input.

---

# 54. Urutan Implementasi

## Fase 1

Bangun dashboard tanpa Gemini terlebih dahulu.

Implementasikan:

```text
periode
KPI
sentimen
grafik
comparison
media aktif
berita terbaru
```

Target:

Dashboard sudah berfungsi hanya dari MySQL.

---

## Fase 2

Tambahkan:

```text
daily_media_metrics
cache
query optimization
```

Target:

30 hari dan 90 hari tetap cepat.

---

## Fase 3

Tambahkan Gemini untuk:

```text
topik utama
ringkasan topik
executive summary
rangkuman sentimen
```

Target:

AI hanya interpretasi, bukan sumber angka.

---

## Fase 4

Tambahkan:

```text
isu perhatian
priority score
berita representatif
```

---

## Fase 5

Jika metadata OPD tersedia:

```text
ranking OPD
sentimen per OPD
detail OPD
```

---

## Fase 6

Optimasi:

```text
queue
cache invalidation
fallback AI
logging
monitoring
```

---

# 55. Struktur Folder yang Direkomendasikan

```text
app/
|
|-- Http/
|   `-- Controllers/
|       `-- Executive/
|           `-- ExecutiveDashboardController.php
|
|-- Jobs/
|   `-- Executive/
|       |-- GenerateExecutiveTopics.php
|       `-- GenerateExecutiveSummary.php
|
|-- Models/
|   |-- DailyMediaMetric.php
|   |-- ExecutiveSummary.php
|   `-- ExecutiveTopic.php
|
`-- Services/
    `-- Executive/
        |-- ExecutivePeriodService.php
        |-- ExecutiveAnalyticsService.php
        |-- ExecutiveTopicService.php
        |-- ExecutiveSummaryService.php
        `-- ExecutiveDashboardService.php
```

Jika project sudah memiliki pola arsitektur sendiri, ikuti struktur existing agar konsisten.

---

# 56. Tampilan Desktop

Struktur:

```text
Executive Summary
----------------------------------------------------

KPI KPI KPI KPI KPI

----------------------------------------------------

Distribusi Sentimen       Tren Sentimen

----------------------------------------------------

Topik Utama

----------------------------------------------------

Perlu Perhatian

----------------------------------------------------

Rangkuman Nada Pemberitaan

----------------------------------------------------

OPD                      Sumber Media

----------------------------------------------------

Berita Representatif
```

---

# 57. Tampilan Mobile

Urutan mobile:

```text
Periode

Executive Summary

KPI horizontal atau 2 kolom

Distribusi Sentimen

Tren

Topik Utama

Perlu Perhatian

Nada Pemberitaan

OPD

Media

Berita
```

Jangan membuat Wali Kota harus melakukan horizontal scroll untuk membaca informasi penting.

---

# 58. Drill Down

Setiap data penting harus bisa dibuka.

Contoh:

Klik:

```text
Negatif 13%
```

membuka:

```text
/executive/articles?period=7d&sentiment=negative
```

Klik topik:

```text
Pengelolaan parkir mulai mendapat sorotan
```

membuka:

```text
/executive/topics/{id}
```

Detail topik:

```text
ringkasan
jumlah berita
sentimen
timeline
media
artikel terkait
```

Klik OPD:

```text
/executive/opd/{id}?period=7d
```

---

# 59. Hal yang Jangan Dilakukan

Jangan:

```text
mengirim ribuan artikel mentah ke Gemini setiap dashboard dibuka
```

Jangan:

```text
meminta Gemini menghitung jumlah sentimen
```

Jangan:

```text
memasukkan artikel tidak relevan ke persentase sentimen
```

Jangan:

```text
menyebut hasil analisis sebagai sentimen masyarakat
```

Jangan:

```text
membuat topik hanya satu kata
```

Jangan:

```text
menjadikan semua artikel negatif sebagai alert
```

Jangan:

```text
memasang kembali multilingual-e5-small hanya demi Dashboard Eksekutif
```

Jangan:

```text
membuat Gemini dipanggil setiap reload halaman
```

---

# 60. Kondisi yang Baru Membutuhkan Embedding di Masa Depan

E5 atau embedding model lain baru layak dipertimbangkan jika SIMEDIA membutuhkan:

```text
semantic search
related articles
deteksi berita semantik yang duplikat
clustering puluhan ribu artikel
tracking isu lintas bulan yang lebih presisi
vector similarity
```

Fitur tersebut bukan dependency Dashboard Eksekutif versi sekarang.

---

# 61. Acceptance Criteria

Dashboard dianggap berhasil jika:

## Data

- hanya artikel relevan yang digunakan
- sentimen dihitung dari database
- persentase benar
- comparison menggunakan periode sebelumnya
- artikel tidak relevan tidak masuk statistik

## Performa

- dashboard tidak menunggu Gemini saat dibuka
- data statistik dapat tampil walau AI gagal
- periode 90 hari tetap responsif
- query agregasi tidak membaca seluruh isi artikel

## AI

- topik berupa kalimat singkat
- Gemini tidak membuat article_id baru
- Gemini tidak menghitung statistik
- ringkasan tidak menyebut sentimen masyarakat
- ringkasan tidak mengarang fakta
- AI summary disimpan dan di-cache

## UI

- kondisi pemberitaan dapat dipahami kurang dari satu menit
- isu penting terlihat jelas
- angka memiliki pembanding
- topik dapat dibuka ke artikel terkait
- dashboard nyaman di desktop dan mobile

---

# 62. Definition of Done MVP

MVP Dashboard Eksekutif dianggap selesai jika sudah memiliki:

```text
[ ] pilihan Hari Ini
[ ] pilihan 7 Hari
[ ] pilihan 30 Hari
[ ] pilihan 3 Bulan

[ ] total berita relevan
[ ] persentase positif
[ ] persentase netral
[ ] persentase negatif
[ ] jumlah media aktif

[ ] comparison dengan periode sebelumnya

[ ] donut sentimen
[ ] line chart tren sentimen

[ ] topik utama berbentuk kalimat
[ ] jumlah artikel per topik
[ ] sentimen per topik

[ ] executive summary
[ ] rangkuman positif
[ ] rangkuman netral
[ ] rangkuman negatif

[ ] isu yang perlu diperhatikan

[ ] berita representatif

[ ] cache
[ ] queue AI
[ ] fallback jika AI gagal
```

OPD dapat menjadi tambahan setelah metadata OPD siap.

---

# 63. Flow Akhir Sistem

```text
Crawler
   |
   v
Artikel
   |
   v
Klasifikasi Relevansi
   |
   +----------------------+
   |                      |
   | Tidak Relevan        | Relevan
   |                      |
   v                      v
Selesai              Analisis Sentimen
                           |
                           v
                        Database
                           |
            +--------------+--------------+
            |                             |
            v                             v
     Statistik Laravel              Queue Executive
            |                             |
            |                    +--------+--------+
            |                    |                 |
            |                    v                 v
            |               Generate Topik    Generate Summary
            |                    |                 |
            |                    +--------+--------+
            |                             |
            +--------------+--------------+
                           |
                           v
                         Cache
                           |
                           v
                  Dashboard Eksekutif
```

---

# 64. Rekomendasi Akhir

Untuk kondisi SIMEDIA sekarang, arsitektur yang direkomendasikan adalah:

```text
Relevansi
+
Sentimen
+
Laravel / MySQL
+
Gemini
+
Queue
+
Cache
```

Tidak perlu menambahkan E5 kembali.

Pembagian tanggung jawab:

```text
Laravel / MySQL
    menghitung dan menentukan fakta numerik

Gemini
    menjelaskan dan merangkum fakta tersebut

Dashboard
    menyajikan informasi agar pimpinan cepat mengambil keputusan
```

Fokus implementasi pertama adalah memastikan data statistik dan period comparison benar.

Setelah itu baru tambahkan topik dan executive summary berbasis Gemini.

Dengan pendekatan ini, Dashboard Eksekutif tetap kuat tanpa menambah kompleksitas model embedding yang saat ini belum dibutuhkan.
