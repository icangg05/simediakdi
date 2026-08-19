<script setup lang="ts">
import KartuEksekutif from '@/components/domain/KartuEksekutif.vue';
import KopEksekutif from '@/components/domain/KopEksekutif.vue';
import PemilihBulan from '@/components/domain/PemilihBulan.vue';
import PemilihRentangTanggal from '@/components/domain/PemilihRentangTanggal.vue';
import PermukaanKendaliKop from '@/components/domain/PermukaanKendaliKop.vue';
import PilKop from '@/components/domain/PilKop.vue';
import KeadaanKosong from '@/components/KeadaanKosong.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { useFormatAngka } from '@/composables/useFormatAngka';
import { usePeriodeEksekutif } from '@/composables/usePeriodeEksekutif';
import LayoutEksekutif from '@/layouts/LayoutEksekutif.vue';
import { Head, Link } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { ArrowRight, Globe2, Handshake, ListOrdered, Newspaper, Radio } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/*
 * Arti warna di halaman ini mengikuti tabel yang sama dengan dashboard: toska
 * untuk media dan jangkauannya, warna sentimen hanya untuk nada pemberitaan.
 * Warna tier berdiri sendiri di luar keduanya, dan itu satu-satunya rona
 * tambahan yang dipakai halaman ini.
 */

interface Baris {
    id: number;
    nama: string;
    tier: string;
    partner: boolean;
    /** Sama dengan jumlah tiga nada di bawahnya, dihitung Postgres. */
    jumlah_artikel: number;
    jumlah_negatif: number;
    jumlah_netral: number;
    jumlah_positif: number;
}

const props = defineProps<{
    periode: { dari: string; sampai: string };
    opsiBulan: string[];
    peringkat: Baris[];
}>();

const { formatAngka } = useFormatAngka();
const { pindah, kueri } = usePeriodeEksekutif(() => props.periode, '/eksekutif/media');

/**
 * Rentang dalam kalimat, bentuk yang sama dengan dashboard dan halaman
 * sentimen. Pimpinan berpindah antara ketiganya lewat menu di kop, dan judul
 * yang bentuknya berbeda membuat perpindahan itu terasa seperti masuk aplikasi
 * lain.
 */
const rentangTerbaca = computed(
    () =>
        `${format(new Date(props.periode.dari), 'd MMMM', { locale: id })} sampai ` +
        `${format(new Date(props.periode.sampai), 'd MMMM yyyy', { locale: id })}`,
);

const totalBerita = computed(() => props.peringkat.reduce((jumlah, m) => jumlah + m.jumlah_artikel, 0));

/**
 * Media yang benar benar memuat berita pada rentang ini.
 *
 * Daftarnya memuat seluruh media terdaftar, termasuk yang diam, jadi
 * `peringkat.length` menjawab pertanyaan yang berbeda dari angka ini. Menyebut
 * "30 media memuat 412 berita" saat hanya 12 yang menulis adalah kalimat yang
 * salah, dan halaman ini tidak boleh memuat angka yang tidak bisa dicek.
 */
const mediaMemberitakan = computed(() => props.peringkat.filter((m) => m.jumlah_artikel > 0).length);

/**
 * Volume media teratas, dipakai sebagai skala batang.
 *
 * Batang tiap baris digambar relatif terhadap angka ini, bukan terhadap lebar
 * penuh barisnya. Dengan begitu panjang batang bisa dibandingkan antarbaris,
 * persis seperti sumbu bersama pada grafik batang.
 */
const puncak = computed(() => props.peringkat.reduce((tertinggi, m) => Math.max(tertinggi, m.jumlah_artikel), 0));

const urutan = ref<'volume' | 'negatif'>('volume');

/**
 * Dua cara membaca papan ini, dan keduanya menjawab pertanyaan yang berbeda.
 *
 * Urutan volume menjawab siapa yang paling banyak memberitakan Pemkot. Urutan
 * porsi negatif menjawab pemberitaan siapa yang paling banyak menyoroti
 * masalah, dan media kecil bisa naik ke puncak di sini walaupun beritanya
 * sedikit. Karena itu jumlah negatif dan jumlah beritanya selalu ikut tercetak
 * di barisnya, sehingga "1 dari 1 berita" tidak terbaca sama beratnya dengan
 * "9 dari 30 berita".
 */
