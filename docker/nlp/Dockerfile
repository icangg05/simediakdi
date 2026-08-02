FROM python:3.11-slim

WORKDIR /nlp

# torch CPU-only. Indeks resmi PyPI membawa varian CUDA yang ukurannya berlipat
# tanpa manfaat — inferensi untuk volume satu kota cukup di CPU.
COPY requirements.txt .
RUN pip install --no-cache-dir --extra-index-url https://download.pytorch.org/whl/cpu -r requirements.txt

# Model diunduh sekali ke volume, bukan ikut ke dalam image: image tetap kecil
# dan mengganti model tidak perlu build ulang.
ENV HF_HOME=/model
ENV TRANSFORMERS_OFFLINE=0

COPY . .

EXPOSE 8001

CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8001", "--workers", "1"]
