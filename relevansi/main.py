"""Layanan pelatihan dan inferensi model relevansi IndoBERT.

Terpisah dari Laravel karena PyTorch tidak berjalan di PHP, dan terpisah dari
layanan `arsip` karena keduanya berat pada hal yang berbeda: arsip menahan
Chromium, layanan ini menahan bobot model 500 MB sampai 1,3 GB. Satu proses
yang memegang keduanya akan kehabisan memori pada saat paling buruk, yaitu
ketika pelatihan sedang berjalan dan seseorang kebetulan meminta tangkapan
layar.

Layanan ini tidak menyentuh database sama sekali. Ia menerima dataset yang
sudah jadi, melatih, dan melaporkan kemajuannya lewat polling. Laravel yang
memutuskan baris mana yang diperbarui. Pembagian itu disengaja: skema database
berubah lebih sering daripada matematika pelatihan, dan layanan yang ikut tahu
skema akan ikut rusak setiap kali kolom bertambah.

Bentuk balasan `/status` sengaja dibuat sama persis dengan nama kolom di tabel
`pelatihan_model_relevansi`, sehingga Laravel hanya menyalinnya. Lapisan
penerjemah di antara keduanya adalah tempat paling umum dua sistem perlahan
tidak sepakat tentang arti kata "progres".

Pelatihan berjalan di proses anak, bukan thread. Alasannya satu dan menentukan:
thread Python tidak bisa dihentikan dari luar. Rancangan sebelumnya menitipkan
pembatalan pada callback Trainer, yang berarti tombol Batalkan hanya bekerja
setelah langkah pelatihan pertama tercapai. Selama tahap memuat, yang untuk
checkpoint besar berarti mengunduh 1,3 GB, tidak ada satu pun callback yang
dipanggil dan pembatalan menggantung berminggu-menit tanpa terlihat berbuat
apa-apa. Proses anak bisa diakhiri kapan saja, termasuk di tengah unduhan.
"""

from __future__ import annotations

import json
import logging
import math
import multiprocessing
import os
import shutil
import threading
import time
import traceback
from pathlib import Path
from typing import Any

import numpy as np
import torch
import transformers
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field
from sklearn.metrics import confusion_matrix, precision_recall_fscore_support
from torch.utils.data import Dataset
from transformers import (
    AutoModelForSequenceClassification,
    AutoTokenizer,
    EarlyStoppingCallback,
    Trainer,
    TrainerCallback,
    TrainingArguments,
)

logging.basicConfig(level=logging.INFO)
log = logging.getLogger("relevansi")

app = FastAPI(title="Model Relevansi SIMEDIA")

# Direktori artefak dibagi dengan Laravel lewat bind mount repositori yang
# sama. Itu sebabnya jalur yang dikembalikan relatif terhadap disk `local`:
# Laravel menghapus artefak dengan Storage, bukan dengan memanggil layanan ini.
AKAR_ARTEFAK = Path(os.environ.get("ARTEFAK_DIR", "/app/storage/app/private/model-relevansi"))

# Batas thread PyTorch di CPU.
#
# Tanpa batas, PyTorch memakai seluruh inti yang terlihat. Pada mesin yang juga
# menjalankan Postgres, worker antrean, dan Vite, pelatihan yang memakan semua
# inti membuat seluruh aplikasi terasa mati selama dua puluh menit sementara
# pelatihannya sendiri hampir tidak bertambah cepat.
THREAD = int(os.environ.get("RELEVANSI_THREAD", "0"))

if THREAD > 0:
    torch.set_num_threads(THREAD)

# `spawn`, bukan `fork`.
#
# Proses induk sudah mengimpor torch dan bisa saja baru selesai melayani satu
# prediksi, artinya kumpulan thread intra-op miliknya hidup. Anak hasil fork
# mewarisi memori tetapi tidak mewarisi thread-nya, dan kunci yang sedang
# dipegang thread yang tidak ikut terbawa akan membuat anaknya menggantung pada
# operasi torch pertama. `spawn` memulai penerjemah yang bersih.
KONTEKS = multiprocessing.get_context("spawn")

