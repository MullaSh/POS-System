import { Trash2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import type { Unit } from '@/lib/types'
import type { UnitRow } from './unitRows'


/**
 * Daftar konversi: "1 [satuan] = [faktor] [satuan dasar]" (C11, AB-02).
 * Satuan dasar sendiri selalu bernilai 1 dan tidak ditampilkan di sini.
 */
export function UnitRowsEditor({
  rows,
  onChange,
  units,
  baseUnitId,
}: {
  rows: UnitRow[]
  onChange: (rows: UnitRow[]) => void
  units: Unit[]
  baseUnitId: number | ''
}) {
  const base = units.find((u) => u.id === baseUnitId)
  const set = (i: number, patch: Partial<UnitRow>) => onChange(rows.map((r, idx) => (idx === i ? { ...r, ...patch } : r)))

  return (
    <div className="space-y-2">
      {rows.map((r, i) => (
        <div key={i} className="flex flex-wrap items-center gap-2 text-sm">
          <span>1</span>
          <Select
            aria-label={`Satuan konversi ${i + 1}`}
            className="w-32"
            value={r.unit_id}
            onChange={(e) => set(i, { unit_id: e.target.value ? Number(e.target.value) : '' })}
          >
            <option value="">Pilih…</option>
            {units.filter((u) => u.id !== baseUnitId).map((u) => (
              <option key={u.id} value={u.id}>{u.code}</option>
            ))}
          </Select>
          <span>=</span>
          <Input
            aria-label={`Faktor konversi ${i + 1}`}
            className="w-28"
            inputMode="decimal"
            value={r.factor_to_base}
            onChange={(e) => set(i, { factor_to_base: e.target.value })}
          />
          <span className="text-muted-foreground">{base?.code ?? 'satuan dasar'}</span>
          <label className="ml-2 flex items-center gap-1 text-xs text-muted-foreground">
            <input
              type="checkbox"
              checked={r.is_purchase_default}
              onChange={(e) => onChange(rows.map((x, idx) => ({ ...x, is_purchase_default: idx === i ? e.target.checked : false })))}
            />
            satuan beli
          </label>
          <Button type="button" variant="ghost" size="sm" aria-label="Hapus konversi" onClick={() => onChange(rows.filter((_, idx) => idx !== i))}>
            <Trash2 className="size-4" />
          </Button>
        </div>
      ))}
      <Button type="button" variant="outline" size="sm" onClick={() => onChange([...rows, { unit_id: '', factor_to_base: '', is_purchase_default: false }])}>
        Tambah konversi
      </Button>
    </div>
  )
}
