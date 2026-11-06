import { useQuery } from '@tanstack/react-query'
import { http } from '@/lib/api'
import type { PosContext } from '@/lib/types'

export const POS_KEY = ['pos']

export function usePosContext(locationId: number | null) {
  return useQuery({
    queryKey: [...POS_KEY, 'context', locationId],
    queryFn: () => http.get<{ data: PosContext }>(`/pos/context${locationId ? `?location_id=${locationId}` : ''}`),
  })
}

export type CartLine = { item_id: number; name: string; price: number; qty: number }
export type PaymentRow = { key: number; payment_method_id: number; amount: number | null; reference: string }

/** Pembagian pembayaran seperti di server: non-tunai sebesar yang diisi, sisanya tunai, kembalian dari tunai. */
export function paymentSummary(total: number, rows: PaymentRow[], cashIds: Set<number>) {
  const nonCash = rows.filter((r) => !cashIds.has(r.payment_method_id)).reduce((s, r) => s + (r.amount ?? 0), 0)
  const cashRow = rows.find((r) => cashIds.has(r.payment_method_id))
  const remaining = Math.max(total - nonCash, 0)
  const received = cashRow?.amount ?? 0
  const change = cashRow ? Math.max(received - remaining, 0) : 0
  let problem: string | null = null
  if (nonCash > total) problem = 'Pembayaran non-tunai melebihi total. Kembalian hanya bisa dari tunai.'
  else if (cashRow && remaining === 0) problem = 'Tagihan sudah lunas dengan non-tunai; hapus baris tunai.'
  else if (cashRow && received < remaining) problem = `Uang tunai kurang ${new Intl.NumberFormat('id-ID').format(remaining - received)}.`
  else if (!cashRow && nonCash < total) problem = `Pembayaran kurang ${new Intl.NumberFormat('id-ID').format(total - nonCash)}.`
  return { nonCash, remaining, change, problem }
}
