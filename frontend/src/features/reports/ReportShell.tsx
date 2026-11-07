import { Printer } from 'lucide-react'
import type { ReactNode } from 'react'
import { Link } from 'react-router'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { ErrorAlert, InfoBanner, Loading, PageHeader } from '@/components/ui/feedback'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'
import { useStockLocations } from '@/features/stock/queries'
import { errorText } from '@/lib/api'
import { formatDate, todayWib } from '@/lib/format'
import { type Mode, presets, useReport, useReportParams } from './queries'
import { cn } from '@/lib/utils'

type ShellProps<T> = {
  title: string
  purpose: string
  explain: ReactNode
  path: string
  mode?: Mode
  withLocation?: boolean
  /** Hanya outlet di pilihan lokasi (laporan penjualan). */
  outletsOnly?: boolean
  children: (data: T) => ReactNode
}

export function ReportShell<T>({ title, purpose, explain, path, mode = 'period', withLocation = false, outletsOnly = false, children }: ShellProps<T>) {
  const p = useReportParams(mode, withLocation)
  const report = useReport<T>(path, p.query)
  const locations = useStockLocations()
  const options = (locations.data?.data ?? []).filter((l) => !outletsOnly || l.type === 'OUTLET')
  const activePreset = presets().find((x) => x.from === p.from && x.to === p.to)?.key

  return (
    <div className="max-w-6xl space-y-4">
      <div className="text-sm print:hidden">
        <Link to="/laporan" className="text-muted-foreground hover:underline">← Semua laporan</Link>
      </div>
      <PageHeader title={title} purpose={purpose}
        actions={<Button variant="outline" onClick={() => window.print()} className="print:hidden"><Printer className="size-4" />Cetak</Button>} />

      <Card className="print:hidden">
        <CardContent className="flex flex-wrap items-end gap-3 pt-6">
          {mode === 'period' && (
            <>
              <div className="inline-flex rounded-lg border border-border p-1" role="group" aria-label="Periode cepat">
                {presets().map((x) => (
                  <button key={x.key} type="button" onClick={() => p.set({ dari: x.from, sampai: x.to })} aria-pressed={activePreset === x.key}
                    className={cn('rounded-md px-3 py-1.5 text-sm', activePreset === x.key ? 'bg-muted font-medium' : 'text-muted-foreground')}>{x.label}</button>
                ))}
              </div>
              <div className="space-y-1">
                <Label htmlFor="rp-from">Dari</Label>
                <Input id="rp-from" type="date" value={p.from} max={p.to} onChange={(e) => e.target.value && p.set({ dari: e.target.value })} className="w-40" />
              </div>
              <div className="space-y-1">
                <Label htmlFor="rp-to">Sampai</Label>
                <Input id="rp-to" type="date" value={p.to} min={p.from} max={todayWib()} onChange={(e) => e.target.value && p.set({ sampai: e.target.value })} className="w-40" />
              </div>
            </>
          )}
          {mode === 'asOf' && (
            <div className="space-y-1">
              <Label htmlFor="rp-asof">Per akhir tanggal</Label>
              <Input id="rp-asof" type="date" value={p.asOf} max={todayWib()} onChange={(e) => e.target.value && p.set({ per: e.target.value })} className="w-40" />
            </div>
          )}
          {withLocation && (
            <div className="space-y-1">
              <Label htmlFor="rp-loc">Lokasi</Label>
              <Select id="rp-loc" value={p.location} onChange={(e) => p.set({ lokasi: e.target.value })} className="w-48">
                <option value="">{outletsOnly ? 'Semua outlet' : 'Semua lokasi'}</option>
                {options.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
              </Select>
            </div>
          )}
          {mode === 'none' && <p className="text-sm text-muted-foreground">Keadaan per hari ini.</p>}
        </CardContent>
      </Card>
      <p className="hidden text-sm print:block">
        {mode === 'period' ? `Periode ${formatDate(p.from)} – ${formatDate(p.to)}` : mode === 'asOf' ? `Per ${formatDate(p.asOf)}` : `Per ${formatDate(todayWib())}`}
        {p.location && ` · ${options.find((l) => String(l.id) === p.location)?.name ?? ''}`}
      </p>

      <InfoBanner className="print:hidden">{explain}</InfoBanner>
      {report.isPending ? <Loading /> : report.error ? <ErrorAlert message={errorText(report.error)} /> : children(report.data)}
    </div>
  )
}

/** Kartu angka ringkas. */
export function Kpi({ label, value, hint, tone }: { label: string; value: string; hint?: string; tone?: 'good' | 'bad' | 'warn' }) {
  return (
    <Card>
      <CardContent className="space-y-1 pt-5">
        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</p>
        <p className={cn('text-xl font-semibold tabular-nums', tone === 'good' && 'text-emerald-700', tone === 'bad' && 'text-destructive', tone === 'warn' && 'text-amber-700')}>{value}</p>
        {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
      </CardContent>
    </Card>
  )
}