LABEL = {0: "tidak_relevan", 1: "relevan"}
INDEKS = {"tidak_relevan": 0, "relevan": 1}

TERMINAL = ("berhasil", "gagal", "dibatalkan")


def perangkat() -> str:
    """Keterangan perangkat apa adanya, untuk ditampilkan di layar hasil."""
    if torch.cuda.is_available():
        return f"GPU: {torch.cuda.get_device_name(0)}"

    return f"CPU, {torch.get_num_threads()} thread"


class Baris(BaseModel):
    teks: str
    label: str


class Konfigurasi(BaseModel):
    base_model: str
    epoch: int = Field(ge=1, le=20)
    batch_size: int = Field(ge=1, le=64)
    learning_rate: float = Field(gt=0, le=0.01)
    max_seq_length: int = Field(ge=64, le=512)
    seed: int
    early_stopping: int | None = None


class PermintaanLatih(BaseModel):
    run_id: int
    konfigurasi: Konfigurasi
    train: list[Baris]
    validation: list[Baris]
    test: list[Baris]


class PermintaanPrediksi(BaseModel):
    artefak_path: str
    teks: str


def keadaan_awal(run_id: int) -> dict[str, Any]:
    """Kunci-kuncinya dinamai persis seperti kolom di `pelatihan_model_relevansi`."""
    return {
        "run_id": run_id,
        "status": "menunggu",
        "tahap": "Menunggu giliran",
        "progres": 0,
        "epoch_berjalan": None,
        "riwayat_epoch": [],
        "metrik": None,
        "confusion_matrix": None,
        "laporan_klasifikasi": None,
        "artefak_path": None,
        "perangkat": perangkat(),
        # Kosong sampai langkah pertama tercapai. Tahap memuat tidak punya
        # satuan yang bisa dihitung: unduhan 1,3 GB dan pemuatan dari cache
        # berbeda dua orde besaran, dan menebak salah satunya berarti
        # menampilkan angka yang meleset jauh persis di menit paling
        # membingungkan.
        "estimasi_sisa_detik": None,
        "galat": None,
    }


# Manajer dibuat saat pelatihan pertama, bukan saat modul diimpor.
#
# `spawn` membuat anak mengimpor ulang modul ini dari awal, jadi manajer yang
# dibuat di tingkat modul akan melahirkan satu proses server tambahan pada
# setiap pelatihan, dan semuanya tetap hidup sesudahnya.
_manajer = None


def _manajer_bersama():
    global _manajer

    if _manajer is None:
        _manajer = KONTEKS.Manager()

    return _manajer


# run_id -> {"keadaan": DictProxy, "proses": Process}
#
# Dijaga kunci karena endpoint sinkron FastAPI dijalankan di kumpulan thread.
# Yang dilindungi bukan dict-nya, melainkan jarak antara memeriksa "ada yang
# berjalan" dan mendaftarkan pelatihan baru: dua permintaan yang tiba bersamaan
# bisa lolos pemeriksaan yang sama lalu menyalakan dua proses sekaligus.
_jalan: dict[int, dict[str, Any]] = {}
_kunci = threading.Lock()


def _hidup(entri: dict[str, Any]) -> bool:
    return entri["proses"].is_alive()


def _sedang_berjalan() -> bool:
    """Satu pelatihan pada satu waktu. Pemanggil yang memegang `_kunci`.

    Sengaja tidak mengambil kuncinya sendiri. Pemeriksaan ini dipakai di dalam
    `/latih` yang sudah memegang kunci, dan kunci non-reentrant yang diambil
    dua kali membekukan proses tanpa satu pun galat.

    Dua pelatihan bersamaan di CPU yang sama tidak berjalan dua kali lebih
    cepat. Keduanya justru melambat dan berebut memori sampai salah satunya
    mati di tengah jalan, dan yang mati adalah yang sudah berjalan lima belas
    menit.
    """
    return any(
        entri["keadaan"]["status"] not in TERMINAL and _hidup(entri)
        for entri in _jalan.values()
    )


