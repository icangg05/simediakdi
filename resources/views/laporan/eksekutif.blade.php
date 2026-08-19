{{--
    Laporan kondisi pemberitaan, dirender dompdf di server.

    Seluruh tata letak memakai tabel dan lebar yang dipatok, bukan flexbox
    maupun grid. Dompdf tidak mengenal keduanya dan diam-diam menumpuk isinya
    menjadi satu kolom, jadi rupa yang benar di peramban belum tentu benar di
    sini. Setiap tabel yang membentang selebar halaman harus menyebutkan lebar
    tiap selnya: sel tanpa lebar diberi ruang seluas isinya, dan satu teks
    panjang cukup untuk mendorong seluruh tabel melewati margin kanan.

    Rupa dokumen dibuat dengan warna dan garis saja, tanpa gambar latar dan
    tanpa font web, supaya berkasnya tetap kecil dan cepat dibuka.
--}}
@php
    $angka = fn (int|float|null $nilai): string => \Illuminate\Support\Number::format((int) ($nilai ?? 0), locale: 'id');
    $proporsi = fn (int $bagian, int $total): string => $total === 0
        ? '0%'
        : \Illuminate\Support\Number::percentage($bagian / $total * 100, maxPrecision: 1, locale: 'id');

    $nada = [
        ['nama' => 'Positif', 'jumlah' => (int) $kpi['positif'], 'warna' => '#287a50'],
        ['nama' => 'Netral', 'jumlah' => (int) $kpi['netral'], 'warna' => '#768397'],
        ['nama' => 'Negatif', 'jumlah' => (int) $kpi['negatif'], 'warna' => '#a72d31'],
    ];

    // Tinggi batang dihitung di sini dalam milimeter, bukan diserahkan ke CSS
    // persen. Dompdf tidak menyelesaikan tinggi persen terhadap induk yang
    // tingginya sendiri ditentukan isinya, dan hasilnya batang setinggi nol.
    $tinggiBidang = 24;
    $puncak = max(1, ...array_map(fn ($baris) => (int) $baris['berlabel'], $tren ?: [['berlabel' => 0]]));

    // Lebar kolom dipatok, bukan dibagi rata selebar halaman. Satu bulan hanya
    // punya empat sampai enam periode, dan membiarkan tabel menebar keempatnya
    // sepanjang 176 mm menghasilkan batang yang saling berjauhan. Sisa lebarnya
    // dipakai dua sel kosong di kiri dan kanan supaya kelompoknya tetap di
    // tengah.
    $lebarKolom = min(30, (int) floor(176 / max(1, count($tren))));
    $lebarBatang = $lebarKolom - 4;
    $sisaTepi = max(0, (176 - $lebarKolom * count($tren)) / 2);
