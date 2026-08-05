<?php

namespace App\Services\Relevance;

use RuntimeException;

/**
 * Artefak model berubah sejak dievaluasi.
 *
 * Dibedakan dari kegagalan biasa karena penanganannya berbeda: kegagalan
 * jaringan pantas dicoba lagi, sedangkan bobot model yang tidak lagi sama
 * dengan yang dievaluasi tidak akan membaik dengan diulang. Dokumen 10 bagian
 * 12.6 menyebutnya sebagai sebab pencabutan gerbang mutu otomatis.
 */
class ArtefakBerubah extends RuntimeException {}
