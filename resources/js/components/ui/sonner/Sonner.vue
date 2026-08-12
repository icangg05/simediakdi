<script lang="ts" setup>
// Gaya bawaan vue-sonner. Tanpa impor ini toast tetap dirender, hanya sebagai
// div telanjang tanpa kotak, tanpa posisi, dan tanpa animasi, sehingga pesannya
// muncul menempel di tengah halaman dan terlihat seperti teks yang bocor dari
// komponen lain. Ditaruh di komponennya sendiri, bukan di app.css, supaya
// gayanya ikut ke mana pun komponen ini dipakai.
import "vue-sonner/style.css"
import type { ToasterProps } from "vue-sonner"
import { reactiveOmit } from "@vueuse/core"
import { CircleCheckIcon, InfoIcon, Loader2Icon, OctagonXIcon, TriangleAlertIcon, XIcon } from "lucide-vue-next"
import { Toaster as Sonner } from "vue-sonner"

const props = defineProps<ToasterProps>()
const delegatedProps = reactiveOmit(props, "toastOptions")
</script>

<template>
  <!-- Tanpa `toast-options.classes`. Seluruh tampilan toast dipegang blok
       `<style>` di bawah lewat atribut data milik sonner, bukan kelas utilitas.
       Kelas utilitas bervarian `group-[.toaster]:` yang dipakai sebelumnya
       menang spesifisitas atas selector atribut mana pun, jadi selama kelas itu
       masih menempel tidak ada satu pun aturan di bawah yang bisa mengubah
       latar, tepi, atau bayangan toast. -->
  <Sonner
    class="toaster group"
    close-button
    close-button-position="top-right"
    v-bind="delegatedProps"
  >
    <template #success-icon>
      <CircleCheckIcon class="size-[18px]" />
    </template>
    <template #info-icon>
      <InfoIcon class="size-[18px]" />
    </template>
    <template #warning-icon>
      <TriangleAlertIcon class="size-[18px]" />
    </template>
    <template #error-icon>
      <OctagonXIcon class="size-[18px]" />
    </template>
    <template #loading-icon>
      <div>
        <Loader2Icon class="size-[18px] animate-spin" />
      </div>
    </template>
    <template #close-icon>
      <XIcon class="size-3.5" />
    </template>
  </Sonner>
</template>

<style>
/*
 * Warna toast mengikuti fungsinya, bukan seleranya.
 *
 * Satu pasang variabel dipakai seluruh bagian toast: rel di tepi kiri, tinta
 * latar, tepi kartu, bayangan, tile ikon, tombol aksi, dan batang sisa waktu.
 * Mengganti satu nilai `--nada` mengganti semuanya sekaligus, dan itu yang
 * menjaga satu toast tetap terbaca sebagai satu benda, bukan kumpulan bagian
 * yang kebetulan berdekatan.
 *
 * Ronanya diambil dari token sentimen di app.css, bukan heksadesimal baru.
 * Hijau, merah, dan kuning di toast harus sama persis dengan hijau, merah, dan
 * kuning di lencana tabel, karena keduanya sering tampil bersamaan: toast
 * mengabarkan hasil klasifikasi, lencananya menempel di baris yang sama.
 *
 * `--nada-kontras` ada karena warna nada membalik terang di mode gelap. Isian
 * tombol aksi yang terang butuh teks gelap, dan teks putih di atasnya hanya
 * mencapai rasio di bawah ambang WCAG AA.
 */
[data-sonner-toast] {
    --nada: hsl(var(--foreground));
    --nada-kontras: #fff;
    --durasi: 4500ms;
}

[data-sonner-toast][data-type='success'] {
    --nada: var(--color-sentimen-positif);
}

[data-sonner-toast][data-type='error'] {
    --nada: var(--color-sentimen-negatif);
}

[data-sonner-toast][data-type='warning'] {
    --nada: var(--color-sentimen-review);
}

[data-sonner-toast][data-type='info'],
[data-sonner-toast][data-type='loading'] {
    --nada: var(--color-brand);
}

/*
 * Kelas nada dari NotifikasiFlash.vue, ditaruh sesudah aturan `data-type` di
 * atas dengan sengaja.
 *
 * Keduanya sama kuat spesifisitasnya, jadi yang menang adalah yang ditulis
 * belakangan. Urutan ini bukan kebetulan dan tidak boleh dibalik: toast hasil
 * klasifikasi selalu bertipe `success`, karena permintaannya memang berhasil,
 * sementara warnanya harus mengikuti isi jawabannya. Artikel yang diputuskan
 * tidak relevan adalah operasi sukses dengan kabar merah.
 */
