import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { ErrorAlert, InfoBanner, Loading, PageHeader } from '@/components/ui/feedback'
import { Field } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { EmptyRow, Table, Td, Th } from '@/components/ui/table'
import { ApiError, errorText, http } from '@/lib/api'
import { WASTE_REASON_LABEL, formatDateTime } from '@/lib/format'
import type { Paginated, StockBatchRow, StockEntry } from '@/lib/types'
import { LinesEditor, type ExtraColumn } from './LinesEditor'
import { filledLines, newLine, type Line } from './lines'
import { LocationPicker } from './LocationPicker'
import { MovementsTable } from './MovementsTable'
import { STOCK_KEY, useSelectedLocation, useStockItems } from './queries'

const KIND_LABEL = { OPENING: 'Saldo awal', WASTE: 'Waste' } as const

/** Saldo awal: Owner atau Finance, satu kali per barang per lokasi, dengan harga pokoknya. */
export function OpeningFormPage() {
  const { list, selected, select } = useSelectedLocation()
  const usable = list.filter((l) => l.can.opening)
  const location = usable.find((l) => l.id === selected?.id) ?? usable[0] ?? null
  const items = useStockItems()
  const [lines, setLines] = useState<Line[]>([newLine({ unit_cost: '', batch_no: '', expiry_date: '' })])
  const [notes, setNotes] = useState('')
  const qc = useQueryClient()
  const navigate = useNavigate()
  const save = useMutation({
    mutationFn: () => http.post<{ data: StockEntry }>('/stock/openings', { location_id: location?.id, notes: notes || null, lines: filledLines(lines) }),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: STOCK_KEY })
      navigate(`/stok/catatan/${res.data.id}`)
    },
  })
  const err = save.error instanceof ApiError ? save.error : null
  const isWarehouse = location?.type === 'WAREHOUSE'

  const extra: ExtraColumn[] = [
    { key: 'unit_cost', label: 'Harga pokok per satuan (Rp)', className: 'px-3 py-2 w-44', render: (l, set, i) => <Input aria-label={`Harga pokok baris ${i + 1}`} inputMode="numeric" value={l.extra.unit_cost} onChange={(e) => set(e.target.value)} /> },
    ...(isWarehouse ? [
      { key: 'batch_no', label: 'No. batch (opsional)', className: 'px-3 py-2 w-40', render: (l: Line, set: (v: string) => void, i: number) => <Input aria-label={`Batch baris ${i + 1}`} value={l.extra.batch_no} onChange={(e) => set(e.target.value)} /> },
      { key: 'expiry_date', label: 'Kedaluwarsa (opsional)', className: 'px-3 py-2 w-44', render: (l: Line, set: (v: string) => void, i: number) => <Input aria-label={`Kedaluwarsa baris ${i + 1}`} type="date" value={l.extra.expiry_date} onChange={(e) => set(e.target.value)} /> },
    ] : []),
  ]

  return (
    <div className="max-w-6xl">
      <PageHeader title="Saldo awal stok" purpose="Jumlah barang dan harga pokoknya saat mulai memakai aplikasi." />
      <InfoBanner className="mb-4">
        Isi satu kali per barang per lokasi, dari hasil hitung staf di lokasi itu. Harga pokok diisi per satuan yang dipilih (mis. per kg). Setelah barang punya
        catatan stok, koreksi jumlahnya lewat stock opname. {isWarehouse ? 'Di gudang, batch dan tanggal kedaluwarsa boleh diisi; bila batch kosong, sistem memakai nomor dokumen.' : ''}
      </InfoBanner>
      <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        <LocationPicker list={usable} value={location?.id ?? null} onChange={(id) => { select(id); setLines([newLine({ unit_cost: '', batch_no: '', expiry_date: '' })]) }} />
        <LinesEditor items={items.data?.data ?? []} lines={lines} onChange={setLines} extra={extra} error={err} />
        <Field id="opening-notes" label="Catatan (opsional)"><Input id="opening-notes" value={notes} onChange={(e) => setNotes(e.target.value)} /></Field>
        {err && !Object.keys(err.errors).some((k) => k.startsWith('lines.')) && <ErrorAlert message={err.message} />}
        <div className="flex gap-2">
          <Button type="submit" disabled={save.isPending || !location}>Simpan saldo awal</Button>
          <Button asChild variant="ghost"><Link to={`/stok${location ? `?lokasi=${location.id}` : ''}`}>Batal</Link></Button>
        </div>
      </form>
    </div>
  )
}

