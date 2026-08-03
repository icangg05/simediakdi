# SIMEDIA Kendari

## Gaya penulisan (dokumen, komentar, commit, teks UI)

**Jangan pernah memakai em dash (U+2014) atau en dash (U+2013).** Tanpa
kecuali, dan berlaku untuk seluruh isi repositori: dokumen di `docs/`, README,
komentar kode PHP dan TypeScript, teks di template Vue, string di controller,
komentar di `config/`, `.env.example`, Dockerfile, docker-compose, pesan
commit, maupun balasan di chat.

Aturan ini pernah gagal karena perintah ceknya hanya menyisir `docs/` dan
`*.md`. Sisa repositori tidak pernah terperiksa, dan 297 tanda lolos ke
komentar kode serta teks yang dibaca pengguna. Perintah cek di bawah menyisir
seluruh repositori, jangan dipersempit lagi.

Gantinya, pilih salah satu:

| Fungsi em dash | Ganti dengan |
|---|---|
| Menyisipkan penjelasan | koma, atau tanda kurung |
| Memisahkan dua klausa | titik (jadikan dua kalimat) |
| Nama bagian lalu keterangannya | titik dua |
| Judul markdown | titik dua: `# 03: Skema Database` |
| Rentang angka dan tanggal | tanda hubung: `10-20` |
| Sel tabel yang kosong | tanda hubung: `-` |

Contoh:

- Salah: `F1 macro di bawah 0,65 berarti berhenti (em dash) jangan bangun dashboard di atas model itu.`
- Benar: `F1 macro di bawah 0,65 berarti berhenti. Jangan bangun dashboard di atas model itu.`

Kalau ragu, pecah jadi dua kalimat. Kalimat pendek lebih baik daripada satu
kalimat panjang yang disambung tanda baca.

Cek sebelum selesai menulis file. Berkas di bawah ini sengaja ditulis tanpa
tanda itu sama sekali supaya perintahnya bisa dijalankan atas repositori penuh
tanpa menemukan dirinya sendiri:

```bash
grep -rnP '\x{2014}|\x{2013}' \
  --exclude-dir=node_modules --exclude-dir=vendor --exclude-dir=.git \
  --exclude-dir=storage --exclude-dir=build .
```

Harus kosong.
