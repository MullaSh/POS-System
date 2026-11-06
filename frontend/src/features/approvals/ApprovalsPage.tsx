import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ChevronDown, ChevronUp } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { ErrorAlert, InfoBanner, Loading, PageHeader } from '@/components/ui/feedback'
import { Field } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { hasRole, useMe } from '@/features/auth/useAuth'
import { PriceForm, type PriceInput } from '@/features/catalog/PriceForm'
import { ITEMS_KEY } from '@/features/catalog/queries'
import { SALES_KEY } from '@/features/sales/queries'
import { STOCK_KEY } from '@/features/stock/queries'
import { ApiError, errorText, http } from '@/lib/api'
import { APPROVAL_ACTION_LABEL, APPROVAL_STATUS_LABEL, APPROVAL_TYPE_LABEL, formatDateTime, formatQty, formatRupiah } from '@/lib/format'
import type { Approval, ApprovalStatus, Paginated } from '@/lib/types'
import { cn } from '@/lib/utils'
import { APPROVALS_KEY } from './queries'

const STATUS_VARIANT: Record<ApprovalStatus, 'default' | 'success' | 'warning' | 'destructive'> = {
  PENDING: 'warning',
  RETURNED: 'warning',
  APPROVED: 'success',
  REJECTED: 'destructive',
  CANCELLED: 'default',
}

/** Satu daftar persetujuan (dokumen 11 §3.1 nomor 11). Owner memutuskan; pengaju memantau. */
export function ApprovalsPage() {
  const { data: me } = useMe()
  const isOwner = hasRole(me, 'OWNER')
  const decides = isOwner || (me?.approval_types?.length ?? 0) > 0
  const [tab, setTab] = useState<'OPEN' | 'DONE'>('OPEN')
  const list = useQuery({
    queryKey: [...APPROVALS_KEY, 'list', tab],
    queryFn: () => http.get<Paginated<Approval>>(`/approvals?status=${tab}`),
  })

  return (
    <div className="max-w-4xl">
      <PageHeader
        title="Persetujuan"
        purpose={decides ? 'Pengajuan yang menunggu keputusan Anda, dan riwayatnya.' : 'Pengajuan yang Anda kirim dan statusnya.'}
      />
      <InfoBanner className="mb-4">
        {!isOwner && decides && (
          <p className="mb-2 font-medium text-foreground">
            Anda menerima delegasi untuk: {me?.approval_types?.map((t) => APPROVAL_TYPE_LABEL[t]).join(', ')}. Keputusan Anda dicatat sebagai keputusan atas delegasi.
          </p>
        )}
        {decides
          ? 'Setujui, tolak, atau kembalikan pengajuan untuk diperbaiki. Penolakan dan pengembalian wajib diberi alasan. Semua keputusan tercatat.'
          : 'Pengajuan yang dikembalikan Owner bisa diperbaiki lalu diajukan ulang. Pengajuan yang belum diputuskan bisa dibatalkan.'}
      </InfoBanner>

      <div role="tablist" className="mb-4 inline-flex rounded-lg border border-border p-1">
        {([['OPEN', 'Belum selesai'], ['DONE', 'Selesai']] as const).map(([key, label]) => (
          <button
            key={key}
            role="tab"
            aria-selected={tab === key}
            onClick={() => setTab(key)}
            className={cn('rounded-md px-3 py-1.5 text-sm', tab === key ? 'bg-muted font-medium' : 'text-muted-foreground')}
          >
            {label}
          </button>
        ))}
      </div>

      {list.isPending ? (
        <Loading />
      ) : list.error ? (
        <ErrorAlert message={errorText(list.error)} />
      ) : list.data.data.length === 0 ? (
        <p className="py-8 text-center text-sm text-muted-foreground">
          {tab === 'OPEN' ? 'Tidak ada pengajuan yang belum selesai.' : 'Belum ada pengajuan yang selesai.'}
        </p>
      ) : (
        <ul className="space-y-3">
          {list.data.data.map((a) => (
            <li key={a.id}><ApprovalCard approval={a} /></li>
          ))}
        </ul>
      )}
    </div>
  )
}