def _direktori(run_id: int) -> tuple[Path, Path]:
    """Direktori artefak jadi dan direktori kerja sementara."""
    return AKAR_ARTEFAK / f"pelatihan-{run_id}", AKAR_ARTEFAK / f".kerja-{run_id}"


class _DatasetTeks(Dataset):
    def __init__(self, baris: list[Baris], tokenizer, maks: int) -> None:
        self.baris = baris
        self.tokenizer = tokenizer
        self.maks = maks

    def __len__(self) -> int:
        return len(self.baris)

    def __getitem__(self, i: int) -> dict:
        satu = self.baris[i]

        hasil = self.tokenizer(
            satu.teks,
            truncation=True,
            max_length=self.maks,
            padding="max_length",
            return_tensors="pt",
        )

        item = {k: v[0] for k, v in hasil.items()}
        item["labels"] = torch.tensor(INDEKS[satu.label], dtype=torch.long)

        return item


def _metrik(prediksi: np.ndarray, rujukan: np.ndarray) -> dict[str, Any]:
    """Angka evaluasi dalam bentuk yang langsung dipakai layar Laravel.

    Rata-rata makro, bukan mikro. Pada dua kelas, rata-rata mikro sama persis
    dengan akurasi dan tidak menambah satu pun keterangan baru.
    """
    presisi, recall, f1, dukungan = precision_recall_fscore_support(
        rujukan, prediksi, labels=[0, 1], zero_division=0
    )
    makro = precision_recall_fscore_support(rujukan, prediksi, average="macro", zero_division=0)
    berbobot = precision_recall_fscore_support(rujukan, prediksi, average="weighted", zero_division=0)
    matriks = confusion_matrix(rujukan, prediksi, labels=[0, 1]).tolist()

    total = int(dukungan.sum()) or 1

    laporan = {
        "tidak_relevan": {
            "precision": round(float(presisi[0]), 4),
            "recall": round(float(recall[0]), 4),
            "f1": round(float(f1[0]), 4),
            "support": int(dukungan[0]),
        },
        "relevan": {
            "precision": round(float(presisi[1]), 4),
            "recall": round(float(recall[1]), 4),
            "f1": round(float(f1[1]), 4),
            "support": int(dukungan[1]),
        },
        "macro": {
            "precision": round(float(makro[0]), 4),
            "recall": round(float(makro[1]), 4),
            "f1": round(float(makro[2]), 4),
            "support": total,
        },
        "weighted": {
            "precision": round(float(berbobot[0]), 4),
            "recall": round(float(berbobot[1]), 4),
            "f1": round(float(berbobot[2]), 4),
            "support": total,
        },
    }

    return {
        "metrik": {
            "accuracy": round(float((prediksi == rujukan).mean()), 4),
            "precision": laporan["macro"]["precision"],
            "recall": laporan["macro"]["recall"],
            "f1": laporan["macro"]["f1"],
        },
        # Baris adalah label sebenarnya, kolom adalah tebakan model. Bentuknya
        # bersarang dengan nama label, bukan larik dua dimensi, supaya layar
        # tidak perlu mengingat indeks mana yang berarti relevan.
        "confusion_matrix": {
            "relevan": {"relevan": matriks[1][1], "tidak_relevan": matriks[1][0]},
            "tidak_relevan": {"relevan": matriks[0][1], "tidak_relevan": matriks[0][0]},
        },
        "laporan_klasifikasi": laporan,
    }


def _hitung_metrik(eval_pred):
    logit, rujukan = eval_pred
    prediksi = np.argmax(logit, axis=-1)
    hasil = _metrik(prediksi, rujukan)

    return {
        "accuracy": hasil["metrik"]["accuracy"],
        "f1": hasil["metrik"]["f1"],
        "precision": hasil["metrik"]["precision"],
        "recall": hasil["metrik"]["recall"],
    }


