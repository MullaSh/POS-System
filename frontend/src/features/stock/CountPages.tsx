import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { ErrorAlert, FieldError, InfoBanner, Loading, PageHeader } from '@/components/ui/feedback'
import { Field } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { EmptyRow, Table, Td, Th } from '@/components/ui/table'
import { APPROVALS_KEY } from '@/features/approvals/queries'
import { ApiError, errorText, http } from '@/lib/api'
import { APPROVAL_STATUS_LABEL, ITEM_TYPE_LABEL, formatDateTime, formatQty, formatRupiah, formatSignedQty } from '@/lib/format'
import type { ApprovalStatus, ItemWithUnits, Paginated, StockCountDetail, StockCountSummary } from '@/lib/types'
import { LocationPicker } from './LocationPicker'
import { STOCK_KEY, useSelectedLocation, useStockItems } from './queries'

const STATUS_VARIANT: Record<ApprovalStatus, 'default' | 'success' | 'warning' | 'destructive'> = {
  PENDING: 'warning', RETURNED: 'warning', APPROVED: 'success', REJECTED: 'destructive', CANCELLED: 'default',
}

type Counted = Record<number, { qty: string; unit_id: string }>

/**
 * Lembar hitung. Saldo sistem tidak ditampilkan saat menghitung supaya hasil hitung tidak
 * terpengaruh (hitung buta; usulan). Barang yang dikosongkan tidak ikut dihitung.
 */
function CountSheet({ items, initial, error, pending, submitLabel, onSubmit, onCancel }: {
  items: ItemWithUnits[]
  initial?: Counted
  error: ApiError | null
  pending: boolean
  submitLabel: string
  onSubmit: (lines: { item_id: number; unit_id: number; counted_qty: string }[], reason: string) => void
  onCancel?: () => void
}) {
  const [counted, setCounted] = useState<Counted>(initial ?? {})
  const [reason, setReason] = useState('')
  const [search, setSearch] = useState('')
  const shown = items.filter((i) => !search || `${i.name} ${i.code}`.toLowerCase().includes(search.toLowerCase()))
  const filled = Object.entries(counted).filter(([, v]) => v.qty.trim() !== '')
  const set = (item: ItemWithUnits, patch: Partial<{ qty: string; unit_id: string }>) =>
    setCounted((c) => ({ ...c, [item.id]: { qty: c[item.id]?.qty ?? '', unit_id: c[item.id]?.unit_id ?? String(item.base_unit.id), ...patch } }))

  return (
    <form
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault()
        onSubmit(filled.map(([id, v]) => ({ item_id: Number(id), unit_id: Number(v.unit_id), counted_qty: v.qty.replace(',', '.') })), reason)
      }}
    >
      <Input aria-label="Cari barang" placeholder="Cari barang…" className="w-64" value={search} onChange={(e) => setSearch(e.target.value)} />
      <Table>
        <thead><tr><Th>Barang</Th><Th>Kelompok</Th><Th className="w-40">Hasil hitung</Th><Th className="w-32">Satuan</Th></tr></thead>
        <tbody>
          {shown.length === 0 && <EmptyRow colSpan={4}>Tidak ada barang.</EmptyRow>}
          {shown.map((item) => (
            <tr key={item.id}>
              <Td><span className="font-medium">{item.name}</span><p className="font-mono text-xs text-muted-foreground">{item.code}</p></Td>
              <Td className="text-muted-foreground">{ITEM_TYPE_LABEL[item.item_type]}</Td>
              <Td><Input aria-label={`Hasil hitung ${item.name}`} inputMode="decimal" placeholder="tidak dihitung" value={counted[item.id]?.qty ?? ''} onChange={(e) => set(item, { qty: e.target.value })} /></Td>
              <Td>
                <Select aria-label={`Satuan ${item.name}`} value={counted[item.id]?.unit_id ?? String(item.base_unit.id)} onChange={(e) => set(item, { unit_id: e.target.value })}>
                  {item.units.map((u) => <option key={u.unit_id} value={u.unit_id}>{u.unit.code}</option>)}
                </Select>
              </Td>
            </tr>
          ))}
        </tbody>
      </Table>
      <FieldError message={error?.field('lines') ?? Object.entries(error?.errors ?? {}).find(([k]) => k.startsWith('lines.'))?.[1]?.[0]} />
      <Field id="count-reason" label="Catatan untuk Owner (opsional)" hint="Mis. opname mingguan, atau penjelasan selisih yang sudah diketahui.">
        <Input id="count-reason" value={reason} onChange={(e) => setReason(e.target.value)} />
      </Field>
      {error && !Object.keys(error.errors).some((k) => k.startsWith('lines')) && <ErrorAlert message={error.message} />}
      <div className="flex items-center gap-3">
        <Button type="submit" disabled={pending || filled.length === 0}>{submitLabel}</Button>
        {onCancel && <Button type="button" variant="ghost" onClick={onCancel}>Batal</Button>}
        <span className="text-sm text-muted-foreground">{filled.length} barang terisi</span>
      </div>
    </form>
  )
}

