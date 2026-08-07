FROM python:3.11-slim

WORKDIR /relevansi

# torch CPU-only lewat indeks resmi PyTorch. Varian bawaan PyPI membawa seluruh
# pustaka CUDA, sekitar dua setengah gigabita, yang tidak pernah dipakai di
# mesin tanpa kartu grafis.
COPY requirements.txt .
RUN pip install --no-cache-dir --extra-index-url https://download.pytorch.org/whl/cpu -r requirements.txt

# Bobot base model diunduh sekali ke volume, bukan ikut ke dalam image. Image
# tetap kecil, dan mengganti base model tidak perlu build ulang.
ENV HF_HOME=/model
ENV TOKENIZERS_PARALLELISM=false

# Direktori dibuat beserta pemiliknya di dalam image, bukan diserahkan ke Docker.
#
# Container ini berjalan sebagai uid 1000 supaya artefak yang ditulisnya ke
# repositori bisa disunting pengguna. Volume kosong yang baru dibuat mewarisi
# kepemilikan direktori dari image, jadi tanpa baris ini /model menjadi milik
# root dan unduhan pertama Hugging Face berhenti dengan "Permission denied:
# /model/hub", galat yang muncul dua persen di dalam pelatihan dan tidak
# menyebut Docker sama sekali.
RUN mkdir -p /model && chown -R 1000:1000 /model

COPY . .

EXPOSE 8001

# Satu worker. Proses ini memegang bobot model saat melatih maupun saat
# menjawab prediksi, dan worker kedua berarti salinan kedua di memori yang sama.
CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8001", "--workers", "1"]
