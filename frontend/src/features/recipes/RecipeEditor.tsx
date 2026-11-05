import { Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { Button } from '@/components/ui/button'
import { ErrorAlert, FieldError } from '@/components/ui/feedback'
import { Field } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import type { ApiError } from '@/lib/api'
import { formatQty, ITEM_TYPE_LABEL, todayWib } from '@/lib/format'
import type { ItemWithUnits, RecipeKind, RecipeVersion } from '@/lib/types'
import { INGREDIENT_TYPES, useItemsWithUnits } from './queries'

type Row = { item_id: string; unit_id: string; qty: string }

export type RecipeInput = {
  output_unit_id: number | null
  output_qty: number | null
  effective_date: string | null
  notes: string | null
  lines: { item_id: number; unit_id: number; qty: number }[]
}

const toNumber = (v: string) => Number(v.replace(',', '.'))

/** Isian satu versi resep: daftar bahan, jumlah hasil (resep produksi), dan tanggal berlaku. */
export function RecipeEditor({
  kind,
  output,
  from,
  submitLabel,
  pending,
  error,
  onSubmit,
  onCancel,
}: {
  kind: RecipeKind
  output: ItemWithUnits | null
  from?: RecipeVersion
  submitLabel: string
  pending: boolean
  error: ApiError | null
  onSubmit: (input: RecipeInput) => void
  onCancel: () => void
}) {
  const items = useItemsWithUnits(INGREDIENT_TYPES[kind])
  const [rows, setRows] = useState<Row[]>(
    from?.lines.map((l) => ({ item_id: String(l.item.id), unit_id: String(l.unit.id), qty: String(Number(l.entered_qty)) })) ?? [
      { item_id: '', unit_id: '', qty: '' },
    ],
  )
  const [outQty, setOutQty] = useState(from ? String(Number(from.output_entered_qty)) : '')
  const [outUnit, setOutUnit] = useState(from ? String(from.output_unit.id) : String(output?.base_unit.id ?? ''))
  const [scheduled, setScheduled] = useState(false)
  const [date, setDate] = useState(todayWib())
  const [notes, setNotes] = useState('')

  const options = (items.data?.data ?? []).filter((i) => i.id !== output?.id)
  const byId = new Map(options.map((i) => [String(i.id), i]))
  const setRow = (idx: number, patch: Partial<Row>) => setRows(rows.map((r, i) => (i === idx ? { ...r, ...patch } : r)))

  const baseHint = (item: ItemWithUnits | undefined, unitId: string, qty: string) => {
    const u = item?.units.find((x) => String(x.unit_id) === unitId)
    if (!item || !u || !qty || Number(u.factor_to_base) === 1) return null
    return `= ${formatQty(toNumber(qty) * Number(u.factor_to_base))} ${item.base_unit.code}`
  }

  return (
    <form
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault()
        onSubmit({
          output_unit_id: kind === 'PRODUCTION' ? Number(outUnit) || null : null,
          output_qty: kind === 'PRODUCTION' ? toNumber(outQty) || null : null,
          effective_date: scheduled ? date : null,
          notes: notes || null,
          lines: rows
            .filter((r) => r.item_id)
            .map((r) => ({ item_id: Number(r.item_id), unit_id: Number(r.unit_id), qty: toNumber(r.qty) })),
        })
      }}
    >
      {kind === 'PRODUCTION' && output && (
        <div className="grid gap-4 md:grid-cols-[200px_200px_1fr]">
          <Field id="out-qty" label="Hasil satu kali produksi" error={error?.field('output_qty')}>
            <Input id="out-qty" inputMode="decimal" value={outQty} onChange={(e) => setOutQty(e.target.value)} placeholder="Mis. 100" />
          </Field>
          <Field id="out-unit" label="Satuan hasil" error={error?.field('output_unit_id')}>
            <Select id="out-unit" value={outUnit} onChange={(e) => setOutUnit(e.target.value)}>
              {output.units.map((u) => <option key={u.unit_id} value={u.unit_id}>{u.unit.code}</option>)}
            </Select>
          </Field>
          <p className="self-end pb-2 text-sm text-muted-foreground">{baseHint(output, outUnit, outQty)}</p>
        </div>
      )}
      {kind === 'MENU' && (
        <p className="text-sm text-muted-foreground">Isi bahan untuk <strong>satu porsi</strong> menu.</p>
      )}

      <div className="space-y-2">
        <p className="text-sm font-medium">Bahan</p>
        {error?.field('lines') && <FieldError message={error.field('lines')} />}
        {rows.map((r, idx) => {
          const item = byId.get(r.item_id)
          const lineErr = error?.field(`lines.${idx}.item_id`) ?? error?.field(`lines.${idx}.unit_id`) ?? error?.field(`lines.${idx}.qty`)
          return (
            <div key={idx} className="space-y-1">
              <div className="grid gap-2 md:grid-cols-[1fr_120px_110px_auto] md:items-center">
                <Select
                  aria-label={`Bahan ${idx + 1}`}
                  value={r.item_id}
                  onChange={(e) => {
                    const it = byId.get(e.target.value)
                    const usage = it?.units.find((u) => String(u.unit_id) === String(it.base_unit.id))
                    setRow(idx, { item_id: e.target.value, unit_id: String(usage?.unit_id ?? it?.base_unit.id ?? '') })
                  }}
                >
                  <option value="">Pilih bahan…</option>
                  {INGREDIENT_TYPES[kind].map((t) => (
                    <optgroup key={t} label={ITEM_TYPE_LABEL[t]}>
                      {options.filter((o) => o.item_type === t).map((o) => (
                        <option key={o.id} value={o.id}>{o.name} ({o.code})</option>
                      ))}
                    </optgroup>
                  ))}
                </Select>
                <Input aria-label={`Jumlah bahan ${idx + 1}`} inputMode="decimal" value={r.qty} onChange={(e) => setRow(idx, { qty: e.target.value })} placeholder="Jumlah" />
                <Select aria-label={`Satuan bahan ${idx + 1}`} value={r.unit_id} onChange={(e) => setRow(idx, { unit_id: e.target.value })} disabled={!item}>
                  {item?.units.map((u) => <option key={u.unit_id} value={u.unit_id}>{u.unit.code}</option>)}
                </Select>
                <Button type="button" variant="ghost" size="sm" aria-label={`Hapus bahan ${idx + 1}`} onClick={() => setRows(rows.filter((_, i) => i !== idx))} disabled={rows.length === 1}>
                  <Trash2 className="size-4" />
                </Button>
              </div>
              {baseHint(item, r.unit_id, r.qty) && <p className="text-xs text-muted-foreground">{baseHint(item, r.unit_id, r.qty)}</p>}
              {lineErr && <FieldError message={lineErr} />}
            </div>
          )
        })}
        <Button type="button" variant="outline" size="sm" onClick={() => setRows([...rows, { item_id: '', unit_id: '', qty: '' }])}>
          <Plus className="size-4" /> Tambah bahan
        </Button>
      </div>

      <div className="grid gap-4 md:grid-cols-2">
        <fieldset className="space-y-1.5">
          <legend className="text-sm font-medium">Mulai berlaku</legend>
          <div className="flex h-10 flex-wrap items-center gap-4 text-sm">
            <label className="flex items-center gap-2">
              <input type="radio" name="recipe-when" checked={!scheduled} onChange={() => setScheduled(false)} /> Langsung
            </label>
            <label className="flex items-center gap-2">
              <input type="radio" name="recipe-when" checked={scheduled} onChange={() => setScheduled(true)} /> Tanggal
            </label>
            {scheduled && <Input aria-label="Tanggal mulai berlaku" type="date" className="w-44" min={todayWib()} value={date} onChange={(e) => setDate(e.target.value)} />}
          </div>
          <FieldError message={error?.field('effective_date')} />
        </fieldset>
        <Field id="recipe-notes" label="Catatan perubahan (opsional)" error={error?.field('notes')}>
          <Input id="recipe-notes" value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Mis. saus dikurangi 5 ml" />
        </Field>
      </div>

      {error && Object.keys(error.errors).length === 0 && <ErrorAlert message={error.message} />}

      <div className="flex gap-2">
        <Button type="submit" disabled={pending}>{submitLabel}</Button>
        <Button type="button" variant="ghost" onClick={onCancel}>Batal</Button>
      </div>
    </form>
  )
}
