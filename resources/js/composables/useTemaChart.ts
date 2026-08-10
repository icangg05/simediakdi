import { computed, onMounted, onUnmounted, ref } from 'vue';

/**
 * Menyuntikkan token warna aplikasi ke opsi ECharts.
 *
 * Warna sentimen dibaca dari CSS custom property, bukan ditulis ulang sebagai
 * heksadesimal di file grafik. Kalau warna negatif suatu saat perlu diubah,
 * satu tempat saja yang disunting, dan grafik tidak pernah menyimpang dari
 * badge sentimen di tabel sebelahnya.
 */
export function useTemaChart() {
    const gelap = ref(false);

    function bacaToken(nama: string): string {
        return getComputedStyle(document.documentElement).getPropertyValue(nama).trim();
    }

    // Dibaca ulang saat tema berubah: nilai token berbeda antara terang dan gelap.
    const versi = ref(0);

    let pengamat: MutationObserver | null = null;

    onMounted(() => {
        gelap.value = document.documentElement.classList.contains('dark');

        pengamat = new MutationObserver(() => {
            const sekarang = document.documentElement.classList.contains('dark');

            if (sekarang !== gelap.value) {
                gelap.value = sekarang;
                versi.value++;
            }
        });

        pengamat.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    });

    onUnmounted(() => pengamat?.disconnect());

    const warna = computed(() => {
        // Sentuh `versi` supaya computed dihitung ulang saat tema berganti.
        void versi.value;

        return {
            negatif: bacaToken('--color-sentimen-negatif'),
            netral: bacaToken('--color-sentimen-netral'),
            positif: bacaToken('--color-sentimen-positif'),
            review: bacaToken('--color-sentimen-review'),
            teks: gelap.value ? '#e5e5e5' : '#404040',
            teksSamar: gelap.value ? '#a3a3a3' : '#737373',
            garis: gelap.value ? '#333333' : '#e9edf2',
            latarTooltip: gelap.value ? '#1c1c1c' : '#ffffff',
        };
    });

    /**
     * Warna per label sentimen. Urutan tetap dari bawah pada area bertumpuk:
     * positif, netral, negatif. Urutan yang berubah antar halaman membuat
     * pembaca salah baca.
     */
    const warnaSentimen = computed<Record<string, string>>(() => ({
        positif: warna.value.positif,
        netral: warna.value.netral,
        negatif: warna.value.negatif,
        perlu_review: warna.value.review,
    }));

    /**
     * Opsi dasar yang sama untuk seluruh grafik.
     *
     * Legenda di atas, bukan di bawah. `containLabel` hanya menyisakan ruang
     * untuk label sumbu, tidak untuk legenda, jadi legenda yang ditaruh di bawah
     * digambar tepat menimpa tanggal di sumbu X. Di panel eksekutif hasilnya
     * terlihat sebagai "20 Jul" dan "Positif" yang tercetak bertindihan menjadi
     * satu baris yang tidak terbaca.
     *
     * Ditaruh di atas juga urutan baca yang benar: keterangan warna dibaca lebih
     * dulu, baru grafiknya.
     *
     * Rata kanan, karena judul grafiknya HTML dan sudah menempati kiri baris
     * yang sama. Legenda rata kiri akan berdiri tepat di bawah judul dan
     * membentuk dua baris teks yang tampak seperti satu judul dua tingkat.
     */
    const dasar = computed(() => ({
        textStyle: { fontFamily: 'IBM Plex Sans, sans-serif', color: warna.value.teks },
        grid: { left: 8, right: 8, top: 44, bottom: 8, containLabel: true },
        tooltip: {
            trigger: 'axis',
            // Angka Indonesia: pemisah ribuan titik.
            valueFormatter: (nilai: number) => new Intl.NumberFormat('id-ID').format(nilai),
            axisPointer: { type: 'line', lineStyle: { color: warna.value.garis } },
            backgroundColor: warna.value.latarTooltip,
            borderWidth: 0,
            padding: [8, 12],
            textStyle: { color: warna.value.teks, fontSize: 12 },
            extraCssText: 'border-radius:10px;box-shadow:0 12px 28px -12px rgb(0 0 0 / 0.25);',
        },
        legend: {
            top: 0,
            right: 0,
            itemWidth: 9,
            itemHeight: 9,
            itemGap: 20,
            icon: 'circle',
            textStyle: { color: warna.value.teksSamar, fontSize: 12 },
        },
    }));

    /** Sumbu Y jumlah artikel selalu mulai dari nol, memotongnya melebih-lebihkan perubahan. */
    const sumbuNilai = computed(() => ({
        type: 'value' as const,
        min: 0,
        axisLine: { show: false },
        axisTick: { show: false },
        // Putus-putus supaya garis bantu jelas berada di belakang datanya.
        splitLine: { lineStyle: { color: warna.value.garis, type: 'dashed' as const } },
        axisLabel: {
            color: warna.value.teksSamar,
            // Pemisah ribuan Indonesia. Tanpa ini sumbu menulis "1,200", yang di
            // Indonesia terbaca sebagai satu koma dua, bukan seribu dua ratus.
            formatter: (nilai: number) => new Intl.NumberFormat('id-ID').format(nilai),
        },
    }));

    const sumbuKategori = computed(() => ({
        type: 'category' as const,
        boundaryGap: false,
        axisLine: { lineStyle: { color: warna.value.garis } },
        axisTick: { show: false },
        splitLine: { show: true, lineStyle: { color: warna.value.garis, type: 'dashed' as const, opacity: 0.6 } },
        axisLabel: { color: warna.value.teksSamar },
    }));

    return { warna, warnaSentimen, dasar, sumbuNilai, sumbuKategori, gelap };
}