[data-sonner-toast].toast-nada-hijau {
    --nada: var(--color-sentimen-positif);
}

[data-sonner-toast].toast-nada-merah {
    --nada: var(--color-sentimen-negatif);
}

[data-sonner-toast].toast-nada-kuning {
    --nada: var(--color-sentimen-review);
}

.dark [data-sonner-toast] {
    --nada-kontras: hsl(var(--background));
}

/*
 * Navy merek diganti biru aksen di mode gelap.
 *
 * `--color-brand` bernilai tetap #163F6C di kedua mode, karena warnanya
 * ditetapkan instansi dan tidak boleh digeser. Di atas kartu gelap navy itu
 * nyaris menyatu dengan latarnya: rel di tepi kiri hilang, dan isian tombol
 * aksi berubah menjadi kotak gelap berteks gelap. Biru aksen adalah rona yang
 * sama dan sudah dicerahkan untuk latar gelap di app.css.
 */
.dark [data-sonner-toast][data-type='info'],
.dark [data-sonner-toast][data-type='loading'] {
    --nada: var(--color-aksen-biru);
}

/*
 * Kartu toast: empat lapis latar, tidak ada satu pun elemen tambahan di DOM.
 *
 * Lapis pertama rel warna setinggi kartu di tepi kiri. Ia menggantikan tepi
 * dua piksel keliling yang dipakai sebelumnya. Tepi berwarna penuh mengurung
 * teks dan membuat toast terbaca seperti peringatan browser, sedangkan satu
 * batang di tepi kiri memberi kode warna yang sama kuatnya tanpa mengepung apa
 * pun. Ujung atas dan bawahnya sengaja memudar supaya batangnya tidak menabrak
 * lengkung sudut kartu.
 *
 * Lapis kedua sapuan tinta nada di belakang ikon, memudar habis sebelum sampai
 * ke teks. Ini yang membuat sisi kiri kartu terasa berwarna tanpa satu pun
 * garis baru.
 *
 * Lapis keempat garis miring rapat, dan lapis ketiga yang menutupinya dari kiri
 * dengan warna kartu sendiri. Pasangan ini yang membuat garisnya terbit pelan
 * dari tengah kartu, bukan mulai mendadak di satu batas lurus. Satu bidang arsir
 * yang dipotong tegak lurus terbaca sebagai persegi yang tertinggal di sana,
 * bukan sebagai ornamen. Ornamen ini mengisi ruang kosong di kanan judul pendek,
 * ruang yang tanpa apa-apa membuat toast terlihat seperti kotak yang belum
 * selesai dirancang.
 *
 * Warna kartunya sendiri `--card`, bukan putih tetap, supaya mode gelap ikut
 * tanpa aturan tambahan.
 *
 * Bayangannya dua lapis: satu netral yang mengangkat kartu dari halaman, satu
 * lagi bertinta nada yang jatuh lebih jauh. Bayangan berwarna inilah yang
 * membuat toast terasa menyala alih-alih ditempel.
 */
[data-sonner-toast][data-styled='true'] {
    gap: 12px;
    padding: 14px 16px 14px 18px;
    border-radius: 14px;
    border: 1px solid color-mix(in oklab, var(--nada) 26%, hsl(var(--border)));
    background-color: hsl(var(--card));
    background-image:
        linear-gradient(180deg, transparent 0%, var(--nada) 14%, var(--nada) 86%, transparent 100%),
        radial-gradient(9rem 6rem at 0% 50%, color-mix(in oklab, var(--nada) 14%, transparent), transparent 72%),
        linear-gradient(90deg, hsl(var(--card)) 0%, hsl(var(--card)) 42%, transparent 100%),
        repeating-linear-gradient(
            135deg,
            color-mix(in oklab, var(--nada) 13%, transparent) 0 1px,
            transparent 1px 9px
        );
    background-size:
        3px 100%,
        100% 100%,
        100% 100%,
        100% 100%;
    background-position:
        left center,
        left center,
        center,
        center;
    background-repeat: no-repeat;
    color: hsl(var(--card-foreground));
    box-shadow:
        0 1px 2px oklch(0 0 0 / 0.05),
        0 10px 24px -14px oklch(0 0 0 / 0.35),
        0 16px 40px -22px color-mix(in oklab, var(--nada) 70%, transparent);
}

