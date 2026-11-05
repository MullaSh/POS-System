import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { InfoBanner } from '@/components/ui/feedback'
import { EmptyRow, Table, Td, Th } from '@/components/ui/table'
import { APPROVALS_KEY } from '@/features/approvals/queries'
import { hasRole, useMe } from '@/features/auth/useAuth'
import { ApiError, http } from '@/lib/api'
import { APPROVAL_STATUS_LABEL, formatDate, formatDateTime, formatRupiah } from '@/lib/format'
import type { ItemDetail } from '@/lib/types'
import { ITEMS_KEY } from './queries'
import { PriceForm, type PriceInput } from './PriceForm'

/**
 * Harga jual menu: satu harga untuk semua outlet (KP-16). Finance mengajukan,
 * Owner menyetujui di Persetujuan atau mengubah langsung (G-05, 2E-Q1b).
 */
export function PriceCard({ item }: { item: ItemDetail }) {
  const { data: me } = useMe()
  const isOwner = hasRole(me, 'OWNER')
  const isFinance = hasRole(me, 'FINANCE') && !isOwner
  const qc = useQueryClient()
  const [editing, setEditing] = useState(false)
  const price = item.price!
  const open = price.open_request

  const submit = useMutation({
    mutationFn: (input: PriceInput) =>
      http.post(`/items/${item.id}/${isOwner ? 'prices' : 'price-requests'}`, {
        price: input.price,
        effective_date: input.effective_date,
        reason: input.reason || null,
      }),
    onSuccess: () => {
      setEditing(false)
      qc.invalidateQueries({ queryKey: ITEMS_KEY })
      qc.invalidateQueries({ queryKey: APPROVALS_KEY })
    },
  })
  const err = submit.error instanceof ApiError ? submit.error : null
  const canChange = (isOwner || isFinance) && !open

  return (
    <Card>
      <CardHeader className="flex items-center justify-between gap-4 space-y-0">
        <CardTitle className="text-base">Harga jual</CardTitle>
        {canChange && !editing && (
          <Button size="sm" onClick={() => { submit.reset(); setEditing(true) }}>
            {isOwner ? 'Ubah harga' : 'Ajukan harga baru'}
          </Button>
        )}
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="flex flex-wrap items-baseline gap-x-6 gap-y-1">
          <p className="text-2xl font-semibold">{price.current ? formatRupiah(price.current.price) : 'Belum ada harga'}</p>
          <p className="text-sm text-muted-foreground">Berlaku di semua outlet.</p>
        </div>

        {price.upcoming.map((u) => (
          <p key={u.id} className="text-sm">
            <Badge variant="warning">Terjadwal</Badge>{' '}
            {formatRupiah(u.price)} mulai {formatDate(u.effective_from)}
          </p>
        ))}

        {open && (
          <InfoBanner>
            Ada pengajuan {formatRupiah(open.payload.price)}
            {open.payload.effective_date ? ` mulai ${formatDate(open.payload.effective_date)}` : ''} dari{' '}
            {open.requester?.name} ({APPROVAL_STATUS_LABEL[open.status].toLowerCase()}).{' '}
            <Link to="/persetujuan" className="font-medium underline">Lihat di Persetujuan</Link>
          </InfoBanner>
        )}

        {editing && (
          <div className="rounded-lg border border-border p-4">
            <p className="mb-3 text-sm text-muted-foreground">
              {isOwner
                ? 'Perubahan oleh Owner langsung berlaku sesuai tanggal yang dipilih dan tercatat sebagai keputusan langsung Owner.'
                : 'Harga baru berlaku setelah disetujui Owner.'}
            </p>
            <PriceForm
              submitLabel={isOwner ? 'Simpan harga' : 'Kirim pengajuan'}
              pending={submit.isPending}
              error={err}
              onSubmit={(input) => submit.mutate(input)}
              onCancel={() => setEditing(false)}
            />
            {err && !err.field('price') && !err.field('request') && !err.field('effective_date') && (
              <p role="alert" className="mt-2 text-sm text-destructive">{err.message}</p>
            )}
          </div>
        )}

        <div>
          <p className="mb-2 text-sm font-medium">Riwayat harga</p>
          <Table>
            <thead>
              <tr>
                <Th>Harga</Th>
                <Th>Berlaku mulai</Th>
                <Th>Ditetapkan oleh</Th>
                <Th>Keterangan</Th>
              </tr>
            </thead>
            <tbody>
              {price.history.length === 0 && <EmptyRow colSpan={4}>Belum ada riwayat harga.</EmptyRow>}
              {price.history.map((h) => (
                <tr key={h.id} className={h.superseded ? 'text-muted-foreground' : undefined}>
                  <Td className={h.superseded ? 'line-through' : undefined}>{formatRupiah(h.price)}</Td>
                  <Td>{formatDateTime(h.effective_from)}</Td>
                  <Td>{h.set_by?.name ?? '–'}</Td>
                  <Td className="text-muted-foreground">
                    {h.approval_number ? `Disetujui dari ${h.approval_number}` : 'Diubah langsung oleh Owner'}
                    {h.reason && <> · {h.reason}</>}
                    {h.superseded && <> · tidak berlaku, digantikan harga yang ditetapkan sesudahnya</>}
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        </div>
      </CardContent>
    </Card>
  )
}
