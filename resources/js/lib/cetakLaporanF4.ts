export interface DataLaporanCetak {
    namaBulan: string;
    rentangPeriode: string;
    waktuPembuatan: string;
    ringkasan: string;
    kpi: {
        berlabel: number;
        negatif: number;
        netral: number;
        positif: number;
        media_aktif: number;
        media_total_aktif: number;
        media_bekerja_sama: number;
        media_tidak_bekerja_sama: number;
    };
    narasi: {
        judul: string | null;
        ringkasan: string | null;
        penjelasan_tren: string | null;
        poin: Array<{ teks: string }>;
    } | null;
    tren: Array<{
        rentang: string;
        berlabel: number;
        jumlah_positif: number;
        jumlah_netral: number;
        jumlah_negatif: number;
    }>;
    media: Array<{
        nama: string;
        partner: boolean;
        jumlah_artikel: number;
        jumlah_positif: number;
        jumlah_netral: number;
        jumlah_negatif: number;
    }>;
}

const angka = new Intl.NumberFormat('id-ID');

const aman = (nilai: string | number | null | undefined): string =>
    String(nilai ?? '').replace(
        /[&<>"']/g,
        (karakter) =>
            ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;',
            })[karakter] ?? karakter,
    );

const paragraf = (nilai: string): string => aman(nilai).replace(/\r?\n/g, '<br>');

const proporsi = (jumlah: number, total: number): string => {
    if (total === 0) return '0%';

    return `${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 }).format((jumlah / total) * 100)}%`;
};

/**
 * HTML cetak sengaja berdiri sendiri.
 *
 * Mencetak DOM SPA membuat Chromium tetap menghitung layout sidebar, header,
 * ikon SVG, utility CSS, dan font web yang akhirnya disembunyikan. Dokumen ini
 * hanya berisi data laporan, satu stylesheet kecil, dan dua logo WebP sehingga
 * pratinjau cetak serta PDF lebih cepat dirender.
 */
