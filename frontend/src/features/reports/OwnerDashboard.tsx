import { AlertTriangle, ArrowRight, ClipboardCheck } from 'lucide-react'
import type { ReactNode } from 'react'
import { Link } from 'react-router'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { ErrorAlert, Loading } from '@/components/ui/feedback'
import { Table, Td, Th } from '@/components/ui/table'
import { errorText } from '@/lib/api'
import { AUDIT_ACTION_LABEL, formatDate, formatDateTime, formatQty } from '@/lib/format'
import { cn } from '@/lib/utils'
import { minus, profitTone, rp, useReport } from './queries'
import { Kpi } from './ReportShell'
import type { Dashboard, WatchGroup } from './types'

const WATCH_LABEL: Record<WatchGroup, string> = {
  refunds: 'Void/refund disetujui',
  adjustments: 'Penyesuaian opname',
  waste: 'Waste dan susut kiriman',
  cancellations: 'Pembatalan dan penutupan dokumen',
  blocked: 'Percobaan ditolak kontrol',
  access: 'Login gagal dan reset kata sandi',
  authority: 'Perubahan akun, delegasi, dan pengaturan',
  owner_direct: 'Keputusan langsung Owner',
  single_person: 'Dikerjakan satu orang',
}

function Attention({ to, show, children }: { to: string; show: boolean; children: ReactNode }) {
  if (!show) return null
  return (
    <li>
      <Link to={to} className="flex items-center justify-between gap-3 rounded-md px-2 py-1.5 hover:bg-muted">
        <span className="flex items-center gap-2"><AlertTriangle className="size-4 shrink-0 text-amber-600" />{children}</span>
        <ArrowRight className="size-4 shrink-0 text-muted-foreground" />
      </Link>
    </li>
  )
}

