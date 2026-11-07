import type { ReactNode } from 'react'
import { Link } from 'react-router'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { PageHeader } from '@/components/ui/feedback'
import { EmptyRow, Table, Td, Th } from '@/components/ui/table'
import {
  DISCREPANCY_CATEGORY_LABEL, ITEM_TYPE_LABEL, MOVEMENT_TYPE_LABEL, WASTE_REASON_LABEL,
  formatCost, formatDate, formatDateTime, formatPct, formatQty, formatSignedRupiah,
} from '@/lib/format'
import type { ItemType, MovementType } from '@/lib/types'
import { cn } from '@/lib/utils'
import { REPORTS, minus, profitTone, rp } from './queries'
import { Kpi, ReportShell } from './ReportShell'
import type {
  CashFlowReport, CashPositions, CashReconReport, ExpenseReport, MenuHppReport, MovementReport, PayablesReport, ProductionWasteReport,
  ProfitReport, ProfitRow, PurchasingReport, SalesReport, ValuationReport,
} from './types'

export function ReportsIndexPage() {
  const groups = [...new Set(REPORTS.map((r) => r.group))]
  return (
    <div className="max-w-5xl space-y-6">
      <PageHeader title="Laporan" purpose="Semua laporan dihitung langsung dari catatan transaksi. Pilih laporan, lalu atur periode dan lokasinya." />
      {groups.map((g) => (
        <section key={g} className="space-y-2">
          <h2 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">{g}</h2>
          <div className="grid gap-3 md:grid-cols-2">
            {REPORTS.filter((r) => r.group === g).map((r) => (
              <Link key={r.to} to={`/laporan/${r.to}`} className="rounded-lg border border-border p-4 hover:bg-muted/50">
                <p className="flex items-center gap-2 font-medium"><r.icon className="size-4" />{r.title}</p>
                <p className="mt-1 text-sm text-muted-foreground">{r.desc}</p>
              </Link>
            ))}
          </div>
        </section>
      ))}
    </div>
  )
}

const R = ({ children, className }: { children: ReactNode; className?: string }) => <Td className={cn('text-right tabular-nums', className)}>{children}</Td>
const RH = ({ children }: { children: ReactNode }) => <Th className="text-right">{children}</Th>
const qty = (v: string, unit?: string) => `${formatQty(v)}${unit ? ` ${unit}` : ''}`

// --- Laba rugi ---

const PROFIT_LINES: { key: keyof ProfitRow; label: string; kind: 'plus' | 'minus' | 'sub' | 'total' | 'signed' }[] = [
  { key: 'sales_gross', label: 'Penjualan', kind: 'plus' },
  { key: 'refunds', label: 'Void/refund', kind: 'minus' },
  { key: 'net_sales', label: 'Penjualan bersih', kind: 'sub' },
  { key: 'hpp', label: 'HPP', kind: 'minus' },
  { key: 'gross_profit', label: 'Laba kotor', kind: 'sub' },
  { key: 'expenses', label: 'Biaya operasional', kind: 'minus' },
  { key: 'waste', label: 'Waste', kind: 'minus' },
  { key: 'transit_loss', label: 'Susut kiriman', kind: 'minus' },
  { key: 'count_adjustment', label: 'Selisih opname', kind: 'minus' },
  { key: 'refund_made', label: 'Refund menu sudah dibuat', kind: 'minus' },
  { key: 'price_difference', label: 'Beda harga tagihan supplier', kind: 'minus' },
  { key: 'cash_business_loss', label: 'Selisih kas (kerugian usaha)', kind: 'signed' },
  { key: 'net_profit', label: 'Laba bersih', kind: 'total' },
]

function profitCell(v: number, kind: string) {
  if (kind === 'minus') return minus(v)
  if (kind === 'signed') return formatSignedRupiah(v)
  return rp(v)
}