class _Pemantau(TrainerCallback):
    """Menyalin kemajuan Trainer ke keadaan bersama.

    Tidak ada lagi pemeriksaan pembatalan di sini. Pembatalan dikerjakan induk
    dengan mengakhiri seluruh proses, dan itu berlaku di tahap mana pun,
    termasuk tahap yang tidak pernah memanggil callback sama sekali.
    """

    def __init__(self, keadaan) -> None:
        self.keadaan = keadaan
        self.mulai = 0.0

    def on_train_begin(self, args, state, control, **kwargs):
        # Jam dimulai di sini, bukan saat proses lahir. Tahap memuat bisa
        # memakan lima menit untuk mengunduh checkpoint dan lima detik kalau
        # sudah ada di cache, dan memasukkannya ke rata-rata membuat estimasi
        # langkah pertama meleset berkali lipat.
        self.mulai = time.perf_counter()

        return control

    def on_step_end(self, args, state, control, **kwargs):
        maks = int(state.max_steps) or 1
        total_epoch = int(args.num_train_epochs)

        # `state.epoch` adalah pecahan, dan pada langkah terakhir nilainya tepat
        # sama dengan jumlah epoch. Menambah satu pada bilangan bulatnya membuat
        # layar berbunyi "Epoch 2 dari 1" persis di detik pelatihan selesai.
        # Pembulatan ke atas menjawab benar di kedua ujung: 0,05 menjadi 1 dan
        # 1,0 tetap 1.
        epoch = max(1, min(math.ceil(state.epoch or 0), total_epoch))

        self.keadaan["epoch_berjalan"] = epoch
        # Sepuluh persen pertama untuk pemuatan model dan tokenisasi, sepuluh
        # persen terakhir untuk evaluasi test dan penyimpanan artefak.
        self.keadaan["progres"] = 10 + int(state.global_step / maks * 80)
        self.keadaan["tahap"] = (
            f"Epoch {epoch} dari {total_epoch}, langkah {int(state.global_step)} dari {maks}"
        )

        langkah = int(state.global_step)

        if langkah > 0:
            # Rata-rata seluruh langkah yang sudah lewat, bukan langkah
            # terakhir saja. Durasi per langkah di CPU bergoyang cukup jauh
            # tergantung beban mesin, dan estimasi yang dihitung dari satu
            # langkah akan melompat-lompat setiap kali disegarkan.
            #
            # Evaluasi per epoch ikut terhitung dengan sendirinya: waktunya
            # jatuh di antara dua langkah, jadi ia masuk ke rata-rata begitu
            # epoch pertama lewat. Yang belum terhitung hanya evaluasi test dan
            # penulisan bobot di ujung, dan keduanya ditambahkan sebagai jatah
            # tetap di bawah.
            per_langkah = (time.perf_counter() - self.mulai) / langkah
            sisa = (maks - langkah) * per_langkah

            # Jatah penutup: satu putaran evaluasi ditambah penulisan bobot
            # ratusan megabita ke disk. Dihitung kasar sebagai lima langkah.
            self.keadaan["estimasi_sisa_detik"] = int(sisa + per_langkah * 5)

        return control

    def on_evaluate(self, args, state, control, metrics=None, **kwargs):
        if not metrics:
            return control

        # Train loss diambil dari catatan Trainer, bukan dihitung ulang. Yang
        # dicari kurva yang bisa dibandingkan dengan validation loss, dan
        # keduanya harus berasal dari sumber yang sama.
        train_loss = next(
            (c["loss"] for c in reversed(state.log_history) if "loss" in c),
            None,
        )

        riwayat = list(self.keadaan["riwayat_epoch"])
        riwayat.append({
            "epoch": len(riwayat) + 1,
            "train_loss": round(float(train_loss), 6) if train_loss is not None else 0.0,
            "val_loss": round(float(metrics.get("eval_loss", 0.0)), 6),
            "val_accuracy": round(float(metrics.get("eval_accuracy", 0.0)), 4),
            "val_f1": round(float(metrics.get("eval_f1", 0.0)), 4),
        })

        # Ditulis ulang utuh, bukan di-append di tempat. Proxy daftar milik
        # multiprocessing tidak meneruskan mutasi bersarang ke proses induk,
        # jadi `append` pada salinan lokal akan hilang tanpa jejak.
        self.keadaan["riwayat_epoch"] = riwayat

        return control


