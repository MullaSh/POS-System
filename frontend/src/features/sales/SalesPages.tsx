import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Printer } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { ErrorAlert, InfoBanner, Loading, PageHeader } from '@/components/ui/feedback'
import { Field } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { EmptyRow, Table, Td, Th } from '@/components/ui/table'
import { APPROVALS_KEY } from '@/features/approvals/queries'
import { ApiError, errorText, http } from '@/lib/api'
import {
  APPROVAL_STATUS_LABEL, CHANNEL_LABEL, REFUND_CONDITION_LABEL, REFUND_REASON_LABEL, SALE_STATUS_LABEL,
  formatCost, formatDateTime, formatQty, formatRupiah, todayWib,
} from '@/lib/format'
import type { Paginated, RefundCondition, RefundReason, SaleDetail, SaleStatus, SaleSummary } from '@/lib/types'
import { SALES_KEY } from './queries'

const STATUS_VARIANT: Record<SaleStatus, 'default' | 'success' | 'warning' | 'destructive'> = {
  PAID: 'success', PARTLY_REFUNDED: 'warning', VOIDED: 'destructive',
}

/** Riwayat transaksi: kasir melihat semua transaksi outletnya (2D-Q2); Owner dan Finance semua outlet. */
export function SalesPage() {
  const [params] = useSearchParams()
  const shift = params.get('shift')
  const [date, setDate] = useState(shift ? '' : todayWib())
  const [q, setQ] = useState('')
  const sales = useQuery({
    queryKey: [...SALES_KEY, 'list', date, q, shift],
    queryFn: () => http.get<Paginated<SaleSummary> & { can_see_cost: boolean }>(`/sales?${new URLSearchParams({ ...(date ? { date } : {}), ...(q ? { q } : {}), ...(shift ? { shift_id: shift } : {}) })}`),
  })
  const rows = sales.data?.data ?? []
  const total = rows.reduce((s, r) => s + r.total - r.refunded_amount, 0)

  return (
    <div className="max-w-6xl">
      <PageHeader title="Riwayat penjualan" purpose="Transaksi yang sudah dibayar, beserta void dan refund-nya." />
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <Field id="sales-date" label="Tanggal"><Input id="sales-date" type="date" value={date} onChange={(e) => setDate(e.target.value)} /></Field>
        <Field id="sales-q" label="Cari nomor"><Input id="sales-q" value={q} placeholder="TRX-…" onChange={(e) => setQ(e.target.value)} /></Field>
        {date && <Button variant="ghost" onClick={() => setDate('')}>Semua tanggal</Button>}
        {shift && <Button asChild variant="ghost"><Link to="/penjualan">Hapus filter shift</Link></Button>}
      </div>
      {sales.isPending ? <Loading /> : sales.error ? <ErrorAlert message={errorText(sales.error)} /> : (
        <>
          <p className="mb-2 text-sm text-muted-foreground">{sales.data.total} transaksi · penjualan bersih di halaman ini {formatRupiah(total)}</p>
          <Table>
            <thead><tr><Th>Nomor</Th><Th>Waktu</Th><Th>Outlet</Th><Th>Kasir</Th><Th>Jenis</Th><Th>Bayar</Th><Th className="text-right">Total</Th>{sales.data.can_see_cost && <Th className="text-right">HPP</Th>}<Th>Status</Th></tr></thead>
            <tbody>
              {rows.length === 0 && <EmptyRow colSpan={9}>Belum ada transaksi.</EmptyRow>}
              {rows.map((s) => (
                <tr key={s.id}>
                  <Td className="font-mono"><Link to={`/penjualan/${s.id}`} className="hover:underline">{s.number}</Link></Td>
                  <Td>{formatDateTime(s.paid_at)}</Td>
                  <Td>{s.location.name}</Td>
                  <Td>{s.cashier?.name}</Td>
                  <Td>{CHANNEL_LABEL[s.channel]}{s.customer_note && <p className="text-xs text-muted-foreground">{s.customer_note}</p>}</Td>
                  <Td>{s.methods.join(' + ')}</Td>
                  <Td className="text-right">{formatRupiah(s.total)}{s.refunded_amount > 0 && <p className="text-xs text-destructive">−{formatRupiah(s.refunded_amount)}</p>}</Td>
                  {sales.data.can_see_cost && <Td className="text-right">{s.hpp_total === null ? 'belum lengkap' : formatCost(s.hpp_total)}</Td>}
                  <Td>
                    <Badge variant={STATUS_VARIANT[s.status]}>{SALE_STATUS_LABEL[s.status]}</Badge>
                    {s.has_pending_refund && <p className="mt-1"><Badge variant="warning">Pembatalan menunggu</Badge></p>}
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        </>
      )}
    </div>
  )
}

export function SaleDetailPage() {
  const { id } = useParams()
  const qc = useQueryClient()
  const [refunding, setRefunding] = useState(false)
  const sale = useQuery({ queryKey: [...SALES_KEY, 'detail', id], queryFn: () => http.get<{ data: SaleDetail }>(`/sales/${id}`) })
  const cancel = useMutation({
    mutationFn: (approvalId: number) => http.post(`/approvals/${approvalId}/cancel`, {}),
    onSuccess: () => { qc.invalidateQueries({ queryKey: SALES_KEY }); qc.invalidateQueries({ queryKey: APPROVALS_KEY }) },
  })

  if (sale.isPending) return <Loading />
  if (sale.error) return <ErrorAlert message={errorText(sale.error)} />
  const s = sale.data.data

  return (
    <div className="max-w-4xl space-y-4">
      <PageHeader
        title={`Transaksi ${s.number}`}
        purpose={`${s.location.name} · ${formatDateTime(s.paid_at)} · kasir ${s.cashier?.name ?? '–'} · ${CHANNEL_LABEL[s.channel]}`}
        actions={<Button asChild variant="outline"><a href={`/penjualan/${s.id}/struk`} target="_blank" rel="noreferrer"><Printer className="size-4" /> Cetak struk</a></Button>}
      />
      <div className="flex flex-wrap items-center gap-3 text-sm">
        <Badge variant={STATUS_VARIANT[s.status]}>{SALE_STATUS_LABEL[s.status]}</Badge>
        {s.shift && <span>Shift <Link to={`/shift/${s.shift.id}`} className="font-mono hover:underline">{s.shift.number}</Link></span>}
        {s.customer_note && <span>Catatan: {s.customer_note}</span>}
      </div>

      <Table>
        <thead><tr><Th>Menu</Th><Th className="text-right">Porsi</Th><Th className="text-right">Harga</Th><Th className="text-right">Jumlah</Th>{s.can_see_cost && <Th className="text-right">HPP / porsi</Th>}</tr></thead>
        <tbody>
          {s.lines.map((l) => (
            <tr key={l.id}>
              <Td>
                {l.item.name}
                {l.refunded_qty > 0 && <p className="text-xs text-destructive">{l.refunded_qty} porsi dibatalkan</p>}
                <p className="text-xs text-muted-foreground">
                  Bahan dipotong: {l.components.map((c) => `${c.item.name} ${formatQty(c.qty)} ${c.base_unit ?? ''}`).join(', ')}
                </p>
              </Td>
              <Td className="text-right">{l.qty}</Td>
              <Td className="text-right">{formatRupiah(l.unit_price)}</Td>
              <Td className="text-right">{formatRupiah(l.line_total)}</Td>
              {s.can_see_cost && <Td className="text-right">{l.unit_hpp === null ? 'belum lengkap' : formatCost(l.unit_hpp)}{l.recipe_version && <p className="text-xs text-muted-foreground">resep v{l.recipe_version.version_no}</p>}</Td>}
            </tr>
          ))}
        </tbody>
        <tfoot>
          <tr><Td colSpan={3} className="text-right font-medium">Total</Td><Td className="text-right font-semibold">{formatRupiah(s.total)}</Td>{s.can_see_cost && <Td className="text-right">{s.hpp_total === null ? '–' : formatCost(s.hpp_total)}</Td>}</tr>
        </tfoot>
      </Table>

      <Card>
        <CardContent className="space-y-1 pt-6 text-sm">
          <p className="font-medium">Pembayaran</p>
          {s.payments.map((p) => (
            <p key={p.payment_method_id}>
              {p.name}: {formatRupiah(p.amount)}
              {p.is_cash && p.received !== null && <span className="text-muted-foreground"> (diterima {formatRupiah(p.received)}, kembali {formatRupiah(p.change)})</span>}
              {p.reference && <span className="text-muted-foreground"> · ref {p.reference}</span>}
            </p>
          ))}
          {s.can_see_cost && s.hpp_complete === false && <p className="text-amber-700">Sebagian bahan belum punya harga pokok saat transaksi, sehingga HPP belum lengkap.</p>}
        </CardContent>
      </Card>

      {s.refunds.length > 0 && (
        <section className="space-y-2">
          <h2 className="font-semibold">Void / refund</h2>
          {s.refunds.map((r) => (
            <Card key={r.id}>
              <CardContent className="space-y-1 pt-6 text-sm">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="font-medium"><span className="font-mono">{r.number}</span> · {r.kind === 'VOID' ? 'Void' : 'Refund'} {r.amount !== null && formatRupiah(r.amount)}</p>
                  {r.approval && <Badge variant={r.approval.status === 'APPROVED' ? 'success' : r.approval.status === 'PENDING' || r.approval.status === 'RETURNED' ? 'warning' : 'default'}>{APPROVAL_STATUS_LABEL[r.approval.status]}</Badge>}
                </div>
                <p>{REFUND_REASON_LABEL[r.reason_code]}{r.reason && ` — ${r.reason}`} · diajukan {r.requested_by?.name} {formatDateTime(r.created_at)}</p>
                <ul className="list-disc pl-5">
                  {r.lines.map((l) => <li key={l.sale_line_id}>{l.item_name} × {l.qty} ({formatRupiah(l.amount)}) — {REFUND_CONDITION_LABEL[l.condition]}</li>)}
                </ul>
                {r.can_cancel && r.approval && (
                  <Button size="sm" variant="ghost" disabled={cancel.isPending} onClick={() => cancel.mutate(r.approval!.id)}>Batalkan pengajuan ini</Button>
                )}
                {r.payments.length > 0 && <p>Uang kembali: {r.payments.map((p) => `${p.method_name} ${formatRupiah(p.amount)}`).join(', ')}</p>}
                {r.approval?.decider && (
                  <p className="text-muted-foreground">
                    Diputuskan {r.approval.decider.name} {formatDateTime(r.approval.decided_at)}
                    {r.approval.delegation && ` atas delegasi ${r.approval.delegation.delegator?.name ?? ''} (${r.approval.delegation.number})`}
                    {r.approval.decision_note && ` — ${r.approval.decision_note}`}
                  </p>
                )}
              </CardContent>
            </Card>
          ))}
        </section>
      )}

      <ErrorAlert message={errorText(cancel.error)} />
      {s.can.refund && !refunding && <Button variant="outline" onClick={() => setRefunding(true)}>Ajukan void / refund</Button>}
      {!s.can.refund && s.refund_blocker && <InfoBanner>{s.refund_blocker}</InfoBanner>}
      {refunding && <RefundForm sale={s} onDone={() => setRefunding(false)} />}
      <p><Link to="/penjualan" className="text-sm hover:underline">← Riwayat penjualan</Link></p>
    </div>
  )
}

function RefundForm({ sale, onDone }: { sale: SaleDetail; onDone: () => void }) {
  const qc = useQueryClient()
  const open = sale.lines.filter((l) => l.qty - l.refunded_qty > 0)
  const [rows, setRows] = useState<Record<number, { qty: number; condition: RefundCondition }>>(() =>
    Object.fromEntries(open.map((l) => [l.id, { qty: 0, condition: 'NOT_MADE' as RefundCondition }])))
  const selectAll = () => setRows(Object.fromEntries(open.map((l) => [l.id, { ...rows[l.id], qty: l.qty - l.refunded_qty }])))
  const [reasonCode, setReasonCode] = useState<RefundReason>('CUSTOMER_CANCEL')
  const [reason, setReason] = useState('')
  const submit = useMutation({
    mutationFn: () => http.post(`/sales/${sale.id}/refunds`, {
      reason_code: reasonCode,
      reason,
      lines: Object.entries(rows).filter(([, r]) => r.qty > 0).map(([id, r]) => ({ sale_line_id: Number(id), qty: r.qty, condition: r.condition })),
    }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: SALES_KEY })
      qc.invalidateQueries({ queryKey: APPROVALS_KEY })
      onDone()
    },
  })
  const err = submit.error instanceof ApiError ? submit.error : null
  const amount = open.reduce((s, l) => s + (rows[l.id]?.qty ?? 0) * l.unit_price, 0)

  return (
    <Card>
      <CardContent className="space-y-4 pt-6">
        <div>
          <h2 className="font-semibold">Ajukan void / refund</h2>
          <p className="text-sm text-muted-foreground">Berlaku setelah disetujui Owner (atau penerima delegasinya). Uang tunai dikembalikan dari laci shift Anda yang sedang berjalan.</p>
        </div>
        <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); submit.mutate() }}>
          <Table>
            <thead><tr><Th>Menu</Th><Th className="text-right">Porsi dibatalkan</Th><Th>Kondisi makanan</Th></tr></thead>
            <tbody>
              {open.map((l, i) => {
                const max = l.qty - l.refunded_qty
                const row = rows[l.id]
                return (
                  <tr key={l.id}>
                    <Td>{l.item.name}<p className="text-xs text-muted-foreground">maks. {max} porsi</p></Td>
                    <Td className="text-right">
                      <Input aria-label={`Porsi ${l.item.name}`} type="number" min={0} max={max} className="ml-auto w-20 text-right" value={row.qty}
                        onChange={(e) => setRows({ ...rows, [l.id]: { ...row, qty: Math.max(0, Math.min(max, Number(e.target.value) || 0)) } })} />
                      {err?.field(`lines.${i}.qty`) && <p className="text-xs text-destructive">{err.field(`lines.${i}.qty`)}</p>}
                    </Td>
                    <Td>
                      <Select aria-label={`Kondisi ${l.item.name}`} value={row.condition} onChange={(e) => setRows({ ...rows, [l.id]: { ...row, condition: e.target.value as RefundCondition } })}>
                        {(['NOT_MADE', 'MADE'] as const).map((c) => <option key={c} value={c}>{REFUND_CONDITION_LABEL[c]}</option>)}
                      </Select>
                    </Td>
                  </tr>
                )
              })}
            </tbody>
          </Table>
          <div className="grid gap-4 md:grid-cols-[14rem_1fr]">
            <Field id="refund-code" label="Jenis alasan">
              <Select id="refund-code" value={reasonCode} onChange={(e) => setReasonCode(e.target.value as RefundReason)}>
                {(Object.keys(REFUND_REASON_LABEL) as RefundReason[]).map((k) => <option key={k} value={k}>{REFUND_REASON_LABEL[k]}</option>)}
              </Select>
            </Field>
            <Field id="refund-reason" label="Penjelasan (wajib)" error={err?.field('reason')}>
              <Input id="refund-reason" value={reason} onChange={(e) => setReason(e.target.value)} />
            </Field>
          </div>
          <div className="flex flex-wrap items-center gap-3">
            <Button type="button" size="sm" variant="outline" onClick={selectAll}>Pilih semua porsi (void)</Button>
            <p className="text-sm">Uang yang dikembalikan: <span className="font-semibold">{formatRupiah(amount)}</span></p>
          </div>
          {err && !err.field('reason') && !Object.keys(err.errors).some((k) => k.endsWith('.qty')) && <ErrorAlert message={Object.values(err.errors)[0]?.[0] ?? err.message} />}
          <div className="flex gap-2">
            <Button type="submit" disabled={submit.isPending || amount === 0}>Kirim pengajuan</Button>
            <Button type="button" variant="ghost" onClick={onDone}>Batal</Button>
          </div>
        </form>
      </CardContent>
    </Card>
  )
}

