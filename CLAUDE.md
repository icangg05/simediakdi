# SIMEDIA Kendari

## Gaya penulisan (dokumen, komentar, commit, teks UI)

**Jangan pernah memakai em dash (`—`) atau en dash (`–`).** Tanpa kecuali:
dokumen di `docs/`, README, komentar kode, pesan commit, teks di UI, maupun
balasan di chat.

Gantinya, pilih salah satu:

| Fungsi em dash | Ganti dengan |
|---|---|
| Menyisipkan penjelasan | koma, atau tanda kurung |
| Memisahkan dua klausa | titik (jadikan dua kalimat) |
| Judul: `# 03 — Skema Database` | titik dua: `# 03: Skema Database` |
| Rentang angka: `10—20` | tanda hubung: `10-20` |

Contoh:

- Salah: `F1 macro di bawah 0,65 berarti berhenti — jangan bangun dashboard di atas model itu.`
- Benar: `F1 macro di bawah 0,65 berarti berhenti. Jangan bangun dashboard di atas model itu.`

Kalau ragu, pecah jadi dua kalimat. Kalimat pendek lebih baik daripada satu
kalimat panjang yang disambung tanda baca.

Cek sebelum selesai menulis file:

```bash
grep -rn '[—–]' docs/ *.md
```

Harus kosong.
