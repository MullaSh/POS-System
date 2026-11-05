import { useQuery } from '@tanstack/react-query'
import { ClipboardList, PackageMinus, PackagePlus, Send, Truck, Undo2 } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { ErrorAlert, InfoBanner, Loading, PageHeader } from '@/components/ui/feedback'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { EmptyRow, Table, Td, Th } from '@/components/ui/table'
import { errorText, http } from '@/lib/api'
import { ITEM_TYPE_LABEL, formatCost, formatDateTime, formatQty, formatRupiah } from '@/lib/format'
import type { StockBalances } from '@/lib/types'
import { LocationPicker } from './LocationPicker'
import { STOCK_KEY, useSelectedLocation } from './queries'

/** Saldo stok per lokasi (AB-03, AB-07; KP-02). */
export function StockPage() {
  const { locations, list, selected, select, canSeeCost } = useSelectedLocation()
  const [search, setSearch] = useState('')
  const [only, setOnly] = useState('')

  const balances = useQuery({
    queryKey: [...STOCK_KEY, 'balances', selected?.id, only],
    queryFn: () => http.get<StockBalances>(`/stock/balances?location_id=${selected?.id}${only ? `&only=${only}` : ''}`),
    enabled: !!selected,
  })
  const rows = (balances.data?.data ?? []).filter((r) => {
    const q = search.trim().toLowerCase()
    return !q || r.item.name.toLowerCase().includes(q) || r.item.code.toLowerCase().includes(q)
  })
  const isWarehouse = selected?.type === 'WAREHOUSE'
  const q = selected ? `?lokasi=${selected.id}` : ''
  const can = selected?.can

  if (locations.isPending) return <Loading />
  if (locations.error) return <ErrorAlert message={errorText(locations.error)} />
  if (!selected) {
    return (
      <div className="max-w-5xl">
        <PageHeader title="Stok" purpose="Jumlah barang di gudang dan outlet." />
        <InfoBanner>Akun Anda belum ditugaskan di gudang atau outlet mana pun.</InfoBanner>
      </div>
    )
  }

  return (
    <div className="max-w-6xl">
      <PageHeader
        title="Stok"
        purpose="Jumlah barang di setiap lokasi, dihitung otomatis dari setiap barang masuk dan keluar."
        actions={
          <div className="flex flex-wrap gap-2">
            {can?.request_stock && <Button asChild variant="outline"><Link to={`/permintaan-stok/baru${q}`}><Send className="size-4" /> Minta stok</Link></Button>}
            {can?.operate && isWarehouse && <Button asChild variant="outline"><Link to="/pengiriman/baru?jenis=DISTRIBUTION"><Truck className="size-4" /> Kirim ke outlet</Link></Button>}
            {can?.operate && !isWarehouse && <Button asChild variant="outline"><Link to={`/pengiriman/baru?jenis=RETURN&lokasi=${selected.id}`}><Undo2 className="size-4" /> Retur ke gudang</Link></Button>}
            {can?.operate && <Button asChild variant="outline"><Link to={`/stok/waste/baru${q}`}><PackageMinus className="size-4" /> Catat waste</Link></Button>}
            {can?.operate && <Button asChild variant="outline"><Link to={`/stok/opname/baru${q}`}><ClipboardList className="size-4" /> Stock opname</Link></Button>}
            {can?.opening && <Button asChild variant="outline"><Link to={`/stok/saldo-awal/baru${q}`}><PackagePlus className="size-4" /> Saldo awal</Link></Button>}
          </div>
        }
      />

      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <LocationPicker list={list} value={selected.id} onChange={select} />
        <div className="flex flex-wrap gap-2">
          <Input aria-label="Cari barang" placeholder="Cari nama atau kode…" className="w-56" value={search} onChange={(e) => setSearch(e.target.value)} />
          <Select aria-label="Tampilkan" className="w-48" value={only} onChange={(e) => setOnly(e.target.value)}>
            <option value="">Semua barang</option>
            <option value="with_stock">Yang ada stoknya</option>
            <option value="low">Stok rendah atau minus</option>
            <option value="negative">Stok minus saja</option>
          </Select>
        </div>
      </div>

      {balances.data && (
        <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Kpi label="Barang ditampilkan" value={String(balances.data.summary.item_count)} />
          <Kpi label="Di bawah stok minimum" value={String(balances.data.summary.low_count)} tone={balances.data.summary.low_count > 0 ? 'warning' : undefined} />
          <Kpi label="Stok minus" value={String(balances.data.summary.negative_count)} tone={balances.data.summary.negative_count > 0 ? 'danger' : undefined} hint="Selesaikan lewat stock opname" />
          {canSeeCost && <Kpi label="Nilai stok" value={formatRupiah(Math.round(Number(balances.data.summary.total_value ?? 0)))} hint="Jumlah × harga pokok rata-rata" />}
        </div>
      )}

      {balances.isPending ? (
        <Loading />
      ) : balances.error ? (
        <ErrorAlert message={errorText(balances.error)} />
      ) : (
        <Table>
          <thead>
            <tr>
              <Th>Barang</Th>
              <Th>Kelompok</Th>
              <Th className="text-right">Saldo</Th>
              <Th className="text-right">Minimum</Th>
              {canSeeCost && <Th className="text-right">Harga pokok rata-rata</Th>}
              {canSeeCost && <Th className="text-right">Nilai</Th>}
              <Th>Terakhir bergerak</Th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 && <EmptyRow colSpan={7}>Tidak ada barang yang cocok.</EmptyRow>}
            {rows.map((r) => (
              <tr key={r.item.id}>
                <Td>
                  <Link to={`/stok/kartu?lokasi=${selected.id}&barang=${r.item.id}`} className="font-medium hover:underline">{r.item.name}</Link>
                  <p className="font-mono text-xs text-muted-foreground">{r.item.code}</p>
                </Td>
                <Td className="text-muted-foreground">{ITEM_TYPE_LABEL[r.item.item_type]}</Td>
                <Td className="text-right">
                  <span className={r.is_negative ? 'font-semibold text-destructive' : 'font-medium'}>{formatQty(r.qty)} {r.item.base_unit.code}</span>
                  {r.is_negative && <> <Badge variant="destructive">Minus</Badge></>}
                  {!r.is_negative && r.is_low && <> <Badge variant="warning">Rendah</Badge></>}
                </Td>
                <Td className="text-right text-muted-foreground">{r.min_qty === null ? '–' : `${formatQty(r.min_qty)} ${r.item.base_unit.code}`}</Td>
                {canSeeCost && <Td className="text-right">{r.avg_cost === null ? '–' : `${formatCost(r.avg_cost)}/${r.item.base_unit.code}`}</Td>}
                {canSeeCost && <Td className="text-right">{r.value === null ? '–' : formatRupiah(Math.round(Number(r.value)))}</Td>}
                <Td className="text-muted-foreground">{r.last_movement_at ? formatDateTime(r.last_movement_at) : 'Belum ada catatan'}</Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
    </div>
  )
}

function Kpi({ label, value, hint, tone }: { label: string; value: string; hint?: string; tone?: 'warning' | 'danger' }) {
  return (
    <Card>
      <CardContent className="pt-5">
        <p className="text-xs text-muted-foreground">{label}</p>
        <p className={`text-2xl font-semibold ${tone === 'danger' ? 'text-destructive' : tone === 'warning' ? 'text-amber-700 dark:text-amber-400' : ''}`}>{value}</p>
        {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
      </CardContent>
    </Card>
  )
}
