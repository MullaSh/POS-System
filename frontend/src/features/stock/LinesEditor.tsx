import { Plus, Trash2 } from 'lucide-react'
import type { ReactNode } from 'react'
import { Button } from '@/components/ui/button'
import { FieldError } from '@/components/ui/feedback'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import type { ApiError } from '@/lib/api'
import type { ItemWithUnits } from '@/lib/types'
import { newLine, type Line } from './lines'

export type ExtraColumn = {
  key: string
  label: string
  className?: string
  render: (line: Line, set: (value: string) => void, index: number) => ReactNode
}

/** Baris barang + jumlah + satuan untuk dokumen stok. Satuan mengikuti konversi barang (C11). */
export function LinesEditor({
  items,
  lines,
  onChange,
  qtyLabel = 'Jumlah',
  extra = [],
  error,
  addLabel = 'Tambah barang',
}: {
  items: ItemWithUnits[]
  lines: Line[]
  onChange: (lines: Line[]) => void
  qtyLabel?: string
  extra?: ExtraColumn[]
  error?: ApiError | null
  addLabel?: string
}) {
  const update = (key: number, patch: Partial<Line>) => onChange(lines.map((l) => (l.key === key ? { ...l, ...patch } : l)))
  const chosen = new Set(lines.map((l) => l.item_id))

  return (
    <div className="space-y-2">
      <div className="overflow-x-auto rounded-lg border border-border">
        <table className="w-full text-sm">
          <thead>
            <tr className="bg-muted text-left text-xs font-semibold text-muted-foreground">
              <th className="px-3 py-2">Barang</th>
              <th className="px-3 py-2 w-32">{qtyLabel}</th>
              <th className="px-3 py-2 w-32">Satuan</th>
              {extra.map((c) => <th key={c.key} className={c.className ?? 'px-3 py-2'}>{c.label}</th>)}
              <th className="w-10" />
            </tr>
          </thead>
          <tbody>
            {lines.map((line, i) => {
              const item = items.find((it) => String(it.id) === line.item_id)
              const fieldErr = (f: string) => error?.field(`lines.${i}.${f}`)
              return (
                <tr key={line.key} className="border-t border-border align-top">
                  <td className="px-3 py-2">
                    <Select
                      aria-label={`Barang baris ${i + 1}`}
                      value={line.item_id}
                      onChange={(e) => {
                        const it = items.find((x) => String(x.id) === e.target.value)
                        update(line.key, { item_id: e.target.value, unit_id: it ? String(it.base_unit.id) : '' })
                      }}
                    >
                      <option value="">Pilih barang…</option>
                      {items.filter((it) => String(it.id) === line.item_id || !chosen.has(String(it.id))).map((it) => (
                        <option key={it.id} value={it.id}>{it.name} ({it.code})</option>
                      ))}
                    </Select>
                    <FieldError message={fieldErr('item_id')} />
                  </td>
                  <td className="px-3 py-2">
                    <Input aria-label={`${qtyLabel} baris ${i + 1}`} inputMode="decimal" value={line.qty} onChange={(e) => update(line.key, { qty: e.target.value })} />
                    <FieldError message={fieldErr('qty') ?? fieldErr('counted_qty')} />
                  </td>
                  <td className="px-3 py-2">
                    <Select aria-label={`Satuan baris ${i + 1}`} value={line.unit_id} disabled={!item} onChange={(e) => update(line.key, { unit_id: e.target.value })}>
                      {item?.units.map((u) => <option key={u.unit_id} value={u.unit_id}>{u.unit.code}</option>)}
                    </Select>
                    <FieldError message={fieldErr('unit_id')} />
                  </td>
                  {extra.map((c) => (
                    <td key={c.key} className="px-3 py-2">
                      {c.render(line, (v) => update(line.key, { extra: { ...line.extra, [c.key]: v } }), i)}
                      <FieldError message={fieldErr(c.key)} />
                    </td>
                  ))}
                  <td className="px-1 py-2">
                    <Button type="button" variant="ghost" size="sm" aria-label={`Hapus baris ${i + 1}`} disabled={lines.length === 1} onClick={() => onChange(lines.filter((l) => l.key !== line.key))}>
                      <Trash2 className="size-4" />
                    </Button>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
      <Button type="button" variant="outline" size="sm" onClick={() => onChange([...lines, newLine(Object.fromEntries(extra.map((c) => [c.key, ''])))])}>
        <Plus className="size-4" /> {addLabel}
      </Button>
      <FieldError message={error?.field('lines')} />
    </div>
  )
}