export function htmlLaporanF4(data: DataLaporanCetak, asal: string): string {
    const logoKota = new URL('/img/Lambang_Kota_Kendari.webp', asal).href;
    const logoSimedia = new URL('/img/logo-simedia.webp', asal).href;
    const nada = [
        { nama: 'Positif', jumlah: data.kpi.positif, kelas: 'positif' },
        { nama: 'Netral', jumlah: data.kpi.netral, kelas: 'netral' },
        { nama: 'Negatif', jumlah: data.kpi.negatif, kelas: 'negatif' },
    ];
    const barisTren = data.tren
        .map(
            (baris) => `<tr>
                <td>${aman(baris.rentang)}</td>
                <td class="angka utama">${angka.format(baris.berlabel)}</td>
                <td class="angka positif-teks">${angka.format(baris.jumlah_positif)}</td>
                <td class="angka">${angka.format(baris.jumlah_netral)}</td>
                <td class="angka negatif-teks">${angka.format(baris.jumlah_negatif)}</td>
            </tr>`,
        )
        .join('');
    const barisMedia = data.media
        .map(
            (media, urutan) => `<tr>
                <td class="nomor">${urutan + 1}</td>
                <td class="nama-media">${aman(media.nama)}</td>
                <td><span class="status ${media.partner ? 'mitra' : ''}">${media.partner ? 'Ya' : 'Tidak'}</span></td>
                <td class="angka utama">${angka.format(media.jumlah_artikel)}</td>
                <td class="angka positif-teks">${angka.format(media.jumlah_positif)}</td>
                <td class="angka">${angka.format(media.jumlah_netral)}</td>
                <td class="angka negatif-teks">${angka.format(media.jumlah_negatif)}</td>
            </tr>`,
        )
        .join('');
    const komposisi = nada
        .map(
            (item) => `<div class="baris-komposisi">
                <span class="label-nada ${item.kelas}-teks">${item.nama}</span>
                <span class="jalur"><span class="isi ${item.kelas}" style="width:${Math.min(100, Math.max(0, data.kpi.berlabel === 0 ? 0 : (item.jumlah / data.kpi.berlabel) * 100))}%"></span></span>
                <strong>${angka.format(item.jumlah)} · ${proporsi(item.jumlah, data.kpi.berlabel)}</strong>
            </div>`,
        )
        .join('');
    const analisis = data.narasi?.ringkasan
        ? `<section class="bagian analisis hindari-putus">
                <div class="judul-bagian">
                    <h2>Analisis pemberitaan</h2>
                    <span>Ikhtisar topik dan pola selama bulan yang dipilih</span>
                </div>
                ${data.narasi.judul ? `<h3>${aman(data.narasi.judul)}</h3>` : ''}
                <p>${paragraf(data.narasi.ringkasan)}</p>
                ${data.narasi.poin.length ? `<ul>${data.narasi.poin.map((poin) => `<li>${aman(poin.teks)}</li>`).join('')}</ul>` : ''}
                ${
                    data.narasi.penjelasan_tren
                        ? `<p class="pola"><strong>Pola sepanjang bulan:</strong> ${paragraf(data.narasi.penjelasan_tren)}</p>`
                        : ''
                }
            </section>`
        : `<section class="bagian analisis hindari-putus">
                <h2>Analisis pemberitaan</h2>
                <p>Analisis pemberitaan untuk bulan ini sedang disiapkan. Angka laporan tetap dapat dibaca dan dicetak.</p>
            </section>`;

    return `<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laporan pemberitaan ${aman(data.namaBulan)}</title>
    <style>
        @page { size: 210mm 330mm; margin: 9mm 10mm 11mm; }
        * { box-sizing: border-box; }
        html, body { margin: 0; background: #fff; }
        body {
            color: #172033;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 9pt;
            line-height: 1.36;
            text-rendering: optimizeLegibility;
        }
        h1, h2, h3, p { margin: 0; }
        h1 { color: #102a48; font-size: 18pt; line-height: 1.12; letter-spacing: -.01em; }
        h2 { color: #102a48; font-size: 11pt; line-height: 1.2; }
        h3 { margin-top: 2.5mm; color: #243b63; font-size: 9.5pt; line-height: 1.3; }
        .kop {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8mm;
            padding-bottom: 3.5mm;
            border-bottom: 1.2mm solid #163f6c;
        }
        .merek { display: flex; align-items: center; gap: 2.5mm; }
        .logo { width: 8.5mm; height: 8.5mm; object-fit: contain; }
        .pemisah-logo { width: .3mm; height: 7mm; background: #cbd5e1; }
        .instansi { font-size: 10pt; font-weight: 700; color: #102a48; }
        .sistem { margin-top: .4mm; color: #526071; font-size: 7.5pt; }
        .periode { text-align: right; }
        .periode span { display: block; color: #64748b; font-size: 7pt; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
        .periode strong { display: block; margin-top: .5mm; color: #102a48; font-size: 9pt; text-transform: capitalize; }
        .judul-dokumen { padding: 4mm 0 3.5mm; border-bottom: .3mm solid #d7dee8; }
        .judul-dokumen p { margin-top: 1.2mm; color: #526071; font-size: 8.5pt; }
        .meta-ringkas { display: grid; grid-template-columns: 1fr auto; gap: 7mm; padding: 3mm 0; border-bottom: .3mm solid #d7dee8; }
        .meta-ringkas strong { color: #102a48; }
        .meta-ringkas span { color: #526071; font-size: 7.5pt; white-space: nowrap; }
        .bagian { margin-top: 3.6mm; }
        .judul-bagian { display: flex; align-items: baseline; justify-content: space-between; gap: 5mm; }
        .judul-bagian > span { color: #64748b; font-size: 7.5pt; text-align: right; }
        .analisis { padding: 3mm 3.5mm; border: .3mm solid #cad4e2; background: #f7f9fc; }
        .analisis > p { margin-top: 1.6mm; color: #364152; }
        .analisis ul { columns: 2; column-gap: 7mm; margin: 2mm 0 0; padding-left: 4mm; color: #364152; }
        .analisis li { margin-bottom: .8mm; break-inside: avoid; }
        .analisis .pola { margin-top: 2mm; padding-top: 2mm; border-top: .3mm solid #d7dee8; }
        .kpi { display: grid; grid-template-columns: repeat(3, 1fr); margin-top: 2mm; border-block: .3mm solid #cbd5e1; }
        .kpi > div { padding: 2.2mm 3mm; }
        .kpi > div + div { border-left: .3mm solid #cbd5e1; }
        .kpi span { display: block; color: #526071; font-size: 7.5pt; font-weight: 700; }
        .kpi strong { display: block; margin-top: .7mm; color: #102a48; font-size: 16pt; line-height: 1; }
        .kpi small { display: block; margin-top: .7mm; color: #64748b; font-size: 7pt; }
        .komposisi { display: grid; grid-template-columns: 45mm 1fr; gap: 7mm; align-items: start; }
        .komposisi p { margin-top: 1mm; color: #64748b; font-size: 7.5pt; }
        .daftar-komposisi { display: grid; gap: 1.4mm; }
        .baris-komposisi { display: grid; grid-template-columns: 16mm 1fr 27mm; align-items: center; gap: 2mm; font-size: 7.8pt; }
        .baris-komposisi strong { text-align: right; white-space: nowrap; }
        .label-nada { font-weight: 700; }
        .jalur { height: 2mm; overflow: hidden; background: #e8edf3; }
        .isi { display: block; height: 100%; }
        .positif { background: #287a50; }
        .netral { background: #768397; }
        .negatif { background: #a72d31; }
        .positif-teks { color: #1f7047; }
        .netral-teks { color: #526071; }
        .negatif-teks { color: #9b262c; }
        table { width: 100%; margin-top: 2mm; border-collapse: collapse; table-layout: fixed; font-size: 8.2pt; }
        thead { display: table-header-group; }
        th { padding: 1.5mm 1.8mm; color: #26364a; background: #edf1f5; font-size: 7.5pt; text-align: left; }
        td { padding: 1.45mm 1.8mm; border-bottom: .25mm solid #dce2e9; vertical-align: middle; }
        th.angka, td.angka { text-align: right; }
        tr { break-inside: avoid; }
        .utama { color: #102a48; font-weight: 700; }
        .nomor { width: 8mm; color: #64748b; text-align: center; }
        .nama-media { width: 54mm; font-weight: 700; }
        .status { display: inline-block; min-width: 12mm; padding: .4mm 1.4mm; border: .25mm solid #cbd5e1; color: #526071; font-size: 7pt; text-align: center; }
        .status.mitra { border-color: #9fb4ce; color: #163f6c; background: #eef4fa; }
        .halaman-baru { break-before: page; padding-top: 0; }
        .hindari-putus { break-inside: avoid; }
        .catatan { margin-top: 3.5mm; padding-top: 2.5mm; border-top: .5mm solid #163f6c; }
        .catatan strong { color: #102a48; }
        .catatan p { margin-top: .8mm; color: #526071; font-size: 7.5pt; }
        .kaki { display: flex; justify-content: space-between; gap: 6mm; margin-top: 3mm; color: #64748b; font-size: 7pt; }
        @media screen {
            body { width: 210mm; min-height: 330mm; margin: 0 auto; padding: 9mm 10mm 11mm; box-shadow: 0 8px 30px rgb(15 23 42 / .16); }
        }
    </style>
</head>
<body>
    <header class="kop">
        <div class="merek">
            <img class="logo" src="${aman(logoKota)}" alt="Lambang Kota Kendari" width="36" height="36">
            <span class="pemisah-logo" aria-hidden="true"></span>
            <img class="logo" src="${aman(logoSimedia)}" alt="Lambang SIMEDIA" width="36" height="36">
            <div>
                <p class="instansi">Pemerintah Kota Kendari</p>
                <p class="sistem">Sistem Pemantauan dan Analisis Pemberitaan Media</p>
            </div>
        </div>
        <div class="periode"><span>Periode laporan</span><strong>${aman(data.namaBulan)}</strong></div>
    </header>

    <section class="judul-dokumen">
        <h1>Laporan kondisi pemberitaan Kota Kendari</h1>
        <p>Periode ${aman(data.rentangPeriode)}</p>
    </section>

    <section class="meta-ringkas hindari-putus">
        <p><strong>Ringkasan keadaan.</strong> ${aman(data.ringkasan)}</p>
        <span>Dibuat ${aman(data.waktuPembuatan)}</span>
    </section>

    ${analisis}

    <section class="bagian hindari-putus">
        <h2>Ikhtisar pemberitaan</h2>
        <div class="kpi">
            <div><span>Total pemberitaan</span><strong>${angka.format(data.kpi.berlabel)}</strong><small>berita pada bulan ini</small></div>
            <div><span>Media memberitakan</span><strong>${angka.format(data.kpi.media_aktif)}/${angka.format(data.kpi.media_total_aktif)}</strong><small>media aktif pada periode ini</small></div>
            <div><span>Status kerja sama</span><strong>${angka.format(data.kpi.media_bekerja_sama)}</strong><small>bekerja sama · ${angka.format(data.kpi.media_tidak_bekerja_sama)} tidak</small></div>
        </div>
    </section>

    <section class="bagian komposisi hindari-putus">
        <div><h2>Komposisi sentimen</h2><p>Perbandingan nada pemberitaan pada bulan ini.</p></div>
        <div class="daftar-komposisi">${komposisi}</div>
    </section>

    <section class="bagian">
        <div class="judul-bagian"><h2>Tren dalam bulan</h2><span>${data.tren.length} periode</span></div>
        <table>
            <thead><tr><th style="width:40%">Rentang tanggal</th><th class="angka">Total</th><th class="angka">Positif</th><th class="angka">Netral</th><th class="angka">Negatif</th></tr></thead>
            <tbody>${barisTren || '<tr><td colspan="5">Belum ada pemberitaan pada bulan ini.</td></tr>'}</tbody>
        </table>
    </section>

    <section class="halaman-baru">
        <div class="judul-bagian"><h2>Kondisi media aktif</h2><span>${angka.format(data.media.length)} media · termasuk yang belum memberitakan</span></div>
        <table>
            <thead><tr><th class="nomor">No.</th><th class="nama-media">Media</th><th style="width:24mm">Kerja sama</th><th class="angka">Total</th><th class="angka">Positif</th><th class="angka">Netral</th><th class="angka">Negatif</th></tr></thead>
            <tbody>${barisMedia}</tbody>
        </table>
    </section>

    <footer class="catatan hindari-putus">
        <strong>Catatan penggunaan</strong>
        <p>Laporan ini menggambarkan volume dan sentimen pemberitaan media tentang Pemerintah Kota Kendari. Angka di dalamnya bukan penilaian kinerja pemerintah.</p>
        <div class="kaki"><span>Sumber: agregasi berita SIMEDIA Kota Kendari</span><span>Dicetak ${aman(data.waktuPembuatan)}</span></div>
    </footer>
</body>
</html>`;
}

/** Buka dokumen ringan, tunggu kedua logo siap, lalu tampilkan dialog cetak. */
export function cetakLaporanF4(data: DataLaporanCetak): boolean {
    const jendela = window.open('', 'simedia-laporan-cetak', 'popup,width=980,height=900');

    if (!jendela) return false;

    jendela.opener = null;
    jendela.document.open();
    jendela.document.write(htmlLaporanF4(data, window.location.origin));
    jendela.document.close();

    const tampilkanDialog = async () => {
        await Promise.all(
            Array.from(jendela.document.images).map(
                (gambar) =>
                    new Promise<void>((selesai) => {
                        if (gambar.complete) {
                            selesai();
                            return;
                        }

                        gambar.addEventListener('load', () => selesai(), { once: true });
                        gambar.addEventListener('error', () => selesai(), { once: true });
                    }),
            ),
        );

        jendela.focus();
        jendela.print();
    };

    if (jendela.document.readyState === 'complete') {
        void tampilkanDialog();
    } else {
        jendela.addEventListener('load', () => void tampilkanDialog(), { once: true });
    }

    jendela.addEventListener('afterprint', () => jendela.close(), { once: true });

    return true;
}
