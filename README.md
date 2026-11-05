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

### Akun contoh (hanya di Docker laptop)

Semua kata sandi: `demo12345`

| Username | Peran | Lokasi |
| --- | --- | --- |
| `owner` | Owner | Semua |
| `finance1` | Finance | Semua |
| `gudang1` | Gudang | Gudang Pusat |
| `kasir1` | Kasir | Outlet 1 |
| `kasir2` | Kasir | Outlet 2 |

Nama outlet dan barang adalah contoh. Di server sungguhan, data contoh tidak
dipasang; akun `owner` memakai kata sandi sementara yang wajib diganti saat
pertama masuk.

### Mulai dari data bersih

Bila tampilan atau data terasa tidak sesuai setelah pembaruan, hapus data
percobaan lalu nyalakan lagi:

```
docker compose down -v
docker compose up --build
```

Perintah pertama menghapus **seluruh data percobaan** di laptop (tidak
menyentuh kode).

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
| 1 | Login, user dan peran, lokasi, data master barang, audit trail | Selesai |
| 2 | Supplier, metode bayar, kategori biaya, harga jual + persetujuan, delegasi | Berikutnya |
| 3–9 | Resep, stok, POS, kas, pembelian, produksi, laporan | Belum dimulai |