/** Stock opname baru oleh petugas lokasi; penyesuaian berlaku setelah disetujui Owner (OD-21). */
export function CountFormPage() {
  const { list, selected, select } = useSelectedLocation()
  const usable = list.filter((l) => l.can.operate)
  const location = usable.find((l) => l.id === selected?.id) ?? usable[0] ?? null
  const items = useStockItems()
  const qc = useQueryClient()
  const navigate = useNavigate()
  const save = useMutation({
    mutationFn: ({ lines, reason }: { lines: unknown[]; reason: string }) =>
      http.post<{ data: StockCountDetail }>('/stock/counts', { location_id: location?.id, reason: reason || null, lines }),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: STOCK_KEY })
      qc.invalidateQueries({ queryKey: APPROVALS_KEY })
      navigate(`/stok/opname/${res.data.id}`)
    },
  })

  if (list.length > 0 && usable.length === 0) return <ErrorAlert message="Stock opname dicatat oleh staf gudang atau kasir di lokasinya." />

  return (
    <div className="max-w-5xl">
      <PageHeader title="Stock opname" purpose="Hitung barang yang ada secara fisik, lalu catat hasilnya." />
      <InfoBanner className="mb-4">
        Isi hanya barang yang dihitung. Setelah dikirim, sistem membandingkan dengan saldo saat itu. Stok baru disesuaikan setelah Owner menyetujui; penjualan
        dan kiriman selama menunggu tetap terhitung.
      </InfoBanner>
      <div className="mb-4"><LocationPicker list={usable} value={location?.id ?? null} onChange={select} /></div>
      {items.isPending ? <Loading /> : (
        <CountSheet
          key={location?.id}
          items={items.data?.data ?? []}
          error={save.error instanceof ApiError ? save.error : null}
          pending={save.isPending}
          submitLabel="Kirim ke Owner"
          onSubmit={(lines, reason) => save.mutate({ lines, reason })}
        />
      )}
    </div>
  )
}

