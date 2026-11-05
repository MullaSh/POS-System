import { useQuery } from '@tanstack/react-query'
import { useSearchParams } from 'react-router'
import { ErrorAlert, InfoBanner, Loading, PageHeader } from '@/components/ui/feedback'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'
import { EmptyRow, Table, Td, Th } from '@/components/ui/table'
import { errorText, http } from '@/lib/api'
import { formatCost, formatDate, formatQty } from '@/lib/format'
import type { Paginated, StockBatchRow, StockMovementRow } from '@/lib/types'
import { LocationPicker } from './LocationPicker'
import { MovementsTable } from './MovementsTable'
import { STOCK_KEY, useSelectedLocation, useStockItems } from './queries'

type MovementsResponse = Paginated<StockMovementRow> & { balance: { qty: string; avg_cost: string | null } | null; can_see_cost: boolean }

/** Kartu stok satu barang di satu lokasi: setiap barang masuk dan keluar (AB-03). */
export function StockCardPage() {
  const { locations, list, selected, select } = useSelectedLocation()
  const [params, setParams] = useSearchParams()
  const items = useStockItems()
  const itemId = params.get('barang') ?? ''
  const item = items.data?.data.find((i) => String(i.id) === itemId)

  const movements = useQuery({
    queryKey: [...STOCK_KEY, 'movements', selected?.id, itemId],
    queryFn: () => http.get<MovementsResponse>(`/stock/movements?location_id=${selected?.id}${itemId ? `&item_id=${itemId}` : ''}`),
    enabled: !!selected,
  })
  const batches = useQuery({
    queryKey: [...STOCK_KEY, 'batches', selected?.id, itemId],
    queryFn: () => http.get<{ data: StockBatchRow[] }>(`/stock/batches?location_id=${selected?.id}&item_id=${itemId}`),
    enabled: !!selected && selected.type === 'WAREHOUSE' && !!itemId,
  })

  if (locations.isPending) return <Loading />
  if (!selected) return <ErrorAlert message="Akun Anda belum ditugaskan di gudang atau outlet mana pun." />

  const unit = item?.base_unit.code ?? ''
  const setItem = (id: string) => {
    const next = new URLSearchParams(params)
    if (id) next.set('barang', id)
    else next.delete('barang')
    setParams(next, { replace: true })
  }

  return (
    <div className="max-w-6xl">
      <PageHeader title="Kartu stok" purpose="Riwayat setiap barang masuk dan keluar. Catatan tidak diubah atau dihapus; koreksi selalu berupa baris baru." />
      <div className="mb-4 flex flex-wrap items-center gap-4">
        <LocationPicker list={list} value={selected.id} onChange={select} />
        <div className="flex items-center gap-2">
          <Label htmlFor="card-item">Barang</Label>
          <Select id="card-item" className="w-72" value={itemId} onChange={(e) => setItem(e.target.value)}>
            <option value="">Semua barang</option>
            {items.data?.data.map((i) => <option key={i.id} value={i.id}>{i.name} ({i.code})</option>)}
          </Select>
        </div>
      </div>

      {movements.data?.balance && item && (
        <InfoBanner className="mb-4">
          Saldo sekarang: <span className="font-semibold text-foreground">{formatQty(movements.data.balance.qty)} {unit}</span>
          {movements.data.balance.avg_cost !== null && <> · harga pokok rata-rata {formatCost(movements.data.balance.avg_cost)}/{unit}</>}
        </InfoBanner>
      )}

      {selected.type === 'WAREHOUSE' && item && (
        <div className="mb-6">
          <h2 className="mb-2 font-semibold">Batch di gudang</h2>
          <p className="mb-2 text-sm text-muted-foreground">Barang keluar diambil dari batch yang paling dekat kedaluwarsa lebih dulu.</p>
          <Table>
            <thead><tr><Th>Batch</Th><Th>Kedaluwarsa</Th><Th className="text-right">Sisa</Th></tr></thead>
            <tbody>
              {(batches.data?.data.length ?? 0) === 0 && <EmptyRow colSpan={3}>Tidak ada batch yang tersisa.</EmptyRow>}
              {batches.data?.data.map((b) => (
                <tr key={b.id}><Td className="font-mono">{b.batch_no}</Td><Td>{formatDate(b.expiry_date)}</Td><Td className="text-right">{formatQty(b.qty_remaining)} {unit}</Td></tr>
              ))}
            </tbody>
          </Table>
        </div>
      )}

      {movements.isPending ? <Loading /> : movements.error ? <ErrorAlert message={errorText(movements.error)} /> : (
        <>
          <MovementsTable rows={movements.data.data} canSeeCost={movements.data.can_see_cost} showItem={!itemId} />
          {movements.data.last_page > 1 && <p className="mt-2 text-sm text-muted-foreground">Menampilkan 50 pergerakan terbaru dari {movements.data.total}.</p>}
        </>
      )}
    </div>
  )
}
