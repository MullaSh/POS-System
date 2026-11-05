import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { ErrorAlert, FieldError, InfoBanner, Loading, PageHeader } from '@/components/ui/feedback'
import { Field } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { EmptyRow, Table, Td, Th } from '@/components/ui/table'
import { ApiError, errorText, http } from '@/lib/api'
import { RESOLUTION_LABEL, RETURN_REASON_LABEL, TRANSFER_STATUS_LABEL, formatDateTime, formatQty, formatRupiah, formatSignedQty } from '@/lib/format'
import type { Location, Paginated, StockRequestDetail, StockTransferDetail, StockTransferLine, StockTransferSummary, TransferKind, TransferResolution, TransferStatus } from '@/lib/types'
import { cn } from '@/lib/utils'
import { LinesEditor } from './LinesEditor'
import { filledLines, newLine, type Line } from './lines'
import { LocationPicker } from './LocationPicker'
import { STOCK_KEY, useSelectedLocation, useStockItems } from './queries'

const STATUS_VARIANT: Record<TransferStatus, 'default' | 'success' | 'warning' | 'destructive'> = { IN_TRANSIT: 'warning', RECEIVED: 'success', CANCELLED: 'default' }
const KIND_LABEL: Record<TransferKind, string> = { DISTRIBUTION: 'Kirim ke outlet', RETURN: 'Retur ke gudang' }

