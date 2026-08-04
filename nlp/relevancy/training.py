"""Fine-tuning classifier relevansi. Dokumen 10 bagian 10 dan 19.

Berjalan di thread latar: satu pelatihan memakan belasan menit, dan permintaan
HTTP yang menunggunya akan mati lebih dulu. Statusnya dibaca lewat polling.

Dilatih di CPU. Checkpoint yang dipakai, `apriandito/indobert-relevancy-classifier`,
ternyata BERT large (24 layer, hidden 1024), bukan base. Dua akibat yang
menentukan setelan bawaannya: panjang 256 bukan 512, dan batch 4 dengan
akumulasi gradien, supaya muat bersama dua model inferensi yang sudah hidup di
proses ini.

Head-nya sudah 2 kelas dengan id2label {0: NOT_RELEVANT, 1: RELEVANT}, jadi ini
kelanjutan pelatihan, bukan penggantian kepala klasifikasi.
"""

from __future__ import annotations

import hashlib
import json
import logging
import shutil
import threading
import traceback
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

import numpy as np
import torch
from sklearn.metrics import confusion_matrix, precision_recall_fscore_support
from torch.utils.data import Dataset
from transformers import (
    AutoModelForSequenceClassification,
    AutoTokenizer,
    Trainer,
    TrainerCallback,
    TrainingArguments,
)

log = logging.getLogger("simedia-nlp.relevancy")

# Status yang dikenali Laravel. Sama persis dengan CHECK constraint kolom
# `status` di pelatihan_model_relevansi, jadi jangan menambah nilai baru di
# sini tanpa mengubah migration-nya.
MENUNGGU = "menunggu"
MEMPERSIAPKAN = "mempersiapkan_model"
MELATIH = "melatih"
MENGEVALUASI = "mengevaluasi_test"
MENYIMPAN = "menyimpan_artefak"
SELESAI = "selesai"
GAGAL = "gagal"
DIBATALKAN = "dibatalkan"


@dataclass
class StatusPelatihan:
    run_id: int
    status: str = MENUNGGU
    progress: int = 0
    epoch: float = 0.0
    step: int = 0
    total_step: int = 0
    metrics_validation: dict[str, Any] = field(default_factory=dict)
    metrics_test: dict[str, Any] = field(default_factory=dict)
    artifact_path: str | None = None
    artifact_manifest: dict[str, Any] = field(default_factory=dict)
    error_summary: str | None = None
    error_trace: str | None = None
    dibatalkan: bool = False

    def sebagai_dict(self) -> dict[str, Any]:
        isi = self.__dict__.copy()
        isi.pop("dibatalkan", None)
        return isi


# Satu proses, satu worker, jadi dict biasa cukup. Kalau nanti ada beberapa
# worker, status harus pindah ke Redis, bukan dijaga dengan lock yang lebih
# rumit di sini.
_jalan: dict[int, StatusPelatihan] = {}
_kunci = threading.Lock()


def status(run_id: int) -> dict[str, Any] | None:
    with _kunci:
        s = _jalan.get(run_id)
        return s.sebagai_dict() if s else None


def batalkan(run_id: int) -> bool:
    with _kunci:
        s = _jalan.get(run_id)
        if s is None or s.status in (SELESAI, GAGAL, DIBATALKAN):
            return False
        s.dibatalkan = True
        return True


def sedang_berjalan() -> bool:
    """Satu pelatihan pada satu waktu.

    Dua pelatihan bersamaan di CPU yang sama tidak berjalan dua kali lebih
    cepat, keduanya justru melambat dan berebut memori sampai salah satunya
    mati kehabisan RAM di tengah jalan.
    """
    with _kunci:
        return any(s.status in (MENUNGGU, MEMPERSIAPKAN, MELATIH, MENGEVALUASI, MENYIMPAN) for s in _jalan.values())


def mulai(run_id: int, konfigurasi: dict[str, Any]) -> None:
    with _kunci:
        _jalan[run_id] = StatusPelatihan(run_id=run_id)

    threading.Thread(target=_jalankan, args=(run_id, konfigurasi), daemon=True).start()


class _Dataset(Dataset):
    def __init__(self, baris: list[dict], tokenizer, maks_panjang: int) -> None:
        self.baris = baris
        self.tokenizer = tokenizer
        self.maks_panjang = maks_panjang

    def __len__(self) -> int:
        return len(self.baris)

    def __getitem__(self, i: int) -> dict:
        satu = self.baris[i]

        # Pasangan kalimat, bukan gabungan. Konteks dan teks dipisah [SEP]
        # persis seperti saat model ini dilatih pertama kali; menggabungnya
        # menghilangkan batas yang justru dipakai model membedakan keduanya.
        hasil = self.tokenizer(
            satu["konteks"],
            satu["teks"],
            truncation="only_second",
            max_length=self.maks_panjang,
            padding="max_length",
            return_tensors="pt",
        )

        return {
            "input_ids": hasil["input_ids"][0],
            "attention_mask": hasil["attention_mask"][0],
            "token_type_ids": hasil.get("token_type_ids", hasil["input_ids"])[0],
            "labels": torch.tensor(satu["label"], dtype=torch.long),
        }


