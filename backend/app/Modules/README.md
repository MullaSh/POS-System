# Modul backend

Aplikasi ini adalah modular monolith (dokumen 12 §2.2). Setiap modul berada di
folder sendiri:

```
app/Modules/<Modul>/
├── routes.php            rute API modul, otomatis dimuat di /api/v1
├── Http/Controllers/     menerima permintaan, memanggil service
├── Models/               model Eloquent milik modul
├── Services/             aturan bisnis
└── Policies/             pemeriksaan izin
```

Aturan:

1. Modul lain memanggil lewat service, tidak langsung mengubah tabel modul lain.
2. Tindakan yang mengubah stok, dana, atau dokumen berjalan dalam satu transaksi
   database bersama catatan auditnya (dokumen 12 §2.4 P1).
3. Catatan berlaku dan catatan audit tidak di-UPDATE atau DELETE (P3).

Modul yang direncanakan: System, Identity, Audit, Location, Catalog, Approval,
Recipe, Inventory, Sales, Cash, Purchasing, Production, Reporting.