/*
 * Batang sisa waktu di kaki kartu.
 *
 * Toast galat bertahan delapan detik dan toast sukses empat setengah, dan tanpa
 * penanda apa pun keduanya terbaca sama saja: pesan yang akan hilang entah
 * kapan. Batang yang menyusut mengubah durasi menjadi sesuatu yang bisa dilihat,
 * jadi pembaca tahu ia masih sempat membaca sampai habis atau perlu menyorotnya.
 *
 * Durasinya diberikan pemanggil lewat `--durasi` pada `style` tiap toast,
 * bukan ditulis mati di sini. Angkanya milik NotifikasiFlash.vue, dan dua
 * salinan yang harus sepakat pasti akan berpisah suatu hari.
 *
 * Berhenti saat kursor menyentuh daftar toast, karena sonner juga menghentikan
 * hitungan mundurnya di saat yang sama. Batang yang tetap jalan sementara
 * waktunya berhenti adalah penanda yang berbohong.
 *
 * Ditempel ke `::after`, bukan `::before`. Sonner memakai `::before` sebagai
 * perpanjangan area sentuh saat toast digeser untuk dibuang, dan menumpanginya
 * merusak geser-buang di ponsel.
 *
 * Sisi kiri dan kanannya ditarik masuk 14 piksel supaya batangnya berhenti
 * sebelum lengkung sudut kartu, bukan menyembul keluar dari sana.
 */
[data-sonner-toast][data-styled='true']::after {
    content: '';
    position: absolute;
    right: 14px;
    /* Satu piksel di atas tepi kartu, bukan menempel di nol. Batang yang duduk
       persis di garis tepi terbaca sebagai tepi yang menebal sebelah. */
    bottom: 1px;
    left: 14px;
    height: 2px;
    border-radius: 999px;
    background: linear-gradient(90deg, var(--nada), color-mix(in oklab, var(--nada) 30%, transparent));
    transform-origin: left;
    animation: toast-sisa-waktu var(--durasi) linear forwards;
}

[data-sonner-toaster]:hover [data-sonner-toast][data-styled='true']::after {
    animation-play-state: paused;
}

@keyframes toast-sisa-waktu {
    from {
        transform: scaleX(1);
    }

    to {
        transform: scaleX(0);
    }
}

/*
 * Ikon naik pangkat dari lambang 16 piksel yang menempel di teks menjadi tile
 * 34 piksel dengan isian lembut dan cincin tipis.
 *
 * Alasannya jarak baca. Toast muncul di sudut layar sementara mata sedang di
 * tengah halaman, dan yang pertama tertangkap bukan kalimatnya melainkan bentuk
 * berwarna di kirinya. Lambang setipis teks tidak cukup untuk itu.
 *
 * Ukurannya ditulis di sini, bukan diwarisi, karena sonner menyetel tinggi dan
 * lebar 16 piksel plus margin negatif pada elemen yang sama.
 */
[data-sonner-toast][data-styled='true'] [data-icon] {
    position: relative;
    height: 34px;
    width: 34px;
    margin: 0;
    justify-content: center;
    border-radius: 11px;
    background: color-mix(in oklab, var(--nada) 14%, hsl(var(--card)));
    box-shadow: inset 0 0 0 1px color-mix(in oklab, var(--nada) 30%, transparent);
    color: var(--nada);
    animation: toast-ikon-masuk 420ms cubic-bezier(0.32, 0.72, 0, 1) both;
}

[data-sonner-toast][data-styled='true'] [data-icon] svg {
    margin: 0;
}

/*
 * Satu denyut cincin saat toast masuk, lalu diam.
 *
 * Sekali saja, bukan berulang. Toast galat bertahan delapan detik di sudut
 * layar, dan cincin yang berdenyut selama itu menarik mata kembali setiap
 * detik tanpa pernah membawa kabar baru.
 */
[data-sonner-toast][data-styled='true'] [data-icon]::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: inherit;
    box-shadow: 0 0 0 0 color-mix(in oklab, var(--nada) 55%, transparent);
    animation: toast-halo 900ms cubic-bezier(0.32, 0.72, 0, 1) 120ms both;
}

@keyframes toast-ikon-masuk {
    from {
        opacity: 0;
        transform: scale(0.6);
    }

    to {
        opacity: 1;
        transform: none;
    }
}

@keyframes toast-halo {
    from {
        box-shadow: 0 0 0 0 color-mix(in oklab, var(--nada) 55%, transparent);
    }

    to {
        box-shadow: 0 0 0 12px transparent;
    }
}

