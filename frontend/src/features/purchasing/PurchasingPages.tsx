import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { ErrorAlert, FieldError, InfoBanner, Loading, PageHeader } from '@/components/ui/feedback'
import { Field } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { RupiahInput } from '@/components/ui/rupiah-input'
import { Select } from '@/components/ui/select'
import { EmptyRow, Table, Td, Th } from '@/components/ui/table'
import { hasRole, useMe } from '@/features/auth/useAuth'
import { LinesEditor } from '@/features/stock/LinesEditor'
import { filledLines, newLine, type Line } from '@/features/stock/lines'
import { STOCK_KEY, useStockItems } from '@/features/stock/queries'
import { ApiError, errorText, http } from '@/lib/api'
import { PO_STATUS_LABEL, PR_STATUS_LABEL, REJECT_REASON_LABEL, formatDate, formatDateTime, formatQty, formatRupiah, todayWib } from '@/lib/format'
import type { Paginated, Payables, PurchaseOrder, PurchaseOrderStatus, PurchaseRequest, PurchaseRequestStatus, SupplierInvoice, Supplier } from '@/lib/types'
import { cn } from '@/lib/utils'
import { PURCHASING_KEY } from './queries'


type Variant = 'default' | 'success' | 'warning' | 'destructive'
const PR_VARIANT: Record<PurchaseRequestStatus, Variant> = {
  PENDING: 'warning', APPROVED: 'warning', ORDERED: 'success', REJECTED: 'destructive', CANCELLED: 'default', CLOSED: 'default',
}
const PO_VARIANT: Record<PurchaseOrderStatus, Variant> = {
  WAITING_OWNER: 'warning', ORDERED: 'warning', PARTIAL: 'warning', RECEIVED: 'success', CLOSED: 'default', REJECTED: 'destructive', CANCELLED: 'default',
}

function Tabs<T extends string>({ value, onChange, options }: { value: T; onChange: (v: T) => void; options: readonly (readonly [T, string])[] }) {
  return (
    <div role="tablist" className="mb-4 inline-flex rounded-lg border border-border p-1">
      {options.map(([k, label]) => (
        <button key={k} role="tab" aria-selected={value === k} onClick={() => onChange(k)}
          className={cn('rounded-md px-3 py-1.5 text-sm', value === k ? 'bg-muted font-medium' : 'text-muted-foreground')}>{label}</button>
      ))}
    </div>
  )
}

const plainQty = (q: string | null | undefined) => (q == null ? '' : formatQty(q))

/** Alasan wajib untuk menutup atau membatalkan dokumen. */
function ReasonForm({ label, submit, pending, error, onSubmit, onCancel, destructive }: {
  label: string; submit: string; pending: boolean; error: ApiError | null; onSubmit: (reason: string) => void; onCancel: () => void; destructive?: boolean
}) {
  const [reason, setReason] = useState('')
  return (
    <form className="flex flex-wrap items-start gap-2" onSubmit={(e) => { e.preventDefault(); onSubmit(reason) }}>
      <div className="min-w-64 flex-1">
        <Field id="reason" label={label} error={error?.field('reason')}><Input id="reason" value={reason} onChange={(e) => setReason(e.target.value)} /></Field>
      </div>
      <div className="flex gap-2 md:pt-[24px]">
        <Button type="submit" variant={destructive ? 'destructive' : 'default'} disabled={pending}>{submit}</Button>
        <Button type="button" variant="ghost" onClick={onCancel}>Batal</Button>
      </div>
    </form>
  )
}

// --- Purchase Request ---