export function ProfitReportPage() {
  return (
    <ReportShell<ProfitReport> title="Laba rugi" path="profit" withLocation
      purpose="Berapa untung usaha pada periode ini, per outlet dan keseluruhan."
      explain={<>
        Laba bersih = penjualan bersih − HPP − biaya operasional − kerugian stok − selisih lain. Void/refund dihitung pada tanggal disetujui.
        Biaya dan kerugian gudang serta pusat tidak dibagi ke outlet; tampil di kolom <b>Gudang &amp; pusat</b>, sehingga jumlah semua kolom = laba bersih usaha.
        Hasil gagal produksi tidak dihitung lagi karena sudah masuk harga pokok hasil baik. Selisih opname bisa mengurangi atau menambah laba.
      </>}>
      {(d) => (
        <>
          <div className="grid gap-3 md:grid-cols-4">
            <Kpi label="Penjualan bersih" value={rp(d.total.net_sales)} hint={`${d.total.transactions} transaksi`} />
            <Kpi label="Laba kotor" value={rp(d.total.gross_profit)} tone={profitTone(d.total.gross_profit)}
              hint={d.total.net_sales > 0 ? `${formatQty(((d.total.gross_profit * 100) / d.total.net_sales).toFixed(1))}% dari penjualan` : undefined} />
            <Kpi label="Biaya + kerugian" value={rp(d.total.expenses + d.total.stock_loss + d.total.other_loss)} />
            <Kpi label="Laba bersih" value={rp(d.total.net_profit)} tone={profitTone(d.total.net_profit)} />
          </div>
          {(d.total.hpp_incomplete > 0 || d.total.expenses_pending_count > 0) && (
            <div className="space-y-1 rounded-lg border border-amber-500/50 px-4 py-3 text-sm">
              {d.total.hpp_incomplete > 0 && <p>{d.total.hpp_incomplete} transaksi punya HPP belum lengkap (ada bahan tanpa harga pokok), sehingga laba bisa terlihat lebih tinggi.</p>}
              {d.total.expenses_pending_count > 0 && <p>{d.total.expenses_pending_count} biaya Petty Cash ({rp(d.total.expenses_pending)}) masih menunggu diperiksa Finance; sudah ikut dihitung.</p>}
            </div>
          )}
          <Table>
            <thead>
              <tr>
                <Th>Uraian</Th>
                {d.rows.map((r) => <RH key={r.location.id}>{r.location.name}</RH>)}
                {d.rows.length > 1 && <RH>Total</RH>}
              </tr>
            </thead>
            <tbody>
              {PROFIT_LINES.map((l) => (
                <tr key={l.key} className={cn(l.kind === 'sub' && 'font-medium', l.kind === 'total' && 'bg-muted/50 font-semibold')}>
                  <Td className={cn(l.kind === 'minus' && 'pl-6', l.kind === 'signed' && 'pl-6')}>{l.label}</Td>
                  {d.rows.map((r) => <R key={r.location.id}>{profitCell(r[l.key] as number, l.kind)}</R>)}
                  {d.rows.length > 1 && <R>{profitCell(d.total[l.key as keyof typeof d.total] as number, l.kind)}</R>}
                </tr>
              ))}
            </tbody>
          </Table>
        </>
      )}
    </ReportShell>
  )
}

// --- Penjualan per outlet ---

export function SalesReportPage() {
  return (
    <ReportShell<SalesReport> title="Penjualan per outlet" path="sales" withLocation outletsOnly
      purpose="Berapa yang terjual di tiap outlet, lewat kanal dan metode bayar apa."
      explain="Penjualan memakai tanggal transaksi; void/refund memakai tanggal disetujui Owner, sehingga laporan hari yang sudah lewat tidak berubah. Metode bayar sudah dikurangi uang yang dikembalikan.">
      {(d) => {
        const methods = [...new Set(d.rows.flatMap((r) => r.methods.map((m) => m.name)))]
        return (
          <>
            <div className="grid gap-3 md:grid-cols-4">
              <Kpi label="Penjualan bersih" value={rp(d.total.net_sales)} />
              <Kpi label="Transaksi" value={String(d.total.transactions)} hint={`rata-rata ${rp(d.total.average)}`} />
              <Kpi label="Void/refund" value={rp(d.total.refunds)} hint={`${d.total.refund_count} pengajuan disetujui`} tone={d.total.refunds > 0 ? 'warn' : undefined} />
              <Kpi label="Dine-in / takeaway" value={`${rp(d.total.dine_in)} / ${rp(d.total.takeaway)}`} />
            </div>
            <Table>
              <thead>
                <tr><Th>Outlet</Th><RH>Transaksi</RH><RH>Penjualan</RH><RH>Void/refund</RH><RH>Penjualan bersih</RH><RH>Rata-rata</RH><RH>Dine-in</RH><RH>Takeaway</RH>{methods.map((m) => <RH key={m}>{m}</RH>)}</tr>
              </thead>
              <tbody>
                {d.rows.length === 0 && <EmptyRow colSpan={8 + methods.length}>Belum ada outlet.</EmptyRow>}
                {d.rows.map((r) => (
                  <tr key={r.location.id}>
                    <Td className="font-medium">{r.location.name}</Td><R>{r.transactions}</R><R>{rp(r.sales_gross)}</R><R>{minus(r.refunds)}</R>
                    <R className="font-medium">{rp(r.net_sales)}</R><R>{rp(r.average)}</R><R>{rp(r.dine_in)}</R><R>{rp(r.takeaway)}</R>
                    {methods.map((m) => <R key={m}>{rp(r.methods.find((x) => x.name === m)?.amount ?? 0)}</R>)}
                  </tr>
                ))}
              </tbody>
            </Table>
            <Card>
              <CardHeader><CardTitle className="text-base">Penjualan bersih per hari</CardTitle></CardHeader>
              <CardContent>
                {d.daily.length === 0 ? <p className="text-sm text-muted-foreground">Belum ada penjualan pada periode ini.</p> : (
                  <Table>
                    <thead><tr><Th>Tanggal</Th>{d.rows.map((r) => <RH key={r.location.id}>{r.location.name}</RH>)}<RH>Total</RH></tr></thead>
                    <tbody>
                      {d.daily.map((day) => (
                        <tr key={day.date}>
                          <Td>{formatDate(day.date)}</Td>
                          {d.rows.map((r) => <R key={r.location.id}>{rp(day.by_location.find((x) => x.location_id === r.location.id)?.net_sales ?? 0)}</R>)}
                          <R className="font-medium">{rp(day.net_sales)}</R>
                        </tr>
                      ))}
                    </tbody>
                  </Table>
                )}
              </CardContent>
            </Card>
          </>
        )
      }}
    </ReportShell>
  )
}