def _jalankan(permintaan: PermintaanLatih, keadaan) -> None:
    """Titik masuk proses anak. Seluruh pelatihan terjadi di sini."""
    cfg = permintaan.konfigurasi
    tujuan, sementara = _direktori(permintaan.run_id)

    try:
        keadaan["status"] = "berjalan"
        keadaan["perangkat"] = perangkat()
        keadaan["tahap"] = f"Memuat {cfg.base_model}"
        keadaan["progres"] = 2

        tokenizer = AutoTokenizer.from_pretrained(cfg.base_model)

        # `ignore_mismatched_sizes` wajib. Checkpoint umum seperti
        # indobert-base-p1 tidak punya kepala klasifikasi sama sekali, dan
        # checkpoint yang punya bisa saja punya jumlah kelas berbeda. Tanpa ini
        # pemuatan gagal dengan galat bentuk tensor yang tidak menyebut
        # sebabnya.
        model = AutoModelForSequenceClassification.from_pretrained(
            cfg.base_model,
            num_labels=2,
            id2label=LABEL,
            label2id=INDEKS,
            ignore_mismatched_sizes=True,
        )

        keadaan["tahap"] = "Menyiapkan dataset"
        keadaan["progres"] = 8

        data_train = _DatasetTeks(permintaan.train, tokenizer, cfg.max_seq_length)
        data_val = _DatasetTeks(permintaan.validation, tokenizer, cfg.max_seq_length)
        data_test = _DatasetTeks(permintaan.test, tokenizer, cfg.max_seq_length)

        shutil.rmtree(sementara, ignore_errors=True)

        argumen = TrainingArguments(
            output_dir=str(sementara),
            num_train_epochs=cfg.epoch,
            per_device_train_batch_size=cfg.batch_size,
            per_device_eval_batch_size=max(cfg.batch_size, 8),
            learning_rate=cfg.learning_rate,
            seed=cfg.seed,
            data_seed=cfg.seed,
            eval_strategy="epoch",
            save_strategy="epoch",
            logging_strategy="epoch",
            save_total_limit=1,
            # Checkpoint terbaik menurut F1 validasi yang dipakai, bukan epoch
            # terakhir. Fine-tuning pada dataset ribuan baris hampir selalu
            # mulai overfit sebelum epoch terakhir, dan epoch terakhir hampir
            # tidak pernah epoch terbaiknya.
            load_best_model_at_end=True,
            metric_for_best_model="f1",
            greater_is_better=True,
            report_to=[],
            disable_tqdm=True,
            # Tanpa ini Trainer memakai beberapa proses pemuat data. Proses ini
            # sendiri sudah anak dari uvicorn, dan anak daemonik tidak boleh
            # punya anak lagi.
            dataloader_num_workers=0,
        )

        panggilan: list[TrainerCallback] = [_Pemantau(keadaan)]

        if cfg.early_stopping:
            panggilan.append(EarlyStoppingCallback(early_stopping_patience=cfg.early_stopping))

        pelatih = Trainer(
            model=model,
            args=argumen,
            train_dataset=data_train,
            eval_dataset=data_val,
            compute_metrics=_hitung_metrik,
            callbacks=panggilan,
        )

        keadaan["tahap"] = "Melatih"
        keadaan["progres"] = 10

        pelatih.train()

        keadaan["tahap"] = "Mengevaluasi data testing"
        keadaan["progres"] = 92

        keluaran = pelatih.predict(data_test)
        hasil = _metrik(np.argmax(keluaran.predictions, axis=-1), keluaran.label_ids)

        keadaan["tahap"] = "Menyimpan model"
        keadaan["progres"] = 96

        shutil.rmtree(tujuan, ignore_errors=True)
        (tujuan / "model").mkdir(parents=True, exist_ok=True)

        pelatih.save_model(str(tujuan / "model"))
        tokenizer.save_pretrained(str(tujuan / "model"))

        # Versi pustaka ikut tersimpan. Model yang tidak bisa dimuat ulang
        # karena `transformers` berubah adalah model yang hilang, dan itu baru
        # ketahuan berbulan-bulan kemudian saat model lama dibutuhkan lagi.
        #
        # Manifest ditulis paling akhir, sesudah bobot. Laravel memakai
        # keberadaannya sebagai tanda artefak lengkap, jadi urutannya penting:
        # manifest yang sudah ada di samping bobot yang belum selesai ditulis
        # adalah model rusak yang terbaca sebagai model siap pakai.
        (tujuan / "manifest.json").write_text(
            json.dumps(
                {
                    "run_id": permintaan.run_id,
                    "base_model": cfg.base_model,
                    "max_seq_length": cfg.max_seq_length,
                    "konfigurasi": cfg.model_dump(),
                    "metrik": hasil["metrik"],
                    "perangkat": keadaan["perangkat"],
                    "torch": torch.__version__,
                    "transformers": transformers.__version__,
                    "disimpan_at": time.strftime("%Y-%m-%dT%H:%M:%S%z"),
                },
                ensure_ascii=False,
                indent=2,
            ),
            encoding="utf-8",
        )

        riwayat = list(keadaan["riwayat_epoch"])

        keadaan["metrik"] = hasil["metrik"] | {
            "jumlah_test": len(permintaan.test),
            "jumlah_train": len(permintaan.train),
            "epoch_dijalankan": len(riwayat),
            "epoch_terbaik": _epoch_terbaik(riwayat),
        }
        keadaan["confusion_matrix"] = hasil["confusion_matrix"]
        keadaan["laporan_klasifikasi"] = hasil["laporan_klasifikasi"]
        keadaan["artefak_path"] = f"model-relevansi/pelatihan-{permintaan.run_id}"
        keadaan["progres"] = 100
        keadaan["tahap"] = "Selesai"
        keadaan["estimasi_sisa_detik"] = 0
        keadaan["status"] = "berhasil"

    except Exception as e:  # noqa: BLE001
        log.error("pelatihan %s gagal: %s", permintaan.run_id, traceback.format_exc())
        keadaan["status"] = "gagal"
        keadaan["tahap"] = "Gagal"
        keadaan["galat"] = f"{type(e).__name__}: {e}"[:1000]

    finally:
        # Checkpoint kerja bisa mencapai beberapa gigabita dan tidak berguna
        # setelah model terbaiknya disalin keluar.
        shutil.rmtree(sementara, ignore_errors=True)