export function TransfersPage() {
  const [tab, setTab] = useState<'OPEN' | 'DONE'>('OPEN')
  const { list } = useSelectedLocation()
  const canSend = list.some((l) => l.type === 'WAREHOUSE' && l.can.operate)
  const transfers = useQuery({
    queryKey: [...STOCK_KEY, 'transfers', tab],
    queryFn: () => http.get<Paginated<StockTransferSummary>>(`/stock-transfers?status=${tab}`),
  })

  return (
    <div className="max-w-5xl">
      <PageHeader
        title="Pengiriman barang"
        purpose="Kiriman gudang ke outlet dan retur outlet ke gudang."
        actions={canSend && <Button asChild><Link to="/pengiriman/baru?jenis=DISTRIBUTION">Kirim ke outlet</Link></Button>}
      />
      <InfoBanner className="mb-4">
        Stok pengirim berkurang saat barang dikirim. Penerima mengisi jumlah yang benar-benar diterima dalam kondisi baik; hanya jumlah itu yang menambah stoknya.
        Bila ada selisih, pengirim wajib menjelaskannya.
      </InfoBanner>
      <div role="tablist" className="mb-4 inline-flex rounded-lg border border-border p-1">
        {([['OPEN', 'Perlu tindakan'], ['DONE', 'Selesai']] as const).map(([k, label]) => (
          <button key={k} role="tab" aria-selected={tab === k} onClick={() => setTab(k)}
            className={cn('rounded-md px-3 py-1.5 text-sm', tab === k ? 'bg-muted font-medium' : 'text-muted-foreground')}>{label}</button>
        ))}
      </div>
      {transfers.isPending ? <Loading /> : transfers.error ? <ErrorAlert message={errorText(transfers.error)} /> : (
        <Table>
          <thead><tr><Th>Nomor</Th><Th>Jenis</Th><Th>Dari → ke</Th><Th>Dikirim</Th><Th>Status</Th></tr></thead>
          <tbody>
            {transfers.data.data.length === 0 && <EmptyRow colSpan={5}>{tab === 'OPEN' ? 'Tidak ada pengiriman yang perlu ditindaklanjuti.' : 'Belum ada pengiriman.'}</EmptyRow>}
            {transfers.data.data.map((t) => (
              <tr key={t.id}>
                <Td className="font-mono"><Link to={`/pengiriman/${t.id}`} className="hover:underline">{t.number}</Link></Td>
                <Td>{KIND_LABEL[t.kind]}</Td>
                <Td>{t.from_location.name} → {t.to_location.name}</Td>
                <Td>{formatDateTime(t.sent_at)}<p className="text-xs text-muted-foreground">{t.sent_by?.name}</p></Td>
                <Td>
                  <Badge variant={STATUS_VARIANT[t.status]}>{TRANSFER_STATUS_LABEL[t.status]}</Badge>
                  {t.unexplained_count > 0 && <> <Badge variant="destructive">{t.unexplained_count} selisih belum dijelaskan</Badge></>}
                  {t.can.receive && <p className="mt-1 text-xs font-medium text-amber-700 dark:text-amber-400">Menunggu Anda menerima</p>}
                </Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
    </div>
  )
}

/** Kirim barang: gudang → outlet (boleh dari permintaan stok), atau retur outlet → gudang. */
export function TransferFormPage() {
  const [params] = useSearchParams()
  const kind: TransferKind = params.get('jenis') === 'RETURN' ? 'RETURN' : 'DISTRIBUTION'
  const requestId = params.get('permintaan')
  const request = useQuery({
    queryKey: [...STOCK_KEY, 'request', requestId],
    queryFn: () => http.get<{ data: StockRequestDetail }>(`/stock-requests/${requestId}`),
    enabled: !!requestId,
  })
  if (requestId && request.isPending) return <Loading />
  if (requestId && request.error) return <ErrorAlert message={errorText(request.error)} />
  return <TransferForm key={`${kind}-${requestId ?? ''}`} kind={kind} request={request.data?.data ?? null} />
}

function TransferForm({ kind, request }: { kind: TransferKind; request: StockRequestDetail | null }) {
  const { list, selected, select } = useSelectedLocation()
  const items = useStockItems()
  const outlets = useQuery({ queryKey: ['locations'], queryFn: () => http.get<{ data: Location[] }>('/locations'), enabled: kind === 'DISTRIBUTION' })
  const [lines, setLines] = useState<Line[]>(() =>
    request ? request.lines.map((l) => ({ ...newLine(), item_id: String(l.item.id), unit_id: String(l.unit.id), qty: String(Number(l.entered_qty)) })) : [newLine()])
  const [toId, setToId] = useState(request ? String(request.location.id) : '')
  const [reason, setReason] = useState('EXCESS')
  const [notes, setNotes] = useState('')

  const returnFrom = list.filter((l) => l.type === 'OUTLET' && l.can.operate)
  const from = kind === 'RETURN' ? (returnFrom.find((l) => l.id === selected?.id) ?? returnFrom[0] ?? null) : list.find((l) => l.type === 'WAREHOUSE' && l.can.operate) ?? null
  const qc = useQueryClient()
  const navigate = useNavigate()
  const save = useMutation({
    mutationFn: () => http.post<{ data: StockTransferDetail }>('/stock-transfers', {
      kind,
      to_location_id: kind === 'DISTRIBUTION' ? Number(toId) || null : null,
      from_location_id: kind === 'RETURN' ? from?.id : null,
      stock_request_id: request?.id ?? null,
      reason_code: kind === 'RETURN' ? reason : null,
      notes: notes || null,
      lines: filledLines(lines),
    }),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: STOCK_KEY })
      navigate(`/pengiriman/${res.data.id}`)
    },
  })
  const err = save.error instanceof ApiError ? save.error : null

  if (list.length > 0 && !from) {
    return <ErrorAlert message={kind === 'RETURN' ? 'Retur dikirim oleh kasir dari outletnya.' : 'Kiriman ke outlet dibuat oleh staf gudang.'} />
  }

  return (
    <div className="max-w-4xl">
      <PageHeader title={KIND_LABEL[kind]} purpose={kind === 'RETURN' ? 'Kembalikan barang dari outlet ke gudang pusat.' : 'Kirim barang dari gudang pusat ke outlet.'} />
      <InfoBanner className="mb-4">
        Stok {from?.name ?? 'pengirim'} langsung berkurang saat disimpan. {kind === 'DISTRIBUTION' && 'Barang diambil dari batch yang paling dekat kedaluwarsa. '}
        Bila salah kirim, kiriman bisa dibatalkan selama belum diterima.
      </InfoBanner>
      {request && (
        <p className="mb-4 text-sm">Memenuhi permintaan <Link className="font-mono hover:underline" to={`/permintaan-stok/${request.id}`}>{request.number}</Link> dari {request.location.name}. Jumlah boleh diubah bila stok gudang kurang.</p>
      )}
      <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {kind === 'DISTRIBUTION' ? (
          <Field id="tr-to" label="Outlet tujuan" error={err?.field('to_location_id') ?? err?.field('stock_request_id')}>
            <Select id="tr-to" className="md:w-72" value={toId} disabled={!!request} onChange={(e) => setToId(e.target.value)}>
              <option value="">Pilih outlet…</option>
              {outlets.data?.data.filter((l) => l.type === 'OUTLET' && l.status === 'ACTIVE').map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
            </Select>
          </Field>
        ) : (
          <div className="flex flex-wrap items-end gap-4">
            <LocationPicker list={returnFrom} value={from?.id ?? null} onChange={select} label="Dari outlet" />
            <Field id="tr-reason" label="Alasan retur" error={err?.field('reason_code')}>
              <Select id="tr-reason" className="w-56" value={reason} onChange={(e) => setReason(e.target.value)}>
                {Object.entries(RETURN_REASON_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
              </Select>
            </Field>
          </div>
        )}
        <LinesEditor items={items.data?.data ?? []} lines={lines} onChange={setLines} qtyLabel="Jumlah kirim" error={err} />
        <Field id="tr-notes" label="Catatan (opsional)"><Input id="tr-notes" value={notes} onChange={(e) => setNotes(e.target.value)} /></Field>
        {err && !Object.keys(err.errors).some((k) => k.startsWith('lines') || ['to_location_id', 'reason_code', 'stock_request_id'].includes(k)) && <ErrorAlert message={err.message} />}
        <div className="flex gap-2">
          <Button type="submit" disabled={save.isPending}>Kirim barang</Button>
          <Button asChild variant="ghost"><Link to={request ? `/permintaan-stok/${request.id}` : '/pengiriman'}>Batal</Link></Button>
        </div>
      </form>
    </div>
  )
}

export function TransferDetailPage() {
  const { id } = useParams()
  const qc = useQueryClient()
  const transfer = useQuery({ queryKey: [...STOCK_KEY, 'transfer', id], queryFn: () => http.get<{ data: StockTransferDetail }>(`/stock-transfers/${id}`) })
  const refresh = () => qc.invalidateQueries({ queryKey: STOCK_KEY })

  if (transfer.isPending) return <Loading />
  if (transfer.error) return <ErrorAlert message={errorText(transfer.error)} />
  const t = transfer.data.data

  return (
    <div className="max-w-5xl space-y-4">
      <PageHeader title={`${KIND_LABEL[t.kind]} ${t.number}`} purpose={`${t.from_location.name} → ${t.to_location.name} · dikirim ${formatDateTime(t.sent_at)} oleh ${t.sent_by?.name ?? '–'}`} />
      <div className="flex flex-wrap items-center gap-3 text-sm">
        <Badge variant={STATUS_VARIANT[t.status]}>{TRANSFER_STATUS_LABEL[t.status]}</Badge>
        {t.unexplained_count > 0 && <Badge variant="destructive">{t.unexplained_count} selisih belum dijelaskan</Badge>}
        {t.stock_request && <span>Dari permintaan <Link to={`/permintaan-stok/${t.stock_request.id}`} className="font-mono hover:underline">{t.stock_request.number}</Link></span>}
        {t.reason_code && <span>Alasan retur: {RETURN_REASON_LABEL[t.reason_code] ?? t.reason_code}</span>}
      </div>
      {t.notes && <p className="text-sm">Catatan pengirim: {t.notes}</p>}
      {t.received_at && <p className="text-sm">Diterima {formatDateTime(t.received_at)} oleh {t.received_by?.name}{t.receive_note ? ` — ${t.receive_note}` : ''}</p>}
      {t.cancelled_at && <p className="text-sm">Dibatalkan {formatDateTime(t.cancelled_at)} oleh {t.cancelled_by?.name} — {t.cancel_note}</p>}

      {t.can.receive ? <ReceiveForm transfer={t} onDone={refresh} /> : <LinesTable transfer={t} onDone={refresh} />}
      {t.can.cancel && <CancelForm transfer={t} onDone={refresh} />}
      <p><Link to="/pengiriman" className="text-sm hover:underline">← Semua pengiriman</Link></p>
    </div>
  )
}

function LinesTable({ transfer: t, onDone }: { transfer: StockTransferDetail; onDone: () => void }) {
  return (
    <Table>
      <thead>
        <tr>
          <Th>Barang</Th><Th className="text-right">Dikirim</Th><Th className="text-right">Diterima</Th><Th className="text-right">Selisih</Th>
          {t.can_see_cost && <Th className="text-right">Nilai selisih</Th>}
          <Th>Penjelasan</Th>
        </tr>
      </thead>
      <tbody>
        {t.lines.map((l) => (
          <tr key={l.id}>
            <Td>{l.item.name}<p className="font-mono text-xs text-muted-foreground">{l.item.code}</p></Td>
            <Td className="text-right">{qtyText(l.sent_entered, l.sent_qty, l)}</Td>
            <Td className="text-right">{l.received_qty === null ? '–' : qtyText(l.received_entered, l.received_qty, l)}</Td>
            <Td className={cn('text-right font-medium', l.difference && Number(l.difference) !== 0 ? 'text-destructive' : 'text-muted-foreground')}>
              {l.difference === null ? '–' : Number(l.difference) === 0 ? 'sesuai' : `${formatSignedQty(-Number(l.difference))} ${l.item.base_unit.code}`}
            </Td>
            {t.can_see_cost && <Td className="text-right">{l.difference_value === null || Number(l.difference_value) === 0 ? '–' : formatRupiah(Math.round(Number(l.difference_value)))}</Td>}
            <Td className="min-w-64">
              {l.resolution && (
                <p className="text-sm">{RESOLUTION_LABEL[l.resolution]}<span className="block text-xs text-muted-foreground">{l.resolution_note} · {l.resolved_by?.name}, {formatDateTime(l.resolved_at)}</span></p>
              )}
              {l.needs_resolution && (t.can.resolve ? <ResolveForm transfer={t} line={l} onDone={onDone} /> : <span className="text-sm text-amber-700 dark:text-amber-400">Menunggu penjelasan pengirim</span>)}
            </Td>
          </tr>
        ))}
      </tbody>
    </Table>
  )
}

function qtyText(entered: string | null, base: string, l: StockTransferLine) {
  return (
    <>
      {formatQty(entered ?? base)} {l.unit.code}
      {l.unit.code !== l.item.base_unit.code && <span className="block text-xs text-muted-foreground">{formatQty(base)} {l.item.base_unit.code}</span>}
    </>
  )
}

function ReceiveForm({ transfer: t, onDone }: { transfer: StockTransferDetail; onDone: () => void }) {
  const [qty, setQty] = useState<Record<number, string>>(() => Object.fromEntries(t.lines.map((l) => [l.id, String(Number(l.sent_entered))])))
  const [note, setNote] = useState('')
  const save = useMutation({
    mutationFn: () => http.post(`/stock-transfers/${t.id}/receive`, { note: note || null, lines: t.lines.map((l) => ({ line_id: l.id, received_qty: (qty[l.id] ?? '').replace(',', '.') })) }),
    onSuccess: onDone,
  })
  const err = save.error instanceof ApiError ? save.error : null

  return (
    <Card>
      <CardHeader><CardTitle className="text-base">Terima barang</CardTitle></CardHeader>
      <CardContent>
        <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
          <p className="text-sm text-muted-foreground">Hitung barang yang datang. Isi jumlah yang diterima dalam kondisi baik; barang rusak atau kurang jangan dihitung. Jumlah yang sudah dikonfirmasi tidak dapat diubah.</p>
          <Table>
            <thead><tr><Th>Barang</Th><Th className="text-right">Dikirim</Th><Th className="w-40">Diterima baik</Th><Th>Satuan</Th></tr></thead>
            <tbody>
              {t.lines.map((l, i) => (
                <tr key={l.id}>
                  <Td>{l.item.name}</Td>
                  <Td className="text-right">{formatQty(l.sent_entered)} {l.unit.code}</Td>
                  <Td>
                    <Input aria-label={`Diterima ${l.item.name}`} inputMode="decimal" value={qty[l.id] ?? ''} onChange={(e) => setQty({ ...qty, [l.id]: e.target.value })} />
                    <FieldError message={err?.field(`lines.${i}.received_qty`)} />
                  </Td>
                  <Td>{l.unit.code}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
          <Field id="rcv-note" label="Catatan penerimaan (opsional)" hint="Mis. 2 pcs pecah, kemasan basah."><Input id="rcv-note" value={note} onChange={(e) => setNote(e.target.value)} /></Field>
          {err && !Object.keys(err.errors).some((k) => k.startsWith('lines.')) && <ErrorAlert message={err.message} />}
          <Button type="submit" disabled={save.isPending}>Konfirmasi penerimaan</Button>
        </form>
      </CardContent>
    </Card>
  )
}

function ResolveForm({ transfer: t, line, onDone }: { transfer: StockTransferDetail; line: StockTransferLine; onDone: () => void }) {
  const short = Number(line.difference) > 0
  const options: TransferResolution[] = short ? ['SHORT_SHIPPED', 'LOST_IN_TRANSIT'] : ['OVER_SENT']
  const [resolution, setResolution] = useState<TransferResolution>(options[0])
  const [note, setNote] = useState('')
  const save = useMutation({
    mutationFn: () => http.post(`/stock-transfers/${t.id}/lines/${line.id}/resolve`, { resolution, note }),
    onSuccess: onDone,
  })
  const err = save.error instanceof ApiError ? save.error : null

  return (
    <form className="space-y-2" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
      <p className="text-sm font-medium text-destructive">{short ? `Diterima kurang ${formatQty(line.difference ?? 0)} ${line.item.base_unit.code}` : `Diterima lebih ${formatQty(-Number(line.difference))} ${line.item.base_unit.code}`}</p>
      <Select aria-label={`Penjelasan ${line.item.name}`} value={resolution} onChange={(e) => setResolution(e.target.value as TransferResolution)}>
        {options.map((o) => <option key={o} value={o}>{RESOLUTION_LABEL[o]}</option>)}
      </Select>
      <Input aria-label={`Keterangan ${line.item.name}`} placeholder="Keterangan (wajib)" value={note} onChange={(e) => setNote(e.target.value)} />
      <FieldError message={err?.field('note') ?? err?.field('resolution') ?? (err && !err.field('note') ? err.message : undefined)} />
      <Button type="submit" size="sm" disabled={save.isPending}>Simpan penjelasan</Button>
    </form>
  )
}

function CancelForm({ transfer: t, onDone }: { transfer: StockTransferDetail; onDone: () => void }) {
  const [open, setOpen] = useState(false)
  const [note, setNote] = useState('')
  const save = useMutation({ mutationFn: () => http.post(`/stock-transfers/${t.id}/cancel`, { note }), onSuccess: onDone })
  const err = save.error instanceof ApiError ? save.error : null
  if (!open) return <Button variant="ghost" onClick={() => setOpen(true)}>Batalkan kiriman</Button>

  return (
    <form className="flex flex-wrap items-start gap-2" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
      <div className="min-w-64 flex-1">
        <Field id="tr-cancel" label="Alasan pembatalan" hint="Barang kembali ke stok pengirim." error={err?.field('note')}>
          <Input id="tr-cancel" value={note} onChange={(e) => setNote(e.target.value)} />
        </Field>
      </div>
      <div className="flex gap-2 md:pt-[24px]">
        <Button type="submit" variant="destructive" disabled={save.isPending}>Batalkan kiriman</Button>
        <Button type="button" variant="ghost" onClick={() => setOpen(false)}>Tutup</Button>
      </div>
      {err && !err.field('note') && <ErrorAlert message={err.message} />}
    </form>
  )
}
