import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Minus, Plus, Printer, X } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { ErrorAlert, InfoBanner, Loading, PageHeader } from '@/components/ui/feedback'
import { Field } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { RupiahInput } from '@/components/ui/rupiah-input'
import { Select } from '@/components/ui/select'
import { ApiError, errorText, http } from '@/lib/api'
import { CHANNEL_LABEL, formatDateTime, formatRupiah } from '@/lib/format'
import type { PosContext, PosPaymentMethod, SaleChannel, SaleDetail } from '@/lib/types'
import { cn } from '@/lib/utils'
import { SALES_KEY } from '@/features/sales/queries'
import { POS_KEY, paymentSummary, usePosContext, type CartLine, type PaymentRow } from './queries'

/**
 * Layar kasir (dokumen 11 §3.1 nomor 5). Transaksi dicatat saat dibayar; menu yang dibatalkan
 * sebelum bayar cukup dihapus dari keranjang (AB-24).
 */
export function PosPage() {
  const [locationId, setLocationId] = useState<number | null>(null)
  const ctx = usePosContext(locationId)

  if (ctx.isPending) return <Loading />
  if (ctx.error) return <ErrorAlert message={errorText(ctx.error)} />
  const c = ctx.data.data
  if (c.outlets.length === 0 || !c.location) return <ErrorAlert message="Anda belum ditugaskan sebagai kasir di outlet yang aktif." />

  return (
    <div>
      <PageHeader
        title="Kasir"
        purpose={`Catat pesanan dan pembayaran di ${c.location.name}.`}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            {c.outlets.length > 1 && (
              <Select aria-label="Outlet" className="w-48" value={c.location.id} onChange={(e) => setLocationId(Number(e.target.value))}>
                {c.outlets.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
              </Select>
            )}
            {c.shift?.is_mine && <Button asChild variant="outline"><Link to={`/shift/${c.shift.id}`}>Tutup shift</Link></Button>}
          </div>
        }
      />
      {!c.shift ? (
        <OpenShiftCard context={c} />
      ) : !c.shift.is_mine ? (
        <InfoBanner>
          Shift {c.shift.number} di {c.location.name} sedang dipakai {c.shift.cashier?.name} sejak {formatDateTime(c.shift.opened_at)}.
          Satu outlet hanya punya satu laci, jadi minta {c.shift.cashier?.name} menutup shift dulu sebelum Anda membuka shift.
        </InfoBanner>
      ) : c.shift.status !== 'OPEN' ? (
        <InfoBanner>
          Hasil hitung uang shift {c.shift.number} sudah dikirim. <Link to={`/shift/${c.shift.id}`} className="font-medium underline">Selesaikan penutupan shift</Link> sebelum membuka shift baru.
        </InfoBanner>
      ) : (
        <Register context={c} />
      )}
    </div>
  )
}

function OpenShiftCard({ context: c }: { context: PosContext }) {
  const qc = useQueryClient()
  const [counted, setCounted] = useState<number | null>(c.float_expected)
  const [note, setNote] = useState('')
  const open = useMutation({
    mutationFn: () => http.post('/shifts', { location_id: c.location?.id, float_counted: counted ?? 0, note: note || null }),
    onSuccess: () => qc.invalidateQueries({ queryKey: POS_KEY }),
  })
  const err = open.error instanceof ApiError ? open.error : null
  const differs = counted !== null && counted !== c.float_expected

  return (
    <Card className="max-w-xl">
      <CardContent className="space-y-4 pt-6">
        <div>
          <h2 className="text-lg font-semibold">Buka shift</h2>
          <p className="text-sm text-muted-foreground">
            Hitung uang kembalian (Cash Float) di laci. Seharusnya <span className="font-medium text-foreground">{formatRupiah(c.float_expected)}</span>.
            Cash Float hanya untuk kembalian dan tetap di laci saat shift ditutup.
          </p>
        </div>
        <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); open.mutate() }}>
          <Field id="float" label="Cash Float yang ada di laci" error={err?.field('float_counted')}>
            <RupiahInput id="float" value={counted} onValueChange={setCounted} />
          </Field>
          {(differs || err?.field('note')) && (
            <Field id="float-note" label="Penjelasan perbedaan (wajib)" error={err?.field('note')}>
              <Input id="float-note" value={note} onChange={(e) => setNote(e.target.value)} placeholder="mis. kurang Rp5.000 dari shift kemarin" />
            </Field>
          )}
          {err && !err.field('note') && !err.field('float_counted') && <ErrorAlert message={err.message} />}
          <Button type="submit" disabled={open.isPending || counted === null}>Buka shift</Button>
        </form>
      </CardContent>
    </Card>
  )
}

