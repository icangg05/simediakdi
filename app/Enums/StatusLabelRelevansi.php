<?php

namespace App\Enums;

enum StatusLabelRelevansi: string
{
    case BelumDilabeli = 'belum_dilabeli';
    case SudahDilabeli = 'sudah_dilabeli';
    case PerluReview = 'perlu_review';
    case Dikeluarkan = 'dikeluarkan';
    /**
     * Anggota test set yang sudah dibekukan. Labelnya hanya boleh diubah
     * superadmin dengan alasan, dan perubahannya membuat versi snapshot baru.
     */
    case TerkunciTest = 'terkunci_test';

    /** Hanya keputusan final yang boleh masuk snapshot dataset. */
    public function final(): bool
    {
        return $this === self::SudahDilabeli || $this === self::TerkunciTest;
    }
}
