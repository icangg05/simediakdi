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


class PermintaanPelatihan(BaseModel):
    """Permintaan memulai fine-tuning relevansi. Dokumen 10 bagian 19.3.

    `berkas` berisi path absolut hasil ekspor Laravel. Layanan ini tidak pernah
    membaca database: ia menerima berkas, melatih, lalu mengembalikan angka.
    """

    run_id: int
    base_model: str
    berkas: dict[str, str]
    artifact_path: str
    epoch: float = 3
    batch_size: int = 4
    gradient_accumulation: int = 4
    learning_rate: float = 1e-5
    weight_decay: float = 0.01
    warmup_ratio: float = 0.1
    max_length: int = 256
    class_weighting: bool = True
    random_seed: int = 42
    metric_utama: str = "f1_relevan"
    versi_input_builder: str | None = None
    snapshot_manifest_hash: str | None = None


class TanggapanPelatihan(BaseModel):
    accepted: bool
    run_id: int
    status: str