// --- HPP per menu ---

export function MenuHppReportPage() {
  return (
    <ReportShell<MenuHppReport> title="HPP per menu" path="menu-hpp" withLocation outletsOnly
      purpose="Menu mana yang paling banyak menghasilkan, dan berapa margin tiap porsinya."
      explain={<>HPP adalah harga pokok bahan dan kemasan yang dikunci saat transaksi (resep dan harga rata-rata saat itu). Porsi yang di-refund sudah dikurangkan. Rincian HPP per bahan ada di halaman <Link to="/resep" className="underline">Resep</Link>.</>}>
      {(d) => (
        <>
          <div className="grid gap-3 md:grid-cols-4">
            <Kpi label="Porsi terjual" value={formatQty(d.total.qty)} />
            <Kpi label="Pendapatan" value={rp(d.total.revenue)} />
            <Kpi label="HPP" value={rp(d.total.hpp)} />
            <Kpi label="Margin" value={d.total.margin_pct == null ? '–' : `${formatQty(d.total.margin_pct)}%`} tone={profitTone(d.total.gross_profit)} />
          </div>
          <Table>
            <thead><tr><Th>Menu</Th><RH>Porsi</RH><RH>Pendapatan</RH><RH>HPP</RH><RH>HPP/porsi</RH><RH>Laba kotor</RH><RH>Margin</RH></tr></thead>
            <tbody>
              {d.rows.length === 0 && <EmptyRow colSpan={7}>Belum ada penjualan pada periode ini.</EmptyRow>}
              {d.rows.map((r) => (
                <tr key={r.item.id}>
                  <Td>
                    <span className="font-medium">{r.item.name}</span>
                    {r.qty_refunded > 0 && <span className="ml-2 text-xs text-muted-foreground">{r.qty_refunded} porsi di-refund</span>}
                    {r.incomplete > 0 && <Badge variant="warning" className="ml-2">HPP belum lengkap</Badge>}
                  </Td>
                  <R>{formatQty(r.qty)}</R><R>{rp(r.revenue)}</R><R>{rp(r.hpp)}</R><R>{r.hpp_per_portion == null ? '–' : rp(r.hpp_per_portion)}</R>
                  <R>{rp(r.gross_profit)}</R><R>{r.margin_pct == null ? '–' : `${formatQty(r.margin_pct)}%`}</R>
                </tr>
              ))}
            </tbody>
          </Table>
        </>
      )}
    </ReportShell>
  )
}

// --- Biaya operasional ---

export function ExpenseReportPage() {
  return (
    <ReportShell<ExpenseReport> title="Biaya operasional" path="expenses" withLocation
      purpose="Ke mana uang operasional dipakai, per kategori dan lokasi."
      explain="Biaya dihitung menurut tanggal biaya. Biaya Petty Cash yang masih menunggu diperiksa Finance ikut dihitung dan ditandai; yang ditolak Finance tidak dihitung sebagai biaya usaha dan tampil di daftar terpisah agar ditindaklanjuti. Ongkos kirim pembelian termasuk di sini (bukan HPP).">
      {(d) => (
        <>
          <div className="grid gap-3 md:grid-cols-3">
            <Kpi label="Total biaya" value={rp(d.total.amount)} />
            <Kpi label="Menunggu diperiksa" value={rp(d.total.pending)} tone={d.total.pending > 0 ? 'warn' : undefined} />
            <Kpi label="Ditolak Finance" value={rp(d.total.rejected)} hint="Tidak masuk laba" tone={d.total.rejected > 0 ? 'warn' : undefined} />
          </div>
          <Table>
            <thead><tr><Th>Kategori</Th><Th>Lokasi</Th><RH>Jumlah catatan</RH><RH>Menunggu diperiksa</RH><RH>Jumlah</RH></tr></thead>
            <tbody>
              {d.rows.length === 0 && <EmptyRow colSpan={5}>Belum ada biaya pada periode ini.</EmptyRow>}
              {d.rows.map((r) => (
                <tr key={`${r.category.id}-${r.location.id}`}>
                  <Td className="font-medium">{r.category.name}</Td><Td>{r.location.name}</Td><R>{r.count}</R><R>{r.pending ? rp(r.pending) : '–'}</R><R>{rp(r.amount)}</R>
                </tr>
              ))}
            </tbody>
          </Table>
          {d.rejected.length > 0 && (
            <Card>
              <CardHeader><CardTitle className="text-base">Biaya yang ditolak Finance</CardTitle></CardHeader>
              <CardContent>
                <Table>
                  <thead><tr><Th>Tanggal</Th><Th>Nomor</Th><Th>Lokasi</Th><Th>Keterangan</Th><Th>Alasan ditolak</Th><RH>Jumlah</RH></tr></thead>
                  <tbody>{d.rejected.map((r) => (
                    <tr key={r.id}><Td>{formatDate(r.date)}</Td><Td className="font-mono">{r.number}</Td><Td>{r.location}</Td><Td>{r.description}</Td><Td>{r.reason ?? '–'}</Td><R>{rp(r.amount)}</R></tr>
                  ))}</tbody>
                </Table>
              </CardContent>
            </Card>
          )}
        </>
      )}
    </ReportShell>
  )
}