export function CountsPage() {
  const { list, selected, select } = useSelectedLocation()
  const counts = useQuery({
    queryKey: [...STOCK_KEY, 'counts', selected?.id],
    queryFn: () => http.get<Paginated<StockCountSummary>>(`/stock/counts?location_id=${selected?.id}`),
    enabled: !!selected,
  })

  return (
    <div className="max-w-5xl">
      <PageHeader
        title="Stock opname"
        purpose="Hasil hitung fisik dan penyesuaian stoknya."
        actions={selected?.can.operate && <Button asChild><Link to={`/stok/opname/baru?lokasi=${selected.id}`}>Mulai opname</Link></Button>}
      />
      <div className="mb-4"><LocationPicker list={list} value={selected?.id ?? null} onChange={select} /></div>
      {counts.isPending ? <Loading /> : counts.error ? <ErrorAlert message={errorText(counts.error)} /> : (
        <Table>
          <thead><tr><Th>Nomor</Th><Th>Waktu</Th><Th>Status</Th><Th className="text-right">Dihitung</Th><Th className="text-right">Berselisih</Th><Th>Oleh</Th></tr></thead>
          <tbody>
            {counts.data.data.length === 0 && <EmptyRow colSpan={6}>Belum ada opname.</EmptyRow>}
            {counts.data.data.map((c) => (
              <tr key={c.id}>
                <Td className="font-mono"><Link to={`/stok/opname/${c.id}`} className="hover:underline">{c.number}</Link></Td>
                <Td>{formatDateTime(c.created_at)}</Td>
                <Td>{c.status && <Badge variant={STATUS_VARIANT[c.status]}>{APPROVAL_STATUS_LABEL[c.status]}</Badge>}</Td>
                <Td className="text-right">{c.line_count}</Td>
                <Td className="text-right">{c.difference_count}</Td>
                <Td>{c.created_by?.name}</Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
    </div>
  )
}

export function CountDetailPage() {
  const { id } = useParams()
  const qc = useQueryClient()
  const items = useStockItems()
  const [mode, setMode] = useState<'none' | 'reject' | 'return' | 'resubmit'>('none')
  const [note, setNote] = useState('')
  const count = useQuery({ queryKey: [...STOCK_KEY, 'count', id], queryFn: () => http.get<{ data: StockCountDetail }>(`/stock/counts/${id}`) })
  const act = useMutation({
    mutationFn: ({ action, body }: { action: string; body?: unknown }) => http.post(`/approvals/${count.data?.data.approval_id}/${action}`, body),
    onSuccess: () => {
      setMode('none')
      setNote('')
      qc.invalidateQueries({ queryKey: STOCK_KEY })
      qc.invalidateQueries({ queryKey: APPROVALS_KEY })
    },
  })
  const err = act.error instanceof ApiError ? act.error : null

  if (count.isPending) return <Loading />
  if (count.error) return <ErrorAlert message={errorText(count.error)} />
  const c = count.data.data

  return (
    <div className="max-w-5xl space-y-4">
      <PageHeader title={`Stock opname ${c.number}`} purpose={`${c.location.name} · dihitung ${formatDateTime(c.counted_at ?? c.created_at)} oleh ${c.created_by?.name ?? '–'}`} />
      <div className="flex flex-wrap items-center gap-3">
        {c.status && <Badge variant={STATUS_VARIANT[c.status]}>{APPROVAL_STATUS_LABEL[c.status]}</Badge>}
        {c.applied_at && <span className="text-sm text-muted-foreground">Penyesuaian berlaku {formatDateTime(c.applied_at)}</span>}
      </div>
      {c.reason && <p className="text-sm">Catatan penghitung: {c.reason}</p>}
      {c.decision_note && <p className="text-sm">Catatan {c.decider?.name}: <span className="font-medium">{c.decision_note}</span></p>}

      <Table>
        <thead>
          <tr>
            <Th>Barang</Th><Th className="text-right">Hasil hitung</Th><Th className="text-right">Saldo sistem saat dihitung</Th><Th className="text-right">Selisih</Th>
            {c.can_see_cost && <Th className="text-right">Nilai selisih</Th>}
          </tr>
        </thead>
        <tbody>
          {c.lines.map((l) => {
            const unit = l.item.base_unit.code
            const diff = Number(l.difference)
            return (
              <tr key={l.item.id}>
                <Td>{l.item.name}<p className="font-mono text-xs text-muted-foreground">{l.item.code}</p></Td>
                <Td className="text-right">{formatQty(l.counted_qty)} {unit}{l.unit.code !== unit && <p className="text-xs text-muted-foreground">diisi {formatQty(l.counted_entered)} {l.unit.code}</p>}</Td>
                <Td className="text-right">{formatQty(l.system_qty)} {unit}</Td>
                <Td className={`text-right font-medium ${diff < 0 ? 'text-destructive' : diff > 0 ? 'text-success' : 'text-muted-foreground'}`}>{diff === 0 ? 'sama' : `${formatSignedQty(l.difference)} ${unit}`}</Td>
                {c.can_see_cost && <Td className="text-right">{l.value === null ? '–' : formatRupiah(Math.round(Number(l.value)))}</Td>}
              </tr>
            )
          })}
        </tbody>
      </Table>
      {c.can_see_cost && c.total_value !== null && (
        <p className="text-sm">
          Total nilai selisih: <span className="font-semibold">{formatRupiah(Math.round(Number(c.total_value)))}</span>
          {c.value_is_estimate && <span className="text-muted-foreground"> (perkiraan dengan harga pokok rata-rata sekarang)</span>}
        </p>
      )}

      {mode === 'none' && (c.can.decide || c.can.cancel || c.can.resubmit) && (
        <Card>
          <CardContent className="flex flex-wrap gap-2 pt-6">
            {c.can.decide && (
              <>
                <Button disabled={act.isPending} onClick={() => act.mutate({ action: 'approve' })}>Setujui penyesuaian</Button>
                <Button variant="outline" onClick={() => { act.reset(); setMode('return') }}>Minta hitung ulang</Button>
                <Button variant="outline" onClick={() => { act.reset(); setMode('reject') }}>Tolak</Button>
              </>
            )}
            {c.can.resubmit && <Button onClick={() => setMode('resubmit')}>Hitung ulang & kirim lagi</Button>}
            {c.can.cancel && <Button variant="ghost" disabled={act.isPending} onClick={() => act.mutate({ action: 'cancel' })}>Batalkan opname</Button>}
          </CardContent>
        </Card>
      )}

      {(mode === 'reject' || mode === 'return') && (
        <form className="flex flex-wrap items-start gap-2" onSubmit={(e) => { e.preventDefault(); act.mutate({ action: mode, body: { note } }) }}>
          <div className="min-w-64 flex-1">
            <Field id="count-note" label={mode === 'reject' ? 'Alasan penolakan' : 'Apa yang perlu dihitung ulang?'} error={err?.field('note')}>
              <Input id="count-note" value={note} onChange={(e) => setNote(e.target.value)} />
            </Field>
          </div>
          <div className="flex gap-2 md:pt-[24px]">
            <Button type="submit" variant={mode === 'reject' ? 'destructive' : 'default'} disabled={act.isPending}>{mode === 'reject' ? 'Tolak' : 'Kembalikan'}</Button>
            <Button type="button" variant="ghost" onClick={() => setMode('none')}>Batal</Button>
          </div>
        </form>
      )}

      {mode === 'resubmit' && (
        <CountSheet
          items={items.data?.data ?? []}
          initial={Object.fromEntries(c.lines.map((l) => [l.item.id, { qty: String(Number(l.counted_entered)), unit_id: String(l.unit_id) }]))}
          error={err}
          pending={act.isPending}
          submitLabel="Kirim lagi ke Owner"
          onSubmit={(lines, reason) => act.mutate({ action: 'resubmit', body: { lines, reason: reason || null } })}
          onCancel={() => setMode('none')}
        />
      )}
      {err && mode === 'none' && <ErrorAlert message={err.message} />}
      <p><Link to={`/stok/opname?lokasi=${c.location.id}`} className="text-sm hover:underline">← Semua opname {c.location.name}</Link></p>
    </div>
  )
}