def _epoch_terbaik(riwayat: list[dict[str, Any]]) -> int:
    if not riwayat:
        return 0

    return max(riwayat, key=lambda b: b["val_f1"])["epoch"]


@app.on_event("startup")
def sapu_sisa_kerja() -> None:
    """Membuang direktori kerja yang tertinggal dari proses yang mati mendadak.

    Trainer menyimpan checkpoint tiap epoch ke direktori kerja, dan untuk
    checkpoint besar satu direktori bisa mencapai beberapa gigabita. Blok
    `finally` di `_jalankan` membuangnya saat pelatihan selesai dengan cara apa
    pun, tetapi ia tidak berjalan kalau prosesnya diakhiri dari luar: container
    di-restart, kernel membunuhnya karena kehabisan memori, atau mesinnya mati
    lampu. Yang tertinggal tidak pernah dibaca siapa pun lagi dan tidak pernah
    dibersihkan siapa pun juga.

    Saat proses ini baru menyala, tidak ada satu pun pelatihan yang berjalan.
    Jadi setiap `.kerja-*` yang ditemukan di sini sudah pasti sampah, dan tidak
    perlu ada pemeriksaan lain untuk memastikannya.
    """
    if not AKAR_ARTEFAK.is_dir():
        return

    for sisa in AKAR_ARTEFAK.glob(".kerja-*"):
        if sisa.is_dir():
            log.warning("membuang direktori kerja yang tertinggal: %s", sisa)
            shutil.rmtree(sisa, ignore_errors=True)


