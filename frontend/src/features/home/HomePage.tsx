import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { InfoBanner, PageHeader } from '@/components/ui/feedback'
import { Link } from 'react-router'
import { Button } from '@/components/ui/button'
import { useApprovalSummary } from '@/features/approvals/queries'
import { hasRole, useMe } from '@/features/auth/useAuth'
import { ROLE_LABEL } from '@/lib/format'

export function HomePage() {
  const { data: user } = useMe()
  const roles = (user?.roles ?? []).filter((r) => r.status === 'ACTIVE')
  const isOwner = hasRole(user, 'OWNER')
  const summary = useApprovalSummary(isOwner || hasRole(user, 'FINANCE'))
  const waiting = summary.data?.data.waiting_my_decision ?? 0
  const returned = summary.data?.data.returned_to_me ?? 0

  return (
    <div className="max-w-4xl space-y-6">
      <PageHeader title={`Selamat datang, ${user?.name ?? ''}`} purpose="Ringkasan akun Anda dan apa yang bisa Anda kerjakan di sini." />

      {(waiting > 0 || returned > 0) && (
        <Card className="border-amber-500/50">
          <CardContent className="flex flex-wrap items-center justify-between gap-4 pt-6">
            <div>
              {waiting > 0 && <p className="font-medium">{waiting} pengajuan menunggu keputusan Anda.</p>}
              {returned > 0 && <p className="font-medium">{returned} pengajuan Anda dikembalikan untuk diperbaiki.</p>}
            </div>
            <Button asChild><Link to="/persetujuan">Buka persetujuan</Link></Button>
          </CardContent>
        </Card>
      )}

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
        Aplikasi ini sedang dibangun bertahap. Yang sudah tersedia: data barang dan harga jual menu, resep menu dan resep produksi, satuan, supplier,
        metode bayar, kategori biaya, gudang dan outlet, pengguna dan peran, persetujuan, serta jejak audit. Kasir, stok, produksi, pembelian, kas, dan laporan menyusul di tahap berikutnya.
      </InfoBanner>
    </div>
  )
}