/** Struk untuk dicetak dari browser (dokumen 11 §3.1; OD-25 printer belum diputuskan). */
export function ReceiptPage() {
  const { id } = useParams()
  const sale = useQuery({ queryKey: [...SALES_KEY, 'detail', id], queryFn: () => http.get<{ data: SaleDetail }>(`/sales/${id}`) })
  if (sale.isPending) return <Loading />
  if (sale.error) return <ErrorAlert message={errorText(sale.error)} />
  const s = sale.data.data

  return (
    <div className="mx-auto max-w-xs bg-white p-4 font-mono text-xs text-black print:p-0">
      <style>{'@media print { @page { size: 80mm auto; margin: 4mm } body { background: white } .no-print { display: none } }'}</style>
      <p className="text-center text-sm font-bold">{s.location.name}</p>
      <p className="text-center">{s.number}</p>
      <p className="text-center">{formatDateTime(s.paid_at)} · {s.cashier?.name}</p>
      <p className="text-center">{CHANNEL_LABEL[s.channel]}{s.customer_note && ` · ${s.customer_note}`}</p>
      <hr className="my-2 border-dashed border-black" />
      {s.lines.map((l) => (
        <div key={l.id} className="mb-1">
          <p>{l.item.name}</p>
          <p className="flex justify-between"><span>{l.qty} × {formatRupiah(l.unit_price)}</span><span>{formatRupiah(l.line_total)}</span></p>
        </div>
      ))}
      <hr className="my-2 border-dashed border-black" />
      <p className="flex justify-between font-bold"><span>Total</span><span>{formatRupiah(s.total)}</span></p>
      {s.payments.map((p) => (
        <p key={p.payment_method_id} className="flex justify-between"><span>{p.name}</span><span>{formatRupiah(p.is_cash ? p.received : p.amount)}</span></p>
      ))}
      {s.change_total > 0 && <p className="flex justify-between"><span>Kembali</span><span>{formatRupiah(s.change_total)}</span></p>}
      {s.refunded_amount > 0 && <p className="mt-1 text-center">Dibatalkan {formatRupiah(s.refunded_amount)}</p>}
      <hr className="my-2 border-dashed border-black" />
      <p className="text-center">Terima kasih</p>
      <div className="no-print mt-4 flex justify-center gap-2 font-sans">
        <Button size="sm" onClick={() => window.print()}><Printer className="size-3" /> Cetak</Button>
      </div>
    </div>
  )
}