/** Waste: dicatat langsung petugas lokasi dengan alasan; dipantau Owner (OD-21). */
export function WasteFormPage() {
  const { list, selected, select } = useSelectedLocation()
  const usable = list.filter((l) => l.can.operate)
  const location = usable.find((l) => l.id === selected?.id) ?? usable[0] ?? null
  const items = useStockItems()
  const isWarehouse = location?.type === 'WAREHOUSE'
  const batches = useQuery({
    queryKey: [...STOCK_KEY, 'batches', location?.id, 'all'],
    queryFn: () => http.get<{ data: StockBatchRow[] }>(`/stock/batches?location_id=${location?.id}`),
    enabled: !!location && isWarehouse,
  })
  const [lines, setLines] = useState<Line[]>([newLine({ batch_id: '' })])
  const [reason, setReason] = useState('DAMAGED')
  const [notes, setNotes] = useState('')
  const qc = useQueryClient()
  const navigate = useNavigate()
  const save = useMutation({
    mutationFn: () => http.post<{ data: StockEntry }>('/stock/wastes', {
      location_id: location?.id, reason_code: reason, notes,
      lines: filledLines(lines).map((l) => ({ ...l, batch_id: l.batch_id ? Number(l.batch_id) : null })),
    }),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: STOCK_KEY })
      navigate(`/stok/catatan/${res.data.id}`)
    },
  })
  const err = save.error instanceof ApiError ? save.error : null

  const extra: ExtraColumn[] = isWarehouse ? [{
    key: 'batch_id', label: 'Batch', className: 'px-3 py-2 w-56',
    render: (l, set, i) => (
      <Select aria-label={`Batch baris ${i + 1}`} value={l.extra.batch_id} onChange={(e) => set(e.target.value)}>
        <option value="">Otomatis (paling dekat kedaluwarsa)</option>
        {batches.data?.data.filter((b) => String(b.item_id) === l.item_id).map((b) => <option key={b.id} value={b.id}>{b.batch_no}</option>)}
      </Select>
    ),
  }] : []

  if (usable.length === 0 && list.length > 0) {
    return <ErrorAlert message="Waste dicatat oleh staf gudang atau kasir di lokasinya." />
  }

  return (
    <div className="max-w-5xl">
      <PageHeader title="Catat waste" purpose="Barang rusak, kedaluwarsa, atau tumpah yang dibuang." />
      <InfoBanner className="mb-4">Stok langsung berkurang tanpa menunggu persetujuan. Setiap waste wajib diberi alasan dan keterangan, dan tampil ke Owner.</InfoBanner>
      <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        <LocationPicker list={usable} value={location?.id ?? null} onChange={(id) => { select(id); setLines([newLine({ batch_id: '' })]) }} />
        <div className="grid gap-4 md:grid-cols-[14rem_1fr]">
          <Field id="waste-reason" label="Alasan" error={err?.field('reason_code')}>
            <Select id="waste-reason" value={reason} onChange={(e) => setReason(e.target.value)}>
              {Object.entries(WASTE_REASON_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </Select>
          </Field>
          <Field id="waste-notes" label="Keterangan" hint="Mis. freezer mati semalam, kemasan sobek." error={err?.field('notes')}>
            <Input id="waste-notes" value={notes} onChange={(e) => setNotes(e.target.value)} />
          </Field>
        </div>
        <LinesEditor items={items.data?.data ?? []} lines={lines} onChange={setLines} extra={extra} error={err} />
        {err && !err.field('notes') && !Object.keys(err.errors).some((k) => k.startsWith('lines.')) && <ErrorAlert message={err.message} />}
        <div className="flex gap-2">
          <Button type="submit" variant="destructive" disabled={save.isPending || !location}>Catat waste</Button>
          <Button asChild variant="ghost"><Link to={`/stok${location ? `?lokasi=${location.id}` : ''}`}>Batal</Link></Button>
        </div>
      </form>
    </div>
  )
}

/** Daftar saldo awal dan waste. */
export function EntriesPage() {
  const { list, selected, select } = useSelectedLocation()
  const [kind, setKind] = useState('WASTE')
  const entries = useQuery({
    queryKey: [...STOCK_KEY, 'entries', selected?.id, kind],
    queryFn: () => http.get<Paginated<StockEntry>>(`/stock/entries?location_id=${selected?.id}&kind=${kind}`),
    enabled: !!selected,
  })

  return (
    <div className="max-w-5xl">
      <PageHeader title="Waste & saldo awal" purpose="Catatan barang yang dibuang dan saldo awal per lokasi." />
      <div className="mb-4 flex flex-wrap items-center gap-4">
        <LocationPicker list={list} value={selected?.id ?? null} onChange={select} />
        <Select aria-label="Jenis catatan" className="w-40" value={kind} onChange={(e) => setKind(e.target.value)}>
          <option value="WASTE">Waste</option>
          <option value="OPENING">Saldo awal</option>
        </Select>
      </div>
      {entries.isPending ? <Loading /> : entries.error ? <ErrorAlert message={errorText(entries.error)} /> : (
        <Table>
          <thead><tr><Th>Nomor</Th><Th>Waktu</Th><Th>Alasan / catatan</Th><Th className="text-right">Baris</Th><Th>Oleh</Th></tr></thead>
          <tbody>
            {entries.data.data.length === 0 && <EmptyRow colSpan={5}>Belum ada catatan.</EmptyRow>}
            {entries.data.data.map((e) => (
              <tr key={e.id}>
                <Td className="font-mono"><Link to={`/stok/catatan/${e.id}`} className="hover:underline">{e.number}</Link></Td>
                <Td>{formatDateTime(e.created_at)}</Td>
                <Td>{e.reason_code && <Badge variant="warning">{WASTE_REASON_LABEL[e.reason_code] ?? e.reason_code}</Badge>} {e.notes}</Td>
                <Td className="text-right">{e.line_count}</Td>
                <Td>{e.created_by?.name}</Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
    </div>
  )
}

export function EntryDetailPage() {
  const { id } = useParams()
  const entry = useQuery({ queryKey: [...STOCK_KEY, 'entry', id], queryFn: () => http.get<{ data: StockEntry }>(`/stock/entries/${id}`) })
  if (entry.isPending) return <Loading />
  if (entry.error) return <ErrorAlert message={errorText(entry.error)} />
  const e = entry.data.data

  return (
    <div className="max-w-6xl">
      <PageHeader title={`${KIND_LABEL[e.kind]} ${e.number}`} purpose={`${e.location.name} · ${formatDateTime(e.created_at)} · oleh ${e.created_by?.name ?? '–'}`} />
      <Card className="mb-4">
        <CardContent className="space-y-1 pt-6 text-sm">
          {e.reason_code && <p>Alasan: <span className="font-medium">{WASTE_REASON_LABEL[e.reason_code] ?? e.reason_code}</span></p>}
          <p>Keterangan: {e.notes || '–'}</p>
        </CardContent>
      </Card>
      <MovementsTable rows={e.movements ?? []} canSeeCost={!!e.can_see_cost} showItem />
      <p className="mt-4"><Link to={`/stok?lokasi=${e.location.id}`} className="text-sm hover:underline">← Kembali ke stok {e.location.name}</Link></p>
    </div>
  )
}