def _baca_jsonl(path: str) -> list[dict]:
    with open(path, encoding="utf-8") as f:
        return [json.loads(baris) for baris in f if baris.strip()]


def _metrik(prediksi, rujukan) -> dict[str, Any]:
    """Presisi, recall, dan F1 per kelas, plus macro.

    Accuracy sengaja ikut dihitung tetapi tidak pernah menjadi metrik utama.
    Dengan sebaran 53 berbanding 47, model yang selalu menebak relevan sudah
    mencetak akurasi 53% tanpa mempelajari apa pun.
    """
    presisi, recall, f1, dukungan = precision_recall_fscore_support(
        rujukan, prediksi, labels=[0, 1], zero_division=0
    )
    macro = precision_recall_fscore_support(rujukan, prediksi, average="macro", zero_division=0)
    matriks = confusion_matrix(rujukan, prediksi, labels=[0, 1]).tolist()

    return {
        "precision_tidak_relevan": round(float(presisi[0]), 4),
        "recall_tidak_relevan": round(float(recall[0]), 4),
        "f1_tidak_relevan": round(float(f1[0]), 4),
        "precision_relevan": round(float(presisi[1]), 4),
        "recall_relevan": round(float(recall[1]), 4),
        "f1_relevan": round(float(f1[1]), 4),
        "macro_f1": round(float(macro[2]), 4),
        "accuracy": round(float((np.array(prediksi) == np.array(rujukan)).mean()), 4),
        "confusion_matrix": matriks,
        "jumlah_sampel": int(sum(dukungan)),
        "true_negative": matriks[0][0],
        "false_positive": matriks[0][1],
        "false_negative": matriks[1][0],
        "true_positive": matriks[1][1],
    }


class _Pemantau(TrainerCallback):
    """Menyalin kemajuan Trainer ke status, dan menghentikan saat dibatalkan."""

    def __init__(self, run_id: int) -> None:
        self.run_id = run_id

    def on_step_end(self, args, state, control, **kwargs):
        with _kunci:
            s = _jalan.get(self.run_id)
            if s is None:
                return control

            s.step = int(state.global_step)
            s.total_step = int(state.max_steps)
            s.epoch = round(float(state.epoch or 0), 2)
            # Disisakan 15% untuk evaluasi test dan penyimpanan artefak, supaya
            # bilah kemajuan tidak berhenti di 100% selama beberapa menit.
            s.progress = int(state.global_step / max(state.max_steps, 1) * 85)

            if s.dibatalkan:
                control.should_training_stop = True

        return control


class _TrainerBerbobot(Trainer):
    """Trainer dengan bobot kelas, dipakai saat datasetnya timpang."""

    def __init__(self, bobot: torch.Tensor | None = None, **kwargs) -> None:
        super().__init__(**kwargs)
        self.bobot = bobot

    def compute_loss(self, model, inputs, return_outputs=False, **kwargs):
        labels = inputs.pop("labels")
        keluaran = model(**inputs)
        rugi = torch.nn.functional.cross_entropy(keluaran.logits, labels, weight=self.bobot)

        return (rugi, keluaran) if return_outputs else rugi


