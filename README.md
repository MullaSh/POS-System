# POS — Sistem Manajemen Usaha Terintegrasi

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

Data contoh stok: saldo awal di Gudang Pusat dan Outlet 1, satu permintaan
stok dari Outlet 1 (untuk diproses `gudang1`), dan satu kiriman ke Outlet 2
(untuk diterima `kasir2`). Jumlah dan harga pokoknya contoh.

Data contoh kasir: shift `kasir1` yang sedang berjalan di Outlet 1 dengan dua
transaksi (satu bayar campur QRIS + tunai), satu pengajuan refund untuk
diputuskan `owner`, menu tambahan "Extra Saus Mentai" (contoh add-on yang
dijual sebagai menu), dan delegasi dari `owner` ke `finance1` untuk
persetujuan void/refund selama 7 hari.

Data contoh kas: saldo tetap Petty Cash Rp200.000 per outlet (angka contoh),
isi awal Petty Cash Outlet 1 oleh `finance1`, satu biaya Outlet 1 yang menunggu
diperiksa Finance, dan satu biaya gudang yang dibayar Finance.

Data contoh pembelian: batas persetujuan Finance Rp5.000.000, satu PR yang
menunggu `finance1`, dan satu PO ke Toko Daging Contoh yang menunggu diterima
`gudang1`.

Data contoh produksi: toleransi varians 5%, umur simpan Frozen Dimsum 90
hari, satu produksi Frozen Dimsum yang sudah selesai (hasil baik 96 pcs, gagal
4 pcs), dan satu permintaan besar dari Outlet 2 yang stok gudangnya kurang,
dengan rencana produksi untuknya (untuk diselesaikan `gudang1`).

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
| `.github/workflows/` | Tes otomatis setiap ada perubahan, termasuk screenshot layar |
| `docker-compose.yml` | Menjalankan aplikasi lengkap di laptop |

## Status pengerjaan

| Irisan | Isi | Status |
| --- | --- | --- |
| 0 | Setup proyek, halaman login awal, tes otomatis, Docker | Selesai |
| 1 | Login, user dan peran, lokasi, data master barang, audit trail | Selesai |
| 2 | Supplier, metode bayar, kategori biaya, harga jual + persetujuan | Selesai |
| 3 | Resep menu dan resep produksi berversi, HPP dari bahan | Selesai |
| 4 | Stok: saldo awal + harga pokok, kartu stok, batch gudang, waste, stock opname (disetujui Owner), permintaan stok, kirim/terima/retur dengan penjelasan selisih | Selesai |
| 5 | POS: shift dengan Cash Float dan tutup shift hitung buta, transaksi dengan stok outlet dipotong per resep dan HPP terkunci, bayar campur, struk dari browser, void/refund dengan persetujuan Owner, delegasi persetujuan | Selesai |
| 6 | Kas: ringkasan tiga dana per outlet, serah terima Outlet → Gudang → Finance dengan konfirmasi dan bukti selisih, Petty Cash dan biaya (isi ulang sebesar biaya terbukti), pencocokan non-tunai harian, keputusan selisih oleh Finance | Selesai |
| 7 | Pembelian: Purchase Request gudang (disetujui Finance), Purchase Order oleh Finance (di atas batas disetujui Owner; gudang tidak melihat harga), penerimaan bertahap dengan QC Incoming, tagihan per penerimaan dengan alasan bila beda harga, pembayaran sebagian dan daftar hutang per jatuh tempo | Selesai |
| 8 | Produksi: Production Order dua langkah (rencana → selesai), dibuat dari permintaan stok yang kurang atau tanpa permintaan; bahan kurang langsung jadi PR terisi; selesai dengan hasil baik/gagal per alasan (QC Production), pemakaian bahan sebenarnya, harga pokok hasil dari bahan terpakai, batch = nomor produksi dengan kedaluwarsa dari umur simpan; toleransi varians diatur Owner | Selesai |
| 9 | Laporan dan dashboard Owner | Berikutnya |
