import { useQuery } from '@tanstack/react-query'
import { useSearchParams } from 'react-router'
import { http } from '@/lib/api'
import type { ItemType, StockLocation } from '@/lib/types'
import { useItemsWithUnits } from '@/features/recipes/queries'

export const STOCK_KEY = ['stock']
export const STOCK_TYPES: ItemType[] = ['RAW', 'SEMI_FINISHED', 'SUPPORTING', 'PACKAGING']

export function useStockLocations() {
  return useQuery({
    queryKey: [...STOCK_KEY, 'locations'],
    queryFn: () => http.get<{ data: StockLocation[]; can_see_cost: boolean }>('/stock/locations'),
  })
}

/** Lokasi terpilih dari alamat halaman (?lokasi=ID); bawaan = lokasi pertama yang boleh dilihat. */
export function useSelectedLocation() {
  const [params, setParams] = useSearchParams()
  const locations = useStockLocations()
  const list = locations.data?.data ?? []
  const wanted = Number(params.get('lokasi'))
  const selected = list.find((l) => l.id === wanted) ?? list[0] ?? null
  const select = (id: number) => {
    const next = new URLSearchParams(params)
    next.set('lokasi', String(id))
    setParams(next, { replace: true })
  }
  return { locations, list, selected, select, canSeeCost: locations.data?.can_see_cost ?? false }
}

export function useStockItems() {
  return useItemsWithUnits(STOCK_TYPES)
}
