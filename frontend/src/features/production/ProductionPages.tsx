import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { ErrorAlert, FieldError, InfoBanner, Loading, PageHeader } from '@/components/ui/feedback'
import { Field } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { EmptyRow, Table, Td, Th } from '@/components/ui/table'
import { hasRole, useMe } from '@/features/auth/useAuth'
import { STOCK_KEY } from '@/features/stock/queries'
import { ApiError, errorText, http } from '@/lib/api'
import { PRODUCTION_FAIL_LABEL, PRODUCTION_STATUS_LABEL, formatDate, formatDateTime, formatPct, formatQty, formatRupiah, todayWib } from '@/lib/format'
import type { Paginated, ProducibleItem, ProductionMaterial, ProductionOrderDetail, ProductionOrderSummary, ProductionStatus } from '@/lib/types'
import { cn } from '@/lib/utils'
import { PRODUCTION_KEY, purchaseLink } from './queries'

type Variant = 'default' | 'success' | 'warning' | 'destructive'
const STATUS_VARIANT: Record<ProductionStatus, Variant> = { PLANNED: 'warning', COMPLETED: 'success', CANCELLED: 'default' }

const num = (v: string) => Number(v.replace(',', '.'))
const plain = (q: string | null | undefined) => (q == null ? '–' : formatQty(q))
const rupiah = (v: string | null | undefined) => (v == null ? '–' : formatRupiah(Math.round(Number(v))))
/** (a − b) ÷ b dalam persen, sama dengan hitungan server. */
const pct = (actual: number, standard: number) => (standard > 0 ? ((actual - standard) / standard) * 100 : 0)

function addDays(date: string, days: number): string {
  const d = new Date(`${date}T00:00:00Z`)
  d.setUTCDate(d.getUTCDate() + days)
  return d.toISOString().slice(0, 10)
}

function Tabs<T extends string>({ value, onChange, options }: { value: T; onChange: (v: T) => void; options: readonly (readonly [T, string])[] }) {
  return (
    <div role="tablist" className="mb-4 inline-flex flex-wrap rounded-lg border border-border p-1">
      {options.map(([k, label]) => (
        <button key={k} role="tab" aria-selected={value === k} onClick={() => onChange(k)}
          className={cn('rounded-md px-3 py-1.5 text-sm', value === k ? 'bg-muted font-medium' : 'text-muted-foreground')}>{label}</button>
      ))}
    </div>
  )
}

type Settings = { variance_tolerance_pct: number; can_edit: boolean }
function useSettings() {
  return useQuery({ queryKey: [...PRODUCTION_KEY, 'settings'], queryFn: () => http.get<{ data: Settings }>('/production/settings') })
}

function useProducts() {
  return useQuery({ queryKey: [...PRODUCTION_KEY, 'products'], queryFn: () => http.get<{ data: ProducibleItem[] }>('/production/products') })
}

/** Toleransi varians (pengaturan Owner): di luar batas wajib alasan dan masuk pantauan Owner. */
function ToleranceCard() {
  const settings = useSettings()
  const qc = useQueryClient()
  const [editing, setEditing] = useState(false)
  const [value, setValue] = useState('')
  const save = useMutation({
    mutationFn: () => http.put('/production/settings', { variance_tolerance_pct: value === '' ? null : Number(value) }),
    onSuccess: () => { setEditing(false); qc.invalidateQueries({ queryKey: PRODUCTION_KEY }) },
  })
  const err = save.error instanceof ApiError ? save.error : null
  if (!settings.data) return null
  const s = settings.data.data

  return (
    <Card>
      <CardContent className="flex flex-wrap items-end justify-between gap-3 pt-6">
        {editing ? (
          <form className="flex flex-wrap items-end gap-2" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
            <Field id="tolerance" label="Toleransi varians (%)" error={err?.field('variance_tolerance_pct')}>
              <Input id="tolerance" inputMode="numeric" className="w-28" value={value} onChange={(e) => setValue(e.target.value.replace(/\D/g, ''))} />
            </Field>
            <Button type="submit" disabled={save.isPending}>Simpan</Button>
            <Button type="button" variant="ghost" onClick={() => setEditing(false)}>Batal</Button>
          </form>
        ) : (
          <div>
            <p className="text-xs text-muted-foreground">Toleransi varians produksi</p>
            <p className="text-lg font-semibold">{s.variance_tolerance_pct}%</p>
            <p className="text-xs text-muted-foreground">Pemakaian bahan dan hasil baik yang berbeda lebih dari ini dari rencana wajib diberi alasan dan ditandai untuk Owner.</p>
          </div>
        )}
        {s.can_edit && !editing && <Button variant="outline" onClick={() => { setValue(String(s.variance_tolerance_pct)); save.reset(); setEditing(true) }}>Ubah toleransi</Button>}
      </CardContent>
    </Card>
  )
}

