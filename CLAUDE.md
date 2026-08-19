# SIMAK Kendari

## Bahasa komunikasi

Selalu gunakan bahasa Indonesia untuk:

- Menjelaskan hasil prompting.
- Menjelaskan perubahan kode.
- Menjelaskan alasan pengambilan keputusan.
- Menjelaskan error dan solusi.
- Memberikan ringkasan pekerjaan.
- Menjawab pertanyaan pengguna.
- Menulis laporan hasil implementasi.
- Menjelaskan hasil analisis, pengujian, dan validasi.

Istilah teknis, nama class, nama method, nama package, nama file, perintah terminal, kode, dan pesan error asli tetap boleh menggunakan bahasa Inggris jika diperlukan.

Jangan hanya memberikan kode tanpa penjelasan. Setelah melakukan perubahan, jelaskan dalam bahasa Indonesia:

1. Apa yang diubah.
2. File yang diubah.
3. Alasan perubahan.
4. Cara kerja perubahan.
5. Cara menguji perubahan.
6. Risiko atau catatan penting, jika ada.

Gunakan bahasa Indonesia yang jelas, langsung, dan mudah dipahami. Hindari penjelasan yang terlalu panjang jika tidak diperlukan.

## Gaya penulisan

Aturan ini berlaku untuk seluruh isi repositori, termasuk:

- Dokumen di `docs/`.
- README.
- File Markdown.
- Komentar kode PHP.
- Komentar kode TypeScript dan JavaScript.
- Template Vue.
- String di controller.
- String validasi.
- String notifikasi.
- Teks antarmuka pengguna.
- File konfigurasi.
- `.env.example`.
- Dockerfile.
- File Docker Compose.
- Pesan commit.
- Balasan di chat.

### Larangan tanda baca

Jangan pernah memakai em dash Unicode U+2014 atau en dash Unicode U+2013.

Aturan ini tidak memiliki pengecualian.

Aturan ini pernah gagal karena perintah pemeriksaan hanya menyisir folder `docs/` dan file `*.md`. Akibatnya, 297 tanda lolos ke komentar kode dan teks yang dibaca pengguna.

Perintah pemeriksaan wajib menyisir seluruh repositori. Jangan mempersempit cakupannya.

Gunakan pengganti berikut:

| Kebutuhan | Gunakan |
|---|---|
| Menyisipkan penjelasan | Koma atau tanda kurung |
| Memisahkan dua klausa | Titik dan buat dua kalimat |
| Nama bagian dan keterangannya | Titik dua |
| Judul Markdown | Titik dua, contoh: `# 03: Skema Database` |
| Rentang angka dan tanggal | Tanda hubung, contoh: `10-20` |
| Sel tabel kosong | Tanda hubung: `-` |

Contoh:

- Salah: `F1 macro di bawah 0,65 berarti berhenti. Jangan bangun dashboard di atas model itu.` jika kedua kalimat disambungkan memakai em dash.
- Benar: `F1 macro di bawah 0,65 berarti berhenti. Jangan bangun dashboard di atas model itu.`

Jika ragu, pecah kalimat menjadi dua. Kalimat pendek lebih baik daripada satu kalimat panjang yang disambungkan dengan tanda baca yang dilarang.

## Pemeriksaan sebelum selesai

Sebelum menyelesaikan pekerjaan, jalankan pemeriksaan berikut dari root repositori:

```bash
grep -rnP '\x{2014}|\x{2013}' \
  --exclude-dir=node_modules \
  --exclude-dir=vendor \
  --exclude-dir=.git \
  --exclude-dir=storage \
  --exclude-dir=build \
  .

## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

Rules:
- For codebase questions, first run `graphify query "<question>"` when graphify-out/graph.json exists. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).