// --- Arus kas ---

function Positions({ p }: { p: CashPositions }) {
  const rows: [string, number, string?][] = [
    ['Sales Cash di outlet, belum diserahkan', p.sales_cash_at_outlets, `${p.shifts_not_handed_over} shift`],
    ['Dalam perjalanan outlet → gudang', p.in_transit_to_warehouse],
    ['Kas di gudang, belum diserahkan ke Finance', p.at_warehouse],
    ['Dalam perjalanan gudang → Finance', p.in_transit_to_finance],
    ['Non-tunai belum dicocokkan (30 hari terakhir)', p.noncash_unmatched, `${p.noncash_unmatched_count} tanggal × metode`],
  ]
  return (
    <Card>
      <CardHeader><CardTitle className="text-base">Uang yang masih di jalan (saat ini)</CardTitle></CardHeader>
      <CardContent>
        <Table>
          <tbody>{rows.map(([label, v, hint]) => (
            <tr key={label}><Td>{label}{hint && v > 0 && <span className="ml-2 text-xs text-muted-foreground">{hint}</span>}</Td><R>{rp(v)}</R></tr>
          ))}</tbody>
        </Table>
      </CardContent>
    </Card>
  )
}

export function CashFlowReportPage() {
  return (
    <ReportShell<CashFlowReport> title="Arus kas" path="cash-flow"
      purpose="Berapa uang yang masuk dan keluar dari usaha pada periode ini."
      explain="Uang masuk dari penjualan (tunai dan non-tunai) dikurangi uang yang dikembalikan. Uang keluar: pembayaran supplier, biaya yang dibayar Finance, dan isi ulang Petty Cash. Biaya kasir dari Petty Cash tidak dihitung lagi karena sudah tercakup di isi ulangnya. Saldo kas dan bank belum dicatat di aplikasi, jadi laporan ini tidak memuat saldo awal dan akhir.">
      {(d) => (
        <>
          <div className="grid gap-3 md:grid-cols-3">
            <Kpi label="Uang masuk" value={rp(d.in_total)} />
            <Kpi label="Uang keluar" value={rp(d.out_total)} />
            <Kpi label="Bersih" value={rp(d.net)} tone={profitTone(d.net)} />
          </div>
          <Table>
            <tbody>
              <tr className="font-medium"><Td>Uang masuk</Td><Td /></tr>
              {d.in.map((l) => <tr key={l.key}><Td className="pl-6">{l.label}</Td><R>{rp(l.amount)}</R></tr>)}
              <tr className="font-medium"><Td>Jumlah masuk</Td><R>{rp(d.in_total)}</R></tr>
              <tr className="font-medium"><Td>Uang keluar</Td><Td /></tr>
              {d.out.map((l) => <tr key={l.key}><Td className="pl-6">{l.label}</Td><R>{minus(l.amount)}</R></tr>)}
              <tr className="font-medium"><Td>Jumlah keluar</Td><R>{minus(d.out_total)}</R></tr>
              <tr className="bg-muted/50 font-semibold"><Td>Arus kas bersih</Td><R>{rp(d.net)}</R></tr>
            </tbody>
          </Table>
          <Positions p={d.positions} />
        </>
      )}
    </ReportShell>
  )
}

// --- Rekonsiliasi kas ---

const CAT_LABEL: Record<string, string> = { OPEN: 'Belum diputuskan', ...DISCREPANCY_CATEGORY_LABEL, NONE: 'Diputuskan tanpa kategori' }

