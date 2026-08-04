<?php

namespace App\Support;

/**
 * Kode alasan label, dokumen 10 bagian 7.5.
 *
 * Kode terstruktur, bukan catatan bebas. Yang seragam bisa dihitung, dan itulah
 * yang menghasilkan kalimat seperti "31% false positive berasal dari Pemprov
 * Sultra" pada analisis kesalahan. Catatan bebas yang ditulis dengan kata
 * berbeda setiap kali tidak bisa dijumlahkan sama sekali.
 *
 * Satu tempat, dipakai validasi maupun pilihan di layar. Dua daftar yang
 * terpisah akan berbeda dalam sebulan, dan yang pertama kali terlihat adalah
 * alasan yang bisa dipilih pelabel tapi ditolak validasi.
 */
class AlasanLabelRelevansi
{
    public const TIDAK_RELEVAN = [
        'lokasi_saja' => 'Kendari hanya lokasi kejadian',
        'pemprov_sultra' => 'Fokusnya Pemprov Sultra',
        'instansi_vertikal' => 'Instansi vertikal',
        'polri_tni' => 'Polri atau TNI',
        'kampus' => 'Kampus',
        'perusahaan_organisasi' => 'Perusahaan atau organisasi',
        'kriminalitas_umum' => 'Kriminalitas umum',
        'olahraga_hiburan' => 'Olahraga atau hiburan',
        'pemerintah_daerah_lain' => 'Pemerintah daerah lain',
        'pemkot_disebut_sepintas' => 'Pemkot disebut sepintas',
        'tidak_ada_kewenangan_pemkot' => 'Tidak menyangkut kewenangan Pemkot',
        'lainnya' => 'Lainnya',
    ];

    public const RELEVAN = [
        'institusi_pemkot' => 'Institusi Pemkot',
        'wali_kota_wakil_wali_kota' => 'Wali Kota atau Wakil Wali Kota',
        'opd_unit_kerja' => 'OPD atau unit kerja',
        'kebijakan_program' => 'Kebijakan atau program',
        'pelayanan_publik' => 'Pelayanan publik',
        'anggaran_pengadaan' => 'Anggaran atau pengadaan',
        'pembangunan_infrastruktur' => 'Pembangunan infrastruktur',
        'kritik_keluhan' => 'Kritik atau keluhan',
        'respons_tindak_lanjut' => 'Respons atau tindak lanjut',
        'hubungan_dprd_pemkot' => 'Hubungan DPRD dan Pemkot',
        'lainnya' => 'Lainnya',
    ];

    /** @return list<string> */
    public static function semua(): array
    {
        return array_keys(self::RELEVAN + self::TIDAK_RELEVAN);
    }

    /**
     * Alasan yang cocok dengan labelnya.
     *
     * Alasan `pemprov_sultra` pada label relevan bukan salah ketik melainkan
     * tanda pelabel salah menekan, dan label yang salah akan diajarkan ke model
     * sebagai kebenaran.
     *
     * @return list<string>
     */
    public static function untuk(string $label): array
    {
        return array_keys($label === 'relevan' ? self::RELEVAN : self::TIDAK_RELEVAN);
    }

    /** @return list<array{nilai: string, label: string}> */
    public static function opsi(array $daftar): array
    {
        return array_map(
            fn (string $nilai, string $label) => ['nilai' => $nilai, 'label' => $label],
            array_keys($daftar),
            array_values($daftar),
        );
    }
}
