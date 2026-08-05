"""Inferensi relevansi dari artefak hasil fine-tuning. Dokumen 10 bagian 19.2.

Tiga hal yang membedakannya dari inferensi sentimen di `main.py`:

1. **Modelnya dimuat dari direktori artefak, bukan dari Hugging Face.** Yang
   dipakai adalah bobot hasil pelatihan lokal, dan versinya berganti setiap
   promosi.
2. **Checksum diverifikasi sebelum dipakai.** Artefak yang berubah tanpa
   sepengetahuan sistem berarti model yang berjalan bukan model yang
   dievaluasi, dan seluruh angka gerbang mutu menjadi klaim tentang berkas yang
   sudah tidak ada. Dokumen 10 bagian 12.6 menyebut checksum berubah sebagai
   sebab pencabutan gerbang otomatis.
3. **Teks tidak dibentuk di sini.** Laravel yang menyusunnya lewat
   `RelevanceInputBuilder`, dan layanan ini menerimanya jadi. Menyusunnya di
   dua tempat adalah cara paling pasti pelatihan dan inferensi memakai susunan
   yang berbeda, dan kegagalannya diam: angkanya tetap keluar dan tetap wajar.
"""

from __future__ import annotations

import hashlib
import json
import logging
import threading
import time
from pathlib import Path
from typing import Any

import torch
from transformers import AutoModelForSequenceClassification, AutoTokenizer

log = logging.getLogger("simedia-nlp.relevancy")

# Satu model dimuat pada satu waktu. BERT large memakan sekitar 1,3 GB, dan
# proses ini sudah memegang model sentimen serta embedding. Membandingkan dua
# versi berarti memuat bergantian, lebih lambat tetapi tidak pernah kehabisan
# memori di tengah jalan.
#
# ponytail: satu slot, ganti kalau perbandingan berdampingan jadi sering
# dipakai dan servernya punya memori lebih.
_dimuat: dict[str, Any] = {}
_kunci = threading.Lock()


class ChecksumTidakCocok(RuntimeError):
    pass


def checksum(direktori: Path) -> str:
    """Cap jari seluruh berkas artefak, dihitung dengan cara yang sama persis
    seperti saat pelatihan menyimpannya."""
    berkas = {}

    for path in sorted(direktori.rglob("*")):
        if path.is_file():
            h = hashlib.sha256()
            with open(path, "rb") as f:
                for potongan in iter(lambda: f.read(1 << 20), b""):
                    h.update(potongan)
            berkas[str(path.relative_to(direktori))] = h.hexdigest()

    return hashlib.sha256(
        "\n".join(f"{k}:{v}" for k, v in berkas.items()).encode()
    ).hexdigest()


def muat(versi: str, artifact_path: str) -> dict[str, Any]:
    """Memuat model sekali lalu memakainya ulang.

    Pemuatan memakan belasan detik dan verifikasi checksum menyapu 1,3 GB, jadi
    melakukannya per artikel akan membuat inferensi seratus kali lebih lambat
    daripada pekerjaan yang sebenarnya.
    """
    with _kunci:
        if _dimuat.get("versi") == versi:
            return _dimuat

        akar = Path(artifact_path)
        model_dir = akar / "model"
        manifest_path = akar / "manifest.json"

        if not model_dir.is_dir():
            raise FileNotFoundError(f"Direktori model tidak ditemukan: {model_dir}")

        if manifest_path.is_file():
            manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
            diharapkan = manifest.get("checksum")
            sebenarnya = checksum(model_dir)

            if diharapkan and diharapkan != sebenarnya:
                # Menolak, bukan memperingatkan lalu tetap memuat. Model yang
                # bobotnya berubah diam-diam tetap mengeluarkan angka, dan
                # angkanya akan dibaca seolah berasal dari model yang lulus
                # gerbang mutu.
                raise ChecksumTidakCocok(
                    f"Checksum artefak {versi} tidak cocok. "
                    f"Diharapkan {diharapkan[:16]}, sebenarnya {sebenarnya[:16]}. "
                    "Artefak berubah sejak dievaluasi, dan model ini tidak boleh dipakai."
                )

        log.info("Memuat model relevansi %s dari %s", versi, model_dir)

        tokenizer = AutoTokenizer.from_pretrained(str(model_dir))
        model = AutoModelForSequenceClassification.from_pretrained(str(model_dir))
        model.eval()

        _dimuat.clear()
        _dimuat.update({"versi": versi, "tokenizer": tokenizer, "model": model})

        return _dimuat


def versi_dimuat() -> str | None:
    with _kunci:
        return _dimuat.get("versi")


def lupakan() -> None:
    """Membuang model dari memori, dipakai saat rollback atau saat artefaknya
    diganti."""
    with _kunci:
        _dimuat.clear()


@torch.inference_mode()
def prediksi(
    versi: str,
    artifact_path: str,
    pasangan: list[dict[str, Any]],
    maks_panjang: int = 256,
) -> list[dict[str, Any]]:
    """Peluang tiap kelas untuk setiap pasangan konteks dan teks.

    `input_truncated` ikut dikembalikan dan bukan hiasan: artikel yang terpotong
    dinilai dari separuh isinya, dan tanpa penanda itu kesalahan yang sebenarnya
    berasal dari pemotongan akan dibaca sebagai kesalahan model lalu diperbaiki
    dengan melatih ulang.
    """
    muatan = muat(versi, artifact_path)
    tokenizer, model = muatan["tokenizer"], muatan["model"]

    mulai = time.perf_counter()

    hasil_tokenisasi = tokenizer(
        [p["konteks"] for p in pasangan],
        [p["teks"] for p in pasangan],
        truncation="only_second",
        max_length=maks_panjang,
        padding=True,
        return_tensors="pt",
    )

    logit = model(**hasil_tokenisasi).logits
    peluang = torch.softmax(logit, dim=1).tolist()

    # Panjang sebelum dipotong, dihitung terpisah supaya `input_truncated`
    # menjawab pertanyaan yang benar: apakah ada isi yang hilang, bukan apakah
    # hasilnya kebetulan pas.
    ms = int((time.perf_counter() - mulai) * 1000 / max(len(pasangan), 1))
    keluaran = []

    for satu, baris in zip(pasangan, peluang):
        penuh = len(tokenizer.tokenize(satu["konteks"])) + len(tokenizer.tokenize(satu["teks"]))

        keluaran.append(
            {
                "id": satu["id"],
                # id2label base model: 0 NOT_RELEVANT, 1 RELEVANT. Dipetakan
                # eksplisit, bukan lewat argmax lalu ditebak namanya.
                "probabilitas_tidak_relevan": round(float(baris[0]), 6),
                "probabilitas_relevan": round(float(baris[1]), 6),
                "input_tokens": penuh + 3,
                "input_truncated": penuh + 3 > maks_panjang,
                "inference_ms": ms,
            }
        )

    return keluaran