[data-sonner-toast][data-styled='true'] [data-content] {
    gap: 3px;
    min-width: 0;
    /* Ruang untuk tombol tutup yang duduk di sudut kanan atas. Tanpa ini judul
       panjang berjalan lurus ke bawah tombolnya. */
    padding-right: 14px;
}

[data-sonner-toast][data-styled='true'] [data-title] {
    font-size: 13.5px;
    font-weight: 600;
    letter-spacing: -0.006em;
    color: hsl(var(--card-foreground));
}

[data-sonner-toast][data-styled='true'] [data-description] {
    font-size: 12.5px;
    color: hsl(var(--muted-foreground));
}

/*
 * Tombol aksi memakai isian nada penuh, bukan abu netral bawaan sonner.
 *
 * Tombol ini satu-satunya jalan kembali ke baris yang barusan berpindah keluar
 * dari layar, jadi ia harus terbaca sebagai tombol dari sudut mata, bukan
 * sebagai keterangan tambahan. Warnanya ikut nada supaya tetap terbaca sebagai
 * bagian dari kabar yang sama.
 */
[data-sonner-toast][data-styled='true'] [data-button] {
    height: 28px;
    padding: 0 11px;
    border-radius: 8px;
    background: var(--nada);
    color: var(--nada-kontras);
    font-size: 12px;
    font-weight: 600;
    box-shadow: 0 6px 14px -8px color-mix(in oklab, var(--nada) 90%, transparent);
    transition:
        transform 200ms cubic-bezier(0.32, 0.72, 0, 1),
        box-shadow 200ms cubic-bezier(0.32, 0.72, 0, 1),
        filter 200ms cubic-bezier(0.32, 0.72, 0, 1);
}

[data-sonner-toast][data-styled='true'] [data-button]:hover {
    transform: translateY(-1px);
    box-shadow: 0 10px 20px -8px color-mix(in oklab, var(--nada) 90%, transparent);
    filter: brightness(1.06);
}

[data-sonner-toast][data-styled='true'] [data-button]:active {
    transform: translateY(0);
}

[data-sonner-toast][data-styled='true'] [data-button]:focus-visible {
    outline: 2px solid var(--nada);
    outline-offset: 2px;
    box-shadow: none;
}

/*
 * Tombol tutup duduk setengah keluar di sudut kanan atas, mengikuti posisi
 * bawaan sonner. Yang diubah hanya warnanya: ia memakai warna kartu dan
 * cincin nada, jadi terbaca sebagai bagian dari toast, bukan noda abu di
 * sudutnya.
 */
[data-sonner-toast][data-styled='true'] [data-close-button] {
    height: 22px;
    width: 22px;
    background: hsl(var(--card));
    border-color: color-mix(in oklab, var(--nada) 34%, hsl(var(--border)));
    color: hsl(var(--muted-foreground));
    transition:
        background 200ms cubic-bezier(0.32, 0.72, 0, 1),
        color 200ms cubic-bezier(0.32, 0.72, 0, 1),
        transform 200ms cubic-bezier(0.32, 0.72, 0, 1);
}

[data-sonner-toast][data-styled='true']:hover [data-close-button]:hover {
    background: color-mix(in oklab, var(--nada) 14%, hsl(var(--card)));
    border-color: var(--nada);
    color: var(--nada);
    transform: var(--toast-close-button-transform) scale(1.08);
}

/*
 * Gerak dimatikan sama sekali, bukan dipercepat.
 *
 * Batang sisa waktu ikut dimatikan lalu disembunyikan. Batang yang berhenti di
 * lebar penuh terbaca sebagai toast yang tidak akan pernah hilang, dan itu
 * kabar yang salah.
 */
@media (prefers-reduced-motion: reduce) {
    [data-sonner-toast][data-styled='true']::after {
        animation: none;
        opacity: 0;
    }

    [data-sonner-toast][data-styled='true'] [data-icon],
    [data-sonner-toast][data-styled='true'] [data-icon]::after {
        animation: none;
    }

    [data-sonner-toast][data-styled='true'] [data-button],
    [data-sonner-toast][data-styled='true'] [data-close-button] {
        transition: none;
    }

    [data-sonner-toast][data-styled='true'] [data-button]:hover {
        transform: none;
    }

    [data-sonner-toast][data-styled='true']:hover [data-close-button]:hover {
        transform: var(--toast-close-button-transform);
    }
}
</style>