/** Daftar Purchase Request (KP-08): gudang mengajukan, Finance menyetujui lalu memesan. */
export function PurchaseRequestsPage() {
  const me = useMe().data
  const [tab, setTab] = useState<'ACTION' | 'ALL'>('ACTION')
  const status = tab === 'ALL' ? '' : 'OPEN'
  const list = useQuery({
    queryKey: [...PURCHASING_KEY, 'requests', status],
    queryFn: () => http.get<Paginated<PurchaseRequest>>(`/purchasing/requests${status ? `?status=${status}` : ''}`),
  })

  return (
    <div className="max-w-5xl">
      <PageHeader
        title="Purchase Request"
        purpose="Permintaan pembelian bahan dari gudang. Berisi barang dan jumlah saja; supplier dan harga dipilih Finance di PO."
        actions={hasRole(me, 'WAREHOUSE') && <Button asChild><Link to="/pembelian/pr/baru">Buat PR</Link></Button>}
      />
      <InfoBanner className="mb-4">
        Alurnya: gudang membuat PR → Finance menyetujui (di halaman Persetujuan) → Finance membuat PO ke supplier → gudang menerima barang dan memeriksa kualitasnya.
        Outlet tidak membeli stok sendiri; pembelian kecil yang mendesak memakai Petty Cash.
      </InfoBanner>
      <Tabs value={tab} onChange={setTab} options={[['ACTION', 'Belum dipesan'], ['ALL', 'Semua']] as const} />
      {list.isPending ? <Loading /> : list.error ? <ErrorAlert message={errorText(list.error)} /> : (
        <Table>
          <thead><tr><Th>Nomor</Th><Th>Diajukan</Th><Th>Dibutuhkan</Th><Th>Barang</Th><Th>Status</Th><Th /></tr></thead>
          <tbody>
            {list.data.data.length === 0 && <EmptyRow colSpan={6}>Tidak ada Purchase Request.</EmptyRow>}
            {list.data.data.map((r) => (
              <tr key={r.id}>
                <Td className="font-mono"><Link to={`/pembelian/pr/${r.id}`} className="hover:underline">{r.number}</Link></Td>
                <Td>{formatDateTime(r.created_at)}<p className="text-xs text-muted-foreground">{r.requested_by?.name}</p></Td>
                <Td>{formatDate(r.needed_by)}</Td>
                <Td className="text-sm">{r.lines.map((l) => `${l.item.name} ${plainQty(l.qty)} ${l.unit.code ?? ''}`).join(', ')}</Td>
                <Td><Badge variant={PR_VARIANT[r.status]}>{PR_STATUS_LABEL[r.status]}</Badge></Td>
                <Td>{r.can.order && <Button asChild size="sm"><Link to={`/pembelian/po/baru?pr=${r.id}`}>Buat PO</Link></Button>}</Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
    </div>
  )
}

export function PurchaseRequestFormPage() {
  const items = useStockItems()
  const [lines, setLines] = useState<Line[]>([newLine()])
  const [neededBy, setNeededBy] = useState('')
  const [reason, setReason] = useState('')
  const qc = useQueryClient()
  const navigate = useNavigate()
  const save = useMutation({
    mutationFn: () => http.post<{ data: PurchaseRequest }>('/purchasing/requests', { needed_by: neededBy || null, reason: reason || null, lines: filledLines(lines) }),
    onSuccess: (res) => { qc.invalidateQueries({ queryKey: PURCHASING_KEY }); navigate(`/pembelian/pr/${res.data.id}`) },
  })
  const err = save.error instanceof ApiError ? save.error : null

  return (
    <div className="max-w-4xl">
      <PageHeader title="Buat Purchase Request" purpose="Tulis barang dan jumlah yang perlu dibeli. Finance yang memilih supplier dan harga." />
      <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        <LinesEditor items={items.data?.data ?? []} lines={lines} onChange={setLines} error={err} />
        <div className="grid gap-4 md:grid-cols-[12rem_1fr]">
          <Field id="pr-date" label="Dibutuhkan tanggal (opsional)" error={err?.field('needed_by')}>
            <Input id="pr-date" type="date" value={neededBy} onChange={(e) => setNeededBy(e.target.value)} />
          </Field>
          <Field id="pr-reason" label="Alasan / catatan untuk Finance (opsional)"><Input id="pr-reason" value={reason} onChange={(e) => setReason(e.target.value)} /></Field>
        </div>
        {err && !Object.keys(err.errors).some((k) => k.startsWith('lines')) && <ErrorAlert message={err.message} />}
        <div className="flex gap-2">
          <Button type="submit" disabled={save.isPending}>Ajukan ke Finance</Button>
          <Button asChild variant="ghost"><Link to="/pembelian/pr">Batal</Link></Button>
        </div>
      </form>
    </div>
  )
}

export function PurchaseRequestDetailPage() {
  const { id } = useParams()
  const qc = useQueryClient()
  const [closing, setClosing] = useState(false)
  const pr = useQuery({ queryKey: [...PURCHASING_KEY, 'request', id], queryFn: () => http.get<{ data: PurchaseRequest }>(`/purchasing/requests/${id}`) })
  const close = useMutation({
    mutationFn: (reason: string) => http.post(`/purchasing/requests/${id}/close`, { reason }),
    onSuccess: () => { setClosing(false); qc.invalidateQueries({ queryKey: PURCHASING_KEY }) },
  })
  const err = close.error instanceof ApiError ? close.error : null
  if (pr.isPending) return <Loading />
  if (pr.error) return <ErrorAlert message={errorText(pr.error)} />
  const r = pr.data.data

  return (
    <div className="max-w-4xl space-y-4">
      <PageHeader title={`Purchase Request ${r.number}`} purpose={`Diajukan ${formatDateTime(r.created_at)} oleh ${r.requested_by?.name ?? '–'}`} />
      <div className="flex flex-wrap items-center gap-3 text-sm">
        <Badge variant={PR_VARIANT[r.status]}>{PR_STATUS_LABEL[r.status]}</Badge>
        {r.needed_by && <span>Dibutuhkan {formatDate(r.needed_by)}</span>}
        {r.approval && <span>Persetujuan <Link to="/persetujuan" className="font-mono hover:underline">{r.approval.number}</Link></span>}
        {r.orders?.map((o) => <span key={o.id}>PO <Link to={`/pembelian/po/${o.id}`} className="font-mono hover:underline">{o.number}</Link> ({PO_STATUS_LABEL[o.status]})</span>)}
      </div>
      {r.reason && <p className="text-sm">Alasan: {r.reason}</p>}
      {r.approval?.decision_note && <p className="text-sm">Catatan Finance: <span className="font-medium">{r.approval.decision_note}</span></p>}
      {r.close_note && <p className="text-sm">Ditutup: <span className="font-medium">{r.close_note}</span></p>}
      <Table>
        <thead><tr><Th>Barang</Th><Th className="text-right">Jumlah</Th></tr></thead>
        <tbody>
          {r.lines.map((l, i) => (
            <tr key={l.id ?? i}><Td>{l.item.name}<p className="font-mono text-xs text-muted-foreground">{l.item.code}</p></Td><Td className="text-right">{plainQty(l.qty)} {l.unit.code}</Td></tr>
          ))}
        </tbody>
      </Table>
      {r.status === 'PENDING' && <InfoBanner>Menunggu keputusan Finance di halaman Persetujuan. Pengaju dapat membatalkannya di sana.</InfoBanner>}
      {r.can.order && !closing && (
        <div className="flex gap-2">
          <Button asChild><Link to={`/pembelian/po/baru?pr=${r.id}`}>Buat PO</Link></Button>
          <Button variant="outline" onClick={() => { close.reset(); setClosing(true) }}>Tutup PR tanpa membeli</Button>
        </div>
      )}
      {closing && <ReasonForm label="Alasan PR ditutup" submit="Tutup PR" pending={close.isPending} error={err} onSubmit={(x) => close.mutate(x)} onCancel={() => setClosing(false)} />}
      {err && !err.field('reason') && <ErrorAlert message={err.message} />}
    </div>
  )
}

// --- Purchase Order ---

type Settings = { finance_limit: number; can_edit: boolean }
function useSettings(enabled = true) {
  return useQuery({ queryKey: [...PURCHASING_KEY, 'settings'], queryFn: () => http.get<{ data: Settings }>('/purchasing/settings'), enabled })
}

/** Batas persetujuan Finance (OD-10): PO di atas batas menunggu Owner. Owner yang mengubah. */
function LimitCard() {
  const settings = useSettings()
  const qc = useQueryClient()
  const [editing, setEditing] = useState(false)
  const [value, setValue] = useState<number | null>(null)
  const save = useMutation({
    mutationFn: () => http.put('/purchasing/settings', { finance_limit: value }),
    onSuccess: () => { setEditing(false); qc.invalidateQueries({ queryKey: PURCHASING_KEY }) },
  })
  const err = save.error instanceof ApiError ? save.error : null
  if (!settings.data) return null
  const s = settings.data.data

  return (
    <Card>
      <CardContent className="flex flex-wrap items-end justify-between gap-3 pt-6">
        {editing ? (
          <form className="flex flex-wrap items-end gap-2" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
            <Field id="limit" label="Batas persetujuan Finance per PO" error={err?.field('finance_limit')}>
              <RupiahInput id="limit" value={value} onValueChange={setValue} />
            </Field>
            <Button type="submit" disabled={save.isPending}>Simpan</Button>
            <Button type="button" variant="ghost" onClick={() => setEditing(false)}>Batal</Button>
          </form>
        ) : (
          <div>
            <p className="text-xs text-muted-foreground">Batas persetujuan Finance per PO</p>
            <p className="text-lg font-semibold">{formatRupiah(s.finance_limit)}</p>
            <p className="text-xs text-muted-foreground">PO di atas batas ini menunggu persetujuan Owner sebelum dipesan.</p>
          </div>
        )}
        {s.can_edit && !editing && <Button variant="outline" onClick={() => { setValue(s.finance_limit); save.reset(); setEditing(true) }}>Ubah batas</Button>}
      </CardContent>
    </Card>
  )
}

export function PurchaseOrdersPage() {
  const me = useMe().data
  const seesPrices = hasRole(me, 'OWNER') || hasRole(me, 'FINANCE')
  const [tab, setTab] = useState<'OPEN' | 'ALL'>('OPEN')
  const list = useQuery({
    queryKey: [...PURCHASING_KEY, 'orders', tab],
    queryFn: () => http.get<Paginated<PurchaseOrder>>(`/purchasing/orders${tab === 'OPEN' ? '?status=OPEN' : ''}`),
  })

  return (
    <div className="max-w-5xl space-y-4">
      <PageHeader title="Purchase Order" purpose="Pesanan ke supplier. Gudang menerima barangnya; pengiriman boleh bertahap sampai lengkap." />
      {seesPrices && <LimitCard />}
      <Tabs value={tab} onChange={setTab} options={[['OPEN', 'Belum selesai'], ['ALL', 'Semua']] as const} />
      {list.isPending ? <Loading /> : list.error ? <ErrorAlert message={errorText(list.error)} /> : (
        <Table>
          <thead><tr><Th>Nomor</Th><Th>Supplier</Th><Th>Dibuat</Th><Th>Perkiraan datang</Th>{seesPrices && <Th className="text-right">Total</Th>}<Th>Status</Th></tr></thead>
          <tbody>
            {list.data.data.length === 0 && <EmptyRow colSpan={6}>Tidak ada PO.</EmptyRow>}
            {list.data.data.map((o) => (
              <tr key={o.id}>
                <Td className="font-mono"><Link to={`/pembelian/po/${o.id}`} className="hover:underline">{o.number}</Link><p className="text-xs text-muted-foreground">{o.request?.number}</p></Td>
                <Td>{o.supplier?.name}</Td>
                <Td>{formatDateTime(o.created_at)}<p className="text-xs text-muted-foreground">{o.created_by?.name}</p></Td>
                <Td>{formatDate(o.expected_date)}</Td>
                {seesPrices && <Td className="text-right">{formatRupiah(o.total)}</Td>}
                <Td><Badge variant={PO_VARIANT[o.status]}>{PO_STATUS_LABEL[o.status]}</Badge></Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
    </div>
  )
}

type OrderLine = { purchase_line_id: number; name: string; unit: string; qty: string; unit_price: number | null }

export function PurchaseOrderFormPage() {
  const [params] = useSearchParams()
  const prId = params.get('pr')
  const pr = useQuery({ queryKey: [...PURCHASING_KEY, 'request', prId], queryFn: () => http.get<{ data: PurchaseRequest }>(`/purchasing/requests/${prId}`), enabled: !!prId })
  const suppliers = useQuery({ queryKey: ['suppliers', 'all'], queryFn: () => http.get<Paginated<Supplier>>('/suppliers?search=') })
  const settings = useSettings()
  if (!prId) return <ErrorAlert message="Buka PO dari Purchase Request yang sudah disetujui." />
  if (pr.isPending || suppliers.isPending) return <Loading />
  if (pr.error) return <ErrorAlert message={errorText(pr.error)} />
  if (!pr.data.data.can.order) return <ErrorAlert message={`PR ${pr.data.data.number} tidak dapat dipesan (${PR_STATUS_LABEL[pr.data.data.status]}).`} />

  return <OrderForm pr={pr.data.data} suppliers={(suppliers.data?.data ?? []).filter((s) => s.is_active)} limit={settings.data?.data.finance_limit ?? null} />
}

function OrderForm({ pr, suppliers, limit }: { pr: PurchaseRequest; suppliers: Supplier[]; limit: number | null }) {
  const [supplierId, setSupplierId] = useState('')
  const [expected, setExpected] = useState('')
  const [note, setNote] = useState('')
  const [lines, setLines] = useState<OrderLine[]>(pr.lines.map((l) => ({ purchase_line_id: l.id!, name: l.item.name, unit: l.unit.code ?? '', qty: plainQty(l.qty), unit_price: null })))
  const qc = useQueryClient()
  const navigate = useNavigate()
  const lineTotal = (l: OrderLine) => Math.round((Number(l.qty.replace(',', '.')) || 0) * (l.unit_price ?? 0))
  const total = lines.reduce((s, l) => s + lineTotal(l), 0)
  const save = useMutation({
    mutationFn: () => http.post<{ data: PurchaseOrder }>('/purchasing/orders', {
      purchase_request_id: pr.id, supplier_id: supplierId ? Number(supplierId) : null, expected_date: expected || null, note: note || null,
      lines: lines.map((l) => ({ purchase_line_id: l.purchase_line_id, qty: l.qty.replace(',', '.'), unit_price: l.unit_price })),
    }),
    onSuccess: (res) => { qc.invalidateQueries({ queryKey: PURCHASING_KEY }); navigate(`/pembelian/po/${res.data.id}`) },
  })
  const err = save.error instanceof ApiError ? save.error : null
  const set = (i: number, patch: Partial<OrderLine>) => setLines(lines.map((l, j) => (j === i ? { ...l, ...patch } : l)))
  const supplier = suppliers.find((s) => String(s.id) === supplierId)

  return (
    <div className="max-w-4xl">
      <PageHeader title={`Buat PO dari ${pr.number}`} purpose="Pilih supplier dan isi harga per satuan beli. Harga ini menjadi nilai stok saat barang diterima." />
      <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        <div className="grid gap-4 md:grid-cols-[1fr_12rem]">
          <Field id="po-supplier" label="Supplier" error={err?.field('supplier_id')}>
            <Select id="po-supplier" value={supplierId} onChange={(e) => setSupplierId(e.target.value)}>
              <option value="">Pilih supplier…</option>
              {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name} ({s.code})</option>)}
            </Select>
          </Field>
          <Field id="po-date" label="Perkiraan datang (opsional)" error={err?.field('expected_date')}>
            <Input id="po-date" type="date" value={expected} onChange={(e) => setExpected(e.target.value)} />
          </Field>
        </div>
        {supplier && <p className="text-sm text-muted-foreground">Termin bayar {supplier.name}: {supplier.payment_term_days === 0 ? 'tunai / saat terima' : `${supplier.payment_term_days} hari setelah tanggal tagihan`}.</p>}
        <Table>
          <thead><tr><Th>Barang</Th><Th className="w-32">Jumlah</Th><Th className="w-48">Harga per satuan</Th><Th className="text-right">Subtotal</Th></tr></thead>
          <tbody>
            {lines.map((l, i) => (
              <tr key={l.purchase_line_id}>
                <Td>{l.name}</Td>
                <Td>
                  <div className="flex items-center gap-1"><Input aria-label={`Jumlah ${l.name}`} inputMode="decimal" value={l.qty} onChange={(e) => set(i, { qty: e.target.value })} /><span className="text-sm">{l.unit}</span></div>
                  <FieldError message={err?.field(`lines.${i}.qty`)} />
                </Td>
                <Td>
                  <RupiahInput aria-label={`Harga ${l.name} per ${l.unit}`} value={l.unit_price} onValueChange={(v) => set(i, { unit_price: v })} />
                  <FieldError message={err?.field(`lines.${i}.unit_price`)} />
                </Td>
                <Td className="text-right">{formatRupiah(lineTotal(l))}</Td>
              </tr>
            ))}
            <tr className="border-t border-border font-semibold"><Td colSpan={3}>Total</Td><Td className="text-right">{formatRupiah(total)}</Td></tr>
          </tbody>
        </Table>
        {limit !== null && total > limit && (
          <InfoBanner>Total di atas batas persetujuan Finance ({formatRupiah(limit)}). PO akan menunggu persetujuan Owner sebelum dipesan ke supplier.</InfoBanner>
        )}
        <Field id="po-note" label="Catatan (opsional)"><Input id="po-note" value={note} onChange={(e) => setNote(e.target.value)} /></Field>
        {err && !Object.keys(err.errors).some((k) => k.startsWith('lines') || k === 'supplier_id') && <ErrorAlert message={err.message} />}
        <div className="flex gap-2">
          <Button type="submit" disabled={save.isPending}>Buat PO</Button>
          <Button asChild variant="ghost"><Link to={`/pembelian/pr/${pr.id}`}>Batal</Link></Button>
        </div>
      </form>
    </div>
  )
}

export function PurchaseOrderDetailPage() {
  const { id } = useParams()
  const qc = useQueryClient()
  const [mode, setMode] = useState<'none' | 'receive' | 'cancel' | 'close'>('none')
  const po = useQuery({ queryKey: [...PURCHASING_KEY, 'order', id], queryFn: () => http.get<{ data: PurchaseOrder }>(`/purchasing/orders/${id}`) })
  const act = useMutation({
    mutationFn: ({ action, reason }: { action: 'cancel' | 'close'; reason: string }) => http.post(`/purchasing/orders/${id}/${action}`, { reason }),
    onSuccess: () => { setMode('none'); qc.invalidateQueries({ queryKey: PURCHASING_KEY }) },
  })
  const err = act.error instanceof ApiError ? act.error : null
  if (po.isPending) return <Loading />
  if (po.error) return <ErrorAlert message={errorText(po.error)} />
  const o = po.data.data
  const prices = o.total !== null

  return (
    <div className="max-w-5xl space-y-4">
      <PageHeader title={`PO ${o.number}`} purpose={`${o.supplier?.name ?? ''} · dibuat ${formatDateTime(o.created_at)} oleh ${o.created_by?.name ?? '–'}`} />
      <div className="flex flex-wrap items-center gap-3 text-sm">
        <Badge variant={PO_VARIANT[o.status]}>{PO_STATUS_LABEL[o.status]}</Badge>
        {o.request && <span>dari <Link to={`/pembelian/pr/${o.request.id}`} className="font-mono hover:underline">{o.request.number}</Link></span>}
        {o.expected_date && <span>Perkiraan datang {formatDate(o.expected_date)}</span>}
        {o.supplier?.phone && <span>Telp. supplier {o.supplier.phone}</span>}
        {prices && <span className="font-semibold">Total {formatRupiah(o.total)}</span>}
      </div>
      {o.note && <p className="text-sm">Catatan: {o.note}</p>}
      {o.status === 'WAITING_OWNER' && <InfoBanner>PO ini di atas batas Finance dan menunggu Owner di halaman <Link to="/persetujuan" className="underline">Persetujuan</Link>. Barang belum boleh diterima.</InfoBanner>}
      {o.close_note && <p className="text-sm">{o.status === 'CANCELLED' ? 'Dibatalkan' : 'Ditutup'} oleh {o.closed_by?.name}: <span className="font-medium">{o.close_note}</span></p>}

      <Table>
        <thead><tr><Th>Barang</Th><Th className="text-right">Dipesan</Th><Th className="text-right">Belum diterima</Th>{prices && <><Th className="text-right">Harga</Th><Th className="text-right">Subtotal</Th></>}</tr></thead>
        <tbody>
          {o.lines?.map((l) => (
            <tr key={l.id}>
              <Td>{l.item.name}</Td>
              <Td className="text-right">{plainQty(l.qty)} {l.unit.code}</Td>
              <Td className="text-right">{Number(l.remaining) > 0 ? `${plainQty(l.remaining)} ${l.unit.code}` : '–'}</Td>
              {prices && <><Td className="text-right">{formatRupiah(l.unit_price)}</Td><Td className="text-right">{formatRupiah(l.line_total)}</Td></>}
            </tr>
          ))}
        </tbody>
      </Table>

      {mode === 'none' && (o.can.receive || o.can.cancel || o.can.close) && (
        <div className="flex flex-wrap gap-2">
          {o.can.receive && <Button onClick={() => setMode('receive')}>Terima barang</Button>}
          {o.can.cancel && <Button variant="outline" onClick={() => { act.reset(); setMode('cancel') }}>Batalkan PO</Button>}
          {o.can.close && <Button variant="outline" onClick={() => { act.reset(); setMode('close') }}>Tutup sisa pesanan</Button>}
        </div>
      )}
      {mode === 'receive' && <ReceiveForm po={o} onDone={() => setMode('none')} />}
      {(mode === 'cancel' || mode === 'close') && (
        <ReasonForm
          label={mode === 'cancel' ? 'Alasan PO dibatalkan' : 'Alasan sisa pesanan tidak ditunggu lagi'}
          submit={mode === 'cancel' ? 'Batalkan PO' : 'Tutup PO'} destructive={mode === 'cancel'}
          pending={act.isPending} error={err} onSubmit={(reason) => act.mutate({ action: mode, reason })} onCancel={() => setMode('none')}
        />
      )}
      {err && !err.field('reason') && <ErrorAlert message={err.message} />}

      <h2 className="pt-2 font-semibold">Penerimaan barang</h2>
      {o.receipts?.length === 0 && <p className="text-sm text-muted-foreground">Belum ada barang yang diterima.</p>}
      {o.receipts?.map((r) => (
        <Card key={r.id}>
          <CardContent className="space-y-2 pt-6 text-sm">
            <div className="flex flex-wrap justify-between gap-2">
              <p><span className="font-mono font-medium">{r.number}</span> · {formatDateTime(r.received_at)} · {r.received_by?.name}{r.supplier_doc && ` · surat jalan ${r.supplier_doc}`}</p>
              {prices && <p>Nilai diterima {formatRupiah(r.accepted_value)} {r.invoice ? <>· tagihan <span className="font-mono">{r.invoice.number}</span></> : o.can.invoice && <>· <Link to="/pembelian/hutang" className="underline">catat tagihan</Link></>}</p>}
            </div>
            <ul className="list-disc pl-5">
              {r.lines.map((l, i) => (
                <li key={i}>
                  {l.item.name}: diterima baik {plainQty(l.qty_accepted)}
                  {Number(l.qty_rejected) > 0 && <>, ditolak {plainQty(l.qty_rejected)} ({REJECT_REASON_LABEL[l.reject_reason ?? 'OTHER']}{l.reject_note ? `: ${l.reject_note}` : ''})</>}
                  {l.expiry_date && <> · kedaluwarsa {formatDate(l.expiry_date)}</>}
                </li>
              ))}
            </ul>
            {r.note && <p>Catatan: {r.note}</p>}
          </CardContent>
        </Card>
      ))}
    </div>
  )
}

type ReceiveRow = { qty_accepted: string; qty_rejected: string; reject_reason: string; reject_note: string; batch_no: string; expiry_date: string }

/** Penerimaan + QC Incoming (C17): hanya barang baik yang masuk stok; yang ditolak dikembalikan ke supplier dan tidak ditagih. */
function ReceiveForm({ po, onDone }: { po: PurchaseOrder; onDone: () => void }) {
  const open = (po.lines ?? []).filter((l) => Number(l.remaining) > 0)
  const [rows, setRows] = useState<Record<number, ReceiveRow>>(() => Object.fromEntries(open.map((l) => [l.id!, {
    qty_accepted: plainQty(l.remaining), qty_rejected: '', reject_reason: '', reject_note: '', batch_no: '', expiry_date: '',
  }])))
  const [doc, setDoc] = useState('')
  const [note, setNote] = useState('')
  const qc = useQueryClient()
  const save = useMutation({
    mutationFn: () => http.post(`/purchasing/orders/${po.id}/receive`, {
      supplier_doc: doc || null, note: note || null,
      lines: open.map((l) => {
        const r = rows[l.id!]
        return {
          purchase_line_id: l.id, qty_accepted: r.qty_accepted.replace(',', '.') || '0', qty_rejected: r.qty_rejected.replace(',', '.') || '0',
          reject_reason: r.reject_reason || null, reject_note: r.reject_note || null, batch_no: r.batch_no || null, expiry_date: r.expiry_date || null,
        }
      }),
    }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: PURCHASING_KEY }); qc.invalidateQueries({ queryKey: STOCK_KEY }); onDone() },
  })
  const err = save.error instanceof ApiError ? save.error : null
  const set = (lineId: number, patch: Partial<ReceiveRow>) => setRows({ ...rows, [lineId]: { ...rows[lineId], ...patch } })

  return (
    <Card>
      <CardContent className="space-y-4 pt-6">
        <InfoBanner>
          Hitung dan periksa barang yang datang. Isi jumlah yang baik di “Diterima baik”. Barang rusak, kedaluwarsa, atau salah diisi di “Ditolak” beserta alasannya —
          barang itu dikembalikan ke kurir dan tidak ditagih. Kekurangan kiriman biarkan; PO tetap terbuka sampai lengkap.
        </InfoBanner>
        <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
          <div className="overflow-x-auto">
            <Table>
              <thead><tr><Th>Barang</Th><Th className="w-28">Diterima baik</Th><Th className="w-28">Ditolak</Th><Th className="w-44">Alasan ditolak</Th><Th className="w-40">Kedaluwarsa</Th><Th className="w-36">No. batch</Th></tr></thead>
              <tbody>
                {open.map((l, i) => {
                  const r = rows[l.id!]
                  const f = (k: string) => err?.field(`lines.${i}.${k}`)
                  return (
                    <tr key={l.id} className="align-top">
                      <Td>{l.item.name}<p className="text-xs text-muted-foreground">sisa pesanan {plainQty(l.remaining)} {l.unit.code}</p></Td>
                      <Td><Input aria-label={`Diterima baik ${l.item.name}`} inputMode="decimal" value={r.qty_accepted} onChange={(e) => set(l.id!, { qty_accepted: e.target.value })} /><FieldError message={f('qty_accepted')} /></Td>
                      <Td><Input aria-label={`Ditolak ${l.item.name}`} inputMode="decimal" value={r.qty_rejected} onChange={(e) => set(l.id!, { qty_rejected: e.target.value })} /><FieldError message={f('qty_rejected')} /></Td>
                      <Td>
                        <Select aria-label={`Alasan ditolak ${l.item.name}`} value={r.reject_reason} onChange={(e) => set(l.id!, { reject_reason: e.target.value })} disabled={!(Number(r.qty_rejected.replace(',', '.')) > 0)}>
                          <option value="">–</option>
                          {Object.entries(REJECT_REASON_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                        </Select>
                        {r.reject_reason === 'OTHER' && <Input className="mt-1" aria-label={`Keterangan ditolak ${l.item.name}`} placeholder="Keterangan" value={r.reject_note} onChange={(e) => set(l.id!, { reject_note: e.target.value })} />}
                        <FieldError message={f('reject_reason')} />
                      </Td>
                      <Td><Input aria-label={`Kedaluwarsa ${l.item.name}`} type="date" value={r.expiry_date} onChange={(e) => set(l.id!, { expiry_date: e.target.value })} /><FieldError message={f('expiry_date')} /></Td>
                      <Td><Input aria-label={`Batch ${l.item.name}`} placeholder="otomatis" value={r.batch_no} onChange={(e) => set(l.id!, { batch_no: e.target.value })} /><FieldError message={f('batch_no')} /></Td>
                    </tr>
                  )
                })}
              </tbody>
            </Table>
          </div>
          <div className="grid gap-4 md:grid-cols-2">
            <Field id="rcv-doc" label="No. surat jalan / nota supplier (opsional)"><Input id="rcv-doc" value={doc} onChange={(e) => setDoc(e.target.value)} /></Field>
            <Field id="rcv-note" label="Catatan (opsional)"><Input id="rcv-note" value={note} onChange={(e) => setNote(e.target.value)} /></Field>
          </div>
          {err && !Object.keys(err.errors).some((k) => k.startsWith('lines.')) && <ErrorAlert message={err.message} />}
          <div className="flex gap-2">
            <Button type="submit" disabled={save.isPending}>Simpan penerimaan</Button>
            <Button type="button" variant="ghost" onClick={onDone}>Batal</Button>
          </div>
        </form>
      </CardContent>
    </Card>
  )
}

// --- Tagihan dan hutang supplier ---

export function PayablesPage() {
  const [tab, setTab] = useState<'OPEN' | 'ALL'>('OPEN')
  const data = useQuery({ queryKey: [...PURCHASING_KEY, 'payables', tab], queryFn: () => http.get<{ data: Payables }>(`/purchasing/payables?status=${tab}`) })
  if (data.isPending) return <Loading />
  if (data.error) return <ErrorAlert message={errorText(data.error)} />
  const p = data.data.data
  const overdue = p.by_supplier.reduce((s, x) => s + x.overdue, 0)

  return (
    <div className="max-w-6xl space-y-4">
      <PageHeader title="Hutang supplier" purpose="Tagihan dari supplier untuk barang yang sudah diterima baik, jatuh temponya, dan pembayarannya." />
      <div className="grid gap-4 sm:grid-cols-3">
        <Card><CardContent className="pt-6"><p className="text-xs text-muted-foreground">Total hutang</p><p className="text-lg font-semibold">{formatRupiah(p.total_outstanding)}</p></CardContent></Card>
        <Card><CardContent className="pt-6"><p className="text-xs text-muted-foreground">Sudah lewat jatuh tempo</p><p className={cn('text-lg font-semibold', overdue > 0 && 'text-red-700 dark:text-red-400')}>{formatRupiah(overdue)}</p></CardContent></Card>
        <Card><CardContent className="pt-6"><p className="text-xs text-muted-foreground">Penerimaan belum ditagih</p><p className="text-lg font-semibold">{p.uninvoiced.length}</p></CardContent></Card>
      </div>

      {p.uninvoiced.length > 0 && (
        <section className="space-y-2">
          <h2 className="font-semibold">Penerimaan belum ada tagihan</h2>
          <p className="text-sm text-muted-foreground">Catat tagihan sesuai nota supplier. Bila jumlahnya berbeda dari nilai barang yang diterima (harga PO), tulis alasannya; selisih itu terlihat Owner.</p>
          {p.uninvoiced.map((r) => <UninvoicedRow key={r.id} r={r} canAct={p.can_act} />)}
        </section>
      )}

      {p.by_supplier.length > 0 && (
        <Table>
          <thead><tr><Th>Supplier</Th><Th className="text-right">Sisa hutang</Th><Th className="text-right">Lewat jatuh tempo</Th></tr></thead>
          <tbody>{p.by_supplier.map((s) => <tr key={s.supplier?.id}><Td>{s.supplier?.name}</Td><Td className="text-right">{formatRupiah(s.outstanding)}</Td><Td className="text-right">{s.overdue > 0 ? formatRupiah(s.overdue) : '–'}</Td></tr>)}</tbody>
        </Table>
      )}

      <h2 className="font-semibold">Tagihan</h2>
      <Tabs value={tab} onChange={setTab} options={[['OPEN', 'Belum lunas'], ['ALL', 'Semua']] as const} />
      {p.invoices.length === 0 && <p className="text-sm text-muted-foreground">Tidak ada tagihan.</p>}
      {p.invoices.map((i) => <InvoiceCard key={i.id} invoice={i} canAct={p.can_act} />)}
    </div>
  )
}

function UninvoicedRow({ r, canAct }: { r: Payables['uninvoiced'][number]; canAct: boolean }) {
  const [open, setOpen] = useState(false)
  const [no, setNo] = useState(r.supplier_doc ?? '')
  const [date, setDate] = useState(todayWib())
  const [amount, setAmount] = useState<number | null>(r.accepted_value)
  const [diff, setDiff] = useState('')
  const qc = useQueryClient()
  const save = useMutation({
    mutationFn: () => http.post<{ data: SupplierInvoice }>(`/purchasing/receipts/${r.id}/invoice`, { invoice_no: no, invoice_date: date, amount, difference_note: diff || null }),
    onSuccess: () => qc.invalidateQueries({ queryKey: PURCHASING_KEY }),
  })
  const err = save.error instanceof ApiError ? save.error : null
  const differs = amount !== null && amount !== r.accepted_value

  return (
    <Card>
      <CardContent className="space-y-3 pt-6 text-sm">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <p><span className="font-medium">{r.supplier}</span> · <span className="font-mono">{r.number}</span> (PO {r.po}) · diterima {formatDateTime(r.received_at)}</p>
          <p>Nilai barang diterima <span className="font-semibold">{formatRupiah(r.accepted_value)}</span></p>
        </div>
        {canAct && !open && <Button size="sm" onClick={() => setOpen(true)}>Catat tagihan</Button>}
        {open && (
          <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
            <div className="grid gap-3 md:grid-cols-3">
              <Field id={`inv-no-${r.id}`} label="No. tagihan / nota supplier" error={err?.field('invoice_no')}><Input id={`inv-no-${r.id}`} value={no} onChange={(e) => setNo(e.target.value)} /></Field>
              <Field id={`inv-date-${r.id}`} label="Tanggal tagihan" error={err?.field('invoice_date')}><Input id={`inv-date-${r.id}`} type="date" value={date} onChange={(e) => setDate(e.target.value)} /></Field>
              <Field id={`inv-amt-${r.id}`} label="Jumlah tagihan" error={err?.field('amount')}><RupiahInput id={`inv-amt-${r.id}`} value={amount} onValueChange={setAmount} /></Field>
            </div>
            {r.term_days !== null && <p className="text-muted-foreground">Jatuh tempo otomatis: tanggal tagihan + {r.term_days} hari. Ongkos kirim dicatat terpisah di Biaya, bukan di sini.</p>}
            {differs && (
              <Field id={`inv-diff-${r.id}`} label="Alasan jumlah berbeda dari nilai barang diterima" error={err?.field('difference_note')}>
                <Input id={`inv-diff-${r.id}`} value={diff} onChange={(e) => setDiff(e.target.value)} />
              </Field>
            )}
            {err && !Object.keys(err.errors).length && <ErrorAlert message={err.message} />}
            {err?.field('goods_receipt_id') && <ErrorAlert message={err.field('goods_receipt_id')} />}
            <div className="flex gap-2">
              <Button type="submit" disabled={save.isPending}>Simpan tagihan</Button>
              <Button type="button" variant="ghost" onClick={() => setOpen(false)}>Batal</Button>
            </div>
          </form>
        )}
      </CardContent>
    </Card>
  )
}

function InvoiceCard({ invoice: i, canAct }: { invoice: SupplierInvoice; canAct: boolean }) {
  const [paying, setPaying] = useState(false)
  const [amount, setAmount] = useState<number | null>(i.outstanding)
  const [date, setDate] = useState(todayWib())
  const [method, setMethod] = useState<'TRANSFER' | 'CASH'>('TRANSFER')
  const [ref, setRef] = useState('')
  const qc = useQueryClient()
  const pay = useMutation({
    mutationFn: () => http.post(`/purchasing/invoices/${i.id}/pay`, { amount, paid_date: date, method, reference: ref || null }),
    onSuccess: () => { setPaying(false); qc.invalidateQueries({ queryKey: PURCHASING_KEY }) },
  })
  const err = pay.error instanceof ApiError ? pay.error : null

  return (
    <Card>
      <CardContent className="space-y-2 pt-6 text-sm">
        <div className="flex flex-wrap items-start justify-between gap-2">
          <div>
            <p className="font-medium">{i.supplier?.name} · nota {i.invoice_no}</p>
            <p className="text-muted-foreground"><span className="font-mono">{i.number}</span> · {i.receipt?.number}{i.receipt?.po && <> · PO <Link to={`/pembelian/po/${i.receipt.po.id}`} className="font-mono hover:underline">{i.receipt.po.number}</Link></>} · tanggal {formatDate(i.invoice_date)}</p>
          </div>
          <div className="text-right">
            <p>Tagihan {formatRupiah(i.amount)} · dibayar {formatRupiah(i.paid)}</p>
            <p className="font-semibold">Sisa {formatRupiah(i.outstanding)}</p>
            <p className={cn(i.overdue && 'font-medium text-red-700 dark:text-red-400')}>Jatuh tempo {formatDate(i.due_date)}{i.overdue && ' · lewat'}</p>
          </div>
        </div>
        {i.difference_note && <p className="text-amber-700 dark:text-amber-400">Berbeda dari nilai barang diterima ({formatRupiah(i.expected_amount)}): {i.difference_note}</p>}
        {i.payments.length > 0 && (
          <ul className="list-disc pl-5">
            {i.payments.map((p) => <li key={p.id}>{formatDate(p.paid_date)} · {formatRupiah(p.amount)} · {p.method === 'CASH' ? 'tunai' : 'transfer'}{p.reference && ` ${p.reference}`} · {p.by}</li>)}
          </ul>
        )}
        {canAct && i.outstanding > 0 && !paying && <Button size="sm" onClick={() => { pay.reset(); setAmount(i.outstanding); setPaying(true) }}>Catat pembayaran</Button>}
        {paying && (
          <form className="grid items-start gap-3 md:grid-cols-[12rem_10rem_9rem_1fr_auto]" onSubmit={(e) => { e.preventDefault(); pay.mutate() }}>
            <Field id={`pay-amt-${i.id}`} label="Jumlah dibayar" error={err?.field('amount')}><RupiahInput id={`pay-amt-${i.id}`} value={amount} onValueChange={setAmount} /></Field>
            <Field id={`pay-date-${i.id}`} label="Tanggal bayar" error={err?.field('paid_date')}><Input id={`pay-date-${i.id}`} type="date" value={date} onChange={(e) => setDate(e.target.value)} /></Field>
            <Field id={`pay-method-${i.id}`} label="Cara bayar">
              <Select id={`pay-method-${i.id}`} value={method} onChange={(e) => setMethod(e.target.value as 'TRANSFER' | 'CASH')}><option value="TRANSFER">Transfer</option><option value="CASH">Tunai</option></Select>
            </Field>
            <Field id={`pay-ref-${i.id}`} label="No. referensi (opsional)"><Input id={`pay-ref-${i.id}`} value={ref} onChange={(e) => setRef(e.target.value)} /></Field>
            <div className="flex gap-2 md:pt-[24px]">
              <Button type="submit" disabled={pay.isPending}>Simpan</Button>
              <Button type="button" variant="ghost" onClick={() => setPaying(false)}>Batal</Button>
            </div>
          </form>
        )}
        {err && !Object.keys(err.errors).length && <ErrorAlert message={err.message} />}
      </CardContent>
    </Card>
  )
}