@endphp
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan pemberitaan {{ $namaBulan }}</title>
    <style>
        /*
            Margin halaman menempel pada elemen html, bukan pada kotak halaman
            tersendiri. Menulis `html { margin: 0 }` di sini, kebiasaan yang
            benar di peramban, menghapus seluruh margin cetak dan membuat isi
            laporan menempel di tepi kertas. Jadi html tidak boleh disentuh.

            Margin bawah sengaja tipis: watermark menempel di dasar bidang isi,
            dan margin yang tebal menaikkannya jauh dari tepi kertas.
        */
        @page { margin: 9mm 10mm 8mm; }
        body { margin: 0; padding: 0; }
        body { color: #172033; font-family: Arial, Helvetica, sans-serif; font-size: 9pt; line-height: 1.36; }
        h1, h2, h3, p { margin: 0; }
        h1 { color: #ffffff; font-size: 18pt; line-height: 1.15; }
        h2 { color: #102a48; font-size: 11pt; }
        h3 { color: #163f6c; font-size: 9.5pt; }
        .lebar { width: 100%; border-collapse: collapse; }
        .kop { border-bottom: 1.2mm solid #163f6c; }
        .kop td { padding: 0 0 3mm; vertical-align: middle; }
        /*
            Keping kaca: bidang bersudut membulat tanpa tepi, warnanya navy merek
            pada kepekatan tujuh persen di atas kertas putih. Kepekatan itu
            ditulis sebagai warna jadi, bukan rgba, karena kertas laporan selalu
            putih sehingga hasil campurannya tetap, sementara alfa di dompdf
            tidak selalu terbawa ke berkas akhir.

            Lebar 22 mm adalah jumlah isinya, bukan angka kira-kira: isi 1,4 mm
            di kiri dan kanan, dua lambang 8,5 mm, dan sela 2,5 mm di antaranya.
            Keping yang lebih lebar dari isinya menyisakan ruang kosong yang
            hanya terlihat di sisi kanan, dan kop jadi tampak miring.

            Kedua lambang duduk di sel bertumpu tengah, bukan sebagai elemen
            sebaris. Elemen sebaris di dompdf bertumpu pada garis alas huruf,
            sehingga kotak yang tingginya berbeda berhenti pada ketinggian yang
            berbeda pula.
        */
        .lencana { width: 22.3mm; padding: 1.4mm; border-radius: 2.4mm; background-color: #eaeff6; }
        .jajar-logo { border-collapse: collapse; }
        .jajar-logo td { padding: 0; vertical-align: middle; }
        .sela-logo { width: 2.5mm; }
        .logo { width: 8.5mm; height: 8.5mm; }
        /* Aksen pendek di bawah garis kop, satu-satunya hiasan yang bukan garis penuh. */
        .aksen-kop { width: 34mm; height: 0.8mm; background-color: #4b7cae; }
        .instansi { color: #102a48; font-size: 10pt; font-weight: bold; }
        .sistem { color: #526071; font-size: 7.5pt; }
        .label-kanan { color: #64748b; font-size: 7pt; font-weight: bold; text-align: right; text-transform: uppercase; }
        .isi-kanan { color: #102a48; font-size: 9pt; font-weight: bold; text-align: right; }
        /* Satu-satunya bidang navy penuh, judul yang dicari saat dokumen ini menumpuk di meja. */
        .judul-dokumen { margin-top: 3mm; padding: 3.5mm 5mm; background-color: #163f6c; }
        .judul-dokumen .jenis { color: #b9cee6; font-size: 7pt; font-weight: bold; text-transform: uppercase; }
        .judul-dokumen .rentang { color: #cfdcec; font-size: 8.5pt; padding-top: 1.6mm; }
        .meta { padding: 2.8mm 3.5mm; border: 0.25mm solid #dbe3ee; background-color: #f7f9fc; }
        .meta td { vertical-align: top; }
        .meta .waktu { width: 54mm; color: #526071; font-size: 7.5pt; text-align: right; }
        .bagian { padding-top: 3mm; }
        .penanda { color: #102a48; font-size: 11pt; font-weight: bold; border-left: 1.1mm solid #163f6c; padding-left: 2.6mm; }
        .keterangan-bagian { width: 62mm; color: #64748b; font-size: 7.5pt; text-align: right; }
        .analisis { padding: 2.6mm 3.5mm; border: 0.25mm solid #d5deea; border-left: 1.1mm solid #163f6c; background-color: #f7f9fc; }
        .analisis .judul-narasi { color: #163f6c; font-size: 9.5pt; font-weight: bold; padding-top: 2.5mm; }
        .analisis .badan { color: #364152; padding-top: 1.6mm; }
        /*
            Titik peluru berdiri di selnya sendiri, bukan diselipkan di depan
            kalimat. Takuk gantung lewat text-indent negatif meleset satu sampai
            dua milimeter karena lebar titik dan spasi tidak pernah persis sama
            dengan takuknya, sedangkan lebar sel selalu persis. Baris sambungan
            jadi lurus di bawah huruf pertama, bukan di bawah titiknya.
        */
        .analisis .peluru { width: 3.4mm; color: #163f6c; font-size: 8.5pt; padding: 0.7mm 0; vertical-align: top; }
        .analisis .poin { color: #364152; font-size: 8.5pt; padding: 0.7mm 0; vertical-align: top; }
        .analisis .pola { color: #364152; padding-top: 2mm; margin-top: 2mm; border-top: 0.3mm solid #d7dee8; }
        .kpi td { width: 33.33%; padding: 2.4mm 3mm; border: 0.25mm solid #dbe3ee; border-top: 0.9mm solid #163f6c; background-color: #f7f9fc; vertical-align: top; }
        .kpi .sela { width: 2mm; padding: 0; border: 0; background-color: transparent; }
        .kpi .nama { color: #526071; font-size: 7.5pt; font-weight: bold; }
        .kpi .nilai { color: #102a48; font-size: 16pt; font-weight: bold; padding-top: 1mm; }
        .kpi .satuan { color: #64748b; font-size: 7pt; padding-top: 0.8mm; }
        .komposisi { padding: 2.6mm 3mm; border: 0.25mm solid #dbe3ee; background-color: #fbfcfe; }
        .komposisi td { font-size: 7.8pt; padding: 0.8mm 0; vertical-align: middle; }
        .komposisi .nama { width: 16mm; font-weight: bold; }
        .komposisi .jalur { height: 2.2mm; background-color: #e8edf3; }
        .komposisi .isi { height: 2.2mm; }
        .komposisi .jumlah { width: 27mm; color: #102a48; font-weight: bold; text-align: right; }
        table.data { width: 100%; border-collapse: collapse; font-size: 8.2pt; border: 0.25mm solid #dbe3ee; }
        table.data th { padding: 1.7mm 1.8mm; color: #ffffff; background-color: #163f6c; font-size: 7.5pt; text-align: left; }
        table.data td { padding: 1.45mm 1.8mm; border-bottom: 0.25mm solid #e3e9f0; }
        table.data th.angka, table.data td.angka { text-align: right; }
        table.data tr.selang td { background-color: #f6f9fc; }
        /* Garis penutup kolom paling kanan, supaya deret angka negatif tidak menggantung. */
        table.data td.tutup { border-right: 0.25mm solid #dbe3ee; }
        table.data th.tutup { border-right: 0.25mm solid #163f6c; }
        .utama { color: #102a48; font-weight: bold; }
        .nomor { width: 8mm; color: #64748b; text-align: center; }
        .nama-media { width: 52mm; font-weight: bold; }
        /* Baris berita setinggi dua sampai empat baris teks, jadi nomor, media,
           dan tanggalnya bertumpu di atas, sejajar dengan judulnya. */
        table.data.berita td { vertical-align: top; }
        .judul-berita { color: #102a48; font-weight: bold; }
        /* Alasan model atas label negatifnya, sengaja lebih kecil dan pucat daripada judul. */
        .penilaian { color: #5b6779; font-size: 7.3pt; line-height: 1.3; padding-top: 0.6mm; }
        .positif-teks { color: #1f7047; }
        .negatif-teks { color: #9b262c; }
        .status { padding: 0.4mm 1.6mm; border: 0.25mm solid #cbd5e1; color: #526071; font-size: 7pt; }
        .status.mitra { border-color: #9fb4ce; color: #163f6c; background-color: #eef4fa; }
        .grafik { padding: 2.5mm 3mm 2mm; border: 0.25mm solid #dbe3ee; background-color: #fbfcfe; }
        .grafik .nilai { color: #102a48; font-size: 7.5pt; font-weight: bold; text-align: center; padding-bottom: 1.2mm; }
        .grafik .bidang { vertical-align: bottom; }
        .grafik .sumbu { color: #64748b; font-size: 6.5pt; text-align: center; padding-top: 1.2mm; }
        .grafik .batang { margin: 0 auto; }
        .keterangan-grafik { padding-top: 1.6mm; color: #64748b; font-size: 7pt; text-align: right; }
        .keterangan-grafik .kotak { padding: 0 1.4mm; margin-left: 1mm; }
        .catatan { margin-top: 4mm; padding: 3mm 3.5mm; border-left: 1.1mm solid #163f6c; background-color: #f2f6fb; }
        .catatan .isi { color: #526071; font-size: 7.5pt; padding-top: 0.8mm; }
        .kaki { padding-top: 2mm; margin-top: 2.5mm; border-top: 0.25mm solid #cfdaea; }
        .kaki td { color: #64748b; font-size: 7pt; }
        /*
            Watermark kaki halaman.

            Elemen fixed digambar ulang dompdf pada setiap halaman, dan itu
            satu-satunya cara memasang penanda berulang tanpa menghitung sendiri
            pemenggalan halaman. Ditempel di dasar bidang isi, bukan di dalam
            margin: dompdf memangkas apa pun yang jatuh di luar bidang itu,
            sehingga watermark bernilai negatif hilang sama sekali. Letak
            turunnya diatur lewat margin bawah halaman, bukan lewat nilai di
            sini.
        */
        .tanda-air {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            padding-top: 1.4mm;
            border-top: 0.25mm solid #e1e8f1;
            color: #a9b7c9;
            font-size: 6.5pt;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="tanda-air">SIMAK &middot; PEMERINTAH KOTA KENDARI &middot; LAPORAN {{ strtoupper($namaBulan) }}</div>

    {{--
        Keping lambang berada dalam satu sel kop, bukan satu sel untuk tiap
        lambang. Dompdf mengabaikan table-layout fixed dan melebarkan sel
        menurut isinya, jadi dua sel lambang yang terpisah di tabel kop berakhir
        saling berjauhan. Lebar sel kop ditulis dalam persen karena itu yang
        dihormatinya.
    --}}
    <table class="lebar kop">
        <tr>
            <td style="width: 17%;">
                <div class="lencana">
                    <table class="jajar-logo">
                        <tr>
                            <td style="width: 8.5mm; padding-left: 6px;"><img class="logo" src="{{ public_path('img/Lambang_Kota_Kendari.webp') }}" alt=""></td>
                            <td class="sela-logo"></td>
                            <td style="width: 8.5mm;"><img class="logo" src="{{ public_path('img/logo-simak.png') }}" alt=""></td>
                        </tr>
                    </table>
                </div>
            </td>
            <td style="width: 51%;">
                <div class="instansi">Pemerintah Kota Kendari</div>
                <div class="sistem">Sistem Pemantauan dan Analisis Pemberitaan Media</div>
            </td>
            <td style="width: 32%;">
                <div class="label-kanan">Periode laporan</div>
                <div class="isi-kanan">{{ $namaBulan }}</div>
            </td>
        </tr>
    </table>
    <div class="aksen-kop"></div>

    <div class="judul-dokumen">
        <div class="jenis">Laporan bulanan pemantauan media</div>
        <h1>Laporan kondisi pemberitaan Kota Kendari</h1>
        <div class="rentang">Periode {{ $rentangPeriode }}</div>
    </div>

    <table class="lebar meta">
        <tr>
            <td><strong>Ringkasan keadaan.</strong> {{ $ringkasan }}</td>
            <td class="waktu">Dibuat {{ $waktuPembuatan }}</td>
        </tr>
    </table>

    <div class="bagian">
        <div class="analisis">
            <div style="color: #102a48; font-size: 11pt; font-weight: bold;">Analisis pemberitaan</div>

            @if ($narasi && $narasi['ringkasan'])
                @if ($narasi['judul'])
                    <div class="judul-narasi">{{ $narasi['judul'] }}</div>
                @endif
                <div class="badan">{!! nl2br(e($narasi['ringkasan'])) !!}</div>

                @if (count($narasi['poin']))
                    {{-- Sela 6 mm antar kolom dibuat sel kosong, karena takuk gantung
                         di sel kanan tidak boleh ikut tergeser oleh padding. --}}
                    <table class="lebar" style="margin-top: 2mm;">
                        @foreach (array_chunk($narasi['poin'], 2) as $pasangan)
                            <tr>
                                @foreach ($pasangan as $urutanPoin => $poin)
                                    @if ($urutanPoin === 1)
                                        <td style="width: 6mm;"></td>
                                    @endif
                                    <td class="peluru">&bull;</td>
                                    <td class="poin">{{ $poin['teks'] }}</td>
                                @endforeach
                                @if (count($pasangan) === 1)
                                    <td style="width: 6mm;"></td>
                                    <td class="peluru"></td>
                                    <td class="poin"></td>
                                @endif
                            </tr>
                        @endforeach
                    </table>
                @endif

                @if ($narasi['penjelasan_tren'])
                    <div class="pola"><strong>Pola sepanjang bulan:</strong> {{ $narasi['penjelasan_tren'] }}</div>
                @endif
            @else
                <div class="badan">Analisis pemberitaan untuk bulan ini sedang disiapkan. Angka laporan tetap dapat dibaca.</div>
            @endif
        </div>
    </div>

    <div class="bagian">
        <div class="penanda">Ikhtisar pemberitaan</div>
        <table class="lebar kpi" style="margin-top: 2.4mm;">
            <tr>
                <td>
                    <div class="nama">Total pemberitaan</div>
                    <div class="nilai">{{ $angka($kpi['berlabel']) }}</div>
                    <div class="satuan">berita pada bulan ini</div>
                </td>
                <td class="sela"></td>
                <td>
                    <div class="nama">Media memberitakan</div>
                    <div class="nilai">{{ $angka($kpi['media_aktif']) }}/{{ $angka($kpi['media_total_aktif']) }}</div>
                    <div class="satuan">media aktif pada periode ini</div>
                </td>
                <td class="sela"></td>
                <td>
                    <div class="nama">Status kerja sama</div>
                    <div class="nilai">{{ $angka($kpi['media_bekerja_sama']) }}</div>
                    <div class="satuan">bekerja sama &middot; {{ $angka($kpi['media_tidak_bekerja_sama']) }} tidak</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="bagian">
        <div class="penanda">Komposisi sentimen</div>
        <div class="komposisi" style="margin-top: 2.4mm;">
            <table class="lebar">
                @foreach ($nada as $item)
                    <tr>
                        <td class="nama" style="color: {{ $item['warna'] }};">{{ $item['nama'] }}</td>
                        <td style="padding-right: 3mm;">
                            <div class="jalur">
                                <div class="isi" style="width: {{ $kpi['berlabel'] > 0 ? round($item['jumlah'] / $kpi['berlabel'] * 100, 1) : 0 }}%; background-color: {{ $item['warna'] }};"></div>
                            </div>
                        </td>
                        <td class="jumlah">{{ $angka($item['jumlah']) }} &middot; {{ $proporsi($item['jumlah'], (int) $kpi['berlabel']) }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>

    <div class="bagian">
        <table class="lebar">
            <tr>
                <td class="penanda">Tren dalam bulan</td>
                <td class="keterangan-bagian">{{ count($tren) }} periode</td>
            </tr>
        </table>

        <table class="data" style="margin-top: 2.4mm;">
            <tr>
                <th style="width: 40%;">Rentang tanggal</th>
                <th class="angka">Total</th>
                <th class="angka">Positif</th>
                <th class="angka">Netral</th>
                <th class="angka tutup">Negatif</th>
            </tr>
            @forelse ($tren as $urutan => $baris)
                <tr class="{{ $urutan % 2 === 1 ? 'selang' : '' }}">
                    <td>{{ $baris['rentang'] }}</td>
                    <td class="angka utama">{{ $angka($baris['berlabel']) }}</td>
                    <td class="angka positif-teks">{{ $angka($baris['jumlah_positif']) }}</td>
                    <td class="angka">{{ $angka($baris['jumlah_netral']) }}</td>
                    <td class="angka negatif-teks tutup">{{ $angka($baris['jumlah_negatif']) }}</td>
                </tr>
            @empty
                <tr><td colspan="5">Belum ada pemberitaan pada bulan ini.</td></tr>
            @endforelse
        </table>

        @if (count($tren))
            {{--
                Grafik batang tumpuk dari data yang sama dengan tabel di atasnya.
                Tabel tidak menunjukkan bentuk bulan itu, sedangkan tinggi batang
                langsung terbaca sebagai naik atau turun.
            --}}
            <div class="grafik" style="margin-top: 2.4mm;">
                <table class="lebar">
                    <tr>
                        @if ($sisaTepi > 0)
                            <td style="width: {{ $sisaTepi }}mm;"></td>
                        @endif
                        @foreach ($tren as $baris)
                            <td class="nilai" style="width: {{ $lebarKolom }}mm;">{{ $angka($baris['berlabel']) }}</td>
                        @endforeach
                        @if ($sisaTepi > 0)
                            <td style="width: {{ $sisaTepi }}mm;"></td>
                        @endif
                    </tr>
                    <tr>
                        @if ($sisaTepi > 0)
                            <td></td>
                        @endif
                        @foreach ($tren as $baris)
                            @php
                                $total = max(0, (int) $baris['berlabel']);
                                $tinggiBatang = $total === 0 ? 0.4 : round($total / $puncak * $tinggiBidang, 2);
                                $tinggiNegatif = $total === 0 ? 0 : round($baris['jumlah_negatif'] / $total * $tinggiBatang, 2);
                                $tinggiNetral = $total === 0 ? 0 : round($baris['jumlah_netral'] / $total * $tinggiBatang, 2);
                                // Sisa dihitung dari pengurangan supaya pembulatan
                                // ketiga segmen tidak pernah melebihi tinggi batang.
                                $tinggiPositif = max(0, round($tinggiBatang - $tinggiNegatif - $tinggiNetral, 2));
                            @endphp
                            <td class="bidang" style="height: {{ $tinggiBidang }}mm;">
                                <div class="batang" style="width: {{ $lebarBatang }}mm;">
                                    <div style="height: {{ $tinggiNegatif }}mm; background-color: #a72d31;"></div>
                                    <div style="height: {{ $tinggiNetral }}mm; background-color: #768397;"></div>
                                    <div style="height: {{ $tinggiPositif }}mm; background-color: #287a50;"></div>
                                </div>
                            </td>
                        @endforeach
                        @if ($sisaTepi > 0)
                            <td></td>
                        @endif
                    </tr>
                    <tr>
                        @if ($sisaTepi > 0)
                            <td></td>
                        @endif
                        @foreach ($tren as $baris)
                            <td class="sumbu">{{ $baris['rentang'] }}</td>
                        @endforeach
                        @if ($sisaTepi > 0)
                            <td></td>
                        @endif
                    </tr>
                </table>
            </div>
            <div class="keterangan-grafik">
                <span class="kotak" style="background-color: #287a50;">&nbsp;</span> Positif
                <span class="kotak" style="background-color: #768397;">&nbsp;</span> Netral
                <span class="kotak" style="background-color: #a72d31;">&nbsp;</span> Negatif
            </div>
        @endif
    </div>

    <div style="page-break-before: always;"></div>

    <table class="lebar">
        <tr>
            <td class="penanda">Kondisi media aktif</td>
            <td class="keterangan-bagian">{{ $angka(count($media)) }} media &middot; termasuk yang belum memberitakan</td>
        </tr>
    </table>

    <table class="data" style="margin-top: 2.4mm;">
        <thead>
            <tr>
                <th class="nomor" style="color: #ffffff;">No.</th>
                <th class="nama-media" style="color: #ffffff;">Media</th>
                <th style="width: 24mm;">Kerja sama</th>
                <th class="angka">Total</th>
                <th class="angka">Positif</th>
                <th class="angka">Netral</th>
                <th class="angka tutup">Negatif</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($media as $urutan => $baris)
                <tr class="{{ $urutan % 2 === 1 ? 'selang' : '' }}">
                    <td class="nomor">{{ $urutan + 1 }}.</td>
                    <td class="nama-media">{{ $baris['nama'] }}</td>
                    <td><span class="status {{ $baris['partner'] ? 'mitra' : '' }}">{{ $baris['partner'] ? 'Ya' : 'Tidak' }}</span></td>
                    <td class="angka utama">{{ $angka($baris['jumlah_artikel']) }}</td>
                    <td class="angka positif-teks">{{ $angka($baris['jumlah_positif']) }}</td>
                    <td class="angka">{{ $angka($baris['jumlah_netral']) }}</td>
                    <td class="angka negatif-teks tutup">{{ $angka($baris['jumlah_negatif']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if (count($negatif))
        {{--
            Lembar tersendiri untuk berita negatif, dan hanya dibuat kalau
            beritanya ada. Halaman kosong bertuliskan "tidak ada berita negatif"
            menghabiskan satu lembar kertas untuk kabar baik yang sudah terbaca
            dari angka nol di komposisi sentimen.
        --}}
        <div style="page-break-before: always;"></div>

        <table class="lebar">
            <tr>
                <td class="penanda">Berita bersentimen negatif</td>
                <td class="keterangan-bagian">{{ $angka(count($negatif)) }} berita &middot; terbaru lebih dulu</td>
            </tr>
        </table>

        <table class="data berita" style="margin-top: 2.4mm;">
            <thead>
                <tr>
                    <th class="nomor" style="color: #ffffff;">No.</th>
                    <th>Berita dan hasil penilaian</th>
                    <th style="width: 30mm;">Media</th>
                    <th style="width: 19mm;">Kerja sama</th>
                    <th class="tutup" style="width: 21mm;">Terbit</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($negatif as $urutan => $baris)
                    <tr class="{{ $urutan % 2 === 1 ? 'selang' : '' }}">
                        <td class="nomor">{{ $urutan + 1 }}.</td>
                        <td>
                            <div class="judul-berita">{{ $baris['judul'] }}</div>
                            @if ($baris['penilaian'])
                                <div class="penilaian">{{ $baris['penilaian'] }}</div>
                            @endif
                        </td>
                        <td>{{ $baris['media'] }}</td>
                        <td><span class="status {{ $baris['partner'] ? 'mitra' : '' }}">{{ $baris['partner'] ? 'Ya' : 'Tidak' }}</span></td>
                        <td class="tutup">{{ $baris['tanggal'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="catatan">
        <strong>Catatan penggunaan</strong>
        <div class="isi">
            Laporan ini menggambarkan volume dan sentimen pemberitaan media tentang Pemerintah Kota Kendari.
            Angka di dalamnya bukan penilaian kinerja pemerintah.
        </div>
    </div>

    <table class="lebar kaki">
        <tr>
            <td>Sumber: agregasi berita SIMAK Kota Kendari</td>
            <td style="width: 62mm; text-align: right;">Dibuat {{ $waktuPembuatan }}</td>
        </tr>
    </table>
</body>
</html>
