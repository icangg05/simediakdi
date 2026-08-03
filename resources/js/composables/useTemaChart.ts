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
            garis: gelap.value ? '#404040' : '#e5e5e5',
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

    /** Opsi dasar yang sama untuk seluruh grafik. */
    const dasar = computed(() => ({
        textStyle: { fontFamily: 'IBM Plex Sans, sans-serif', color: warna.value.teks },
        grid: { left: 8, right: 8, top: 24, bottom: 8, containLabel: true },
        tooltip: {
            trigger: 'axis',
            // Angka Indonesia: pemisah ribuan titik.
            valueFormatter: (nilai: number) => new Intl.NumberFormat('id-ID').format(nilai),
        },
        legend: { bottom: 0, textStyle: { color: warna.value.teksSamar } },
    }));

    /** Sumbu Y jumlah artikel selalu mulai dari nol, memotongnya melebih-lebihkan perubahan. */
    const sumbuNilai = computed(() => ({
        type: 'value' as const,
        min: 0,
        axisLine: { show: false },
        axisTick: { show: false },
        splitLine: { lineStyle: { color: warna.value.garis } },
        axisLabel: { color: warna.value.teksSamar },
    }));

    const sumbuKategori = computed(() => ({
        type: 'category' as const,
        boundaryGap: false,
        axisLine: { lineStyle: { color: warna.value.garis } },
        axisTick: { show: false },
        axisLabel: { color: warna.value.teksSamar },
    }));

    return { warna, warnaSentimen, dasar, sumbuNilai, sumbuKategori, gelap };
}
