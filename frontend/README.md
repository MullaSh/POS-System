# Frontend (React + TypeScript)

Layar aplikasi POS. Rancangan: dokumen 12 di Project Knowledge.

- React + TypeScript + Vite
- Tailwind CSS + komponen gaya shadcn/ui (`src/components/ui`)
- TanStack Query (data server), React Hook Form + Zod (form)
- Bahasa layar: Bahasa Indonesia

## Perintah pengembang

```bash
npm install
npm run dev        # http://localhost:5173, /api diteruskan ke backend :8000
npm run lint
npm run build
npm test
```

Struktur: `src/features/<modul>` untuk layar per modul, `src/lib/api.ts` untuk
pemanggilan API dengan cookie sesi Sanctum.