let rowKey = 1

function Register({ context: c }: { context: PosContext }) {
  const qc = useQueryClient()
  const methods = c.payment_methods
  const cashMethod = methods.find((m) => m.is_cash)
  const cashIds = new Set(methods.filter((m) => m.is_cash).map((m) => m.id))
  const [cart, setCart] = useState<CartLine[]>([])
  const [channel, setChannel] = useState<SaleChannel>('DINE_IN')
  const [note, setNote] = useState('')
  const [payments, setPayments] = useState<PaymentRow[]>(() => (cashMethod ? [{ key: rowKey++, payment_method_id: cashMethod.id, amount: null, reference: '' }] : []))
  const [done, setDone] = useState<{ sale: SaleDetail; warnings: string[] } | null>(null)

  const total = cart.reduce((s, l) => s + l.price * l.qty, 0)
  const pay = paymentSummary(total, payments, cashIds)

  const add = (id: number, name: string, price: number) => {
    setDone(null)
    setCart((prev) => {
      const found = prev.find((l) => l.item_id === id)
      return found ? prev.map((l) => (l.item_id === id ? { ...l, qty: l.qty + 1 } : l)) : [...prev, { item_id: id, name, price, qty: 1 }]
    })
  }
  const setQty = (id: number, qty: number) => setCart((prev) => (qty <= 0 ? prev.filter((l) => l.item_id !== id) : prev.map((l) => (l.item_id === id ? { ...l, qty } : l))))
  const reset = () => {
    setCart([])
    setNote('')
    setChannel('DINE_IN')
    setPayments(cashMethod ? [{ key: rowKey++, payment_method_id: cashMethod.id, amount: null, reference: '' }] : [])
  }

  const save = useMutation({
    mutationFn: () => http.post<{ data: SaleDetail; warnings: string[] }>('/sales', {
      location_id: c.location?.id,
      channel,
      customer_note: note || null,
      lines: cart.map((l) => ({ item_id: l.item_id, qty: l.qty })),
      payments: payments.filter((p) => (p.amount ?? 0) > 0).map((p) => ({ payment_method_id: p.payment_method_id, amount: p.amount, reference: p.reference || null })),
    }),
    onSuccess: (res) => {
      setDone({ sale: res.data, warnings: res.warnings })
      reset()
      qc.invalidateQueries({ queryKey: SALES_KEY })
    },
  })
  const err = save.error instanceof ApiError ? save.error : null
  const usedMethods = new Set(payments.map((p) => p.payment_method_id))
  const nextMethod = methods.find((m) => !usedMethods.has(m.id))

  return (
    <div className="grid gap-6 lg:grid-cols-[1fr_24rem]">
      <section aria-label="Menu">
        <p className="mb-3 text-sm text-muted-foreground">
          Shift <Link to={`/shift/${c.shift?.id}`} className="font-mono hover:underline">{c.shift?.number}</Link> · dibuka {formatDateTime(c.shift?.opened_at)}
        </p>
        <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
          {c.menu.map((m) => (
            <button
              key={m.id}
              type="button"
              disabled={!m.sellable}
              onClick={() => m.price !== null && add(m.id, m.name, m.price)}
              className={cn('rounded-xl border border-border bg-background p-4 text-left shadow-sm transition hover:border-primary disabled:cursor-not-allowed disabled:opacity-50')}
            >
              <span className="block font-medium">{m.name}</span>
              <span className="mt-1 block text-sm">{m.price === null ? '–' : formatRupiah(m.price)}</span>
              {m.problem && <span className="mt-1 block text-xs text-destructive">{m.problem}</span>}
            </button>
          ))}
          {c.menu.length === 0 && <p className="text-sm text-muted-foreground">Belum ada menu aktif.</p>}
        </div>
      </section>

      <aside aria-label="Pesanan">
        <Card>
          <CardContent className="space-y-4 pt-6">
            {done && <SaleDone result={done} onClose={() => setDone(null)} />}
            <div role="radiogroup" aria-label="Jenis pesanan" className="inline-flex rounded-lg border border-border p-1">
              {(['DINE_IN', 'TAKEAWAY'] as const).map((ch) => (
                <button key={ch} type="button" role="radio" aria-checked={channel === ch} onClick={() => setChannel(ch)}
                  className={cn('rounded-md px-3 py-1.5 text-sm', channel === ch ? 'bg-muted font-medium' : 'text-muted-foreground')}>{CHANNEL_LABEL[ch]}</button>
              ))}
            </div>

            {cart.length === 0 ? (
              <p className="py-4 text-center text-sm text-muted-foreground">Pilih menu di sebelah kiri.</p>
            ) : (
              <ul className="divide-y divide-border">
                {cart.map((l) => (
                  <li key={l.item_id} className="flex items-center gap-2 py-2">
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-medium">{l.name}</p>
                      <p className="text-xs text-muted-foreground">{formatRupiah(l.price)}</p>
                    </div>
                    <Button type="button" size="sm" variant="outline" aria-label={`Kurangi ${l.name}`} onClick={() => setQty(l.item_id, l.qty - 1)}><Minus className="size-3" /></Button>
                    <span className="w-6 text-center text-sm" aria-label={`Jumlah ${l.name}`}>{l.qty}</span>
                    <Button type="button" size="sm" variant="outline" aria-label={`Tambah ${l.name}`} onClick={() => setQty(l.item_id, l.qty + 1)}><Plus className="size-3" /></Button>
                    <span className="w-20 text-right text-sm">{formatRupiah(l.price * l.qty)}</span>
                    <Button type="button" size="sm" variant="ghost" aria-label={`Hapus ${l.name}`} onClick={() => setQty(l.item_id, 0)}><X className="size-3" /></Button>
                  </li>
                ))}
              </ul>
            )}
            <div className="flex items-baseline justify-between border-t border-border pt-3">
              <span className="text-sm">Total</span>
              <span className="text-2xl font-semibold" data-testid="pos-total">{formatRupiah(total)}</span>
            </div>

            <Field id="pos-note" label="Nama / catatan pesanan (opsional)">
              <Input id="pos-note" value={note} maxLength={100} onChange={(e) => setNote(e.target.value)} />
            </Field>

            <fieldset className="space-y-2">
              <legend className="text-sm font-medium">Pembayaran</legend>
              {payments.map((p) => (
                <PaymentInput
                  key={p.key}
                  row={p}
                  methods={methods.filter((m) => m.id === p.payment_method_id || !usedMethods.has(m.id))}
                  isCash={cashIds.has(p.payment_method_id)}
                  remaining={pay.remaining}
                  canRemove={payments.length > 1}
                  onChange={(next) => setPayments((prev) => prev.map((r) => (r.key === p.key ? next : r)))}
                  onRemove={() => setPayments((prev) => prev.filter((r) => r.key !== p.key))}
                />
              ))}
              {nextMethod && (
                <Button type="button" size="sm" variant="ghost" onClick={() => setPayments((prev) => [...prev, { key: rowKey++, payment_method_id: nextMethod.id, amount: null, reference: '' }])}>
                  <Plus className="size-3" /> Bayar campur
                </Button>
              )}
              {cart.length > 0 && payments.some((p) => cashIds.has(p.payment_method_id)) && pay.change > 0 && !pay.problem && (
                <p className="text-sm">Kembalian: <span className="text-lg font-semibold">{formatRupiah(pay.change)}</span></p>
              )}
              {cart.length > 0 && pay.problem && payments.some((p) => p.amount !== null) && <p className="text-sm text-destructive">{pay.problem}</p>}
            </fieldset>

            {err && <ErrorAlert message={Object.values(err.errors)[0]?.[0] ?? err.message} />}
            <Button className="w-full" size="lg" disabled={cart.length === 0 || !!pay.problem || save.isPending} onClick={() => save.mutate()}>
              Bayar {formatRupiah(total)}
            </Button>
            {cart.length > 0 && <Button className="w-full" variant="ghost" onClick={reset}>Kosongkan pesanan</Button>}
          </CardContent>
        </Card>
      </aside>
    </div>
  )
}

