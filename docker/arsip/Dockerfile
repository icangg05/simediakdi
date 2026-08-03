# Image resmi Playwright sudah memuat Chromium beserta seluruh pustaka sistem
# yang dibutuhkannya. Memasang sendiri di atas python:slim berarti mengejar
# daftar paket apt yang berubah tiap rilis Chromium.
FROM mcr.microsoft.com/playwright/python:v1.49.1-noble

WORKDIR /arsip

# Image ini membawa binari peramban di /ms-playwright, bukan paket Python-nya.
# Versi paket dikunci ke versi image: peramban dan pustaka yang tidak sepadan
# gagal dengan pesan yang menyesatkan tentang "executable doesn't exist".
RUN pip install --no-cache-dir \
    "playwright==1.49.1" \
    "fastapi==0.115.*" \
    "uvicorn[standard]==0.32.*"

COPY . .

EXPOSE 8002

CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8002", "--workers", "1"]
