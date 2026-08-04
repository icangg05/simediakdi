"""Layanan NLP SIMEDIA Kendari.

Menerima teks, mengembalikan angka. Layanan ini tidak pernah menyentuh
database: seluruh penyimpanan dikerjakan Laravel. Aturan itu menjaga satu
sumber kebenaran dan membuat proses ini bisa dimatikan kapan saja tanpa risiko
kehilangan data, kalau mati, job menumpuk di antrean `nlp` lalu jalan lagi.

Dijalankan satu worker saja. Model dimuat sekali saat startup; dua worker
berarti dua salinan model di memori yang sama.

Tiga tugas sejak revisi 1.6: embedding untuk deteksi salinan, sentimen, dan
relevansi. Relevansi kembali punya model sendiri, kali ini hasil fine-tuning
dengan dataset lokal, dan pelatihannya juga dijalankan dari sini. Dokumen 05
bagian 2 dan dokumen 10 bagian 19.
"""

import logging
import os
import secrets

import torch
from fastapi import FastAPI, Header, HTTPException

from relevancy import training
from sentence_transformers import SentenceTransformer
from transformers import AutoModelForSequenceClassification, AutoTokenizer

from models import (
    PermintaanEmbed,
    PermintaanPelatihan,
    TanggapanPelatihan,
    PermintaanPasangan,
    HasilSentimen,
    SkorSentimen,
    TanggapanEmbed,
    TanggapanSehat,
    TanggapanSentimen,
)

VERSI = "2.0.0"

MODEL_SENTIMEN = os.getenv("MODEL_SENTIMEN", "apriandito/indobert-sentiment-classifier")

# e5 menggantikan MiniLM karena relevansi kini diukur sebagai kemiripan antara
# artikel dan deskripsi konteks, dan e5 dilatih persis untuk perbandingan
# asimetris semacam itu lewat awalan `query:` dan `passage:`. Awalannya
# ditambahkan pemanggil, bukan di sini, karena hanya pemanggil yang tahu teks
# mana yang berperan sebagai kueri.
MODEL_EMBEDDING = os.getenv("MODEL_EMBEDDING", "intfloat/multilingual-e5-small")

# Model sentimen memakai IndoBERT dengan batas 512 token. Artikel berita jauh
# lebih panjang, jadi teks dipotong: bagian awal berita memuat inti peristiwa,
# sehingga pemotongan di ekor lebih aman daripada di kepala.
MAKS_TOKEN = 512

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("simedia-nlp")

app = FastAPI(title="SIMEDIA Kendari NLP", version=VERSI)

muatan: dict = {}


@app.on_event("startup")
def muat_model() -> None:
    torch.set_num_threads(int(os.getenv("TORCH_THREADS", "2")))

    log.info("Memuat model sentimen %s", MODEL_SENTIMEN)
    muatan["tok_sentimen"] = AutoTokenizer.from_pretrained(MODEL_SENTIMEN)
    muatan["mod_sentimen"] = AutoModelForSequenceClassification.from_pretrained(
        MODEL_SENTIMEN
    ).eval()

    log.info("Memuat model embedding %s", MODEL_EMBEDDING)
    muatan["embedding"] = SentenceTransformer(MODEL_EMBEDDING)

    log.info("Seluruh model siap")


@app.get("/health", response_model=TanggapanSehat)
def health() -> TanggapanSehat:
    if not muatan:
        raise HTTPException(status_code=503, detail="Model belum selesai dimuat")

    return TanggapanSehat(
        status="ok",
        model_sentimen=MODEL_SENTIMEN,
        model_embedding=MODEL_EMBEDDING,
        versi=VERSI,
    )


@app.post("/embed", response_model=TanggapanEmbed)
def embed(permintaan: PermintaanEmbed) -> TanggapanEmbed:
    vektor = muatan["embedding"].encode(
        permintaan.teks,
        normalize_embeddings=True,
        show_progress_bar=False,
    )

    return TanggapanEmbed(
        embedding=[v.tolist() for v in vektor],
        dimensi=int(vektor.shape[1]),
    )