/** Beranda Owner: urutan sesuai keputusan Owner irisan 9 butir 7. */
export function OwnerDashboard({ waiting }: { waiting: number }) {
  const q = useReport<Dashboard>('dashboard', '')
  if (q.isPending) return <Loading />
  if (q.error) return <ErrorAlert message={errorText(q.error)} />
  const d = q.data
  const a = d.attention
  const anyAttention = a.discrepancies.count + a.stock.negative + a.stock.low + a.production_over_tolerance + a.payables.overdue_count
    + a.payables.due_soon_count + a.price_differences.count + a.expenses_to_check + (a.cash_at_warehouse > 0 ? 1 : 0) > 0
  const watchTotal = Object.values(d.watch.counts).reduce((s, n) => s + n, 0)

  return (
    <div className="space-y-6">
      {/* 1. Persetujuan menunggu */}
      <Card className={cn(waiting > 0 && 'border-amber-500/50')}>
        <CardContent className="flex flex-wrap items-center justify-between gap-4 pt-6">
          <p className="flex items-center gap-2 font-medium">
            <ClipboardCheck className="size-5" />
            {waiting > 0 ? `${waiting} pengajuan menunggu keputusan Anda.` : 'Tidak ada pengajuan yang menunggu keputusan Anda.'}
          </p>
          <Button asChild variant={waiting > 0 ? 'default' : 'outline'}><Link to="/persetujuan">Buka persetujuan</Link></Button>
        </CardContent>
      </Card>

      {/* 2. Perlu perhatian */}
      <Card>
        <CardHeader><CardTitle className="text-base">Perlu perhatian</CardTitle></CardHeader>
        <CardContent>
          {!anyAttention ? <p className="text-sm text-muted-foreground">Tidak ada yang perlu ditindaklanjuti saat ini.</p> : (
            <ul className="space-y-0.5 text-sm">
              <Attention to="/kas/selisih" show={a.discrepancies.count > 0}>{a.discrepancies.count} selisih kas belum diputuskan Finance (jumlah {rp(a.discrepancies.amount)})</Attention>
              <Attention to="/stok" show={a.stock.negative > 0}>{a.stock.negative} barang stoknya minus</Attention>
              <Attention to="/stok" show={a.stock.low > 0}>{a.stock.low} barang di bawah stok minimum</Attention>
              <Attention to="/laporan/produksi-waste" show={a.production_over_tolerance > 0}>{a.production_over_tolerance} produksi bulan ini di luar toleransi varians</Attention>
              <Attention to="/laporan/hutang" show={a.payables.overdue_count > 0}>{a.payables.overdue_count} tagihan supplier lewat jatuh tempo ({rp(a.payables.overdue)})</Attention>
              <Attention to="/laporan/hutang" show={a.payables.due_soon_count > 0}>{a.payables.due_soon_count} tagihan supplier jatuh tempo dalam {a.payables.days} hari ({rp(a.payables.due_soon)})</Attention>
              <Attention to="/laporan/pembelian" show={a.price_differences.count > 0}>{a.price_differences.count} tagihan supplier bulan ini berbeda dari harga PO ({rp(a.price_differences.amount)})</Attention>
              <Attention to="/kas" show={a.cash_at_warehouse > 0}>{rp(a.cash_at_warehouse)} kas di gudang belum diterima Finance</Attention>
              <Attention to="/kas/biaya" show={a.expenses_to_check > 0}>{a.expenses_to_check} biaya Petty Cash menunggu diperiksa Finance</Attention>
            </ul>
          )}
          {a.stock.items.length > 0 && (
            <p className="mt-2 px-2 text-xs text-muted-foreground">
              {a.stock.items.map((s) => `${s.name} di ${s.location}: ${formatQty(s.qty)} ${s.unit}${s.min_qty ? ` (min ${formatQty(s.min_qty)})` : ''}`).join(' · ')}
            </p>
          )}
        </CardContent>
      </Card>

      {/* 3. Penjualan per outlet */}
      <Card>
        <CardHeader className="flex-row items-center justify-between">
          <CardTitle className="text-base">Penjualan per outlet</CardTitle>
          <Link to="/laporan/penjualan" className="text-sm text-muted-foreground hover:underline">Laporan lengkap</Link>
        </CardHeader>
        <CardContent>
          <Table>
            <thead><tr><Th>Outlet</Th><Th className="text-right">Hari ini ({formatDate(d.today)})</Th><Th className="text-right">Bulan ini</Th></tr></thead>
            <tbody>
              {d.sales.rows.map((r) => (
                <tr key={r.location.id}>
                  <Td className="font-medium">{r.location.name}</Td>
                  <Td className="text-right tabular-nums">{rp(r.today)} <span className="text-xs text-muted-foreground">{r.today_transactions} trx</span></Td>
                  <Td className="text-right tabular-nums">{rp(r.month)} <span className="text-xs text-muted-foreground">{r.month_transactions} trx</span></Td>
                </tr>
              ))}
              <tr className="bg-muted/50 font-semibold"><Td>Total</Td><Td className="text-right tabular-nums">{rp(d.sales.today)}</Td><Td className="text-right tabular-nums">{rp(d.sales.month)}</Td></tr>
            </tbody>
          </Table>
          <p className="mt-2 text-xs text-muted-foreground">Penjualan bersih, sudah dikurangi void/refund.</p>
        </CardContent>
      </Card>

      {/* 4. Laba bulan ini */}
      <section className="space-y-2">
        <div className="flex items-center justify-between">
          <h2 className="font-semibold">Laba bulan ini <span className="text-sm font-normal text-muted-foreground">({formatDate(d.month.from)} – {formatDate(d.month.to)})</span></h2>
          <Link to="/laporan/laba" className="text-sm text-muted-foreground hover:underline">Laporan laba rugi</Link>
        </div>
        <div className="grid gap-3 md:grid-cols-4">
          <Kpi label="Penjualan bersih" value={rp(d.profit.net_sales)} />
          <Kpi label="Laba kotor" value={rp(d.profit.gross_profit)} hint={`HPP ${minus(d.profit.hpp)}`} tone={profitTone(d.profit.gross_profit)} />
          <Kpi label="Biaya + kerugian" value={minus(d.profit.expenses + d.profit.stock_loss + d.profit.other_loss)}
            hint={`biaya ${rp(d.profit.expenses)} · kerugian stok ${rp(d.profit.stock_loss)}`} />
          <Kpi label="Laba bersih" value={rp(d.profit.net_profit)} tone={profitTone(d.profit.net_profit)} />
        </div>
        {d.profit.hpp_incomplete > 0 && (
          <p className="text-xs text-amber-700">{d.profit.hpp_incomplete} transaksi punya HPP belum lengkap, sehingga laba bisa terlihat lebih tinggi dari sebenarnya.</p>
        )}
      </section>

      {/* 5. Pengawasan audit */}
      <Card>
        <CardHeader className="flex-row items-center justify-between">
          <CardTitle className="text-base">Pengawasan {d.watch.days} hari terakhir</CardTitle>
          <Link to="/jejak-audit" className="text-sm text-muted-foreground hover:underline">Jejak audit</Link>
        </CardHeader>
        <CardContent className="space-y-3">
          {watchTotal === 0 ? <p className="text-sm text-muted-foreground">Tidak ada kejadian yang perlu diawasi.</p> : (
            <>
              <div className="flex flex-wrap gap-2">
                {(Object.keys(WATCH_LABEL) as WatchGroup[]).filter((k) => d.watch.counts[k] > 0).map((k) => (
                  <span key={k} className={cn('rounded-full border px-3 py-1 text-sm', k === 'blocked' ? 'border-destructive/40 text-destructive' : 'border-border')}>
                    {WATCH_LABEL[k]}: <b>{d.watch.counts[k]}</b>
                  </span>
                ))}
              </div>
              <Table>
                <thead><tr><Th>Waktu</Th><Th>Kejadian</Th><Th>Oleh</Th><Th>Lokasi</Th><Th>Keterangan</Th></tr></thead>
                <tbody>{d.watch.latest.map((w) => (
                  <tr key={w.id}>
                    <Td className="whitespace-nowrap">{formatDateTime(w.at)}</Td>
                    <Td>{AUDIT_ACTION_LABEL[w.action] ?? w.action}{w.subject_code && <span className="ml-1 font-mono text-xs text-muted-foreground">{w.subject_code}</span>}</Td>
                    <Td>{w.user ?? '–'}</Td><Td>{w.location ?? '–'}</Td><Td>{w.note ?? '–'}</Td>
                  </tr>
                ))}</tbody>
              </Table>
            </>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
