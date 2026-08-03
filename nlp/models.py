"""Bentuk permintaan dan tanggapan layanan NLP.

Kontraknya ada di dokumen 02 bagian 6 dan tidak boleh menyimpang: `id` adalah
`artikel_id` dari Laravel dan dikembalikan apa adanya, supaya pemetaan hasil
tidak bergantung pada urutan array.
"""

from pydantic import BaseModel, Field

# Batas ini menjaga memori worker. Melebihinya ditolak, bukan dipotong diam-diam,
# supaya pemanggil tahu ada hasil yang tidak akan pernah datang.
MAKS_TEKS_EMBED = 32
MAKS_PASANGAN = 16


class PermintaanEmbed(BaseModel):
    teks: list[str] = Field(min_length=1, max_length=MAKS_TEKS_EMBED)


class TanggapanEmbed(BaseModel):
    embedding: list[list[float]]
    dimensi: int


class Pasangan(BaseModel):
    id: int
    konteks: str
    teks: str


class PermintaanPasangan(BaseModel):
    pasangan: list[Pasangan] = Field(min_length=1, max_length=MAKS_PASANGAN)


class SkorSentimen(BaseModel):
    negatif: float
    netral: float
    positif: float


class HasilSentimen(BaseModel):
    id: int
    label: str
    skor: SkorSentimen
    keyakinan: float
    model_versi: str


class TanggapanSentimen(BaseModel):
    hasil: list[HasilSentimen]


class TanggapanSehat(BaseModel):
    status: str
    model_sentimen: str
    model_embedding: str
    versi: str