function OutputCell({ o }: { o: ProductionOrderSummary }) {
  const base = o.output_item?.base_unit.code ?? ''
  if (o.status !== 'COMPLETED') return <>–</>
  return (
    <>
      {plain(o.good_qty)} {base} baik
      {Number(o.failed_qty) > 0 && <p className="text-xs text-muted-foreground">{plain(o.failed_qty)} {base} gagal</p>}
    </>
  )
}

// --- Daftar ---

export function ProductionOrdersPage() {
  const me = useMe().data
  const [tab, setTab] = useState<'PLANNED' | 'COMPLETED' | 'OVER_TOLERANCE' | 'ALL'>('PLANNED')
  const status = tab === 'ALL' ? '' : tab
  const list = useQuery({
    queryKey: [...PRODUCTION_KEY, 'orders', status],
    queryFn: () => http.get<Paginated<ProductionOrderSummary> & { counts: { planned: number; over_tolerance: number } }>(`/production/orders${status ? `?status=${status}` : ''}`),
  })
  const counts = list.data?.counts

  return (
    <div className="max-w-5xl space-y-4">
      <PageHeader
        title="Production Order"
        purpose="Rencana dan hasil produksi barang setengah jadi di gudang, dengan pemakaian bahan sebenarnya dan pemeriksaan hasil."
        actions={hasRole(me, 'WAREHOUSE') && <Button asChild><Link to="/produksi/baru">Rencanakan produksi</Link></Button>}
      />
      <InfoBanner>
        Alurnya: gudang membuat rencana (stok belum berubah) → setelah produksi, gudang mencatat hasil baik, hasil gagal, dan bahan yang benar-benar dipakai.
        Saat itu bahan dipotong dari stok gudang dan hanya hasil baik yang masuk stok. Produksi tidak perlu persetujuan.
      </InfoBanner>
      <ToleranceCard />
      <Tabs value={tab} onChange={setTab} options={[
        ['PLANNED', `Rencana${counts ? ` (${counts.planned})` : ''}`],
        ['COMPLETED', 'Selesai'],
        ['OVER_TOLERANCE', `Di luar toleransi${counts ? ` (${counts.over_tolerance})` : ''}`],
        ['ALL', 'Semua'],
      ] as const} />
      {list.isPending ? <Loading /> : list.error ? <ErrorAlert message={errorText(list.error)} /> : (
        <Table>
          <thead><tr><Th>Nomor</Th><Th>Barang</Th><Th className="text-right">Target</Th><Th className="text-right">Hasil</Th><Th>Status</Th></tr></thead>
          <tbody>
            {list.data.data.length === 0 && <EmptyRow colSpan={5}>Tidak ada Production Order.</EmptyRow>}
            {list.data.data.map((o) => (
              <tr key={o.id}>
                <Td className="font-mono">
                  <Link to={`/produksi/${o.id}`} className="hover:underline">{o.number}</Link>
                  <p className="font-sans text-xs text-muted-foreground">{formatDateTime(o.created_at)} · {o.created_by?.name}</p>
                </Td>
                <Td>{o.output_item?.name}{o.stock_request && <p className="text-xs text-muted-foreground">untuk {o.stock_request.number}</p>}</Td>
                <Td className="text-right">{plain(o.planned_entered)} {o.unit?.code}{o.planned_date && <p className="text-xs text-muted-foreground">{formatDate(o.planned_date)}</p>}</Td>
                <Td className="text-right"><OutputCell o={o} /></Td>
                <Td>
                  <Badge variant={STATUS_VARIANT[o.status]}>{PRODUCTION_STATUS_LABEL[o.status]}</Badge>
                  {o.over_tolerance && <Badge variant="destructive" className="ml-1">Di luar toleransi</Badge>}
                </Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
    </div>
  )
}

// --- Rencana baru ---

function MaterialsPreview({ lines }: { lines: ProductionMaterial[] }) {
  return (
    <Table>
      <thead><tr><Th>Bahan</Th><Th className="text-right">Kebutuhan (resep)</Th><Th className="text-right">Stok gudang</Th><Th className="text-right">Kurang</Th></tr></thead>
      <tbody>
        {lines.map((l) => {
          const short = Number(l.shortage_qty ?? 0) > 0
          return (
            <tr key={l.item.id}>
              <Td>{l.item.name}<p className="font-mono text-xs text-muted-foreground">{l.item.code}</p></Td>
              <Td className="text-right">{plain(l.standard_entered)} {l.unit.code}</Td>
              <Td className="text-right">{plain(String(Number(l.stock_qty ?? 0) / Number(l.factor)))} {l.unit.code}</Td>
              <Td className={cn('text-right', short && 'font-medium text-destructive')}>{short ? `${plain(l.shortage_entered)} ${l.unit.code}` : '–'}</Td>
            </tr>
          )
        })}
      </tbody>
    </Table>
  )
}

export function ProductionFormPage() {
  const [params] = useSearchParams()
  const products = useProducts()
  if (products.isPending) return <Loading />
  if (products.error) return <ErrorAlert message={errorText(products.error)} />

  return <ProductionForm products={products.data.data} params={params} />
}

function ProductionForm({ products, params }: { products: ProducibleItem[]; params: URLSearchParams }) {
  const requestId = params.get('permintaan')
  const preset = products.find((p) => String(p.id) === params.get('barang'))
  const presetUnit = preset?.units.find((u) => String(u.unit_id) === params.get('satuan'))
  const [itemId, setItemId] = useState(preset ? String(preset.id) : '')
  const [unitId, setUnitId] = useState(presetUnit ? String(presetUnit.unit_id) : preset ? String(preset.recipe.output_unit_id) : '')
  const [qty, setQty] = useState(params.get('jumlah') ?? '')
  const [date, setDate] = useState('')
  const [notes, setNotes] = useState('')
  const qc = useQueryClient()
  const navigate = useNavigate()
  const item = products.find((p) => String(p.id) === itemId)
  const valid = !!item && !!unitId && num(qty) > 0
  const preview = useQuery({
    queryKey: [...PRODUCTION_KEY, 'preview', itemId, unitId, qty],
    queryFn: () => http.get<{ data: { planned_qty: string; recipe_version_no: number; lines: ProductionMaterial[] } }>(
      `/production/preview?output_item_id=${itemId}&unit_id=${unitId}&qty=${num(qty)}`),
    enabled: valid,
  })
  const save = useMutation({
    mutationFn: () => http.post<{ data: ProductionOrderDetail }>('/production/orders', {
      output_item_id: itemId ? Number(itemId) : null, unit_id: unitId ? Number(unitId) : null, qty: qty.replace(',', '.'),
      planned_date: date || null, notes: notes || null, stock_request_id: requestId ? Number(requestId) : null,
    }),
    onSuccess: (res) => { qc.invalidateQueries({ queryKey: PRODUCTION_KEY }); qc.invalidateQueries({ queryKey: STOCK_KEY }); navigate(`/produksi/${res.data.id}`) },
  })
  const err = save.error instanceof ApiError ? save.error : null
  const lines = preview.data?.data.lines ?? []
  const prLink = item ? purchaseLink(lines, `Bahan untuk rencana produksi ${item.name}`) : null
  const batch = item && item.recipe.output_entered_qty && item.units.find((u) => u.unit_id === item.recipe.output_unit_id)

  return (
    <div className="max-w-4xl">
      <PageHeader title="Rencanakan produksi" purpose="Pilih barang dan target hasil. Kebutuhan bahan dihitung dari resep produksi yang berlaku; stok belum berubah sampai produksi selesai." />
      {requestId && <InfoBanner className="mb-4">Rencana ini untuk permintaan stok outlet. Setelah produksi selesai, kirim barangnya dari halaman permintaan.</InfoBanner>}
      {products.length === 0 && <InfoBanner className="mb-4">Belum ada barang dengan resep produksi. Owner membuat resep produksi di halaman <Link to="/resep" className="underline">Resep</Link>.</InfoBanner>}
      <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        <div className="grid gap-4 md:grid-cols-[1fr_10rem_8rem]">
          <Field id="prd-item" label="Barang yang diproduksi" error={err?.field('output_item_id')}>
            <Select id="prd-item" value={itemId} onChange={(e) => {
              const p = products.find((x) => String(x.id) === e.target.value)
              setItemId(e.target.value)
              setUnitId(p ? String(p.recipe.output_unit_id) : '')
            }}>
              <option value="">Pilih barang…</option>
              {products.map((p) => <option key={p.id} value={p.id}>{p.name} ({p.code})</option>)}
            </Select>
          </Field>
          <Field id="prd-qty" label="Target hasil" error={err?.field('qty')}>
            <Input id="prd-qty" inputMode="decimal" value={qty} onChange={(e) => setQty(e.target.value)} />
          </Field>
          <Field id="prd-unit" label="Satuan" error={err?.field('unit_id')}>
            <Select id="prd-unit" value={unitId} disabled={!item} onChange={(e) => setUnitId(e.target.value)}>
              {item?.units.map((u) => <option key={u.unit_id} value={u.unit_id}>{u.unit.code}</option>)}
            </Select>
          </Field>
        </div>
        {item && batch && <p className="text-sm text-muted-foreground">Satu resep (versi {item.recipe.version_no}) menghasilkan {plain(item.recipe.output_entered_qty)} {batch.unit.code}.</p>}
        {valid && preview.isPending && <Loading label="Menghitung kebutuhan bahan…" />}
        {preview.error && <ErrorAlert message={errorText(preview.error)} />}
        {lines.length > 0 && <MaterialsPreview lines={lines} />}
        {prLink && (
          <InfoBanner>
            Stok gudang belum cukup untuk sebagian bahan. Rencana tetap boleh disimpan; ajukan pembelian bahan yang kurang lewat Purchase Request.{' '}
            <Link to={prLink} target="_blank" rel="noreferrer" className="font-medium underline">Buat PR untuk bahan yang kurang</Link>
          </InfoBanner>
        )}
        <div className="grid gap-4 md:grid-cols-[12rem_1fr]">
          <Field id="prd-date" label="Rencana tanggal (opsional)" error={err?.field('planned_date')}>
            <Input id="prd-date" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
          </Field>
          <Field id="prd-notes" label="Catatan (opsional)"><Input id="prd-notes" value={notes} onChange={(e) => setNotes(e.target.value)} /></Field>
        </div>
        {err && !['output_item_id', 'qty', 'unit_id', 'planned_date'].some((k) => err.field(k)) && <ErrorAlert message={err.field('stock_request_id') ?? err.message} />}
        <div className="flex gap-2">
          <Button type="submit" disabled={save.isPending || !valid}>Simpan rencana</Button>
          <Button asChild variant="ghost"><Link to={requestId ? `/permintaan-stok/${requestId}` : '/produksi'}>Batal</Link></Button>
        </div>
      </form>
    </div>
  )
}

// --- Detail, selesai, batal ---

export function ProductionDetailPage() {
  const { id } = useParams()
  const qc = useQueryClient()
  const [mode, setMode] = useState<'none' | 'complete' | 'cancel'>('none')
  const [reason, setReason] = useState('')
  const order = useQuery({ queryKey: [...PRODUCTION_KEY, 'order', id], queryFn: () => http.get<{ data: ProductionOrderDetail }>(`/production/orders/${id}`) })
  const cancel = useMutation({
    mutationFn: () => http.post(`/production/orders/${id}/cancel`, { reason }),
    onSuccess: () => { setMode('none'); qc.invalidateQueries({ queryKey: PRODUCTION_KEY }) },
  })
  const err = cancel.error instanceof ApiError ? cancel.error : null
  if (order.isPending) return <Loading />
  if (order.error) return <ErrorAlert message={errorText(order.error)} />
  const o = order.data.data
  const base = o.output_item?.base_unit.code ?? ''
  const planned = o.status === 'PLANNED'
  const costs = o.total_cost !== null
  const prLink = planned && o.can.request_purchase ? purchaseLink(o.lines, `Bahan untuk ${o.number} (${o.output_item?.name ?? ''})`) : null

  return (
    <div className="max-w-5xl space-y-4">
      <PageHeader title={`Production Order ${o.number}`} purpose={`${o.output_item?.name ?? ''} · dibuat ${formatDateTime(o.created_at)} oleh ${o.created_by?.name ?? '–'}`} />
      <div className="flex flex-wrap items-center gap-3 text-sm">
        <Badge variant={STATUS_VARIANT[o.status]}>{PRODUCTION_STATUS_LABEL[o.status]}</Badge>
        {o.over_tolerance && <Badge variant="destructive">Di luar toleransi {o.tolerance_pct && `${Number(o.tolerance_pct)}%`}</Badge>}
        <span>Target {plain(o.planned_entered)} {o.unit?.code}{o.unit?.code !== base && ` (${plain(o.planned_qty)} ${base})`}</span>
        {o.planned_date && <span>Rencana {formatDate(o.planned_date)}</span>}
        {o.recipe_version_no && <span>Resep versi {o.recipe_version_no}</span>}
        {o.stock_request && <span>untuk permintaan <Link to={`/permintaan-stok/${o.stock_request.id}`} className="font-mono hover:underline">{o.stock_request.number}</Link></span>}
      </div>
      {o.notes && <p className="text-sm">Catatan: {o.notes}</p>}
      {o.cancel_reason && <p className="text-sm">Dibatalkan oleh {o.cancelled_by?.name} ({formatDateTime(o.cancelled_at)}): <span className="font-medium">{o.cancel_reason}</span></p>}

      {o.status === 'COMPLETED' && (
        <div className="grid gap-3 md:grid-cols-4">
          <Stat label="Hasil baik (masuk stok)" value={`${plain(o.good_qty)} ${base}`} hint={`selisih dari target ${formatPct(o.output_variance_pct)}`} warn={o.over_tolerance === true} />
          <Stat label="Hasil gagal (waste produksi)" value={`${plain(o.failed_qty)} ${base}`} hint={costs ? `nilai bahan ${rupiah(o.failed_value)}` : undefined} />
          <Stat label="Batch dan kedaluwarsa" value={o.batch_no ?? '–'} hint={o.expiry_date ? `kedaluwarsa ${formatDate(o.expiry_date)}` : 'tanpa tanggal kedaluwarsa'} />
          {costs && <Stat label={`Harga pokok per ${base}`} value={rupiah(o.unit_cost)} hint={`total bahan ${rupiah(o.total_cost)}${o.cost_complete === false ? ' · sebagian bahan belum punya harga pokok' : ''}`} warn={o.cost_complete === false} />}
        </div>
      )}
      {o.status === 'COMPLETED' && (
        <div className="space-y-1 text-sm">
          <p>Selesai {formatDateTime(o.completed_at)} oleh {o.completed_by?.name}.</p>
          {o.failures.length > 0 && <p>Gagal QC: {o.failures.map((f) => `${PRODUCTION_FAIL_LABEL[f.reason]} ${plain(f.qty)} ${base}${f.note ? ` (${f.note})` : ''}`).join('; ')}</p>}
          {o.variance_note && <p>Alasan varians: <span className="font-medium">{o.variance_note}</span></p>}
          {o.completion_note && <p>Catatan: {o.completion_note}</p>}
        </div>
      )}

      <h2 className="pt-2 font-semibold">Bahan</h2>
      <Table>
        <thead>
          <tr>
            <Th>Bahan</Th><Th className="text-right">Standar resep</Th>
            {planned ? <><Th className="text-right">Stok gudang</Th><Th className="text-right">Kurang</Th></> : o.status === 'COMPLETED' && <><Th className="text-right">Dipakai</Th><Th className="text-right">Varians</Th>{costs && <Th className="text-right">Biaya</Th>}</>}
          </tr>
        </thead>
        <tbody>
          {o.lines.map((l) => {
            const short = Number(l.shortage_qty ?? 0) > 0
            const tol = Number(o.tolerance_pct ?? 0)
            const beyond = l.variance_pct != null && Math.abs(Number(l.variance_pct)) > tol
            return (
              <tr key={l.id}>
                <Td>{l.item.name}<p className="font-mono text-xs text-muted-foreground">{l.item.code}</p></Td>
                <Td className="text-right">{plain(l.standard_entered)} {l.unit.code}</Td>
                {planned && <>
                  <Td className="text-right">{plain(String(Number(l.stock_qty ?? 0) / Number(l.factor)))} {l.unit.code}</Td>
                  <Td className={cn('text-right', short && 'font-medium text-destructive')}>{short ? `${plain(l.shortage_entered)} ${l.unit.code}` : '–'}</Td>
                </>}
                {o.status === 'COMPLETED' && <>
                  <Td className="text-right">{plain(l.actual_entered)} {l.unit.code}</Td>
                  <Td className={cn('text-right', beyond && 'font-medium text-destructive')}>{formatPct(l.variance_pct)}</Td>
                  {costs && <Td className="text-right">{rupiah(l.cost)}</Td>}
                </>}
              </tr>
            )
          })}
        </tbody>
      </Table>
      {prLink && (
        <InfoBanner>
          Stok gudang belum cukup untuk sebagian bahan. <Link to={prLink} className="font-medium underline">Buat PR untuk bahan yang kurang</Link>
        </InfoBanner>
      )}

      {mode === 'none' && (o.can.complete || o.can.cancel) && (
        <div className="flex flex-wrap gap-2">
          {o.can.complete && <Button onClick={() => setMode('complete')}>Selesaikan produksi</Button>}
          {o.can.cancel && <Button variant="outline" onClick={() => { cancel.reset(); setReason(''); setMode('cancel') }}>Batalkan rencana</Button>}
        </div>
      )}
      {mode === 'complete' && <CompleteForm order={o} onDone={() => setMode('none')} />}
      {mode === 'cancel' && (
        <form className="flex flex-wrap items-start gap-2" onSubmit={(e) => { e.preventDefault(); cancel.mutate() }}>
          <div className="min-w-64 flex-1">
            <Field id="prd-cancel" label="Alasan rencana dibatalkan" error={err?.field('reason')}><Input id="prd-cancel" value={reason} onChange={(e) => setReason(e.target.value)} /></Field>
          </div>
          <div className="flex gap-2 md:pt-[24px]">
            <Button type="submit" variant="destructive" disabled={cancel.isPending}>Batalkan rencana</Button>
            <Button type="button" variant="ghost" onClick={() => setMode('none')}>Kembali</Button>
          </div>
        </form>
      )}
      {err && !err.field('reason') && <ErrorAlert message={err.message} />}
      <p><Link to="/produksi" className="text-sm hover:underline">← Semua Production Order</Link></p>
    </div>
  )
}

function Stat({ label, value, hint, warn }: { label: string; value: string; hint?: string; warn?: boolean }) {
  return (
    <Card>
      <CardContent className="pt-6">
        <p className="text-xs text-muted-foreground">{label}</p>
        <p className={cn('text-lg font-semibold', warn && 'text-destructive')}>{value}</p>
        {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
      </CardContent>
    </Card>
  )
}

type FailRow = { qty: string; note: string }

/** Selesai: hasil baik, hasil gagal per alasan baku (QC Production), dan pemakaian bahan sebenarnya. */
function CompleteForm({ order, onDone }: { order: ProductionOrderDetail; onDone: () => void }) {
  const products = useProducts()
  const settings = useSettings()
  const tolerance = settings.data?.data.variance_tolerance_pct ?? 0
  const item = products.data?.data.find((p) => p.id === order.output_item?.id)
  const units = item?.units ?? (order.unit ? [{ unit_id: order.unit.id, factor_to_base: order.factor, unit: { id: order.unit.id, code: order.unit.code, name: order.unit.code } }] : [])
  const [unitId, setUnitId] = useState(String(order.unit?.id ?? ''))
  const [good, setGood] = useState(formatQty(order.planned_entered).replace(/\./g, ''))
  const [fails, setFails] = useState<Record<string, FailRow>>(() => Object.fromEntries(Object.keys(PRODUCTION_FAIL_LABEL).map((k) => [k, { qty: '', note: '' }])))
  const [actuals, setActuals] = useState<Record<number, string>>(() => Object.fromEntries(order.lines.map((l) => [l.id!, formatQty(l.standard_entered).replace(/\./g, '')])))
  const shelf = order.output_item?.shelf_life_days
  const [expiry, setExpiry] = useState(shelf ? addDays(todayWib(), shelf) : '')
  const [varianceNote, setVarianceNote] = useState('')
  const [note, setNote] = useState('')
  const qc = useQueryClient()

  const factor = Number(units.find((u) => String(u.unit_id) === unitId)?.factor_to_base ?? order.factor)
  const goodBase = (num(good) || 0) * factor
  const failedBase = Object.values(fails).reduce((s, f) => s + (num(f.qty) || 0), 0) * factor
  const outputPct = pct(goodBase, Number(order.planned_qty))
  const linePct = (l: ProductionMaterial) => pct(num(actuals[l.id!] ?? '') || 0, Number(l.standard_entered))
  const beyond = (p: number) => Math.abs(p) > tolerance + 1e-9
  const anyBeyond = beyond(outputPct) || order.lines.some((l) => beyond(linePct(l)))

  const save = useMutation({
    mutationFn: () => http.post(`/production/orders/${order.id}/complete`, {
      unit_id: unitId ? Number(unitId) : null,
      good_qty: good.replace(',', '.') || '0',
      failures: failReasons.filter((k) => num(fails[k].qty) > 0).map((reason) => ({ reason, qty: fails[reason].qty.replace(',', '.'), note: fails[reason].note || null })),
      lines: order.lines.map((l) => ({ line_id: l.id, actual_qty: (actuals[l.id!] ?? '').replace(',', '.') || '0' })),
      expiry_date: expiry || null,
      variance_note: varianceNote || null,
      note: note || null,
    }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: PRODUCTION_KEY }); qc.invalidateQueries({ queryKey: STOCK_KEY }); onDone() },
  })
  const err = save.error instanceof ApiError ? save.error : null
  const unitCode = units.find((u) => String(u.unit_id) === unitId)?.unit.code ?? ''
  const failReasons = Object.keys(PRODUCTION_FAIL_LABEL)
  /** Urutan baris gagal yang dikirim, untuk mencocokkan pesan kesalahan server (failures.N.*). */
  const sentFails = failReasons.filter((k) => num(fails[k].qty) > 0)

  return (
    <Card>
      <CardContent className="space-y-5 pt-6">
        <InfoBanner>
          Hitung hasil yang baik dan periksa mutunya. Hasil yang rusak atau tidak layak diisi di “Hasil gagal” beserta alasannya — tidak masuk stok dan dicatat sebagai waste produksi.
          Isi bahan yang benar-benar terpakai (sudah terisi dari resep; ubah bila berbeda).
        </InfoBanner>
        <form className="space-y-5" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
          <div className="grid gap-4 md:grid-cols-[10rem_8rem_1fr]">
            <Field id="cmp-good" label="Hasil baik" error={err?.field('good_qty')}>
              <Input id="cmp-good" inputMode="decimal" value={good} onChange={(e) => setGood(e.target.value)} />
            </Field>
            <Field id="cmp-unit" label="Satuan" error={err?.field('unit_id')}>
              <Select id="cmp-unit" value={unitId} onChange={(e) => setUnitId(e.target.value)}>
                {units.map((u) => <option key={u.unit_id} value={u.unit_id}>{u.unit.code}</option>)}
              </Select>
            </Field>
            <div className={cn('self-end pb-2 text-sm', beyond(outputPct) ? 'font-medium text-destructive' : 'text-muted-foreground')}>
              Target {plain(order.planned_entered)} {order.unit?.code} · selisih hasil baik {formatPct(outputPct.toFixed(2))}
            </div>
          </div>

          <div>
            <p className="mb-2 text-sm font-medium">Hasil gagal QC (dalam {unitCode})</p>
            <div className="grid gap-2 md:grid-cols-2">
              {failReasons.map((k, i) => (
                <div key={k} className="flex flex-wrap items-start gap-2">
                  <label htmlFor={`fail-${k}`} className="w-36 pt-2 text-sm">{PRODUCTION_FAIL_LABEL[k]}</label>
                  <div className="w-24">
                    <Input id={`fail-${k}`} inputMode="decimal" value={fails[k].qty} onChange={(e) => setFails({ ...fails, [k]: { ...fails[k], qty: e.target.value } })} />
                  </div>
                  {num(fails[k].qty) > 0 && (
                    <div className="min-w-40 flex-1">
                      <Input aria-label={`Keterangan ${PRODUCTION_FAIL_LABEL[k]}`} placeholder={k === 'OTHER' ? 'Keterangan (wajib)' : 'Keterangan (opsional)'} value={fails[k].note}
                        onChange={(e) => setFails({ ...fails, [k]: { ...fails[k], note: e.target.value } })} />
                    </div>
                  )}
                  <FieldError message={err?.field(`failures.${sentFails.indexOf(k)}.note`) ?? err?.field(`failures.${sentFails.indexOf(k)}.qty`) ?? (i === 0 ? err?.field('failures') : undefined)} />
                </div>
              ))}
            </div>
            {failedBase > 0 && <p className="mt-1 text-xs text-muted-foreground">Total gagal {formatQty(failedBase / factor)} {unitCode}.</p>}
          </div>

          <div>
            <p className="mb-2 text-sm font-medium">Bahan yang dipakai</p>
            <Table>
              <thead><tr><Th>Bahan</Th><Th className="text-right">Standar</Th><Th className="w-40">Dipakai</Th><Th className="text-right">Varians</Th></tr></thead>
              <tbody>
                {order.lines.map((l, i) => {
                  const p = linePct(l)
                  return (
                    <tr key={l.id} className="align-top">
                      <Td>{l.item.name}</Td>
                      <Td className="text-right">{plain(l.standard_entered)} {l.unit.code}</Td>
                      <Td>
                        <div className="flex items-center gap-1">
                          <Input aria-label={`Dipakai ${l.item.name}`} inputMode="decimal" value={actuals[l.id!] ?? ''} onChange={(e) => setActuals({ ...actuals, [l.id!]: e.target.value })} />
                          <span className="text-sm">{l.unit.code}</span>
                        </div>
                        <FieldError message={err?.field(`lines.${i}.actual_qty`)} />
                      </Td>
                      <Td className={cn('text-right', beyond(p) && 'font-medium text-destructive')}>{formatPct(p.toFixed(2))}</Td>
                    </tr>
                  )
                })}
              </tbody>
            </Table>
          </div>

          {anyBeyond && (
            <Field id="cmp-variance" label={`Alasan varians (wajib — ada yang berbeda lebih dari ${tolerance}%)`} error={err?.field('variance_note')}>
              <Input id="cmp-variance" value={varianceNote} onChange={(e) => setVarianceNote(e.target.value)} />
            </Field>
          )}
          <div className="grid gap-4 md:grid-cols-[12rem_1fr]">
            <Field id="cmp-expiry" label="Kedaluwarsa hasil" error={err?.field('expiry_date')}
              hint={shelf ? `Terisi dari umur simpan ${shelf} hari.` : 'Opsional; umur simpan barang ini belum diatur.'}>
              <Input id="cmp-expiry" type="date" value={expiry} onChange={(e) => setExpiry(e.target.value)} />
            </Field>
            <Field id="cmp-note" label="Catatan (opsional)"><Input id="cmp-note" value={note} onChange={(e) => setNote(e.target.value)} /></Field>
          </div>
          {err && !Object.keys(err.errors).some((k) => k.startsWith('lines.') || k.startsWith('failures.') || ['good_qty', 'variance_note', 'expiry_date', 'unit_id'].includes(k)) && <ErrorAlert message={err.message} />}
          <div className="flex gap-2">
            <Button type="submit" disabled={save.isPending}>Simpan hasil produksi</Button>
            <Button type="button" variant="ghost" onClick={onDone}>Batal</Button>
          </div>
        </form>
      </CardContent>
    </Card>
  )
}