function PaymentInput({ row, methods, isCash, remaining, canRemove, onChange, onRemove }: {
  row: PaymentRow
  methods: PosPaymentMethod[]
  isCash: boolean
  remaining: number
  canRemove: boolean
  onChange: (row: PaymentRow) => void
  onRemove: () => void
}) {
  return (
    <div className="space-y-2 rounded-lg border border-border p-2">
      <div className="flex gap-2">
        <Select aria-label="Metode bayar" className="w-32" value={row.payment_method_id} onChange={(e) => onChange({ ...row, payment_method_id: Number(e.target.value) })}>
          {methods.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
        </Select>
        <div className="flex-1">
          <RupiahInput aria-label={isCash ? 'Uang diterima' : 'Jumlah dibayar'} placeholder={isCash ? 'Uang diterima' : 'Jumlah'} value={row.amount} onValueChange={(v) => onChange({ ...row, amount: v })} />
        </div>
        {canRemove && <Button type="button" size="sm" variant="ghost" aria-label="Hapus pembayaran" className="h-10" onClick={onRemove}><X className="size-3" /></Button>}
      </div>
      {isCash ? (
        <div className="flex flex-wrap gap-1">
          {[remaining, 50000, 100000].filter((v, i, a) => v > 0 && a.indexOf(v) === i).map((v) => (
            <Button key={v} type="button" size="sm" variant="outline" onClick={() => onChange({ ...row, amount: v })}>
              {v === remaining ? 'Uang pas' : formatRupiah(v)}
            </Button>
          ))}
        </div>
      ) : (
        <div className="flex gap-2">
          <Button type="button" size="sm" variant="outline" onClick={() => onChange({ ...row, amount: remaining || row.amount })}>Sisa tagihan</Button>
          <Input aria-label="Nomor referensi" placeholder="No. referensi (opsional)" value={row.reference} onChange={(e) => onChange({ ...row, reference: e.target.value })} className="h-8" />
        </div>
      )}
    </div>
  )
}

function SaleDone({ result, onClose }: { result: { sale: SaleDetail; warnings: string[] }; onClose: () => void }) {
  const s = result.sale
  return (
    <div role="status" className="space-y-2 rounded-lg border border-success/40 bg-success/10 p-3 text-sm">
      <p className="font-medium">Transaksi <Link to={`/penjualan/${s.id}`} className="font-mono hover:underline">{s.number}</Link> tercatat — {formatRupiah(s.total)}</p>
      {s.change_total > 0 && <p>Kembalian: <span className="text-lg font-semibold">{formatRupiah(s.change_total)}</span></p>}
      {result.warnings.map((w) => <p key={w} className="text-amber-700 dark:text-amber-400">{w}</p>)}
      <div className="flex gap-2">
        <Button asChild size="sm" variant="outline"><a href={`/penjualan/${s.id}/struk`} target="_blank" rel="noreferrer"><Printer className="size-3" /> Cetak struk</a></Button>
        <Button size="sm" variant="ghost" onClick={onClose}>Tutup</Button>
      </div>
    </div>
  )
}