export function CashReconReportPage() {
  return (
    <ReportShell<CashReconReport> title="Rekonsiliasi kas" path="cash-reconciliation"
      purpose="Apakah uang yang dihitung dan diserahkan cocok dengan catatan sistem."
      explain={<>Tutup shift memakai tanggal tutup; serah terima memakai tanggal diterima. Setiap selisih wajib dijelaskan dan diputuskan Finance di halaman <Link to="/kas/selisih" className="underline">Selisih kas</Link>. Hanya keputusan &quot;kerugian usaha&quot; yang mengurangi laba.</>}>
      {(d) => (
        <>
          <Table>
            <thead><tr><Th>Tahap</Th><RH>Jumlah</RH><RH>Seharusnya</RH><RH>Dihitung / diterima</RH><RH>Selisih</RH><RH>Berselisih</RH></tr></thead>
            <tbody>
              <tr><Td>Tutup shift kasir</Td><R>{d.shifts.count}</R><R>{rp(d.shifts.expected)}</R><R>{rp(d.shifts.counted)}</R><R>{formatSignedRupiah(d.shifts.difference)}</R><R>{d.shifts.with_difference}</R></tr>
              {d.handovers.map((h) => (
                <tr key={h.stage}><Td>Serah terima {h.label}</Td><R>{h.count}</R><R>{rp(h.expected)}</R><R>{rp(h.received)}</R><R>{formatSignedRupiah(h.difference)}</R><R>{h.with_difference}</R></tr>
              ))}
            </tbody>
          </Table>
          <Card>
            <CardHeader><CardTitle className="text-base">Pencocokan non-tunai</CardTitle></CardHeader>
            <CardContent>
              <Table>
                <thead><tr><Th>Metode</Th><RH>Hari dicocokkan</RH><RH>Menurut sistem</RH><RH>Mutasi rekening</RH><RH>Selisih</RH></tr></thead>
                <tbody>
                  {d.noncash.length === 0 && <EmptyRow colSpan={5}>Belum ada pencocokan pada periode ini.</EmptyRow>}
                  {d.noncash.map((n) => <tr key={n.method.id}><Td>{n.method.name}</Td><R>{n.days}</R><R>{rp(n.system)}</R><R>{rp(n.bank)}</R><R>{formatSignedRupiah(n.difference)}</R></tr>)}
                </tbody>
              </Table>
            </CardContent>
          </Card>
          <Card>
            <CardHeader><CardTitle className="text-base">Selisih menurut keputusan</CardTitle></CardHeader>
            <CardContent className="space-y-3">
              <div className="grid gap-3 md:grid-cols-5">
                {Object.entries(d.categories).filter(([k, v]) => k !== 'NONE' || v.count > 0).map(([k, v]) => (
                  <div key={k} className="rounded-lg border border-border p-3 text-sm">
                    <p className="text-muted-foreground">{CAT_LABEL[k]}</p>
                    <p className="font-semibold tabular-nums">{formatSignedRupiah(v.amount)}</p>
                    <p className="text-xs text-muted-foreground">{v.count} selisih</p>
                  </div>
                ))}
              </div>
              {d.discrepancies.length > 0 && (
                <Table>
                  <thead><tr><Th>Waktu</Th><Th>Jenis</Th><Th>Nomor</Th><Th>Lokasi</Th><Th>Keputusan</Th><RH>Selisih</RH></tr></thead>
                  <tbody>{d.discrepancies.map((x) => (
                    <tr key={`${x.subject_type}-${x.subject_id}`}>
                      <Td>{formatDateTime(x.at)}</Td><Td>{x.kind}</Td><Td><Link to={x.link} className="font-mono hover:underline">{x.number}</Link></Td><Td>{x.location ?? '–'}</Td>
                      <Td>{x.resolution ? CAT_LABEL[x.resolution.category ?? 'NONE'] : <Badge variant="warning">Belum diputuskan</Badge>}</Td>
                      <R>{formatSignedRupiah(x.amount)}</R>
                    </tr>
                  ))}</tbody>
                </Table>
              )}
            </CardContent>
          </Card>
          <Positions p={d.positions} />
        </>
      )}
    </ReportShell>
  )
}

// --- Nilai stok ---

const TYPES: ItemType[] = ['RAW', 'SEMI_FINISHED', 'SUPPORTING', 'PACKAGING']

export function ValuationReportPage() {
  return (
    <ReportShell<ValuationReport> title="Nilai stok" path="inventory-valuation" mode="asOf" withLocation
      purpose="Berapa nilai persediaan di gudang dan tiap outlet pada akhir satu tanggal."
      explain="Nilai = jumlah stok × harga pokok rata-rata (moving average) dari kartu stok pada akhir tanggal itu. Stok minus dinilai apa adanya dan ditandai; selesaikan lewat stock opname. Barang tanpa harga pokok tidak ikut dinilai.">
      {(d) => (
        <>
          <div className="grid gap-3 md:grid-cols-5">
            <Kpi label="Total nilai stok" value={rp(d.total.value)} />
            {TYPES.map((t) => <Kpi key={t} label={ITEM_TYPE_LABEL[t]} value={rp(d.total.by_type[t] ?? 0)} />)}
          </div>
          {(d.total.negative > 0 || d.total.no_cost > 0) && (
            <p className="rounded-lg border border-amber-500/50 px-4 py-3 text-sm">
              {d.total.negative > 0 && `${d.total.negative} barang stoknya minus. `}{d.total.no_cost > 0 && `${d.total.no_cost} barang belum punya harga pokok.`}
            </p>
          )}
          <Table>
            <thead><tr><Th>Lokasi</Th>{TYPES.map((t) => <RH key={t}>{ITEM_TYPE_LABEL[t]}</RH>)}<RH>Total</RH></tr></thead>
            <tbody>
              {d.locations.length === 0 && <EmptyRow colSpan={6}>Belum ada stok.</EmptyRow>}
              {d.locations.map((l) => (
                <tr key={l.location.id}><Td className="font-medium">{l.location.name}</Td>{TYPES.map((t) => <R key={t}>{rp(l.by_type[t] ?? 0)}</R>)}<R className="font-medium">{rp(l.value)}</R></tr>
              ))}
            </tbody>
          </Table>
          <Table>
            <thead><tr><Th>Lokasi</Th><Th>Barang</Th><Th>Kelompok</Th><RH>Jumlah</RH><RH>Harga pokok</RH><RH>Nilai</RH></tr></thead>
            <tbody>{d.rows.map((r) => (
              <tr key={`${r.location.id}-${r.item.id}`}>
                <Td>{r.location.name}</Td>
                <Td><Link to={`/stok/kartu?barang=${r.item.id}&lokasi=${r.location.id}`} className="hover:underline">{r.item.name}</Link></Td>
                <Td>{ITEM_TYPE_LABEL[r.item.type as ItemType] ?? r.item.type}</Td>
                <R className={cn(r.negative && 'text-destructive')}>{qty(r.qty, r.item.unit)}{r.negative && ' (minus)'}</R>
                <R>{r.avg_cost == null ? '–' : `${formatCost(r.avg_cost)}/${r.item.unit}`}</R>
                <R>{r.value == null ? '–' : rp(r.value)}</R>
              </tr>
            ))}</tbody>
          </Table>
        </>
      )}
    </ReportShell>
  )
}