function ApprovalCard({ approval: a }: { approval: Approval }) {
  const qc = useQueryClient()
  const [mode, setMode] = useState<'none' | 'reject' | 'return' | 'resubmit'>('none')
  const [note, setNote] = useState('')
  const [showHistory, setShowHistory] = useState(false)

  const refresh = () => {
    setMode('none')
    setNote('')
    qc.invalidateQueries({ queryKey: APPROVALS_KEY })
    qc.invalidateQueries({ queryKey: ITEMS_KEY })
    qc.invalidateQueries({ queryKey: STOCK_KEY })
    qc.invalidateQueries({ queryKey: SALES_KEY })
    qc.invalidateQueries({ queryKey: ['purchasing'] })
  }
  const act = useMutation({
    mutationFn: ({ action, body }: { action: string; body?: unknown }) => http.post(`/approvals/${a.id}/${action}`, body),
    onSuccess: refresh,
  })
  const history = useQuery({
    queryKey: [...APPROVALS_KEY, 'detail', a.id],
    queryFn: () => http.get<{ data: Approval }>(`/approvals/${a.id}`),
    enabled: showHistory,
  })
  const err = act.error instanceof ApiError ? act.error : null

  return (
    <Card>
      <CardContent className="space-y-3 pt-6">
        <div className="flex flex-wrap items-start justify-between gap-2">
          <div>
            <p className="font-medium">
              {a.subject.type === 'item' ? <Link to={`/barang/${a.subject.id}`} className="hover:underline">{a.title}</Link>
                : a.subject.type === 'stock_count' ? <Link to={`/stok/opname/${a.subject.id}`} className="hover:underline">{a.title}</Link>
                : a.type === 'SALE_REFUND' ? <Link to={`/penjualan/${String(a.payload.sale_id)}`} className="hover:underline">{a.title}</Link>
                : a.type === 'PURCHASE_REQUEST' ? <Link to={`/pembelian/pr/${a.subject.id}`} className="hover:underline">{a.title}</Link>
                : a.type === 'PURCHASE_ORDER' ? <Link to={`/pembelian/po/${a.subject.id}`} className="hover:underline">{a.title}</Link>
                : a.title}
            </p>
            <p className="text-lg">{a.summary}</p>
          </div>
          <Badge variant={STATUS_VARIANT[a.status]}>{APPROVAL_STATUS_LABEL[a.status]}</Badge>
        </div>
        <p className="text-sm text-muted-foreground">
          <span className="font-mono">{a.number}</span> · diajukan {a.requester?.name} ({a.requester_role?.name}) ·{' '}
          {formatDateTime(a.submitted_at)}
        </p>
        {a.reason && <p className="text-sm">Alasan: {a.reason}</p>}
        {a.type === 'SALE_REFUND' && Array.isArray(a.payload.lines) && (
          <ul className="list-disc pl-5 text-sm">
            {(a.payload.lines as { sale_line_id: number; item_name: string; qty: number; condition: string }[]).map((l) => (
              <li key={l.sale_line_id}>{l.item_name} × {l.qty} — {l.condition === 'MADE' ? 'sudah dibuat/dibuang, bahan tidak kembali' : 'belum dibuat, bahan kembali ke stok'}</li>
            ))}
          </ul>
        )}
        {(a.type === 'PURCHASE_REQUEST' || a.type === 'PURCHASE_ORDER') && Array.isArray(a.payload.lines) && (
          <ul className="list-disc pl-5 text-sm">
            {(a.payload.lines as { item_name: string; qty: string; unit_code: string | null; line_total?: number }[]).map((l, i) => (
              <li key={i}>{l.item_name} {formatQty(l.qty)} {l.unit_code}{l.line_total !== undefined && ` — ${formatRupiah(l.line_total)}`}</li>
            ))}
          </ul>
        )}
        {a.delegation && <p className="text-sm text-muted-foreground">Diputuskan atas delegasi dari {a.delegation.delegator?.name} ({a.delegation.number}).</p>}
        {a.decision_note && (
          <p className="text-sm">
            Catatan {a.decider?.name}: <span className="font-medium">{a.decision_note}</span>
          </p>
        )}

        {mode === 'none' && (a.can.decide || a.can.resubmit || a.can.cancel || a.type === 'STOCK_COUNT' || a.type === 'SALE_REFUND') && (
          <div className="flex flex-wrap gap-2">
            {a.can.decide && (
              <>
                <Button size="sm" disabled={act.isPending} onClick={() => act.mutate({ action: 'approve' })}>Setujui</Button>
                {a.type !== 'PURCHASE_REQUEST' && a.type !== 'PURCHASE_ORDER' && (
                  <Button size="sm" variant="outline" onClick={() => { act.reset(); setMode('return') }}>Kembalikan</Button>
                )}
                <Button size="sm" variant="outline" onClick={() => { act.reset(); setMode('reject') }}>Tolak</Button>
              </>
            )}
            {a.type === 'STOCK_COUNT' && <Button asChild size="sm" variant="outline"><Link to={`/stok/opname/${a.subject.id}`}>Lihat rincian opname</Link></Button>}
            {a.type === 'SALE_REFUND' && <Button asChild size="sm" variant="outline"><Link to={`/penjualan/${String(a.payload.sale_id)}`}>Lihat transaksi</Link></Button>}
            {a.can.resubmit && a.type === 'ITEM_PRICE' && <Button size="sm" onClick={() => { act.reset(); setMode('resubmit') }}>Perbaiki & ajukan ulang</Button>}
            {a.can.cancel && (
              <Button size="sm" variant="ghost" disabled={act.isPending} onClick={() => act.mutate({ action: 'cancel' })}>
                Batalkan pengajuan
              </Button>
            )}
          </div>
        )}

        {(mode === 'reject' || mode === 'return') && (
          <form
            className="flex flex-wrap items-start gap-2"
            onSubmit={(e) => {
              e.preventDefault()
              act.mutate({ action: mode, body: { note } })
            }}
          >
            <div className="min-w-64 flex-1">
              <Field id={`note-${a.id}`} label={mode === 'reject' ? 'Alasan penolakan' : 'Apa yang perlu diperbaiki?'} error={err?.field('note')}>
                <Input id={`note-${a.id}`} value={note} onChange={(e) => setNote(e.target.value)} />
              </Field>
            </div>
            <div className="flex gap-2 md:pt-[24px]">
              <Button type="submit" variant={mode === 'reject' ? 'destructive' : 'default'} disabled={act.isPending}>
                {mode === 'reject' ? 'Tolak pengajuan' : 'Kembalikan'}
              </Button>
              <Button type="button" variant="ghost" onClick={() => setMode('none')}>Batal</Button>
            </div>
          </form>
        )}

        {mode === 'resubmit' && a.type === 'ITEM_PRICE' && (
          <div className="rounded-lg border border-border p-4">
            <PriceForm
              initial={{ price: a.payload.price, effective_date: a.payload.effective_date, reason: a.reason ?? '' }}
              submitLabel="Ajukan ulang"
              pending={act.isPending}
              error={err}
              onSubmit={(input: PriceInput) =>
                act.mutate({ action: 'resubmit', body: { price: input.price, effective_date: input.effective_date, reason: input.reason || null } })
              }
              onCancel={() => setMode('none')}
            />
          </div>
        )}

        {err && !err.field('note') && !err.field('price') && !err.field('effective_date') && <ErrorAlert message={err.message} />}

        <button
          type="button"
          className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:underline"
          onClick={() => setShowHistory((v) => !v)}
        >
          {showHistory ? <ChevronUp className="size-4" /> : <ChevronDown className="size-4" />} Riwayat pengajuan
        </button>
        {showHistory && (
          history.isPending ? <Loading /> : (
            <ol className="space-y-1 border-l border-border pl-4 text-sm">
              {history.data?.data.events?.map((e, idx) => (
                <li key={idx}>
                  <span className="font-medium">{APPROVAL_ACTION_LABEL[e.action]}</span> oleh {e.user?.name} ·{' '}
                  <span className="text-muted-foreground">{formatDateTime(e.created_at)}</span>
                  {e.note && <> — {e.note}</>}
                </li>
              ))}
            </ol>
          )
        )}
      </CardContent>
    </Card>
  )
}