const daftar = computed(() => {
    const baris = [...props.peringkat];

    if (urutan.value === 'negatif') {
        return baris.sort((a, b) => {
            const porsiA = a.jumlah_artikel === 0 ? 0 : a.jumlah_negatif / a.jumlah_artikel;
            const porsiB = b.jumlah_artikel === 0 ? 0 : b.jumlah_negatif / b.jumlah_artikel;

            return porsiB - porsiA || b.jumlah_negatif - a.jumlah_negatif || b.jumlah_artikel - a.jumlah_artikel;
        });
    }

    return baris.sort((a, b) => b.jumlah_artikel - a.jumlah_artikel);
});

/**
 * Dua pilihan urutan, masing-masing dengan warnanya sendiri saat menyala.
 *
 * Warnanya bukan hiasan, ia menyebut apa yang sedang diurutkan. Toska berarti
 * papan sedang disusun menurut volume media, dan merah sentimen berarti papan
 * sedang disusun menurut porsi berita negatif. Pengguna yang menoleh ke papan
 * setelah beberapa detik bisa tahu urutan mana yang berlaku tanpa membaca
 * label tombolnya.
 */
const opsiUrutan = [
    { nilai: 'volume' as const, label: 'Terbanyak memberitakan', aktif: 'bg-aksen-toska text-white shadow-xs dark:text-background' },
    { nilai: 'negatif' as const, label: 'Porsi negatif tertinggi', aktif: 'bg-sentimen-negatif text-white shadow-xs dark:text-background' },
];

/** Lebar batang satu baris terhadap media teratas, dalam persen. */
function lebarBatang(m: Baris): string {
    return puncak.value === 0 ? '0%' : `${(m.jumlah_artikel / puncak.value) * 100}%`;
}

/** Lebar satu potong nada di dalam batang barisnya sendiri. */
function lebarPotong(bagian: number, m: Baris): string {
    return m.jumlah_artikel === 0 ? '0%' : `${(bagian / m.jumlah_artikel) * 100}%`;
}

/**
 * Keterangan batang untuk pembaca yang memakai pembaca layar.
 *
 * Warna tidak boleh menjadi satu-satunya penanda, dan tiga potong berwarna
 * tanpa kalimat ini tidak menyampaikan apa pun di luar layar.
 */
function bacaanBatang(m: Baris): string {
    return (
        `${formatAngka(m.jumlah_artikel)} berita, ` +
        `${formatAngka(m.jumlah_positif)} positif, ` +
        `${formatAngka(m.jumlah_netral)} netral, ` +
        `${formatAngka(m.jumlah_negatif)} negatif`
    );
}

const warnaTier: Record<string, string> = {
    nasional: 'bg-tier-nasional/10 text-tier-nasional',
    regional: 'bg-tier-regional/10 text-tier-regional',
    lokal: 'bg-tier-lokal/10 text-tier-lokal',
};

const artiTier: Record<string, string> = {
    nasional: 'Jangkauan nasional',
    regional: 'Jangkauan provinsi',
    lokal: 'Jangkauan Kota Kendari',
};

/**
 * Ditulis utuh, bukan dirangkai `bg-tier-${tier}`.
 *
 * Tailwind memindai kelas sebagai teks apa adanya di berkas sumber. Nama kelas
 * yang baru terbentuk saat program berjalan tidak pernah ikut tergenerasi, dan
 * batangnya akan tampil tanpa warna sama sekali.
 */
const batangTier: Record<string, string> = {
    nasional: 'bg-tier-nasional',
    regional: 'bg-tier-regional',
    lokal: 'bg-tier-lokal',
};

/*
 * Bidangnya putih, tier dinyatakan tepi dan pita saja.
 *
 * Sebelumnya tiap kartu berisian rona tiernya pada enam persen. Tiga isian yang
 * berbeda rona pada kepekatan serendah itu tidak cukup kuat untuk menyatakan
 * apa pun, tetapi cukup untuk membuat ketiganya terbaca sebagai tiga bahan yang
 * berlainan, padahal ketiganya menjawab satu pertanyaan yang sama. Di atas
 * latar eksekutif yang sendirinya bertinta, isian setipis itu juga menjadi
 * lapis transparan kedua yang harus dibaca mata sebelum sampai ke angkanya.
 *
 * Putih memberi ketiganya bidang yang sama, sehingga yang berbeda tinggal
 * angkanya, dan itu memang satu satunya yang dibandingkan pembaca di sini.
 * Identitas tier tidak hilang: pita di tepi atas, warna judul, dan warna batang
 * semuanya tetap rona tiernya, dan ketiganya penanda yang lebih tegas daripada
 * isian enam persen yang digantikannya.
 */
