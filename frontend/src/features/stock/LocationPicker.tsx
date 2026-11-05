import { Select } from '@/components/ui/select'
import { Label } from '@/components/ui/label'
import type { StockLocation } from '@/lib/types'

/** Pilihan lokasi; disembunyikan bila user hanya punya satu lokasi. */
export function LocationPicker({
  list,
  value,
  onChange,
  label = 'Lokasi',
}: {
  list: StockLocation[]
  value: number | null
  onChange: (id: number) => void
  label?: string
}) {
  if (list.length <= 1) {
    return list[0] ? <p className="text-sm"><span className="text-muted-foreground">{label}:</span> <span className="font-medium">{list[0].name}</span></p> : null
  }
  return (
    <div className="flex items-center gap-2">
      <Label htmlFor="stock-location">{label}</Label>
      <Select id="stock-location" className="w-56" value={value ?? ''} onChange={(e) => onChange(Number(e.target.value))}>
        {list.map((l) => (
          <option key={l.id} value={l.id}>{l.name}{l.status === 'CLOSED' ? ' (ditutup)' : ''}</option>
        ))}
      </Select>
    </div>
  )
}