@app.get("/health")
def kesehatan() -> dict[str, Any]:
    with _kunci:
        melatih = _sedang_berjalan()

    return {
        "status": "ok",
        "perangkat": perangkat(),
        "torch": torch.__version__,
        "transformers": transformers.__version__,
        "sedang_melatih": melatih,
    }


@app.post("/latih", status_code=202)
def latih(permintaan: PermintaanLatih) -> dict[str, Any]:
    if not permintaan.train or not permintaan.test:
        raise HTTPException(status_code=422, detail="Dataset training atau testing kosong.")

    with _kunci:
        if _sedang_berjalan():
            raise HTTPException(
                status_code=409,
                detail="Masih ada pelatihan yang berjalan. Tunggu sampai selesai atau batalkan lebih dulu.",
            )

        keadaan = _manajer_bersama().dict(keadaan_awal(permintaan.run_id))

        # `daemon=True` supaya pelatihan ikut mati saat container dihentikan.
        # Tanpanya, proses anak bertahan sesudah uvicorn berhenti dan terus
        # memakan CPU sampai container dibunuh paksa.
        proses = KONTEKS.Process(target=_jalankan, args=(permintaan, keadaan), daemon=True)
        proses.start()

        _jalan[permintaan.run_id] = {"keadaan": keadaan, "proses": proses}

    return {"diterima": True, "perangkat": keadaan["perangkat"]}


@app.get("/status/{run_id}")
def status(run_id: int) -> dict[str, Any]:
    entri = _jalan.get(run_id)

    if entri is None:
        raise HTTPException(status_code=404, detail="Pelatihan tidak dikenal oleh layanan.")

    isi = dict(entri["keadaan"])

    # Proses yang mati tanpa sempat menutup keadaannya sendiri. Sebab paling
    # sering adalah kernel membunuhnya karena kehabisan memori, dan pembunuhan
    # itu tidak melewati blok except mana pun. Tanpa pemeriksaan ini, barisnya
    # berhenti pada persentase terakhir yang sempat tercatat dan halaman
    # menampilkan pelatihan yang sebenarnya sudah tidak ada.
    if isi["status"] not in TERMINAL and not _hidup(entri):
        isi["status"] = "gagal"
        isi["tahap"] = "Gagal"
        isi["galat"] = (
            "Proses pelatihan berhenti mendadak, kemungkinan kehabisan memori. "
            "Turunkan batch size atau maximum sequence length lalu coba lagi."
        )
        entri["keadaan"].update(isi)

    isi["selesai"] = isi["status"] in TERMINAL

    return isi


