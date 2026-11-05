import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { ErrorAlert, InfoBanner, Loading, PageHeader } from '@/components/ui/feedback'
import { Field } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { EmptyRow, Table, Td, Th } from '@/components/ui/table'
import { ApiError, errorText, http } from '@/lib/api'
import { STOCK_REQUEST_STATUS_LABEL, formatDate, formatDateTime, formatQty } from '@/lib/format'
import type { Paginated, StockRequestDetail, StockRequestStatus, StockRequestSummary } from '@/lib/types'
import { cn } from '@/lib/utils'
import { LinesEditor } from './LinesEditor'
import { filledLines, newLine, type Line } from './lines'
import { LocationPicker } from './LocationPicker'
import { STOCK_KEY, useSelectedLocation, useStockItems } from './queries'

const REQUEST_VARIANT: Record<StockRequestStatus, 'default' | 'success' | 'warning' | 'destructive'> = {
  SUBMITTED: 'warning', IN_TRANSIT: 'default', COMPLETED: 'success', REJECTED: 'destructive', CANCELLED: 'default',
}

/** Daftar permintaan stok (KP-09): kasir melihat outletnya, gudang melihat semua. */
export function RequestsPage() {
  const [tab, setTab] = useState<'OPEN' | 'DONE'>('OPEN')
  const { list } = useSelectedLocation()
  const canRequest = list.some((l) => l.can.request_stock)
  const requests = useQuery({
    queryKey: [...STOCK_KEY, 'requests', tab],
    queryFn: () => http.get<Paginated<StockRequestSummary>>(`/stock-requests?status=${tab}`),
  })

  return (
    <div className="max-w-5xl">
      <PageHeader
        title="Permintaan stok"
        purpose="Permintaan barang dari outlet ke gudang pusat."
        actions={canRequest && <Button asChild><Link to="/permintaan-stok/baru">Buat permintaan</Link></Button>}
      />
      <InfoBanner className="mb-4">Kasir membuat permintaan untuk outletnya. Gudang memenuhi dengan mengirim barang, atau menolak dengan alasan.</InfoBanner>
      <div role="tablist" className="mb-4 inline-flex rounded-lg border border-border p-1">
        {([['OPEN', 'Belum selesai'], ['DONE', 'Selesai']] as const).map(([k, label]) => (
          <button key={k} role="tab" aria-selected={tab === k} onClick={() => setTab(k)}
            className={cn('rounded-md px-3 py-1.5 text-sm', tab === k ? 'bg-muted font-medium' : 'text-muted-foreground')}>{label}</button>
        ))}
      </div>
      {requests.isPending ? <Loading /> : requests.error ? <ErrorAlert message={errorText(requests.error)} /> : (
        <Table>
          <thead><tr><Th>Nomor</Th><Th>Outlet</Th><Th>Dibuat</Th><Th>Dibutuhkan</Th><Th className="text-right">Barang</Th><Th>Status</Th></tr></thead>
          <tbody>
            {requests.data.data.length === 0 && <EmptyRow colSpan={6}>Tidak ada permintaan.</EmptyRow>}
            {requests.data.data.map((r) => (
              <tr key={r.id}>
                <Td className="font-mono"><Link to={`/permintaan-stok/${r.id}`} className="hover:underline">{r.number}</Link></Td>
                <Td>{r.location.name}</Td>
                <Td>{formatDateTime(r.created_at)}<p className="text-xs text-muted-foreground">{r.requested_by?.name}</p></Td>
                <Td>{formatDate(r.needed_date)}</Td>
                <Td className="text-right">{r.line_count}</Td>
                <Td><Badge variant={REQUEST_VARIANT[r.status]}>{STOCK_REQUEST_STATUS_LABEL[r.status]}</Badge></Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
    </div>
  )
}

export function RequestFormPage() {
  const { list, selected, select } = useSelectedLocation()
  const usable = list.filter((l) => l.can.request_stock)
  const location = usable.find((l) => l.id === selected?.id) ?? usable[0] ?? null
  const items = useStockItems()
  const [lines, setLines] = useState<Line[]>([newLine()])
  const [neededDate, setNeededDate] = useState('')
  const [notes, setNotes] = useState('')
  const qc = useQueryClient()
  const navigate = useNavigate()
  const save = useMutation({
    mutationFn: () => http.post<{ data: StockRequestDetail }>('/stock-requests', {
      location_id: location?.id, needed_date: neededDate || null, notes: notes || null, lines: filledLines(lines),
    }),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: STOCK_KEY })
      navigate(`/permintaan-stok/${res.data.id}`)
    },
  })
  const err = save.error instanceof ApiError ? save.error : null

  if (list.length > 0 && usable.length === 0) return <ErrorAlert message="Permintaan stok dibuat oleh kasir di outletnya." />

  return (
    <div className="max-w-4xl">
      <PageHeader title="Buat permintaan stok" purpose="Minta barang dari gudang pusat untuk outlet Anda." />
      <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        <LocationPicker list={usable} value={location?.id ?? null} onChange={select} label="Outlet" />
        <LinesEditor items={items.data?.data ?? []} lines={lines} onChange={setLines} error={err} />
        <div className="grid gap-4 md:grid-cols-[12rem_1fr]">
          <Field id="sr-date" label="Dibutuhkan tanggal (opsional)" error={err?.field('needed_date')}>
            <Input id="sr-date" type="date" value={neededDate} onChange={(e) => setNeededDate(e.target.value)} />
          </Field>
          <Field id="sr-notes" label="Catatan untuk gudang (opsional)"><Input id="sr-notes" value={notes} onChange={(e) => setNotes(e.target.value)} /></Field>
        </div>
        {err && !Object.keys(err.errors).some((k) => k.startsWith('lines')) && <ErrorAlert message={err.message} />}
        <div className="flex gap-2">
          <Button type="submit" disabled={save.isPending || !location}>Kirim permintaan</Button>
          <Button asChild variant="ghost"><Link to="/permintaan-stok">Batal</Link></Button>
        </div>
      </form>
    </div>
  )
}

export function RequestDetailPage() {
  const { id } = useParams()
  const qc = useQueryClient()
  const [mode, setMode] = useState<'none' | 'reject'>('none')
  const [note, setNote] = useState('')
  const request = useQuery({ queryKey: [...STOCK_KEY, 'request', id], queryFn: () => http.get<{ data: StockRequestDetail }>(`/stock-requests/${id}`) })
  const act = useMutation({
    mutationFn: ({ action, body }: { action: string; body?: unknown }) => http.post(`/stock-requests/${id}/${action}`, body),
    onSuccess: () => { setMode('none'); qc.invalidateQueries({ queryKey: STOCK_KEY }) },
  })
  const err = act.error instanceof ApiError ? act.error : null

  if (request.isPending) return <Loading />
  if (request.error) return <ErrorAlert message={errorText(request.error)} />
  const r = request.data.data
  const showWarehouse = r.lines.some((l) => l.warehouse_qty !== null)

  return (
    <div className="max-w-4xl space-y-4">
      <PageHeader title={`Permintaan ${r.number}`} purpose={`${r.location.name} · dibuat ${formatDateTime(r.created_at)} oleh ${r.requested_by?.name ?? '–'}`} />
      <div className="flex flex-wrap items-center gap-3 text-sm">
        <Badge variant={REQUEST_VARIANT[r.status]}>{STOCK_REQUEST_STATUS_LABEL[r.status]}</Badge>
        {r.needed_date && <span>Dibutuhkan {formatDate(r.needed_date)}</span>}
        {r.transfer && <span>Pengiriman <Link to={`/pengiriman/${r.transfer.id}`} className="font-mono hover:underline">{r.transfer.number}</Link></span>}
      </div>
      {r.notes && <p className="text-sm">Catatan: {r.notes}</p>}
      {r.close_note && <p className="text-sm">{r.status === 'REJECTED' ? 'Alasan ditolak' : 'Catatan'} ({r.closed_by?.name}): <span className="font-medium">{r.close_note}</span></p>}

      <Table>
        <thead><tr><Th>Barang</Th><Th className="text-right">Diminta</Th>{showWarehouse && <Th className="text-right">Stok gudang sekarang</Th>}</tr></thead>
        <tbody>
          {r.lines.map((l) => (
            <tr key={l.item.id}>
              <Td>{l.item.name}<p className="font-mono text-xs text-muted-foreground">{l.item.code}</p></Td>
              <Td className="text-right">{formatQty(l.entered_qty)} {l.unit.code}{l.unit.code !== l.item.base_unit.code && <p className="text-xs text-muted-foreground">{formatQty(l.qty)} {l.item.base_unit.code}</p>}</Td>
              {showWarehouse && (
                <Td className={cn('text-right', Number(l.warehouse_qty) < Number(l.qty) && 'text-destructive')}>
                  {formatQty(l.warehouse_qty ?? 0)} {l.item.base_unit.code}
                  {Number(l.warehouse_qty) < Number(l.qty) && <p className="text-xs">kurang — perlu produksi atau kirim sebagian</p>}
                </Td>
              )}
            </tr>
          ))}
        </tbody>
      </Table>

      {mode === 'none' && (r.can.fulfil || r.can.reject || r.can.cancel) && (
        <Card>
          <CardContent className="flex flex-wrap gap-2 pt-6">
            {r.can.fulfil && <Button asChild><Link to={`/pengiriman/baru?jenis=DISTRIBUTION&permintaan=${r.id}`}>Kirim barang</Link></Button>}
            {r.can.reject && <Button variant="outline" onClick={() => { act.reset(); setMode('reject') }}>Tolak</Button>}
            {r.can.cancel && <Button variant="ghost" disabled={act.isPending} onClick={() => act.mutate({ action: 'cancel' })}>Batalkan permintaan</Button>}
          </CardContent>
        </Card>
      )}
      {mode === 'reject' && (
        <form className="flex flex-wrap items-start gap-2" onSubmit={(e) => { e.preventDefault(); act.mutate({ action: 'reject', body: { note } }) }}>
          <div className="min-w-64 flex-1">
            <Field id="sr-reject" label="Alasan penolakan" error={err?.field('note')}><Input id="sr-reject" value={note} onChange={(e) => setNote(e.target.value)} /></Field>
          </div>
          <div className="flex gap-2 md:pt-[24px]">
            <Button type="submit" variant="destructive" disabled={act.isPending}>Tolak permintaan</Button>
            <Button type="button" variant="ghost" onClick={() => setMode('none')}>Batal</Button>
          </div>
        </form>
      )}
      {err && !err.field('note') && <ErrorAlert message={err.message} />}
      <p><Link to="/permintaan-stok" className="text-sm hover:underline">← Semua permintaan</Link></p>
    </div>
  )
}
