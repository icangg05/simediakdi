"""Layanan NLP SIMEDIA Kendari.

Menerima teks, mengembalikan angka. Layanan ini tidak pernah menyentuh
database: seluruh penyimpanan dikerjakan Laravel. Aturan itu menjaga satu
sumber kebenaran dan membuat proses ini bisa dimatikan kapan saja tanpa risiko
kehilangan data — kalau mati, job menumpuk di antrean `nlp` lalu jalan lagi.

Dijalankan satu worker saja. Model dimuat sekali saat startup dan memakan
sekitar 1,5 GB; dua worker berarti dua salinan model di memori yang sama.
"""

import logging
import os

import torch
from fastapi import FastAPI, HTTPException
from sentence_transformers import SentenceTransformer
from transformers import AutoModelForSequenceClassification, AutoTokenizer

from models import (
    PermintaanEmbed,
    PermintaanPasangan,
    HasilRelevansi,
    HasilSentimen,
    SkorSentimen,
    TanggapanEmbed,
    TanggapanRelevansi,
    TanggapanSehat,
    TanggapanSentimen,
)

VERSI = "1.0.0"

MODEL_SENTIMEN = os.getenv("MODEL_SENTIMEN", "apriandito/indobert-sentiment-classifier")
MODEL_RELEVANSI = os.getenv("MODEL_RELEVANSI", "apriandito/indobert-relevancy-classifier")
MODEL_EMBEDDING = os.getenv(
    "MODEL_EMBEDDING", "sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2"
)

# Dua model klasifikasi memakai IndoBERT Large dengan batas 512 token. Artikel
# berita jauh lebih panjang, jadi teks dipotong — bagian awal berita memuat
# inti peristiwa, sehingga pemotongan di ekor lebih aman daripada di kepala.
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

    log.info("Memuat model relevansi %s", MODEL_RELEVANSI)
    muatan["tok_relevansi"] = AutoTokenizer.from_pretrained(MODEL_RELEVANSI)
    muatan["mod_relevansi"] = AutoModelForSequenceClassification.from_pretrained(
        MODEL_RELEVANSI
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
        model_relevansi=MODEL_RELEVANSI,
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


@app.post("/relevancy", response_model=TanggapanRelevansi)
def relevancy(permintaan: PermintaanPasangan) -> TanggapanRelevansi:
    peluang = _klasifikasi(
        muatan["tok_relevansi"], muatan["mod_relevansi"], permintaan.pasangan
    )

    # id2label: 0 = NOT_RELEVANT, 1 = RELEVANT.
    return TanggapanRelevansi(
        hasil=[
            HasilRelevansi(
                id=pasangan.id,
                relevan=bool(baris[1] >= baris[0]),
                keyakinan=round(float(max(baris)), 4),
            )
            for pasangan, baris in zip(permintaan.pasangan, peluang)
        ]
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

    Kedua model dilatih dengan format `[CLS] konteks [SEP] teks [SEP]`, jadi
    keduanya diberikan sebagai pasangan kalimat — bukan digabung jadi satu
    string. Menggabungnya membuat model kehilangan batas antara konteks dan
    isi, dan hasilnya kembali seperti model sentimen biasa.
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