@app.post("/sentiment", response_model=TanggapanSentimen)
def sentiment(permintaan: PermintaanPasangan) -> TanggapanSentimen:
    peluang = _klasifikasi(
        muatan["tok_sentimen"], muatan["mod_sentimen"], permintaan.pasangan
    )

    # id2label: 0 = NEGATIF, 1 = NETRAL, 2 = POSITIF.
    label = ["negatif", "netral", "positif"]

    return TanggapanSentimen(
        hasil=[
            HasilSentimen(
                id=pasangan.id,
                label=label[int(baris.index(max(baris)))],
                skor=SkorSentimen(
                    negatif=round(float(baris[0]), 4),
                    netral=round(float(baris[1]), 4),
                    positif=round(float(baris[2]), 4),
                ),
                keyakinan=round(float(max(baris)), 4),
                model_versi=f"{MODEL_SENTIMEN.split('/')[-1]}-{VERSI}",
            )
            for pasangan, baris in zip(permintaan.pasangan, peluang)
        ]
    )


def _klasifikasi(tokenizer, model, pasangan) -> list[list[float]]:
    """Peluang tiap kelas untuk setiap pasangan konteks-teks.

    Model dilatih dengan format `[CLS] konteks [SEP] teks [SEP]`, jadi keduanya
    diberikan sebagai pasangan kalimat, bukan digabung jadi satu string.
    Menggabungnya membuat model kehilangan batas antara konteks dan isi, dan
    hasilnya kembali seperti model sentimen biasa.
    """
    masukan = tokenizer(
        [p.konteks for p in pasangan],
        [p.teks for p in pasangan],
        padding=True,
        truncation="only_second",
        max_length=MAKS_TOKEN,
        return_tensors="pt",
    )

    with torch.inference_mode():
        logit = model(**masukan).logits

    return torch.softmax(logit, dim=-1).tolist()


def _periksa_rahasia(diberikan: str | None) -> None:
    """Lapisan kedua di atas binding ke localhost.

    Endpoint kelompok ini menerima path direktori lalu menjalankan proses
    panjang, dan itu pantas dijaga lebih dari sekadar tidak terekspos ke luar.
    Dikosongkan berarti mati, dan itu hanya untuk pengembangan lokal.
    """
    harusnya = os.getenv("RELEVANSI_TRAINING_SECRET", "")

    if harusnya == "":
        return

    if diberikan is None or not secrets.compare_digest(diberikan, harusnya):
        raise HTTPException(status_code=401, detail="Rahasia internal tidak cocok.")


@app.post("/relevancy/training-runs", response_model=TanggapanPelatihan)
def mulai_pelatihan(
    permintaan: PermintaanPelatihan,
    x_internal_secret: str | None = Header(default=None),
) -> TanggapanPelatihan:
    _periksa_rahasia(x_internal_secret)

    # Satu pelatihan pada satu waktu. Dua pelatihan bersamaan di CPU yang sama
    # tidak selesai dua kali lebih cepat, keduanya melambat lalu berebut memori
    # sampai salah satunya mati di tengah jalan.
    if training.sedang_berjalan():
        raise HTTPException(status_code=409, detail="Masih ada pelatihan yang berjalan.")

    for nama in ("train", "validation", "test"):
        if nama not in permintaan.berkas or not os.path.exists(permintaan.berkas[nama]):
            raise HTTPException(status_code=400, detail=f"Berkas {nama} tidak ditemukan.")

    training.mulai(permintaan.run_id, permintaan.model_dump())

    return TanggapanPelatihan(accepted=True, run_id=permintaan.run_id, status="menunggu")


@app.get("/relevancy/training-runs/{run_id}")
def status_pelatihan(
    run_id: int,
    x_internal_secret: str | None = Header(default=None),
) -> dict:
    _periksa_rahasia(x_internal_secret)

    hasil = training.status(run_id)

    if hasil is None:
        raise HTTPException(status_code=404, detail="Pelatihan tidak dikenal.")

    return hasil


@app.post("/relevancy/training-runs/{run_id}/cancel")
def batalkan_pelatihan(
    run_id: int,
    x_internal_secret: str | None = Header(default=None),
) -> dict:
    _periksa_rahasia(x_internal_secret)

    return {"dibatalkan": training.batalkan(run_id)}
