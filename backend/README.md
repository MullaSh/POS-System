# Backend (Laravel)

API dan aturan bisnis POS UMKM. Rancangan: dokumen 12 di Project Knowledge.

- Framework: Laravel 13, PHP 8.3+
- Database: PostgreSQL 16
- Login: Laravel Sanctum, cookie sesi (SPA satu domain)
- Struktur modul: `app/Modules/` (lihat `app/Modules/README.md`)

## Menjalankan untuk pengembang

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve
```

Tes (butuh database `pos_test` di PostgreSQL):

```bash
php artisan test
```

Pemilik usaha tidak perlu menjalankan perintah ini; gunakan Docker dari folder
utama (lihat README di root repository).
