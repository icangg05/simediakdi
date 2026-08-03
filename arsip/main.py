"""Layanan tangkapan layar untuk bukti pemuatan kontrak (F-52).

Dipisah dari layanan NLP dengan sengaja. Layanan NLP berjalan satu worker
karena modelnya memakan 1,5 GB memori, dan satu render Chromium yang memakan
CPU beberapa detik akan menahan antrean analisis sentimen di belakangnya.

Tidak menyentuh database dan tidak menyimpan berkas. Ia menerima URL,
mengembalikan PNG, selesai. Laravel yang memutuskan di mana berkasnya
disimpan dan baris mana yang diperbarui.
"""

import asyncio
import os

from fastapi import FastAPI, HTTPException, Response
from playwright.async_api import async_playwright
from pydantic import BaseModel, HttpUrl

app = FastAPI(title="Arsip SIMEDIA")

# Chromium dijalankan sekali lalu dipakai ulang. Menyalakannya per permintaan
# menambah sekitar dua detik untuk pekerjaan yang sebenarnya sepersepuluhnya.
peramban = None
kunci = asyncio.Lock()

BATAS_DETIK = int(os.environ.get("ARSIP_TIMEOUT", "30"))
LEBAR = int(os.environ.get("ARSIP_LEBAR", "1280"))


@app.on_event("startup")
async def nyalakan():
    global peramban
    pw = await async_playwright().start()
    peramban = await pw.chromium.launch(args=["--no-sandbox", "--disable-dev-shm-usage"])


@app.get("/health")
async def kesehatan():
    return {"status": "ok", "peramban": peramban is not None and peramban.is_connected()}


class Permintaan(BaseModel):
    url: HttpUrl


@app.post("/tangkap")
async def tangkap(permintaan: Permintaan) -> Response:
    if peramban is None or not peramban.is_connected():
        raise HTTPException(503, "Peramban belum siap")

    # Satu halaman sekaligus. Bukti pemuatan datang beberapa per hari, bukan
    # beberapa per detik, jadi antre lebih murah daripada mengelola pool.
    async with kunci:
        konteks = await peramban.new_context(
            viewport={"width": LEBAR, "height": 900},
            # Situs media daerah banyak yang memuat iklan lambat. Halaman yang
            # tidak pernah "networkidle" tetap harus menghasilkan bukti.
            ignore_https_errors=False,
        )
        try:
            halaman = await konteks.new_page()
            await halaman.goto(str(permintaan.url), wait_until="domcontentloaded", timeout=BATAS_DETIK * 1000)
            await halaman.wait_for_timeout(1500)
            gambar = await halaman.screenshot(full_page=True, type="png")
        except Exception as e:
            raise HTTPException(502, f"Gagal menangkap layar: {e}") from e
        finally:
            await konteks.close()

    return Response(content=gambar, media_type="image/png")
