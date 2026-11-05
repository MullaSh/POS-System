/** Alamat dokumen asal suatu baris kartu stok. */
export function sourcePath(source: { type: string; id: number }): string | null {
  switch (source.type) {
    case 'stock_entry':
      return `/stok/catatan/${source.id}`
    case 'stock_count':
      return `/stok/opname/${source.id}`
    case 'stock_transfer':
      return `/pengiriman/${source.id}`
    default:
      return null
  }
}