const kartuTier: Record<string, string> = {
    nasional: 'border-tier-nasional/25 bg-card',
    regional: 'border-tier-regional/25 bg-card',
    lokal: 'border-tier-lokal/25 bg-card',
};

const teksTier: Record<string, string> = {
    nasional: 'text-tier-nasional',
    regional: 'text-tier-regional',
    lokal: 'text-tier-lokal',
};

const pitaTier: Record<string, string> = {
    nasional: 'from-tier-nasional',
    regional: 'from-tier-regional',
    lokal: 'from-tier-lokal',
};

/**
 * Sebaran menurut jangkauan media.
 *
 * Pertanyaan yang berbeda dari papan peringkat, karena itu bagiannya sendiri.
 * Papan peringkat menjawab siapa yang memberitakan, bagian ini menjawab
 * seberapa jauh berita itu keluar dari Kendari. Sebelumnya tier hanya lencana
 * berwarna di kolom tabel yang tidak dipakai menghitung apa pun.
 */
const jangkauan = computed(() =>
    ['nasional', 'regional', 'lokal']
        .map((tier) => {
            const anggota = props.peringkat.filter((m) => m.tier === tier);

            return {
                tier,
                nama: tier.charAt(0).toUpperCase() + tier.slice(1),
                arti: artiTier[tier] ?? '',
                jumlahMedia: anggota.filter((m) => m.jumlah_artikel > 0).length,
                jumlahArtikel: anggota.reduce((jumlah, m) => jumlah + m.jumlah_artikel, 0),
                jumlahNegatif: anggota.reduce((jumlah, m) => jumlah + m.jumlah_negatif, 0),
                batang: batangTier[tier] ?? 'bg-muted-foreground',
                kartu: kartuTier[tier] ?? '',
                teks: teksTier[tier] ?? '',
                pita: pitaTier[tier] ?? '',
            };
        })
        .filter((t) => t.jumlahArtikel > 0),
);

/**
 * Rupa nomor urut. Tiga teratas memakai navy merek, sisanya abu.
 *
 * Papan ini dibaca untuk menemukan siapa yang di puncak, dan tiga puluh nomor
 * dengan bobot yang sama membuat puncaknya harus dicari baris demi baris.
 */
function rupaNomor(urut: number): string {
    return urut < 3 ? 'bg-brand text-white shadow-xs dark:bg-aksen-biru dark:text-background' : 'bg-muted text-muted-foreground';
}
</script>

