import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { ErrorAlert, InfoBanner, Loading, PageHeader } from '@/components/ui/feedback'
import { Field } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { RupiahInput } from '@/components/ui/rupiah-input'
import { Select } from '@/components/ui/select'
import { EmptyRow, Table, Td, Th } from '@/components/ui/table'
import { hasRole, useMe } from '@/features/auth/useAuth'
import { ApiError, errorText, http } from '@/lib/api'
import {
  EXPENSE_STATUS_LABEL, HANDOVER_STAGE_LABEL, PETTY_MOVEMENT_LABEL, formatDate, formatDateTime, formatRupiah, formatSignedRupiah,
} from '@/lib/format'
import type { CashHandover, CashOverview, Discrepancy, Expense, HandoverPending, Location, Paginated, PettyCash, ReconciliationRow, SimpleMaster } from '@/lib/types'
import { cn } from '@/lib/utils'
import { CASH_KEY } from './queries'


function useOverview() {
  return useQuery({ queryKey: [...CASH_KEY, 'overview'], queryFn: () => http.get<{ data: CashOverview }>('/cash/overview') })
}

function Money({ value, label, hint, warn }: { value: number | null; label: string; hint?: string; warn?: boolean }) {
  return (
    <div>
      <p className="text-xs text-muted-foreground">{label}</p>
      <p className={cn('text-lg font-semibold', warn && 'text-amber-700 dark:text-amber-400')}>{value === null ? '–' : formatRupiah(value)}</p>
      {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
    </div>
  )
}

/** Ringkasan tiga dana per outlet (C14) dan kas yang sedang menuju Finance. */
export function CashOverviewPage() {
  const overview = useOverview()
  if (overview.isPending) return <Loading />
  if (overview.error) return <ErrorAlert message={errorText(overview.error)} />
  const o = overview.data.data

  return (
    <div className="max-w-6xl space-y-4">
      <PageHeader
        title="Kas"
        purpose="Posisi Cash Float, Sales Cash, dan Petty Cash di setiap outlet, serta kas yang sedang menuju Finance."
        actions={<div className="flex gap-2"><Button asChild><Link to="/kas/serah-terima/baru">Serahkan kas</Link></Button></div>}
      />
      <InfoBanner>
        Tiga dana terpisah dan tidak saling dipakai: Cash Float hanya untuk kembalian, Sales Cash diserahkan utuh lewat gudang ke Finance,
        dan biaya outlet dibayar dari Petty Cash.
      </InfoBanner>
      {o.sees_all && o.open_discrepancies !== null && o.open_discrepancies > 0 && (
        <p className="text-sm"><Link to="/kas/selisih" className="font-medium text-amber-700 underline">{o.open_discrepancies} selisih kas menunggu keputusan Finance</Link></p>
      )}
      <div className="grid gap-4 lg:grid-cols-2">
        {o.outlets.map((x) => (
          <Card key={x.location.id}>
            <CardContent className="space-y-4 pt-6">
              <div className="flex items-center justify-between">
                <h2 className="font-semibold">{x.location.name}</h2>
                {x.shift_running && <Badge variant="success">Shift berjalan</Badge>}
              </div>
              <div className="grid grid-cols-3 gap-3">
                <Money label="Cash Float di laci" value={x.cash_float} warn={x.cash_float !== null && x.cash_float < x.cash_float_target}
                  hint={x.cash_float !== null && x.cash_float < x.cash_float_target ? `kurang dari ${formatRupiah(x.cash_float_target)}` : undefined} />
                <Money label="Sales Cash belum diserahkan" value={x.sales_cash_at_outlet} hint={`${x.shifts_to_hand_over} shift`} />
                <Money label="Petty Cash" value={x.petty_cash} warn={x.petty_cash_target !== null && x.petty_cash < x.petty_cash_target / 4}
                  hint={x.petty_cash_target === null ? 'saldo tetap belum diatur' : `dari ${formatRupiah(x.petty_cash_target)}`} />
              </div>
              {x.sales_cash_in_transit > 0 && <p className="text-sm">Sedang diserahkan ke gudang: <span className="font-medium">{formatRupiah(x.sales_cash_in_transit)}</span></p>}
              <div className="flex flex-wrap items-center gap-2 text-sm">
                <Button asChild size="sm" variant="outline"><Link to={`/kas/petty-cash/${x.location.id}`}>Petty Cash</Link></Button>
                {x.expenses_to_check > 0 && <Badge variant="warning">{x.expenses_to_check} biaya menunggu diperiksa</Badge>}
                {x.refill_requested && <Badge variant="warning">Isi ulang diminta</Badge>}
              </div>
            </CardContent>
          </Card>
        ))}
        {o.warehouse && (
          <Card>
            <CardContent className="space-y-4 pt-6">
              <h2 className="font-semibold">{o.warehouse.location.name}</h2>
              <div className="grid grid-cols-3 gap-3">
                <Money label="Dikirim outlet, belum diterima" value={o.warehouse.incoming} />
                <Money label="Kas di gudang" value={o.warehouse.at_warehouse} hint="sudah diterima, belum ke Finance" />
                <Money label="Dikirim ke Finance" value={o.warehouse.to_finance} hint="menunggu konfirmasi Finance" />
              </div>
            </CardContent>
          </Card>
        )}
      </div>
    </div>
  )
}

// --- Serah terima ---

export function HandoversPage() {
  const [tab, setTab] = useState<'SENT' | 'RECEIVED'>('SENT')
  const list = useQuery({ queryKey: [...CASH_KEY, 'handovers', tab], queryFn: () => http.get<Paginated<CashHandover>>(`/cash/handovers?status=${tab}`) })

  return (
    <div className="max-w-6xl">
      <PageHeader title="Serah terima kas" purpose="Sales Cash dari outlet ke gudang, lalu dari gudang ke Finance."
        actions={<Button asChild><Link to="/kas/serah-terima/baru">Serahkan kas</Link></Button>} />
      <InfoBanner className="mb-4">
        Pengirim mengisi jumlah yang diserahkan; penerima menghitung lalu mengisi jumlah yang diterima. Bila ada selisih, penerima wajib menuliskan bukti.
        Jumlah yang sudah dikonfirmasi tidak bisa diubah.
      </InfoBanner>
      <div role="tablist" className="mb-4 inline-flex rounded-lg border border-border p-1">
        {([['SENT', 'Menunggu konfirmasi'], ['RECEIVED', 'Sudah diterima']] as const).map(([k, label]) => (
          <button key={k} role="tab" aria-selected={tab === k} onClick={() => setTab(k)}
            className={cn('rounded-md px-3 py-1.5 text-sm', tab === k ? 'bg-muted font-medium' : 'text-muted-foreground')}>{label}</button>
        ))}
      </div>
      {list.isPending ? <Loading /> : list.error ? <ErrorAlert message={errorText(list.error)} /> : (
        <Table>
          <thead><tr><Th>Nomor</Th><Th>Tahap</Th><Th>Dari</Th><Th>Dikirim</Th><Th className="text-right">Seharusnya</Th><Th className="text-right">Diserahkan</Th><Th className="text-right">Diterima</Th><Th className="text-right">Selisih</Th></tr></thead>
          <tbody>
            {list.data.data.length === 0 && <EmptyRow colSpan={8}>Tidak ada serah terima.</EmptyRow>}
            {list.data.data.map((h) => (
              <tr key={h.id}>
                <Td className="font-mono"><Link to={`/kas/serah-terima/${h.id}`} className="hover:underline">{h.number}</Link></Td>
                <Td>{HANDOVER_STAGE_LABEL[h.stage]}</Td>
                <Td>{h.from.name}</Td>
                <Td>{formatDateTime(h.sent_at)}<p className="text-xs text-muted-foreground">{h.sender?.name}</p></Td>
                <Td className="text-right">{formatRupiah(h.expected_amount)}</Td>
                <Td className="text-right">{formatRupiah(h.reported_amount)}</Td>
                <Td className="text-right">{h.received_amount === null ? '–' : formatRupiah(h.received_amount)}</Td>
                <Td className={cn('text-right', (h.difference ?? 0) !== 0 && 'text-destructive')}>{h.difference === null ? '–' : formatSignedRupiah(h.difference)}</Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
    </div>
  )
}

export function HandoverFormPage() {
  const { data: me } = useMe()
  const overview = useOverview()
  const [params] = useSearchParams()
  const qc = useQueryClient()
  const navigate = useNavigate()
  const choices = [
    ...(hasRole(me, 'CASHIER') ? (overview.data?.data.outlets ?? []).map((x) => x.location) : []),
    ...(hasRole(me, 'WAREHOUSE') && overview.data?.data.warehouse ? [overview.data.data.warehouse.location] : []),
  ]
  const [locationId, setLocationId] = useState<number | null>(Number(params.get('lokasi')) || null)
  const loc = choices.find((c) => c.id === locationId) ?? choices[0] ?? null
  const pending = useQuery({
    queryKey: [...CASH_KEY, 'pending', loc?.id],
    queryFn: () => http.get<{ data: HandoverPending }>(`/cash/handovers/pending?location_id=${loc?.id}`),
    enabled: !!loc,
  })
  const [picked, setPicked] = useState<Set<number> | null>(null)
  const [reported, setReported] = useState<number | null | undefined>(undefined)
  const [note, setNote] = useState('')
  const p = pending.data?.data
  const items = p ? (p.shifts.length ? p.shifts.map((s) => ({ id: s.id, label: `${s.number} · ${s.cashier?.name ?? ''} · ditutup ${formatDateTime(s.closed_at)}`, amount: s.sales_cash }))
    : p.handovers.map((h) => ({ id: h.id, label: `${h.number} · dari ${h.from?.name ?? ''} · diterima ${formatDateTime(h.received_at)}`, amount: h.received_amount }))) : []
  const selected = picked ?? new Set(items.map((i) => i.id))
  const expected = items.filter((i) => selected.has(i.id)).reduce((s, i) => s + i.amount, 0)
  const isOutlet = loc?.type !== 'WAREHOUSE' && (p?.location.type ?? 'OUTLET') === 'OUTLET'
  const send = useMutation({
    mutationFn: () => http.post<{ data: CashHandover }>('/cash/handovers', {
      location_id: loc?.id, [isOutlet ? 'shift_ids' : 'handover_ids']: [...selected], reported_amount: reported === undefined ? expected : reported, note: note || null,
    }),
    onSuccess: (res) => { qc.invalidateQueries({ queryKey: CASH_KEY }); navigate(`/kas/serah-terima/${res.data.id}`) },
  })
  const err = send.error instanceof ApiError ? send.error : null

  if (overview.isPending) return <Loading />
  if (choices.length === 0) return <ErrorAlert message="Kas diserahkan oleh kasir outlet (ke gudang) atau staf gudang (ke Finance)." />

  return (
    <div className="max-w-3xl space-y-4">
      <PageHeader title="Serahkan kas" purpose={isOutlet ? 'Serahkan Sales Cash dari shift yang sudah ditutup ke gudang.' : 'Teruskan kas yang ada di gudang ke Finance.'} />
      {choices.length > 1 && (
        <Field id="ho-loc" label="Dari">
          <Select id="ho-loc" value={loc?.id ?? ''} onChange={(e) => { setLocationId(Number(e.target.value)); setPicked(null); setReported(undefined) }}>
            {choices.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </Select>
        </Field>
      )}
      {pending.isPending ? <Loading /> : pending.error ? <ErrorAlert message={errorText(pending.error)} /> : items.length === 0 ? (
        <InfoBanner>{isOutlet ? 'Belum ada shift yang ditutup dan belum diserahkan.' : 'Belum ada kas di gudang yang perlu diteruskan.'}</InfoBanner>
      ) : (
        <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); send.mutate() }}>
          <fieldset className="space-y-2">
            <legend className="mb-1 text-sm font-medium">{isOutlet ? 'Shift' : 'Kas dari outlet'}</legend>
            {items.map((i) => (
              <label key={i.id} className="flex items-center justify-between gap-2 rounded-lg border border-border px-3 py-2 text-sm">
                <span className="flex items-center gap-2">
                  <input type="checkbox" checked={selected.has(i.id)} onChange={(e) => {
                    const next = new Set(selected)
                    if (e.target.checked) next.add(i.id); else next.delete(i.id)
                    setPicked(next)
                  }} />
                  {i.label}
                </span>
                <span className="font-medium">{formatRupiah(i.amount)}</span>
              </label>
            ))}
            {(err?.field('shift_ids') || err?.field('handover_ids')) && <p className="text-sm text-destructive">{err.field('shift_ids') ?? err.field('handover_ids')}</p>}
          </fieldset>
          <p className="text-sm">Seharusnya diserahkan: <span className="text-lg font-semibold">{formatRupiah(expected)}</span></p>
          <div className="grid gap-4 md:grid-cols-[14rem_1fr]">
            <Field id="ho-amount" label="Uang yang diserahkan" error={err?.field('reported_amount')}>
              <RupiahInput id="ho-amount" value={reported === undefined ? expected : reported} onValueChange={setReported} />
            </Field>
            <Field id="ho-note" label={reported !== undefined && reported !== expected ? 'Penjelasan perbedaan (wajib)' : 'Catatan (opsional)'} error={err?.field('note')}>
              <Input id="ho-note" value={note} onChange={(e) => setNote(e.target.value)} />
            </Field>
          </div>
          {err && !err.field('note') && !err.field('reported_amount') && !err.field('shift_ids') && !err.field('handover_ids') && <ErrorAlert message={Object.values(err.errors)[0]?.[0] ?? err.message} />}
          <div className="flex gap-2">
            <Button type="submit" disabled={send.isPending || selected.size === 0 || !p?.can_send || reported === null}>Kirim serah terima</Button>
            <Button asChild variant="ghost"><Link to="/kas/serah-terima">Batal</Link></Button>
          </div>
        </form>
      )}
    </div>
  )
}

export function HandoverDetailPage() {
  const { id } = useParams()
  const qc = useQueryClient()
  const [received, setReceived] = useState<number | null>(null)
  const [evidence, setEvidence] = useState('')
  const [note, setNote] = useState('')
  const h = useQuery({ queryKey: [...CASH_KEY, 'handover', id], queryFn: () => http.get<{ data: CashHandover }>(`/cash/handovers/${id}`) })
  const receive = useMutation({
    mutationFn: () => http.post(`/cash/handovers/${id}/receive`, { received_amount: received, evidence_note: evidence || null, note: note || null }),
    onSuccess: () => qc.invalidateQueries({ queryKey: CASH_KEY }),
  })
  const err = receive.error instanceof ApiError ? receive.error : null
  if (h.isPending) return <Loading />
  if (h.error) return <ErrorAlert message={errorText(h.error)} />
  const x = h.data.data
  const differs = received !== null && (received !== x.expected_amount || received !== x.reported_amount)

  return (
    <div className="max-w-3xl space-y-4">
      <PageHeader title={`Serah terima ${x.number}`} purpose={`${HANDOVER_STAGE_LABEL[x.stage]} · dari ${x.from.name} · dikirim ${formatDateTime(x.sent_at)} oleh ${x.sender?.name ?? '–'}`} />
      <Badge variant={x.status === 'RECEIVED' ? 'success' : 'warning'}>{x.status === 'RECEIVED' ? 'Sudah diterima' : 'Menunggu konfirmasi penerima'}</Badge>
      <Card>
        <CardContent className="grid gap-3 pt-6 md:grid-cols-4">
          <Money label="Seharusnya" value={x.expected_amount} />
          <Money label="Diserahkan pengirim" value={x.reported_amount} />
          <Money label="Diterima" value={x.received_amount} />
          <div>
            <p className="text-xs text-muted-foreground">Selisih</p>
            <p className={cn('text-lg font-semibold', (x.difference ?? 0) !== 0 && 'text-destructive')}>{x.difference === null ? '–' : formatSignedRupiah(x.difference)}</p>
          </div>
        </CardContent>
      </Card>
      {x.sender_note && <p className="text-sm">Catatan pengirim: {x.sender_note}</p>}
      {x.receiver && <p className="text-sm">Diterima {x.receiver.name} · {formatDateTime(x.received_at)}{x.receiver_note && ` — ${x.receiver_note}`}</p>}
      {x.evidence_note && <p className="text-sm">Bukti selisih: <span className="font-medium">{x.evidence_note}</span></p>}
      <Table>
        <thead><tr><Th>Isi</Th><Th className="text-right">Jumlah</Th></tr></thead>
        <tbody>
          {(x.items ?? []).map((i, idx) => (
            <tr key={idx}>
              <Td>{i.shift ? <Link to={`/shift/${i.shift.id}`} className="hover:underline">Shift {i.shift.number} · {i.shift.cashier}</Link>
                : i.source ? <Link to={`/kas/serah-terima/${i.source.id}`} className="hover:underline">{i.source.number} · dari {i.source.from}</Link> : '–'}</Td>
              <Td className="text-right">{formatRupiah(i.amount)}</Td>
            </tr>
          ))}
        </tbody>
      </Table>
      {x.can.receive && (
        <Card>
          <CardContent className="space-y-4 pt-6">
            <h2 className="font-semibold">Konfirmasi penerimaan</h2>
            <p className="text-sm text-muted-foreground">Hitung uangnya di depan pengirim, lalu isi jumlah yang benar-benar Anda terima.</p>
            <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); receive.mutate() }}>
              <div className="grid gap-4 md:grid-cols-[14rem_1fr]">
                <Field id="rc-amount" label="Uang yang diterima" error={err?.field('received_amount')}>
                  <RupiahInput id="rc-amount" value={received} onValueChange={setReceived} />
                </Field>
                <Field id="rc-note" label="Catatan (opsional)"><Input id="rc-note" value={note} onChange={(e) => setNote(e.target.value)} /></Field>
              </div>
              {(differs || err?.field('evidence_note')) && (
                <Field id="rc-evidence" label="Bukti dan penjelasan selisih (wajib)" error={err?.field('evidence_note')}>
                  <Input id="rc-evidence" value={evidence} onChange={(e) => setEvidence(e.target.value)} placeholder="mis. dihitung bersama pengirim, foto nomor …" />
                </Field>
              )}
              {err && !err.field('evidence_note') && !err.field('received_amount') && <ErrorAlert message={err.message} />}
              <Button type="submit" disabled={receive.isPending || received === null}>Konfirmasi diterima</Button>
            </form>
          </CardContent>
        </Card>
      )}
      <p><Link to="/kas/serah-terima" className="text-sm hover:underline">← Semua serah terima</Link></p>
    </div>
  )
}

// --- Biaya dan Petty Cash ---

const EXPENSE_VARIANT: Record<string, 'default' | 'success' | 'warning' | 'destructive'> = { RECORDED: 'warning', VERIFIED: 'success', REJECTED: 'destructive', CANCELLED: 'default' }

export function ExpensesPage() {
  const { data: me } = useMe()
  const qc = useQueryClient()
  const isFinance = hasRole(me, 'FINANCE')
  const overview = useOverview()
  const allLocations = useQuery({ queryKey: ['locations'], queryFn: () => http.get<{ data: Location[] }>('/locations'), enabled: isFinance })
  const categories = useQuery({ queryKey: ['expense-categories'], queryFn: () => http.get<{ data: SimpleMaster[] }>('/expense-categories') })
  const list = useQuery({ queryKey: [...CASH_KEY, 'expenses'], queryFn: () => http.get<Paginated<Expense>>('/cash/expenses') })
  const choices = [
    ...(hasRole(me, 'CASHIER') ? (overview.data?.data.outlets ?? []).map((o) => ({ ...o.location, petty: true })) : []),
    ...(isFinance ? (allLocations.data?.data ?? []).filter((l) => l.status !== 'CLOSED').map((l) => ({ id: l.id, code: l.code, name: l.name, type: l.type, petty: false })) : []),
  ].filter((c, i, a) => a.findIndex((x) => x.id === c.id) === i)
  const [form, setForm] = useState({ location_id: '', expense_category_id: '', description: '', evidence_note: '' })
  const [amount, setAmount] = useState<number | null>(null)
  const [cancelId, setCancelId] = useState<number | null>(null)
  const [reason, setReason] = useState('')
  const save = useMutation({
    mutationFn: () => http.post('/cash/expenses', { ...form, location_id: Number(form.location_id || choices[0]?.id), expense_category_id: Number(form.expense_category_id) || null, amount, evidence_note: form.evidence_note || null }),
    onSuccess: () => { setForm({ ...form, description: '', evidence_note: '' }); setAmount(null); qc.invalidateQueries({ queryKey: CASH_KEY }) },
  })
  const cancel = useMutation({
    mutationFn: () => http.post(`/cash/expenses/${cancelId}/cancel`, { reason }),
    onSuccess: () => { setCancelId(null); setReason(''); qc.invalidateQueries({ queryKey: CASH_KEY }) },
  })
  const err = save.error instanceof ApiError ? save.error : null
  const loc = choices.find((c) => String(c.id) === form.location_id) ?? choices[0]
  const fromPetty = loc && loc.type === 'OUTLET' && hasRole(me, 'CASHIER') && (me?.roles.some((r) => r.code === 'CASHIER' && (r.scope === 'ALL_LOCATIONS' || r.locations.some((l) => l.id === loc.id))) ?? false)

  return (
    <div className="max-w-6xl space-y-4">
      <PageHeader title="Biaya operasional" purpose="Pengeluaran outlet dari Petty Cash, serta biaya gudang dan pusat yang dibayar Finance." />
      {choices.length > 0 && (
        <Card>
          <CardContent className="pt-6">
            <form className="grid gap-4 md:grid-cols-6" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
              <div className="md:col-span-2">
                <Field id="ex-loc" label="Lokasi">
                  <Select id="ex-loc" value={form.location_id || String(choices[0]?.id ?? '')} onChange={(e) => setForm({ ...form, location_id: e.target.value })}>
                    {choices.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                  </Select>
                </Field>
              </div>
              <div className="md:col-span-2">
                <Field id="ex-cat" label="Kategori" error={err?.field('expense_category_id')}>
                  <Select id="ex-cat" value={form.expense_category_id} onChange={(e) => setForm({ ...form, expense_category_id: e.target.value })}>
                    <option value="">Pilih kategori…</option>
                    {(categories.data?.data ?? []).filter((c) => c.is_active).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                  </Select>
                </Field>
              </div>
              <div className="md:col-span-2">
                <Field id="ex-amount" label="Jumlah" error={err?.field('amount')}><RupiahInput id="ex-amount" value={amount} onValueChange={setAmount} /></Field>
              </div>
              <div className="md:col-span-3">
                <Field id="ex-desc" label="Untuk apa" error={err?.field('description')}><Input id="ex-desc" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} /></Field>
              </div>
              <div className="md:col-span-3">
                <Field id="ex-evidence" label="Bukti (no. nota / keterangan foto)"><Input id="ex-evidence" value={form.evidence_note} onChange={(e) => setForm({ ...form, evidence_note: e.target.value })} /></Field>
              </div>
              <div className="flex items-center gap-3 md:col-span-6">
                <Button type="submit" disabled={save.isPending || !amount}>Catat biaya</Button>
                <span className="text-sm text-muted-foreground">{fromPetty ? 'Dibayar dari Petty Cash outlet; Finance memeriksa buktinya saat isi ulang.' : 'Dibayar Finance.'}</span>
              </div>
              {err && !Object.keys(err.errors).length && <div className="md:col-span-6"><ErrorAlert message={err.message} /></div>}
            </form>
          </CardContent>
        </Card>
      )}
      {list.isPending ? <Loading /> : list.error ? <ErrorAlert message={errorText(list.error)} /> : (
        <Table>
          <thead><tr><Th>Nomor</Th><Th>Tanggal</Th><Th>Lokasi</Th><Th>Kategori</Th><Th>Keterangan</Th><Th>Dibayar dari</Th><Th className="text-right">Jumlah</Th><Th>Status</Th><Th></Th></tr></thead>
          <tbody>
            {list.data.data.length === 0 && <EmptyRow colSpan={9}>Belum ada biaya.</EmptyRow>}
            {list.data.data.map((e) => (
              <tr key={e.id}>
                <Td className="font-mono">{e.number}</Td>
                <Td>{formatDate(e.business_date)}<p className="text-xs text-muted-foreground">{e.recorded_by?.name}</p></Td>
                <Td>{e.location.name}</Td>
                <Td>{e.category.name}</Td>
                <Td>{e.description}{e.evidence_note && <p className="text-xs text-muted-foreground">Bukti: {e.evidence_note}</p>}</Td>
                <Td>{e.paid_from === 'PETTY_CASH' ? 'Petty Cash' : 'Finance'}</Td>
                <Td className="text-right">{formatRupiah(e.amount)}</Td>
                <Td><Badge variant={EXPENSE_VARIANT[e.status]}>{EXPENSE_STATUS_LABEL[e.status]}</Badge>{e.decision_note && <p className="text-xs text-muted-foreground">{e.decision_note}</p>}</Td>
                <Td>{e.can.cancel && <Button size="sm" variant="ghost" onClick={() => { cancel.reset(); setCancelId(e.id) }}>Batalkan</Button>}</Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
      {cancelId !== null && (
        <form className="flex flex-wrap items-end gap-2" onSubmit={(e) => { e.preventDefault(); cancel.mutate() }}>
          <div className="min-w-64 flex-1"><Field id="ex-cancel" label="Alasan pembatalan" error={cancel.error instanceof ApiError ? cancel.error.field('reason') : undefined}><Input id="ex-cancel" value={reason} onChange={(e) => setReason(e.target.value)} /></Field></div>
          <Button type="submit" variant="destructive" disabled={cancel.isPending}>Batalkan biaya</Button>
          <Button type="button" variant="ghost" onClick={() => setCancelId(null)}>Tutup</Button>
        </form>
      )}
    </div>
  )
}

export function PettyCashPage() {
  const { id } = useParams()
  const qc = useQueryClient()
  const key = [...CASH_KEY, 'petty', id]
  const pc = useQuery({ queryKey: key, queryFn: () => http.get<{ data: PettyCash }>(`/cash/petty-cash/${id}`) })
  const [decisions, setDecisions] = useState<Record<number, { decision: 'VERIFIED' | 'REJECTED'; note: string }>>({})
  const [target, setTarget] = useState<number | null | undefined>(undefined)
  const done = (res: { data: PettyCash }) => { qc.setQueryData(key, res); qc.invalidateQueries({ queryKey: CASH_KEY }); setDecisions({}) }
  const act = useMutation({ mutationFn: ({ path, body, method }: { path: string; body?: unknown; method?: 'put' }) => (method === 'put' ? http.put<{ data: PettyCash }>(path, body) : http.post<{ data: PettyCash }>(path, body)), onSuccess: done })
  const err = act.error instanceof ApiError ? act.error : null
  if (pc.isPending) return <Loading />
  if (pc.error) return <ErrorAlert message={errorText(pc.error)} />
  const p = pc.data.data
  const verified = p.pending.filter((e) => decisions[e.id]?.decision === 'VERIFIED').reduce((s, e) => s + e.amount, 0)
  const rejected = p.pending.filter((e) => decisions[e.id]?.decision === 'REJECTED').reduce((s, e) => s + e.amount, 0)
  const gap = p.target === null ? 0 : Math.max(0, p.target - p.balance - verified - rejected)
  const allDecided = p.pending.every((e) => decisions[e.id])

  return (
    <div className="max-w-5xl space-y-4">
      <PageHeader title={`Petty Cash ${p.location.name}`} purpose="Dana tetap untuk biaya operasional outlet, dipegang kasir dan diisi ulang Finance." />
      <Card>
        <CardContent className="grid gap-3 pt-6 md:grid-cols-3">
          <Money label="Saldo sekarang" value={p.balance} />
          <Money label="Saldo tetap" value={p.target} hint={p.target === null ? 'belum diatur Owner' : undefined} />
          <Money label="Biaya menunggu diperiksa" value={p.pending.reduce((s, e) => s + e.amount, 0)} hint={`${p.pending.length} biaya`} />
        </CardContent>
      </Card>
      {p.request && <InfoBanner>Isi ulang diminta {p.request.by} · {formatDateTime(p.request.at)}{p.request.note && ` — ${p.request.note}`}</InfoBanner>}
      <div className="flex flex-wrap gap-2">
        {p.can.record && <Button asChild variant="outline"><Link to="/kas/biaya">Catat biaya</Link></Button>}
        {p.can.request && <Button variant="outline" disabled={act.isPending} onClick={() => act.mutate({ path: `/cash/petty-cash/${id}/request`, body: {} })}>Minta isi ulang ke Finance</Button>}
      </div>

      {p.can.set_target && (
        <form className="flex flex-wrap items-end gap-2" onSubmit={(e) => { e.preventDefault(); act.mutate({ path: `/cash/petty-cash/${id}/target`, body: { amount: target === undefined ? p.target : target }, method: 'put' }) }}>
          <div className="w-56"><Field id="pc-target" label="Saldo tetap Petty Cash (diatur Owner)" error={err?.field('amount')}><RupiahInput id="pc-target" value={target === undefined ? p.target : target} onValueChange={setTarget} /></Field></div>
          <Button type="submit" variant="outline" disabled={act.isPending}>Simpan saldo tetap</Button>
        </form>
      )}

      {p.can.refill && (
        <Card>
          <CardContent className="space-y-3 pt-6">
            <h2 className="font-semibold">Periksa biaya dan isi ulang</h2>
            <p className="text-sm text-muted-foreground">Tandai setiap biaya terbukti atau ditolak. Petty Cash diisi ulang sebesar biaya yang terbukti; biaya yang ditolak menjadi selisih yang Anda putuskan di halaman Selisih kas.</p>
            {p.pending.length === 0 && <p className="text-sm">Tidak ada biaya yang menunggu.</p>}
            {p.pending.map((e) => (
              <div key={e.id} className="flex flex-wrap items-center gap-2 rounded-lg border border-border p-2 text-sm">
                <span className="min-w-56 flex-1">{e.number} · {e.category.name} · {e.description}{e.evidence_note && ` (bukti: ${e.evidence_note})`} · <span className="font-medium">{formatRupiah(e.amount)}</span></span>
                <Select aria-label={`Keputusan ${e.number}`} className="w-36" value={decisions[e.id]?.decision ?? ''}
                  onChange={(ev) => setDecisions({ ...decisions, [e.id]: { decision: ev.target.value as 'VERIFIED' | 'REJECTED', note: decisions[e.id]?.note ?? '' } })}>
                  <option value="">Pilih…</option><option value="VERIFIED">Terbukti</option><option value="REJECTED">Ditolak</option>
                </Select>
                {decisions[e.id]?.decision === 'REJECTED' && (
                  <Input aria-label={`Alasan ditolak ${e.number}`} className="w-56" placeholder="Alasan ditolak" value={decisions[e.id].note}
                    onChange={(ev) => setDecisions({ ...decisions, [e.id]: { ...decisions[e.id], note: ev.target.value } })} />
                )}
                {err?.field(`decisions.${e.id}`) && <p className="w-full text-destructive">{err.field(`decisions.${e.id}`)}</p>}
              </div>
            ))}
            <p className="text-sm">Akan diisi: <span className="text-lg font-semibold">{formatRupiah(verified + gap)}</span>{gap > 0 && <span className="text-muted-foreground"> (termasuk {formatRupiah(gap)} untuk mencapai saldo tetap)</span>}</p>
            <Button disabled={act.isPending || !allDecided} onClick={() => act.mutate({ path: `/cash/petty-cash/${id}/refill`, body: { decisions: Object.fromEntries(Object.entries(decisions).map(([k, v]) => [k, { decision: v.decision, note: v.note || null }])) } })}>
              Isi ulang Petty Cash
            </Button>
          </CardContent>
        </Card>
      )}
      {err && !Object.keys(err.errors).some((k) => k.startsWith('decisions') || k === 'amount') && <ErrorAlert message={Object.values(err.errors)[0]?.[0] ?? err.message} />}

      <Table>
        <thead><tr><Th>Waktu</Th><Th>Jenis</Th><Th>Dokumen</Th><Th>Oleh</Th><Th className="text-right">Jumlah</Th><Th className="text-right">Saldo</Th></tr></thead>
        <tbody>
          {p.movements.length === 0 && <EmptyRow colSpan={6}>Belum ada catatan Petty Cash.</EmptyRow>}
          {p.movements.map((m) => (
            <tr key={m.id}><Td>{formatDateTime(m.created_at)}</Td><Td>{PETTY_MOVEMENT_LABEL[m.type]}</Td><Td className="font-mono">{m.source.number}</Td><Td>{m.user}</Td>
              <Td className={cn('text-right', m.amount < 0 && 'text-destructive')}>{formatSignedRupiah(m.amount)}</Td><Td className="text-right">{formatRupiah(m.balance_after)}</Td></tr>
          ))}
        </tbody>
      </Table>
    </div>
  )
}

// --- Pencocokan non-tunai dan selisih ---

export function ReconciliationPage() {
  const { data: me } = useMe()
  const canAct = hasRole(me, 'FINANCE')
  const list = useQuery({ queryKey: [...CASH_KEY, 'recon'], queryFn: () => http.get<{ data: ReconciliationRow[] }>('/cash/reconciliations?days=14') })
  return (
    <div className="max-w-5xl space-y-4">
      <PageHeader title="Pencocokan non-tunai" purpose="Cocokkan total QRIS, transfer, dan e-wallet di sistem dengan mutasi rekening usaha, setiap hari." />
      <InfoBanner>Total di sistem = pembayaran non-tunai semua outlet pada tanggal itu dikurangi pengembalian (refund) non-tunai. Isi total mutasi masuk di rekening untuk metode itu; selisih wajib dijelaskan.</InfoBanner>
      {list.isPending ? <Loading /> : list.error ? <ErrorAlert message={errorText(list.error)} /> : (
        <Table>
          <thead><tr><Th>Tanggal</Th><Th>Metode</Th><Th className="text-right">Di sistem</Th><Th>Mutasi rekening</Th><Th className="text-right">Selisih</Th></tr></thead>
          <tbody>
            {list.data.data.length === 0 && <EmptyRow colSpan={5}>Belum ada metode non-tunai.</EmptyRow>}
            {list.data.data.map((r) => <ReconRow key={`${r.business_date}-${r.method.id}`} row={r} canAct={canAct} />)}
          </tbody>
        </Table>
      )}
    </div>
  )
}

function ReconRow({ row: r, canAct }: { row: ReconciliationRow; canAct: boolean }) {
  const qc = useQueryClient()
  const [bank, setBank] = useState<number | null>(null)
  const [note, setNote] = useState('')
  const save = useMutation({
    mutationFn: () => http.post('/cash/reconciliations', { business_date: r.business_date, payment_method_id: r.method.id, bank_amount: bank, note: note || null }),
    onSuccess: () => qc.invalidateQueries({ queryKey: CASH_KEY }),
  })
  const err = save.error instanceof ApiError ? save.error : null
  const rec = r.reconciliation
  return (
    <tr>
      <Td>{formatDate(r.business_date)}</Td>
      <Td>{r.method.name}</Td>
      <Td className="text-right">{formatRupiah(r.system_amount)}</Td>
      <Td>
        {rec ? <span>{formatRupiah(rec.bank_amount)} <span className="text-xs text-muted-foreground">· {rec.by}{rec.note && ` · ${rec.note}`}</span></span>
          : canAct ? (
            <form className="flex flex-wrap items-start gap-2" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
              <div className="w-36"><RupiahInput aria-label={`Mutasi ${r.method.name} ${r.business_date}`} value={bank} onValueChange={setBank} /></div>
              {bank !== null && bank !== r.system_amount && <Input aria-label="Penjelasan selisih" className="w-48" placeholder="Penjelasan selisih" value={note} onChange={(e) => setNote(e.target.value)} />}
              <Button type="submit" size="sm" className="h-10" disabled={save.isPending || bank === null}>Cocokkan</Button>
              {err && <p className="w-full text-xs text-destructive">{Object.values(err.errors)[0]?.[0] ?? err.message}</p>}
            </form>
          ) : <span className="text-muted-foreground">belum dicocokkan</span>}
      </Td>
      <Td className={cn('text-right', (rec?.difference ?? 0) !== 0 && 'text-destructive')}>{rec ? formatSignedRupiah(rec.difference) : '–'}</Td>
    </tr>
  )
}

export function DiscrepanciesPage() {
  const [tab, setTab] = useState<'OPEN' | 'ALL'>('OPEN')
  const list = useQuery({ queryKey: [...CASH_KEY, 'discrepancies', tab], queryFn: () => http.get<{ data: Discrepancy[]; can_resolve: boolean }>(`/cash/discrepancies?status=${tab}`) })
  return (
    <div className="max-w-6xl space-y-4">
      <PageHeader title="Selisih kas" purpose="Semua selisih uang: tutup shift, serah terima, biaya Petty Cash yang ditolak, dan pencocokan non-tunai." />
      <InfoBanner>Setiap selisih sudah dijelaskan oleh yang mencatat. Finance memutuskan penyelesaiannya; Owner melihat semua selisih. Keputusan tercatat dan tidak bisa diubah.</InfoBanner>
      <div role="tablist" className="inline-flex rounded-lg border border-border p-1">
        {([['OPEN', 'Belum diputuskan'], ['ALL', 'Semua']] as const).map(([k, label]) => (
          <button key={k} role="tab" aria-selected={tab === k} onClick={() => setTab(k)}
            className={cn('rounded-md px-3 py-1.5 text-sm', tab === k ? 'bg-muted font-medium' : 'text-muted-foreground')}>{label}</button>
        ))}
      </div>
      {list.isPending ? <Loading /> : list.error ? <ErrorAlert message={errorText(list.error)} /> : list.data.data.length === 0 ? (
        <p className="py-8 text-center text-sm text-muted-foreground">{tab === 'OPEN' ? 'Tidak ada selisih yang menunggu keputusan.' : 'Belum ada selisih.'}</p>
      ) : (
        <ul className="space-y-3">{list.data.data.map((d) => <li key={`${d.subject_type}-${d.subject_id}`}><DiscrepancyCard d={d} canResolve={list.data.can_resolve} /></li>)}</ul>
      )}
    </div>
  )
}

function DiscrepancyCard({ d, canResolve }: { d: Discrepancy; canResolve: boolean }) {
  const qc = useQueryClient()
  const [decision, setDecision] = useState('')
  const save = useMutation({
    mutationFn: () => http.post('/cash/discrepancies/resolve', { subject_type: d.subject_type, subject_id: d.subject_id, decision }),
    onSuccess: () => qc.invalidateQueries({ queryKey: CASH_KEY }),
  })
  const err = save.error instanceof ApiError ? save.error : null
  return (
    <Card>
      <CardContent className="space-y-2 pt-6 text-sm">
        <div className="flex flex-wrap items-start justify-between gap-2">
          <div>
            <p className="font-medium">{d.kind} · <Link to={d.link} className="font-mono hover:underline">{d.number}</Link></p>
            <p className="text-muted-foreground">{d.location}{d.person && ` · ${d.person}`} · {formatDateTime(d.at)}</p>
          </div>
          <p className={cn('text-lg font-semibold', d.amount < 0 ? 'text-destructive' : 'text-amber-700')}>{formatSignedRupiah(d.amount)}</p>
        </div>
        {d.explanation && <p>Penjelasan: {d.explanation}</p>}
        {d.resolution ? (
          <p>Keputusan {d.resolution.by} ({formatDateTime(d.resolution.at)}): <span className="font-medium">{d.resolution.decision}</span></p>
        ) : canResolve ? (
          <form className="flex flex-wrap items-start gap-2" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
            <div className="min-w-64 flex-1">
              <Field id={`dc-${d.subject_type}-${d.subject_id}`} label="Keputusan penyelesaian" error={err?.field('decision')}>
                <Input id={`dc-${d.subject_type}-${d.subject_id}`} value={decision} onChange={(e) => setDecision(e.target.value)} placeholder="mis. ditanggung kasir, dicatat sebagai kerugian, salah hitung" />
              </Field>
            </div>
            <Button type="submit" className="md:mt-[24px]" disabled={save.isPending}>Simpan keputusan</Button>
            {err && !err.field('decision') && <p className="w-full text-destructive">{Object.values(err.errors)[0]?.[0] ?? err.message}</p>}
          </form>
        ) : <p className="text-muted-foreground">Menunggu keputusan Finance.</p>}
      </CardContent>
    </Card>
  )
}