def _jalankan(run_id: int, cfg: dict[str, Any]) -> None:
    try:
        _tahap(run_id, MEMPERSIAPKAN, 2)

        tokenizer = AutoTokenizer.from_pretrained(cfg["base_model"])
        model = AutoModelForSequenceClassification.from_pretrained(cfg["base_model"], num_labels=2)

        maks = int(cfg.get("max_length", 256))
        train = _Dataset(_baca_jsonl(cfg["berkas"]["train"]), tokenizer, maks)
        validation = _Dataset(_baca_jsonl(cfg["berkas"]["validation"]), tokenizer, maks)
        test = _Dataset(_baca_jsonl(cfg["berkas"]["test"]), tokenizer, maks)

        keluaran = Path(cfg["artifact_path"])
        keluaran.mkdir(parents=True, exist_ok=True)

        argumen = TrainingArguments(
            output_dir=str(keluaran / "checkpoint"),
            num_train_epochs=float(cfg.get("epoch", 3)),
            per_device_train_batch_size=int(cfg.get("batch_size", 4)),
            per_device_eval_batch_size=int(cfg.get("batch_size", 4)),
            gradient_accumulation_steps=int(cfg.get("gradient_accumulation", 4)),
            learning_rate=float(cfg.get("learning_rate", 1e-5)),
            weight_decay=float(cfg.get("weight_decay", 0.01)),
            warmup_ratio=float(cfg.get("warmup_ratio", 0.1)),
            seed=int(cfg.get("random_seed", 42)),
            eval_strategy="epoch",
            save_strategy="epoch",
            save_total_limit=1,
            # Checkpoint terbaik dipilih dari validation, bukan dari epoch
            # terakhir. Epoch terakhir sering justru yang paling overfit.
            load_best_model_at_end=True,
            metric_for_best_model=f"eval_{cfg.get('metric_utama', 'f1_relevan')}",
            greater_is_better=True,
            logging_steps=5,
            use_cpu=True,
            report_to=[],
        )

        bobot = None
        if cfg.get("class_weighting", True):
            jumlah = np.bincount([b["label"] for b in train.baris], minlength=2)
            bobot = torch.tensor((jumlah.sum() / (2 * np.maximum(jumlah, 1))), dtype=torch.float)

        def hitung(evaluasi):
            return _metrik(np.argmax(evaluasi.predictions, axis=1), evaluasi.label_ids)

        trainer = _TrainerBerbobot(
            bobot=bobot,
            model=model,
            args=argumen,
            train_dataset=train,
            eval_dataset=validation,
            compute_metrics=hitung,
            callbacks=[_Pemantau(run_id)],
        )

        _tahap(run_id, MELATIH, 5)
        trainer.train()

        if _dibatalkan(run_id):
            _tahap(run_id, DIBATALKAN, 0)
            return

        hasil_validation = trainer.evaluate(validation)
        _simpan_metrik(run_id, "validation", hasil_validation)

        _tahap(run_id, MENGEVALUASI, 88)
        hasil_test = trainer.evaluate(test)
        _simpan_metrik(run_id, "test", hasil_test)

        _tahap(run_id, MENYIMPAN, 94)
        trainer.save_model(str(keluaran / "model"))
        tokenizer.save_pretrained(str(keluaran / "model"))

        # Checkpoint antara dibuang: ia salinan bobot yang sama dan menghabiskan
        # ratusan megabita per pelatihan.
        shutil.rmtree(keluaran / "checkpoint", ignore_errors=True)

        manifest = _manifest(keluaran / "model", cfg)
        (keluaran / "manifest.json").write_text(
            json.dumps(manifest, indent=2, ensure_ascii=False), encoding="utf-8"
        )

        with _kunci:
            s = _jalan[run_id]
            s.artifact_path = str(keluaran)
            s.artifact_manifest = manifest
            s.status = SELESAI
            s.progress = 100

        log.info("Pelatihan %s selesai", run_id)

    except Exception as e:  # noqa: BLE001
        log.exception("Pelatihan %s gagal", run_id)
        with _kunci:
            s = _jalan.get(run_id)
            if s is not None:
                s.status = GAGAL
                s.error_summary = f"{type(e).__name__}: {e}"[:500]
                s.error_trace = traceback.format_exc()[:8000]


def _manifest(direktori: Path, cfg: dict[str, Any]) -> dict[str, Any]:
    """Checksum tiap berkas artefak, plus versi yang menentukan bentuk input.

    Checksum bukan hiasan: artefak yang berubah tanpa sepengetahuan sistem
    berarti model yang berjalan bukan model yang dievaluasi, dan seluruh angka
    gerbang mutu menjadi klaim tentang berkas yang sudah tidak ada.
    """
    berkas = {}

    for path in sorted(direktori.rglob("*")):
        if path.is_file():
            h = hashlib.sha256()
            with open(path, "rb") as f:
                for potongan in iter(lambda: f.read(1 << 20), b""):
                    h.update(potongan)
            berkas[str(path.relative_to(direktori))] = {
                "sha256": h.hexdigest(),
                "bytes": path.stat().st_size,
            }

    gabungan = hashlib.sha256(
        "\n".join(f"{k}:{v['sha256']}" for k, v in berkas.items()).encode()
    ).hexdigest()

    return {
        "checksum": gabungan,
        "berkas": berkas,
        "base_model": cfg["base_model"],
        "versi_input_builder": cfg.get("versi_input_builder"),
        "snapshot_manifest_hash": cfg.get("snapshot_manifest_hash"),
        "konfigurasi": {k: v for k, v in cfg.items() if k != "berkas"},
        "runtime": {
            "torch": torch.__version__,
            "threads": torch.get_num_threads(),
        },
    }


def _tahap(run_id: int, status_baru: str, progress: int) -> None:
    with _kunci:
        s = _jalan.get(run_id)
        if s is not None:
            s.status = status_baru
            s.progress = max(s.progress, progress)


def _dibatalkan(run_id: int) -> bool:
    with _kunci:
        s = _jalan.get(run_id)
        return bool(s and s.dibatalkan)


def _simpan_metrik(run_id: int, jenis: str, hasil: dict) -> None:
    bersih = {k.replace("eval_", ""): v for k, v in hasil.items() if not k.startswith("eval_runtime")}

    with _kunci:
        s = _jalan.get(run_id)
        if s is None:
            return
        if jenis == "validation":
            s.metrics_validation = bersih
        else:
            s.metrics_test = bersih