// --- Pergerakan stok ---

export function MovementReportPage() {
  return (
    <ReportShell<MovementReport> title="Pergerakan stok" path="stock-movements" withLocation
      purpose="Dari mana stok bertambah dan ke mana berkurang, per barang per lokasi."
      explain="Saldo awal = saldo akhir hari sebelum periode. Masuk dan keluar dijumlah dari kartu stok, dalam satuan dasar barang. Saldo awal + masuk − keluar = saldo akhir. Arahkan kursor ke angka masuk/keluar untuk melihat rinciannya per jenis.">
      {(d) => (
        <>
          <div className="grid gap-3 md:grid-cols-4">
            <Kpi label="Nilai awal" value={rp(d.total.opening_value)} />
            <Kpi label="Nilai masuk" value={rp(d.total.in_value)} />
            <Kpi label="Nilai keluar" value={rp(d.total.out_value)} />
            <Kpi label="Nilai akhir" value={rp(d.total.closing_value)} />
          </div>
          <Table>
            <thead><tr><Th>Lokasi</Th><Th>Barang</Th><RH>Saldo awal</RH><RH>Masuk</RH><RH>Keluar</RH><RH>Saldo akhir</RH><RH>Nilai akhir</RH></tr></thead>
            <tbody>
              {d.rows.length === 0 && <EmptyRow colSpan={7}>Belum ada pergerakan stok.</EmptyRow>}
              {d.rows.map((r) => {
                const detail = (sign: 1 | -1) => Object.entries(r.by_type).filter(([, v]) => Math.sign(Number(v)) === sign)
                  .map(([t, v]) => `${MOVEMENT_TYPE_LABEL[t as MovementType] ?? t}: ${formatQty(Math.abs(Number(v)))}`).join('\n')
                return (
                  <tr key={`${r.location.id}-${r.item.id}`}>
                    <Td>{r.location.name}</Td>
                    <Td><Link to={`/stok/kartu?barang=${r.item.id}&lokasi=${r.location.id}`} className="hover:underline">{r.item.name}</Link></Td>
                    <R>{qty(r.opening_qty, r.item.unit)}</R>
                    <R><span title={detail(1)}>{Number(r.in_qty) ? `+${qty(r.in_qty)}` : '–'}</span></R>
                    <R><span title={detail(-1)}>{Number(r.out_qty) ? `-${qty(r.out_qty)}` : '–'}</span></R>
                    <R className={cn('font-medium', Number(r.closing_qty) < 0 && 'text-destructive')}>{qty(r.closing_qty, r.item.unit)}</R>
                    <R>{rp(r.closing_value)}</R>
                  </tr>
                )
              })}
            </tbody>
          </Table>
        </>
      )}
    </ReportShell>
  )
}

// --- Produksi, waste, varians ---

