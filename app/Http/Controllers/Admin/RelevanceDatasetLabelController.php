<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StatusLabelRelevansi;
use App\Http\Controllers\Controller;
use App\Models\SampelRelevansi;
use App\Support\AlasanLabelRelevansi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Menyimpan keputusan pelabel atas satu sampel.
 *
 * Perubahannya dicatat activity log lewat `SampelRelevansi`, beserta nilai
 * sebelum dan sesudahnya. Label adalah bahan baku model: yang salah akan
 * diajarkan dan menjadi kesalahan permanen sampai ada yang menelusurinya
 * kembali, dan penelusuran itu mustahil tanpa catatan siapa dan kapan.
 */
class RelevanceDatasetLabelController extends Controller
{
    public function store(Request $request, SampelRelevansi $sampel): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', Rule::in(['relevan', 'tidak_relevan'])],
            'alasan' => ['nullable', Rule::in(AlasanLabelRelevansi::semua())],
            'kesulitan' => ['nullable', Rule::in(['normal', 'hard_positive', 'hard_negative'])],
        ]);

        // Kunci yang tidak dikirim sama sekali tidak muncul di hasil validate,
        // dan itu berbeda dari kunci yang dikirim bernilai null. Disamakan di
        // satu tempat supaya sisa method ini tidak perlu memikirkannya.
        $data['alasan'] ??= null;
        $data['kesulitan'] ??= null;

        $this->pastikanAlasanCukup($sampel, $data);

        $this->pastikanKesulitanCocok($data);

        if ($data['alasan'] !== null && ! in_array($data['alasan'], AlasanLabelRelevansi::untuk($data['label']), strict: true)) {
            // Alasan `pemprov_sultra` pada label relevan bukan salah ketik
            // melainkan tanda pelabel salah menekan, dan label yang salah akan
            // diajarkan ke model sebagai kebenaran.
            throw ValidationException::withMessages([
                'alasan' => 'Alasan itu tidak cocok dengan label yang dipilih.',
            ]);
        }

        $sampel->update([
            'label_manual' => $data['label'],
            'alasan_label' => $data['alasan'],
            'tingkat_kesulitan' => $data['kesulitan'] ?? $sampel->tingkat_kesulitan,
            // Sampel test yang sudah terkunci tetap terkunci. Melabelinya
            // ulang tidak boleh diam-diam mengembalikannya ke dataset biasa,
            // karena yang menentukan angka evaluasi adalah keanggotaannya di
            // test set, bukan statusnya di sini.
            'status_label' => $sampel->status_label === StatusLabelRelevansi::TerkunciTest
                ? StatusLabelRelevansi::TerkunciTest
                : StatusLabelRelevansi::SudahDilabeli,
            'labeled_by' => $request->user()->id,
            'labeled_at' => now(),
            'last_reviewed_at' => now(),
        ]);

        return back()->with('sukses', 'Label tersimpan.');
    }

    /**
     * Melewati satu sampel tanpa memutuskan.
     *
     * Menandainya `perlu_review`, bukan membiarkannya `belum_dilabeli`. Sampel
     * yang dilewati akan muncul lagi di urutan teratas antrean berikutnya, dan
     * pelabel bertemu artikel yang sama berulang kali sampai menyerah.
     */
    public function lewati(SampelRelevansi $sampel): RedirectResponse
    {
        $sampel->update(['status_label' => StatusLabelRelevansi::PerluReview]);

        return back()->with('sukses', 'Dilewati, masuk antrean perlu review.');
    }

    /**
     * Mengeluarkan sampel dari dataset. Selalu wajib beralasan.
     *
     * Tidak menghapus barisnya. Sampel yang dikeluarkan adalah bukti bahwa
     * sesuatu pernah dipertimbangkan lalu ditolak, dan menghapusnya membuat
     * orang berikutnya mengimpornya kembali dengan alasan yang sama.
     */
    public function keluarkan(Request $request, SampelRelevansi $sampel): RedirectResponse
    {
        $data = $request->validate([
            'alasan' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $sampel->update([
            'is_excluded' => true,
            'excluded_reason' => $data['alasan'],
            'status_label' => StatusLabelRelevansi::Dikeluarkan,
        ]);

        return back()->with('sukses', 'Sampel dikeluarkan dari dataset.');
    }

    /**
     * Hard case harus berpasangan dengan labelnya.
     *
     * `hard_negative` berarti artikel yang **tidak relevan** tetapi terlihat
     * relevan, dan `hard_positive` kebalikannya. Pasangan yang tertukar bukan
     * sekadar salah istilah: strategi sampling `balanced_with_hard_cases`
     * memilih sampel berdasarkan penanda ini, dan analisis kesalahan
     * mengelompokkan false positive dengan false negative memakainya. Penanda
     * yang terbalik membuat keduanya menghitung hal yang salah tanpa satu pun
     * galat muncul.
     *
     * @param  array<string, mixed>  $data
     */
    private function pastikanKesulitanCocok(array $data): void
    {
        $harusnya = [
            'hard_negative' => 'tidak_relevan',
            'hard_positive' => 'relevan',
        ][$data['kesulitan'] ?? 'normal'] ?? null;

        if ($harusnya !== null && $data['label'] !== $harusnya) {
            throw ValidationException::withMessages([
                'kesulitan' => $data['kesulitan'] === 'hard_negative'
                    ? 'Hard negative hanya untuk artikel yang dilabeli tidak relevan, yaitu yang terlihat relevan padahal bukan.'
                    : 'Hard positive hanya untuk artikel yang dilabeli relevan, yaitu yang tidak terlihat relevan padahal iya.',
            ]);
        }
    }

    /**
     * Lima keadaan yang mewajibkan alasan, dokumen 10 bagian 7.5.
     *
     * Di luar kelima itu alasan boleh kosong, dan itu disengaja. Mewajibkannya
     * pada setiap keputusan menurunkan laju pelabelan sampai target 1.500
     * artikel tidak akan pernah tercapai, dan alasan yang diisi asal-asalan
     * karena wajib lebih buruk daripada alasan yang kosong dengan jujur.
     *
     * Dua keadaan lain di dokumen, yaitu label yang berbeda dari prediksi
     * berkeyakinan tinggi, menyusul bersama model pertama yang benar-benar
     * menghasilkan prediksi.
     *
     * @param  array<string, mixed>  $data
     */
    private function pastikanAlasanCukup(SampelRelevansi $sampel, array $data): void
    {
        if ($data['alasan'] !== null) {
            return;
        }

        $wajib = match (true) {
            $sampel->label_manual !== null && $sampel->label_manual->value !== $data['label'] => 'Mengubah label yang sudah ada wajib disertai alasan.',
            $sampel->status_label === StatusLabelRelevansi::TerkunciTest => 'Sampel di test set terkunci wajib disertai alasan.',
            ($data['kesulitan'] ?? 'normal') !== 'normal' => 'Sampel yang ditandai hard case wajib disertai alasan.',
            default => null,
        };

        if ($wajib !== null) {
            throw ValidationException::withMessages(['alasan' => $wajib]);
        }
    }
}
