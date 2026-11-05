# POS UMKM — Sistem Manajemen Usaha Terintegrasi

Aplikasi web untuk usaha makanan dengan satu gudang pusat dan banyak outlet:
kasir, stok, produksi, pembelian, kas, dan laporan dalam satu sistem.

Dokumen bisnis dan rancangan ada di Claude Project Knowledge
(Master Project Context, dokumen 11 Aturan Bisnis dan Cakupan MVP,
dokumen 12 Arsitektur dan Database Fondasi).

## Mencoba aplikasi di laptop (Windows)

Hanya perlu dilakukan sekali:

1. Pasang **Docker Desktop** dari https://www.docker.com/products/docker-desktop/
   lalu buka aplikasinya sampai statusnya "Engine running".

Setiap kali ingin mencoba:

1. Buka folder `C:\ProjectPOS` di File Explorer.
2. Klik kolom alamat di atas, ketik `powershell`, tekan Enter.
3. Ketik perintah berikut lalu tekan Enter:

   ```
   docker compose up --build
   ```

   Pertama kali butuh beberapa menit. Tunggu sampai muncul tulisan
   `Backend siap di port 8000`.
4. Buka browser ke **http://localhost:8080**

Untuk berhenti: tekan `Ctrl + C` di jendela PowerShell tadi.

## Isi repository

| Folder | Isi |
| --- | --- |
| `backend/` | Laravel 13 (API, aturan bisnis, database PostgreSQL) |
| `frontend/` | React + TypeScript (layar aplikasi, Bahasa Indonesia) |
| `.github/workflows/` | Tes otomatis setiap ada perubahan |
| `docker-compose.yml` | Menjalankan aplikasi lengkap di laptop |

## Status pengerjaan

| Irisan | Isi | Status |
| --- | --- | --- |
| 0 | Setup proyek, halaman login awal, tes otomatis, Docker | Selesai |
| 1 | Login, user dan peran, lokasi, data master barang, audit trail | Berikutnya |
| 2–9 | Data master lanjutan, resep, stok, POS, kas, pembelian, produksi, laporan | Belum dimulai |