export function ProductionWasteReportPage() {
  return (
    <ReportShell<ProductionWasteReport> title="Produksi, waste & varians" path="production-waste" withLocation
      purpose="Hasil produksi dibanding rencana, serta semua barang yang hilang atau terbuang."
      explain="Varians hasil = (hasil baik − target) ÷ target. Biaya hasil gagal produksi sudah termasuk di harga pokok hasil baik, sehingga tidak dihitung lagi di laba. Waste, susut kiriman, dan refund menu yang sudah dibuat mengurangi laba; selisih opname bisa kurang atau lebih.">
      {(d) => (
        <>
          <div className="grid gap-3 md:grid-cols-4">
            <Kpi label="Produksi selesai" value={String(d.production_total.orders)} hint={`${d.production_total.over_tolerance} di luar toleransi`} tone={d.production_total.over_tolerance > 0 ? 'warn' : undefined} />
            <Kpi label="Biaya bahan produksi" value={rp(d.production_total.total_cost)} hint={`hasil gagal ${rp(d.production_total.failed_value)}`} />
            <Kpi label="Waste" value={rp(d.waste_total)} tone={d.waste_total > 0 ? 'warn' : undefined} />
            <Kpi label="Susut kiriman" value={rp(d.transit_total)} tone={d.transit_total > 0 ? 'warn' : undefined} />
          </div>
          <Card>
            <CardHeader><CardTitle className="text-base">Produksi</CardTitle></CardHeader>
            <CardContent>
              <Table>
                <thead><tr><Th>Selesai</Th><Th>Nomor</Th><Th>Barang</Th><RH>Target</RH><RH>Hasil baik</RH><RH>Gagal</RH><RH>Varians hasil</RH><RH>Harga pokok/satuan</RH><Th>Catatan</Th></tr></thead>
                <tbody>
                  {d.production.length === 0 && <EmptyRow colSpan={9}>Belum ada produksi selesai pada periode ini.</EmptyRow>}
                  {d.production.map((o) => (
                    <tr key={o.id}>
                      <Td>{formatDate(o.completed_at)}</Td>
                      <Td><Link to={`/produksi/${o.id}`} className="font-mono hover:underline">{o.number}</Link></Td>
                      <Td>{o.item.name}</Td>
                      <R>{qty(o.planned_qty, o.item.unit)}</R><R>{qty(o.good_qty)}</R><R>{qty(o.failed_qty)}</R>
                      <R className={cn(o.over_tolerance && 'text-amber-700 font-medium')}>{formatPct(o.output_variance_pct)}{o.materials_over > 0 && ` · ${o.materials_over} bahan`}</R>
                      <R>{o.unit_cost == null ? '–' : formatCost(o.unit_cost)}{!o.cost_complete && <Badge variant="warning" className="ml-1">belum lengkap</Badge>}</R>
                      <Td>{o.over_tolerance && <Badge variant="warning" className="mr-1">Di luar toleransi</Badge>}{o.variance_note}</Td>
                    </tr>
                  ))}
                </tbody>
              </Table>
            </CardContent>
          </Card>
          <Card>
            <CardHeader><CardTitle className="text-base">Waste</CardTitle></CardHeader>
            <CardContent>
              <Table>
                <thead><tr><Th>Lokasi</Th><Th>Alasan</Th><Th>Barang</Th><RH>Jumlah</RH><RH>Nilai</RH></tr></thead>
                <tbody>
                  {d.waste.length === 0 && <EmptyRow colSpan={5}>Tidak ada waste pada periode ini.</EmptyRow>}
                  {d.waste.map((w, i) => (
                    <tr key={i}><Td>{w.location.name}</Td><Td>{WASTE_REASON_LABEL[w.reason] ?? w.reason}</Td><Td>{w.item.name}</Td><R>{qty(w.qty, w.item.unit)}</R><R>{rp(w.value)}</R></tr>
                  ))}
                </tbody>
              </Table>
            </CardContent>
          </Card>
          <div className="grid gap-4 md:grid-cols-2">
            <Card>
              <CardHeader><CardTitle className="text-base">Susut kiriman (hilang/rusak di perjalanan)</CardTitle></CardHeader>
              <CardContent>
                {d.transit_loss.length === 0 ? <p className="text-sm text-muted-foreground">Tidak ada.</p> : (
                  <Table>
                    <thead><tr><Th>Kiriman</Th><Th>Barang</Th><RH>Jumlah</RH><RH>Nilai</RH></tr></thead>
                    <tbody>{d.transit_loss.map((t, i) => (
                      <tr key={i}><Td><Link to={`/pengiriman/${t.transfer_id}`} className="font-mono hover:underline">{t.number}</Link><p className="text-xs text-muted-foreground">{t.from} → {t.to}</p></Td><Td>{t.item}</Td><R>{formatQty(t.qty)}</R><R>{rp(t.value)}</R></tr>
                    ))}</tbody>
                  </Table>
                )}
              </CardContent>
            </Card>
            <Card>
              <CardHeader><CardTitle className="text-base">Selisih opname dan refund menu sudah dibuat</CardTitle></CardHeader>
              <CardContent className="space-y-3 text-sm">
                {d.count_adjustments.length === 0 && d.refund_made.length === 0 && <p className="text-muted-foreground">Tidak ada.</p>}
                {d.count_adjustments.map((a) => (
                  <p key={`a${a.location.id}`}>{a.location.name}: opname kurang <b>{rp(a.short_value)}</b>, lebih <b>{rp(a.over_value)}</b></p>
                ))}
                {d.refund_made.map((m) => (
                  <p key={`m${m.location.id}`}>{m.location.name}: {m.portions} porsi di-refund setelah dibuat, HPP <b>{rp(m.value)}</b></p>
                ))}
              </CardContent>
            </Card>
          </div>
        </>
      )}
    </ReportShell>
  )
}

// --- Pembelian ---

