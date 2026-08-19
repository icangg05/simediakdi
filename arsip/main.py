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
from playwright.async_api import TimeoutError as PlaywrightTimeoutError
from playwright.async_api import async_playwright
from pydantic import BaseModel, HttpUrl

app = FastAPI(title="Arsip SIMAK")

# Chromium dijalankan sekali lalu dipakai ulang. Menyalakannya per permintaan
# menambah sekitar dua detik untuk pekerjaan yang sebenarnya sepersepuluhnya.
peramban = None
kunci = asyncio.Lock()

BATAS_DETIK = int(os.environ.get("ARSIP_TIMEOUT", "30"))
LEBAR = int(os.environ.get("ARSIP_LEBAR", "1280"))

# Batas kesabaran menunggu jaringan sepi pada /render. Lebih pendek daripada
# BATAS_DETIK karena habisnya bukan kegagalan, hanya tanda berhenti menunggu.
TUNGGU_SEPI_DETIK = int(os.environ.get("ARSIP_TUNGGU_SEPI", "12"))


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


class PermintaanRender(Permintaan):
    # CSS selector yang menandai daftar beritanya sudah ada. Pemanggil sudah
    # menyimpannya sebagai selector item, jadi tidak ada yang perlu ditebak.
    tunggu: str | None = None


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


@app.post("/render")
async def render(permintaan: PermintaanRender) -> Response:
    """HTML sesudah JavaScript halaman selesai berjalan.

    Dipakai crawler untuk halaman indeks yang daftar beritanya dirakit di sisi
    klien. tempo.co adalah aplikasi Nuxt: HTML yang dikirim server hanya berisi
    kerangka dan pilihan filter, sedangkan daftar artikelnya baru muncul
    sesudah hidrasi. Pengunduh biasa tidak akan pernah melihat satu tautan pun
    di sana.

    Hanya untuk halaman indeks, bukan halaman artikel. Isi artikel tetap
    diambil PengunduhHalaman karena hampir semua situs merender artikelnya di
    server, dan menyalakan Chromium untuk itu berarti membayar beberapa detik
    CPU demi hasil yang sama.

    Kalau `tunggu` diisi, yang ditunggu adalah munculnya elemen itu, dan itu
    syarat yang benar. `networkidle` saja tidak cukup: jaringan bisa sepi
    justru pada jeda sebelum permintaan daftar beritanya berangkat, dan halaman
    kosong pun lolos. Sekali percobaan pertama mengembalikan nol item karena
    ini, sementara percobaan yang sama diulang menghasilkan dua puluh.

    Habisnya waktu tidak dianggap gagal, baik saat menunggu elemen maupun saat
    menunggu jaringan sepi. Portal berita memasang pelacak yang terus
    berdenyut, dan halaman yang sudah lengkap tidak boleh dibuang hanya karena
    satu beacon iklan belum berhenti. Yang dikirim balik adalah keadaan halaman
    saat itu juga, dan pemanggilnya yang menilai apakah isinya cukup.
    """
    if peramban is None or not peramban.is_connected():
        raise HTTPException(503, "Peramban belum siap")

    async with kunci:
        konteks = await peramban.new_context(
            viewport={"width": LEBAR, "height": 900},
            user_agent=os.environ.get("ARSIP_USER_AGENT", "SimakKendariBot/1.0"),
        )
        try:
            halaman = await konteks.new_page()
            await halaman.goto(str(permintaan.url), wait_until="domcontentloaded", timeout=BATAS_DETIK * 1000)

            try:
                if permintaan.tunggu:
                    await halaman.wait_for_selector(permintaan.tunggu, timeout=TUNGGU_SEPI_DETIK * 1000)
                else:
                    await halaman.wait_for_load_state("networkidle", timeout=TUNGGU_SEPI_DETIK * 1000)
            except PlaywrightTimeoutError:
                pass

            html = await halaman.content()
        except Exception as e:
            raise HTTPException(502, f"Gagal merender halaman: {e}") from e
        finally:
            await konteks.close()

    return Response(content=html, media_type="text/html; charset=utf-8")
