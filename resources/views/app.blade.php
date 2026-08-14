<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        {{-- Lambang resmi Pemerintah Kota Kendari, berkas yang sama dengan logo di kop halaman. --}}
        <link rel="icon" type="image/webp" href="/img/Lambang_Kota_Kendari.webp">
        <link rel="apple-touch-icon" href="/img/Lambang_Kota_Kendari.webp">

        {{--
            Lambang ini elemen terbesar yang pertama terlihat (LCP) di halaman
            login maupun di kop setiap halaman lain. Tanpa baris ini peramban
            baru tahu gambarnya ada setelah Vue selesai memasang pohon DOM,
            dan Lighthouse mencatat 4,4 detik hanya untuk menunggu permintaan
            gambarnya dimulai. Dengan preload, unduhannya berjalan bersamaan
            dengan bundel JavaScript, bukan mengantre di belakangnya.
        --}}
        <link rel="preload" as="image" type="image/webp" href="/img/Lambang_Kota_Kendari.webp" fetchpriority="high">

        {{--
            Font sengaja TIDAK di-preload, dan itu hasil pengukuran, bukan
            kelalaian.

            Ketiga berkasnya memang baru berangkat sekitar detik 4,5, saat Vue
            selesai menggambar teks pertama yang memakainya. Terdengar seperti
            kesalahan yang perlu diperbaiki. Preload memang memajukannya ke
            detik 0,6, tapi 70 kB itu lalu berebut jalur dengan bundel
            JavaScript yang menentukan kapan halaman muncul: bundelnya tiba 400
            ms lebih lambat, FCP mundur dari 4,7 detik ke 5,1 detik, dan LCP
            tidak bergerak sama sekali. `fetchpriority="low"` juga sudah dicoba
            dan hasilnya sama.

            Jangan tambahkan kembali tanpa mengukur ulang. Kalau ukuran bundel
            JavaScript nanti turun banyak, kesimpulannya bisa berbalik.
        --}}

        @routes
        @vite(['resources/js/app.ts'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
