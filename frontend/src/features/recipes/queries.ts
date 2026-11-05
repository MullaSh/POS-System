import { useQuery } from '@tanstack/react-query'
import { http } from '@/lib/api'
import type { ItemType, ItemWithUnits, Paginated, RecipeKind } from '@/lib/types'

export const RECIPES_KEY = ['recipes']

/** Jenis bahan per jenis resep (C2; Project Instructions §3). Sama dengan aturan di server. */
export const INGREDIENT_TYPES: Record<RecipeKind, ItemType[]> = {
  MENU: ['SEMI_FINISHED', 'SUPPORTING', 'PACKAGING'],
  PRODUCTION: ['RAW', 'SUPPORTING', 'PACKAGING', 'SEMI_FINISHED'],
}

export const PRODUCIBLE_TYPES: ItemType[] = ['SEMI_FINISHED', 'SUPPORTING']

export const KIND_LABEL: Record<RecipeKind, string> = { MENU: 'Resep menu', PRODUCTION: 'Resep produksi' }

export function kindFor(type: ItemType): RecipeKind | null {
  if (type === 'MENU') return 'MENU'
  return PRODUCIBLE_TYPES.includes(type) ? 'PRODUCTION' : null
}

export function useItemsWithUnits(types: ItemType[]) {
  const t = types.join(',')
  return useQuery({
    queryKey: ['items', 'with-units', t],
    queryFn: () => http.get<Paginated<ItemWithUnits>>(`/items?types=${t}&active=1&with_units=1&per_page=500`),
  })
}
