import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { InfoBanner, PageHeader } from '@/components/ui/feedback'
import { useMe } from '@/features/auth/useAuth'
import { ROLE_LABEL } from '@/lib/format'

export function HomePage() {
  const { data: user } = useMe()
  const roles = (user?.roles ?? []).filter((r) => r.status === 'ACTIVE')

  return (
    <div className="max-w-4xl space-y-6">
      <PageHeader title={`Selamat datang, ${user?.name ?? ''}`} purpose="Ringkasan akun Anda dan apa yang bisa Anda kerjakan di sini." />

      <Card>
        <CardHeader>
          <CardTitle className="text-base">Peran Anda</CardTitle>
        </CardHeader>
        <CardContent>
          {roles.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              Akun Anda belum diberi peran. Hubungi pemilik usaha agar diberi peran sesuai tugas Anda.
            </p>
          ) : (
            <ul className="space-y-2 text-sm">
              {roles.map((r) => (
                <li key={r.assignment_id} className="flex flex-wrap items-baseline gap-2">
                  <span className="font-medium">{ROLE_LABEL[r.code]}</span>
                  <span className="text-muted-foreground">
                    {r.scope === 'ALL_LOCATIONS' ? 'semua lokasi' : r.locations.map((l) => l.name).join(', ')}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>

      <InfoBanner>
        Aplikasi ini sedang dibangun bertahap. Yang sudah tersedia: data barang, satuan, gudang dan outlet, pengguna
        dan peran, serta jejak audit. Kasir, stok, produksi, pembelian, kas, dan laporan menyusul di tahap berikutnya.
      </InfoBanner>
    </div>
  )
}
