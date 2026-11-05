import { useQuery } from '@tanstack/react-query'
import { http } from '@/lib/api'
import type { Unit } from '@/lib/types'

export const ITEMS_KEY = ['items']
export const UNITS_KEY = ['units']

export function useUnits() {
  return useQuery({ queryKey: UNITS_KEY, queryFn: () => http.get<{ data: Unit[] }>('/units') })
}
