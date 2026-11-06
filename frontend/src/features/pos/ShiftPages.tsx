import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { ErrorAlert, InfoBanner, Loading, PageHeader } from '@/components/ui/feedback'
import { Field } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { RupiahInput } from '@/components/ui/rupiah-input'
import { EmptyRow, Table, Td, Th } from '@/components/ui/table'
import { hasRole, useMe } from '@/features/auth/useAuth'
import { ApiError, errorText, http } from '@/lib/api'
import { SHIFT_STATUS_LABEL, formatDateTime, formatRupiah, formatSignedRupiah } from '@/lib/format'
import type { Paginated, Shift, ShiftDetail, ShiftStatus } from '@/lib/types'
import { cn } from '@/lib/utils'
import { POS_KEY } from './queries'

const STATUS_VARIANT: Record<ShiftStatus, 'default' | 'success' | 'warning'> = { OPEN: 'success', COUNTED: 'warning', CLOSED: 'default' }
const SHIFT_KEY = ['shifts']

/** Daftar shift: kasir melihat shift miliknya; Owner dan Finance semua outlet (2D-Q2). */
export function ShiftsPage() {
  const { data: me } = useMe()
  const all = hasRole(me, 'OWNER') || hasRole(me, 'FINANCE')
  const shifts = useQuery({ queryKey: [...SHIFT_KEY, 'list'], queryFn: () => http.get<Paginated<Shift>>('/shifts') })

  return (
    <div className="max-w-6xl">
      <PageHeader title="Shift kasir" purpose={all ? 'Shift semua outlet, hasil hitung uang laci, dan selisihnya.' : 'Shift Anda dan hasil hitung uang lacinya.'} />
      <InfoBanner className="mb-4">
        Cash Float tetap di laci untuk shift berikutnya. Sisa uang tunai di atas Cash Float adalah Sales Cash yang siap diserahkan ke gudang.
        Setiap selisih wajib dijelaskan; penyelesaiannya diputuskan Finance.
      </InfoBanner>
      {shifts.isPending ? <Loading /> : shifts.error ? <ErrorAlert message={errorText(shifts.error)} /> : (
        <Table>
          <thead><tr><Th>Nomor</Th><Th>Outlet</Th><Th>Kasir</Th><Th>Dibuka</Th><Th>Ditutup</Th><Th className="text-right">Transaksi</Th><Th className="text-right">Selisih</Th><Th className="text-right">Sales Cash</Th><Th>Status</Th></tr></thead>
          <tbody>
            {shifts.data.data.length === 0 && <EmptyRow colSpan={9}>Belum ada shift.</EmptyRow>}
            {shifts.data.data.map((s) => (
              <tr key={s.id}>
                <Td className="font-mono"><Link to={`/shift/${s.id}`} className="hover:underline">{s.number}</Link></Td>
                <Td>{s.location.name}</Td>
                <Td>{s.cashier?.name}</Td>
                <Td>{formatDateTime(s.opened_at)}</Td>
                <Td>{formatDateTime(s.closed_at)}</Td>
                <Td className="text-right">{s.sale_count ?? 0}</Td>
                <Td className={cn('text-right', (s.cash_difference ?? 0) !== 0 && 'text-destructive')}>{s.cash_difference === null ? '–' : formatSignedRupiah(s.cash_difference)}</Td>
                <Td className="text-right">{s.sales_cash === null ? '–' : formatRupiah(s.sales_cash)}</Td>
                <Td><Badge variant={STATUS_VARIANT[s.status]}>{SHIFT_STATUS_LABEL[s.status]}</Badge></Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
    </div>
  )
}

export function ShiftDetailPage() {
  const { id } = useParams()
  const qc = useQueryClient()
  const [counted, setCounted] = useState<number | null>(null)
  const [reason, setReason] = useState('')
  const shift = useQuery({ queryKey: [...SHIFT_KEY, 'detail', id], queryFn: () => http.get<{ data: ShiftDetail }>(`/shifts/${id}`) })
  const act = useMutation({
    mutationFn: ({ action, body }: { action: 'count' | 'close'; body: unknown }) => http.post<{ data: ShiftDetail }>(`/shifts/${id}/${action}`, body),
    onSuccess: (res) => {
      qc.setQueryData([...SHIFT_KEY, 'detail', id], res)
      qc.invalidateQueries({ queryKey: SHIFT_KEY })
      qc.invalidateQueries({ queryKey: POS_KEY })
    },
  })
  const err = act.error instanceof ApiError ? act.error : null

  if (shift.isPending) return <Loading />
  if (shift.error) return <ErrorAlert message={errorText(shift.error)} />
  const s = shift.data.data

  return (
    <div className="max-w-4xl space-y-4">
      <PageHeader title={`Shift ${s.number}`} purpose={`${s.location.name} · ${s.cashier?.name ?? '–'} · dibuka ${formatDateTime(s.opened_at)}`} />
      <div className="flex flex-wrap items-center gap-3 text-sm">
        <Badge variant={STATUS_VARIANT[s.status]}>{SHIFT_STATUS_LABEL[s.status]}</Badge>
        <span>{s.sale_count} transaksi · <Link to={`/penjualan?shift=${s.id}`} className="hover:underline">lihat di riwayat penjualan</Link></span>
      </div>

      <Card>
        <CardContent className="grid gap-3 pt-6 text-sm md:grid-cols-2">
          <Row label="Cash Float seharusnya saat dibuka" value={formatRupiah(s.float_expected)} />
          <Row label="Cash Float dihitung saat dibuka" value={formatRupiah(s.float_counted)} note={s.open_note} />
          {s.counted_cash !== null && <Row label="Uang tunai di laci (hitung kasir)" value={formatRupiah(s.counted_cash)} />}
          {s.expected_cash !== null && <Row label="Seharusnya menurut sistem" value={formatRupiah(s.expected_cash)} />}
          {s.cash_difference !== null && <Row label="Selisih" value={formatSignedRupiah(s.cash_difference)} danger={s.cash_difference !== 0} note={s.close_note} />}
          {s.float_kept !== null && <Row label="Cash Float ditinggal di laci" value={formatRupiah(s.float_kept)} />}
          {s.sales_cash !== null && <Row label="Sales Cash siap diserahkan" value={formatRupiah(s.sales_cash)} strong />}
          {s.closed_at && <Row label="Ditutup" value={formatDateTime(s.closed_at)} />}
        </CardContent>
      </Card>

      {s.totals && (
        <Table>
          <thead><tr><Th>Metode bayar</Th><Th className="text-right">Transaksi</Th><Th className="text-right">Diterima</Th><Th className="text-right">Dikembalikan</Th></tr></thead>
          <tbody>
            {s.totals.by_method.length === 0 && <EmptyRow colSpan={4}>Belum ada pembayaran.</EmptyRow>}
            {s.totals.by_method.map((m) => (
              <tr key={m.payment_method_id}><Td>{m.name}</Td><Td className="text-right">{m.count}</Td><Td className="text-right">{formatRupiah(m.amount)}</Td><Td className="text-right">{formatRupiah(m.refunded)}</Td></tr>
            ))}
          </tbody>
        </Table>
      )}

      {s.is_mine && s.status === 'OPEN' && (
        <Card>
          <CardContent className="space-y-4 pt-6">
            <div>
              <h2 className="text-lg font-semibold">Tutup shift</h2>
              <p className="text-sm text-muted-foreground">
                Hitung <span className="font-medium text-foreground">seluruh</span> uang tunai di laci, termasuk Cash Float. Angka sistem baru ditampilkan setelah hasil hitung dikirim,
                dan hasil hitung tidak bisa diubah lagi. Pastikan sudah benar.
              </p>
            </div>
            {s.pending_refunds.length > 0 && (
              <InfoBanner>
                Masih ada pengajuan pembatalan yang menunggu keputusan:{' '}
                {s.pending_refunds.map((r) => <Link key={r.id} to={`/penjualan/${r.sale_id}`} className="font-mono underline">{r.number}</Link>)}.
                Tunggu keputusannya, atau buka transaksinya lalu batalkan pengajuan.
              </InfoBanner>
            )}
            <form className="flex flex-wrap items-end gap-3" onSubmit={(e) => { e.preventDefault(); act.mutate({ action: 'count', body: { counted_cash: counted } }) }}>
              <div className="w-56">
                <Field id="counted" label="Jumlah uang tunai di laci" error={err?.field('counted_cash')}>
                  <RupiahInput id="counted" value={counted} onValueChange={setCounted} />
                </Field>
              </div>
              <Button type="submit" disabled={act.isPending || counted === null}>Kirim hasil hitung</Button>
            </form>
          </CardContent>
        </Card>
      )}

      {s.is_mine && s.status === 'COUNTED' && (
        <Card>
          <CardContent className="space-y-4 pt-6">
            <p className="text-sm">
              Hasil hitung berselisih <span className="font-semibold text-destructive">{formatSignedRupiah(s.cash_difference)}</span> dari yang seharusnya.
              Tuliskan penjelasannya untuk menutup shift. Finance yang memutuskan penyelesaian selisih, dan Owner melihat semua selisih.
            </p>
            <form className="flex flex-wrap items-end gap-3" onSubmit={(e) => { e.preventDefault(); act.mutate({ action: 'close', body: { reason } }) }}>
              <div className="min-w-72 flex-1">
                <Field id="close-reason" label="Penjelasan selisih" error={err?.field('reason')}>
                  <Input id="close-reason" value={reason} onChange={(e) => setReason(e.target.value)} />
                </Field>
              </div>
              <Button type="submit" disabled={act.isPending}>Tutup shift</Button>
            </form>
          </CardContent>
        </Card>
      )}
      {s.status === 'CLOSED' && s.is_mine && <p className="text-sm">Shift sudah ditutup. <Link to="/kasir" className="underline">Kembali ke kasir</Link></p>}
      {err && !err.field('counted_cash') && !err.field('reason') && <ErrorAlert message={err.message} />}
      <p><Link to="/shift" className="text-sm hover:underline">← Semua shift</Link></p>
    </div>
  )
}

function Row({ label, value, note, danger, strong }: { label: string; value: string; note?: string | null; danger?: boolean; strong?: boolean }) {
  return (
    <div>
      <p className="text-muted-foreground">{label}</p>
      <p className={cn('text-base', danger && 'text-destructive', strong && 'font-semibold')}>{value}</p>
      {note && <p className="text-xs">Catatan: {note}</p>}
    </div>
  )
}