export function PurchasingReportPage() {
  return (
    <ReportShell<PurchasingReport> title="Pembelian" path="purchasing"
      purpose="Berapa yang dipesan, diterima, ditolak, dan ditagih tiap supplier."
      explain="PO dihitung menurut tanggal dibuat (yang dibatalkan atau ditolak Owner tidak dihitung); penerimaan menurut tanggal diterima; tagihan menurut tanggal tagihan; pembayaran menurut tanggal bayar. Beda harga tagihan terhadap PO mengurangi laba bila lebih mahal.">
      {(d) => (
        <>
          <div className="grid gap-3 md:grid-cols-4">
            <Kpi label="Dipesan (PO)" value={rp(d.total.ordered)} hint={`${d.total.orders} PO`} />
            <Kpi label="Diterima baik" value={rp(d.total.received)} />
            <Kpi label="Beda harga tagihan" value={formatSignedRupiah(d.total.price_difference)} tone={d.total.price_difference > 0 ? 'warn' : undefined} />
            <Kpi label="Dibayar" value={rp(d.total.paid)} hint={`${d.total.pending_requests} PR menunggu · ${d.total.open_orders} PO terbuka`} />
          </div>
          <Table>
            <thead><tr><Th>Supplier</Th><RH>PO</RH><RH>Dipesan</RH><RH>Diterima baik</RH><RH>Ditolak saat terima</RH><RH>Ditagih</RH><RH>Beda harga</RH><RH>Dibayar</RH></tr></thead>
            <tbody>
              {d.rows.length === 0 && <EmptyRow colSpan={8}>Belum ada pembelian pada periode ini.</EmptyRow>}
              {d.rows.map((r) => (
                <tr key={r.supplier.id}>
                  <Td className="font-medium">{r.supplier.name}</Td><R>{r.orders}</R><R>{rp(r.ordered)}</R><R>{rp(r.received)}</R>
                  <R>{r.rejected_lines ? `${rp(r.rejected_value)} (${r.rejected_lines} baris)` : '–'}</R><R>{rp(r.invoiced)}</R>
                  <R>{r.price_difference_count ? formatSignedRupiah(r.price_difference) : '–'}</R><R>{rp(r.paid)}</R>
                </tr>
              ))}
            </tbody>
          </Table>
          {d.differences.length > 0 && (
            <Card>
              <CardHeader><CardTitle className="text-base">Tagihan yang berbeda dari PO</CardTitle></CardHeader>
              <CardContent>
                <Table>
                  <thead><tr><Th>Tanggal</Th><Th>Tagihan</Th><Th>Supplier</Th><RH>Menurut PO</RH><RH>Ditagih</RH><RH>Beda</RH><Th>Alasan</Th></tr></thead>
                  <tbody>{d.differences.map((x) => (
                    <tr key={x.id}><Td>{formatDate(x.date)}</Td><Td className="font-mono">{x.number}</Td><Td>{x.supplier}</Td><R>{rp(x.expected)}</R><R>{rp(x.amount)}</R><R>{formatSignedRupiah(x.difference)}</R><Td>{x.note ?? '–'}</Td></tr>
                  ))}</tbody>
                </Table>
              </CardContent>
            </Card>
          )}
        </>
      )}
    </ReportShell>
  )
}

// --- Hutang supplier ---

const BUCKET_LABEL: Record<string, string> = { overdue_30: 'Lewat > 30 hari', overdue: 'Lewat jatuh tempo', due_soon: 'Jatuh tempo ≤ 7 hari', later: 'Belum jatuh tempo' }

export function PayablesReportPage() {
  return (
    <ReportShell<PayablesReport> title="Hutang supplier" path="payables" mode="none"
      purpose="Berapa yang masih harus dibayar ke tiap supplier, dan kapan jatuh temponya."
      explain={<>Sisa tagihan = tagihan − pembayaran yang sudah dicatat, dikelompokkan menurut jatuh tempo terhadap hari ini. Pembayaran dicatat di halaman <Link to="/pembelian/hutang" className="underline">Hutang supplier</Link>.</>}>
      {(d) => (
        <>
          <div className="grid gap-3 md:grid-cols-5">
            <Kpi label="Total hutang" value={rp(d.total)} />
            {Object.entries(d.buckets).map(([k, v]) => <Kpi key={k} label={BUCKET_LABEL[k]} value={rp(v)} tone={k.startsWith('overdue') && v > 0 ? 'bad' : k === 'due_soon' && v > 0 ? 'warn' : undefined} />)}
          </div>
          <Table>
            <thead><tr><Th>Supplier</Th>{Object.keys(BUCKET_LABEL).map((k) => <RH key={k}>{BUCKET_LABEL[k]}</RH>)}<RH>Total</RH></tr></thead>
            <tbody>
              {d.suppliers.length === 0 && <EmptyRow colSpan={6}>Tidak ada hutang supplier.</EmptyRow>}
              {d.suppliers.map((s) => (
                <tr key={s.supplier.id}><Td className="font-medium">{s.supplier.name}</Td><R>{rp(s.overdue_30)}</R><R>{rp(s.overdue)}</R><R>{rp(s.due_soon)}</R><R>{rp(s.later)}</R><R className="font-medium">{rp(s.total)}</R></tr>
              ))}
            </tbody>
          </Table>
          {d.invoices.length > 0 && (
            <Table>
              <thead><tr><Th>Jatuh tempo</Th><Th>Tagihan</Th><Th>Supplier</Th><RH>Tagihan</RH><RH>Dibayar</RH><RH>Sisa</RH></tr></thead>
              <tbody>{d.invoices.map((i) => (
                <tr key={i.id}>
                  <Td className={cn(i.bucket.startsWith('overdue') && 'text-destructive')}>{formatDate(i.due_date)} <span className="text-xs text-muted-foreground">({i.days < 0 ? `lewat ${-i.days} hari` : i.days === 0 ? 'hari ini' : `${i.days} hari lagi`})</span></Td>
                  <Td className="font-mono">{i.number}</Td><Td>{i.supplier}</Td><R>{rp(i.amount)}</R><R>{rp(i.paid)}</R><R className="font-medium">{rp(i.outstanding)}</R>
                </tr>
              ))}</tbody>
            </Table>
          )}
        </>
      )}
    </ReportShell>
  )
}