@app.post("/batal/{run_id}")
def batal(run_id: int) -> dict[str, bool]:
    with _kunci:
        entri = _jalan.get(run_id)

    if entri is None or entri["keadaan"]["status"] in TERMINAL:
        return {"dibatalkan": False}

    proses = entri["proses"]

    if proses.is_alive():
        # SIGTERM dulu, lalu SIGKILL kalau membandel. Perbedaannya jarang
        # terasa di sini karena tidak ada penangan sinyal yang dipasang, tetapi
        # proses yang sedang menulis berkas besar kadang perlu sedetik dua
        # untuk benar-benar berhenti.
        proses.terminate()
        proses.join(timeout=10)

        if proses.is_alive():
            proses.kill()
            proses.join(timeout=5)

    entri["keadaan"].update({
        "status": "dibatalkan",
        "tahap": "Dibatalkan",
        "galat": None,
    })

    # Dua direktori dibersihkan induk, bukan anak. Anak yang baru saja diakhiri
    # tidak menjalankan blok `finally` miliknya, dan yang tertinggal bukan
    # sekadar sampah: artefak separuh tulis terlihat persis seperti model utuh
    # bagi siapa pun yang membuka direktorinya.
    for direktori in _direktori(run_id):
        shutil.rmtree(direktori, ignore_errors=True)

    return {"dibatalkan": True}


# Satu model inferensi dipegang pada satu waktu.
#
# Pemuatan memakan beberapa detik dan bobotnya ratusan megabita, jadi memuatnya
# per permintaan membuat tombol Uji Model terasa rusak. Memegang beberapa
# sekaligus membuat proses ini kehabisan memori tepat saat pelatihan berjalan.
#
# ponytail: satu slot. Naikkan kalau membandingkan dua model berdampingan jadi
# sering dipakai dan mesinnya punya memori lebih.
_muat: dict[str, Any] = {}
_kunci_muat = threading.Lock()


@app.post("/prediksi")
def prediksi(permintaan: PermintaanPrediksi) -> dict[str, Any]:
    direktori = AKAR_ARTEFAK / Path(permintaan.artefak_path).name / "model"

    if not direktori.is_dir():
        raise HTTPException(
            status_code=404,
            detail="Berkas model tidak ditemukan. Model perlu dilatih ulang.",
        )

    mulai = time.perf_counter()

    # Satu slot dipakai bergantian, jadi dua permintaan yang tiba bersamaan
    # tidak boleh saling menukar model di tengah jalan. Tanpa kunci ini,
    # permintaan kedua bisa menimpa `_muat` tepat setelah yang pertama membaca
    # tokenizer-nya, dan hasilnya teks dinilai oleh model yang bukan dipilih.
    with _kunci_muat:
        if _muat.get("path") != str(direktori):
            _muat["tokenizer"] = AutoTokenizer.from_pretrained(str(direktori))
            _muat["model"] = AutoModelForSequenceClassification.from_pretrained(str(direktori))
            _muat["model"].eval()
            _muat["maks"] = _maks_panjang(direktori.parent)
            _muat["path"] = str(direktori)

        masukan = _muat["tokenizer"](
            permintaan.teks,
            truncation=True,
            max_length=_muat["maks"],
            return_tensors="pt",
        )

        with torch.no_grad():
            logit = _muat["model"](**masukan).logits

    peluang = torch.softmax(logit, dim=-1)[0].tolist()

    p_relevan = float(peluang[1])

    return {
        "label": "relevan" if p_relevan >= 0.5 else "tidak_relevan",
        "probabilitas_relevan": p_relevan,
        "probabilitas_tidak_relevan": float(peluang[0]),
        # Keyakinan adalah jarak dari keraguan penuh, bukan peluang kelas yang
        # menang. Model yang menjawab 0,51 dan model yang menjawab 0,99
        # sama-sama menjawab relevan, dan hanya yang kedua boleh dibaca sebagai
        # yakin.
        "confidence": abs(p_relevan - 0.5) * 2,
        "inferensi_ms": int((time.perf_counter() - mulai) * 1000),
    }


def _maks_panjang(akar: Path) -> int:
    """Panjang potongan dibaca dari manifest, bukan disetel ulang di sini.

    Inferensi yang memotong teks pada panjang berbeda dari pelatihan menjawab
    pertanyaan yang bentuknya lain, dan kegagalannya diam: angkanya tetap
    keluar dan tetap terlihat wajar.
    """
    try:
        return int(json.loads((akar / "manifest.json").read_text())["max_seq_length"])
    except Exception:  # noqa: BLE001
        return 256
