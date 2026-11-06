import type { ProductionMaterial } from '@/lib/types'

export const PRODUCTION_KEY = ['production']

/** Isi PR dari bahan yang kurang: ?isi=itemId:unitId:jumlah,... (dibaca form PR). */
export function purchaseLink(lines: ProductionMaterial[], note: string): string | null {
  const short = lines.filter((l) => Number(l.shortage_qty ?? 0) > 0)
  if (short.length === 0) return null
  const isi = short.map((l) => `${l.item.id}:${l.unit.id}:${Number(l.shortage_entered)}`).join(',')
  return `/pembelian/pr/baru?isi=${encodeURIComponent(isi)}&catatan=${encodeURIComponent(note)}`
}