<template>
    <Head title="Peringkat media" />

    <LayoutEksekutif>
        <!--
            Kalimat pembuka pindah ke dalam kop, bukan paragraf yang melayang di
            antara kop dan papan. Isinya tiga fakta, dan ketiganya memang
            pembuka halaman: berapa media terdaftar, berapa di antaranya yang
            benar benar menulis, dan berapa berita yang mereka hasilkan.
        -->
        <KopEksekutif judul="Peringkat media" :keterangan="`Media yang memberitakan Pemerintah Kota, ${rentangTerbaca}`">
            <template #kendali>
                <div class="flex flex-col gap-1.5 sm:flex-row sm:flex-wrap sm:items-start">
                    <PermukaanKendaliKop tanpa-padding>
                        <PemilihBulan
                            :dari="periode.dari"
                            :sampai="periode.sampai"
                            :opsi="opsiBulan"
                            @ubah="(dari, sampai) => pindah({ dari, sampai })"
                        />
                    </PermukaanKendaliKop>
                    <!--
                        Dua kotak tanggal berdiri di halaman, bentuk yang sama
                        dengan Arsip berita. Rentang di sini dipakai sesering
                        pemilih bulannya, dan menyembunyikannya di balik lapisan
                        yang menutupi papan peringkat membuat pengguna membuka
                        dan menutup lapisan itu hanya untuk melihat akibat
                        pilihannya. Tempatnya di baris kedua, di bawah pintasan,
                        supaya deretan kendali di kop tidak memanjang melewati
                        lebar judulnya.

                        `reset-bulan-ini` memadamkan keempat pintasan begitu
                        rentangnya tidak lagi sama dengan salah satu dari mereka,
                        termasuk saat bulan lampau dipilih lewat pemilih bulan,
                        dan menggantinya dengan satu tombol pulang.
                    -->
                    <PemilihRentangTanggal
                        :dari="periode.dari"
                        :sampai="periode.sampai"
                        inline
                        kalender
                        tanpa-sheet
                        rentang-di-bawah
                        reset-bulan-ini
                        @ubah="(dari, sampai) => pindah({ dari, sampai })"
                    />
                </div>
            </template>

            <template v-if="totalBerita > 0" #pil>
                <PilKop :ikon="Radio">{{ formatAngka(mediaMemberitakan) }} dari {{ formatAngka(peringkat.length) }} media menulis</PilKop>
                <PilKop :ikon="Newspaper">{{ formatAngka(totalBerita) }} berita</PilKop>
            </template>

            <template v-if="totalBerita > 0">
                Papan di bawah menyusun media menurut jumlah berita tentang Pemerintah Kota pada rentang ini. Ketuk satu baris untuk membaca
                beritanya.
            </template>
        </KopEksekutif>

        <template v-if="totalBerita > 0">
            <div class="muncul" style="animation-delay: 80ms">
                <KartuEksekutif
                    judul="Papan peringkat media"
                    :catatan="`${formatAngka(peringkat.length)} media terdaftar, termasuk yang belum menulis pada rentang ini`"
                    :ikon="ListOrdered"
                    rona="toska"
                >
                    <!--
                        Dua cara mengurutkan, bukan delapan kolom yang bisa
                        disortir. Halaman ini dibaca di ponsel dalam hitungan
                        detik, dan pertanyaan yang benar benar dibawa pembaca ke
                        sini hanya dua.
                    -->
                    <template #aksi>
                        <div
                            class="flex w-full items-center gap-1 rounded-full bg-muted p-1 sm:w-auto"
                            role="group"
                            aria-label="Urutan papan peringkat"
                        >
                            <button
                                v-for="opsi in opsiUrutan"
                                :key="opsi.nilai"
                                type="button"
                                :aria-pressed="urutan === opsi.nilai"
                                class="tekan flex-1 rounded-full px-3 py-1.5 text-xs leading-tight font-semibold transition-colors duration-200 ease-[cubic-bezier(0.32,0.72,0,1)] focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:outline-hidden sm:flex-none"
                                :class="urutan === opsi.nilai ? opsi.aktif : 'text-muted-foreground hover:text-foreground'"
                                @click="urutan = opsi.nilai"
                            >
                                {{ opsi.label }}
                            </button>
                        </div>
                    </template>

                    <!--
                        Keterangan warna ditulis sekali di atas daftar, bukan
                        diulang sebagai tiga keping angka di tiap baris. Tiga
                        puluh baris dikali tiga keping menghasilkan sembilan
                        puluh angka yang tidak satu pun dibaca.
                    -->
                    <p class="mb-3 flex flex-wrap items-center gap-x-4 gap-y-1.5 rounded-xl bg-muted/50 px-3 py-2 text-xs text-muted-foreground">
                        <span class="inline-flex items-center gap-1.5">
                            <span class="size-2 rounded-full bg-sentimen-positif" aria-hidden="true"></span>
                            Positif
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <!-- Titik legenda wajib memakai token batang yang
                                 sama, bukan nada kuatnya. Legenda ini satu
                                 satunya penerjemah potongan batang di bawahnya,
                                 dan titik yang lebih pekat daripada potongan
                                 yang diterjemahkannya membuat pembaca mencari
                                 warna yang tidak ada di sana. -->
                            <span class="size-2 rounded-full bg-sentimen-netral-batang" aria-hidden="true"></span>
                            Netral
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <span class="size-2 rounded-full bg-sentimen-negatif" aria-hidden="true"></span>
                            Negatif
                        </span>
                        <span>Panjang batang dibandingkan terhadap media teratas.</span>
                    </p>

                    <ol class="divide-y">
                        <li v-for="(m, urut) in daftar" :key="m.id">
                            <Link
                                :href="`/eksekutif/berita?${kueri({ media: m.id })}`"
                                class="tekan group block rounded-lg px-2 py-3 hover:bg-muted/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                            >
                                <div class="flex items-center gap-3">
                                    <!--
                                        Nomor urut ikut berpindah saat urutannya
                                        diganti, jadi angka ini menyatakan
                                        peringkat pada urutan yang sedang dibaca,
                                        bukan nomor baris yang kebetulan tetap.
                                    -->
                                    <span
                                        class="angka grid size-7 shrink-0 place-items-center rounded-lg text-xs font-semibold"
                                        :class="rupaNomor(urut)"
                                    >
                                        {{ urut + 1 }}
                                    </span>

                                    <span class="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-1">
                                        <span class="truncate text-sm font-semibold">{{ m.nama }}</span>
                                        <Badge variant="secondary" :class="warnaTier[m.tier]" class="shrink-0 capitalize">{{ m.tier }}</Badge>
                                        <span
                                            v-if="m.partner"
                                            class="inline-flex shrink-0 items-center gap-1 rounded-md bg-aksen-toska/10 px-1.5 py-0.5 text-[11px] font-medium text-aksen-toska"
                                        >
                                            <Handshake class="size-3 shrink-0" aria-hidden="true" />
                                            Bekerja sama
                                        </span>
                                    </span>

                                    <span class="shrink-0 text-right">
                                        <span class="angka block text-sm leading-tight font-semibold">
                                            {{ formatAngka(m.jumlah_artikel) }}
                                            <span class="text-xs font-normal text-muted-foreground">berita</span>
                                        </span>
                                        <!--
                                            Positif dan netral berdiri di sini,
                                            negatifnya tetap di bawah batang.
                                            Menaruh ketiganya berjejer membuat
                                            baris ini dibaca sebagai tabel,
                                            padahal yang menuntut tindakan hanya
                                            angka negatifnya.

                                            Netral memakai `sentimen-netral`,
                                            bukan `sentimen-netral-batang`.
                                            Yang kedua diterangkan sampai 0,64
                                            khusus untuk potongan batang, dan
                                            sebagai teks kontrasnya jatuh jauh
                                            di bawah ambang.
                                        -->
                                        <span v-if="m.jumlah_artikel > 0" class="angka block text-xs leading-tight">
                                            <span class="font-medium text-sentimen-positif">{{ formatAngka(m.jumlah_positif) }} positif</span>
                                            <span class="text-muted-foreground">, </span>
                                            <span class="font-medium text-sentimen-netral">{{ formatAngka(m.jumlah_netral) }} netral</span>
                                        </span>
                                    </span>

                                    <ArrowRight
                                        class="size-4 shrink-0 text-muted-foreground transition-transform duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] group-hover:translate-x-1"
                                        aria-hidden="true"
                                    />
                                </div>

                                <div class="mt-2.5 flex items-center gap-3 pl-10">
                                    <span class="h-2 min-w-0 flex-1 overflow-hidden rounded-full bg-muted" role="img" :aria-label="bacaanBatang(m)">
                                        <span class="tumbuh flex h-full overflow-hidden rounded-full" :style="{ width: lebarBatang(m) }">
                                            <span
                                                class="h-full bg-sentimen-positif"
                                                :style="{ width: lebarPotong(m.jumlah_positif, m) }"
                                                :title="`${formatAngka(m.jumlah_positif)} berita positif`"
                                            ></span>
                                            <span
                                                class="h-full bg-sentimen-netral-batang"
                                                :style="{ width: lebarPotong(m.jumlah_netral, m) }"
                                                :title="`${formatAngka(m.jumlah_netral)} berita netral`"
                                            ></span>
                                            <span
                                                class="h-full bg-sentimen-negatif"
                                                :style="{ width: lebarPotong(m.jumlah_negatif, m) }"
                                                :title="`${formatAngka(m.jumlah_negatif)} berita negatif`"
                                            ></span>
                                        </span>
                                    </span>

                                    <!--
                                        Hanya angka negatifnya yang dicetak. Itu
                                        satu satunya angka di baris ini yang
                                        menentukan tindakan, dan dua angka
                                        lainnya sudah terbaca dari panjang
                                        potongan batangnya.
                                    -->
                                    <span
                                        v-if="m.jumlah_negatif > 0"
                                        class="angka shrink-0 text-xs font-semibold text-sentimen-negatif"
                                        :class="{ 'opacity-70': urutan === 'volume' }"
                                    >
                                        {{ formatAngka(m.jumlah_negatif) }} negatif
                                    </span>
                                    <!--
                                        Nol berita dan nol negatif adalah dua
                                        keadaan yang berbeda. Media yang diam
                                        tidak boleh terbaca seperti media yang
                                        menulis banyak tanpa satu pun kritik.
                                    -->
                                    <span v-else-if="m.jumlah_artikel === 0" class="shrink-0 text-xs text-muted-foreground">
                                        Belum memberitakan pada rentang ini
                                    </span>
                                    <span v-else class="shrink-0 text-xs text-muted-foreground">Tidak ada berita negatif</span>
                                </div>
                            </Link>
                        </li>
                    </ol>
                </KartuEksekutif>
            </div>

            <!--
                Jangkauan media, pertanyaan kedua halaman ini.

                Papan di atas menjawab siapa yang memberitakan, bagian ini
                menjawab seberapa jauh beritanya keluar dari Kendari.

                Bentuknya sengaja bukan kartu keempat yang seragam, melainkan
                tiga keping berjajar. Halaman ini kalau seluruhnya kartu
                selebar layar akan terbaca sebagai satu kolom panjang tanpa
                irama, dan pertanyaan yang dijawab di sini memang berbeda
                jenisnya dari papan di atasnya. Warnanya token tier, satu-satunya
                rona di panel eksekutif yang bukan nada maupun aksen.
            -->
            <section v-if="jangkauan.length" class="muncul space-y-3" style="animation-delay: 160ms">
                <h2 class="flex items-center gap-2.5 text-base font-semibold text-primary dark:text-aksen-biru">
                    <span class="grid size-7 shrink-0 place-items-center rounded-lg bg-aksen-toska/10">
                        <Globe2 class="size-4 text-aksen-toska" aria-hidden="true" />
                    </span>
                    <span class="shrink-0">Jangkauan media</span>
                    <span class="h-px flex-1 bg-linear-to-r from-brand/30 to-transparent dark:from-aksen-biru/30" aria-hidden="true"></span>
                </h2>

                <div class="grid gap-3.5 md:grid-cols-3">
                    <div
                        v-for="(t, urut) in jangkauan"
                        :key="t.tier"
                        :class="t.kartu"
                        class="angkat muncul relative space-y-2.5 overflow-hidden rounded-2xl border p-4"
                        :style="{ animationDelay: `${220 + urut * 80}ms` }"
                    >
                        <span
                            :class="t.pita"
                            class="tumbuh absolute inset-x-0 top-0 h-[3px] bg-linear-to-r to-transparent"
                            :style="{ animationDelay: `${280 + urut * 80}ms` }"
                            aria-hidden="true"
                        ></span>

                        <div class="flex items-baseline justify-between gap-3">
                            <span :class="t.teks" class="text-sm font-semibold">{{ t.nama }}</span>
                            <span class="angka shrink-0 text-2xl leading-none font-semibold">
                                {{ formatAngka(t.jumlahArtikel) }}
                                <span class="text-xs font-normal text-muted-foreground">berita</span>
                            </span>
                        </div>

                        <p class="text-xs text-muted-foreground">{{ t.arti }}</p>

                        <span class="block h-2 overflow-hidden rounded-full bg-muted">
                            <span
                                :class="t.batang"
                                class="tumbuh block h-full rounded-full"
                                :style="{
                                    width: totalBerita === 0 ? '0%' : `${(t.jumlahArtikel / totalBerita) * 100}%`,
                                    animationDelay: `${300 + urut * 90}ms`,
                                }"
                            ></span>
                        </span>

                        <p class="angka text-xs leading-relaxed text-muted-foreground">
                            {{ formatAngka(t.jumlahMedia) }} media, {{ formatAngka(t.jumlahNegatif) }} berita negatif
                        </p>
                    </div>
                </div>
            </section>
        </template>

        <Card v-else class="muncul">
            <CardContent class="p-0">
                <KeadaanKosong
                    judul="Belum ada media yang memuat berita"
                    keterangan="Perlebar rentang tanggal, atau periksa apakah crawler berjalan."
                />
            </CardContent>
        </Card>
    </LayoutEksekutif>
</template>
