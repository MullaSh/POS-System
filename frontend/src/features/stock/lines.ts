export type Line = { key: number; item_id: string; unit_id: string; qty: string; extra: Record<string, string> }

let nextKey = 1
export function newLine(extra: Record<string, string> = {}): Line {
  return { key: nextKey++, item_id: '', unit_id: '', qty: '', extra }
}

/** Baris yang terisi, siap dikirim ke server. */
export function filledLines(lines: Line[], qtyKey = 'qty') {
  return lines
    .filter((l) => l.item_id)
    .map((l) => ({ item_id: Number(l.item_id), unit_id: Number(l.unit_id), [qtyKey]: l.qty.replace(',', '.'), ...l.extra }))
}
