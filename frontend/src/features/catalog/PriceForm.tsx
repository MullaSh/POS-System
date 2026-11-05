import { useState } from 'react'
import { Button } from '@/components/ui/button'
import { Field } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { RupiahInput } from '@/components/ui/rupiah-input'
import type { ApiError } from '@/lib/api'
import { todayWib } from '@/lib/format'

export type PriceInput = { price: number | null; effective_date: string | null; reason: string }

/** Isian harga baru: harga, kapan berlaku (langsung atau tanggal tertentu), dan alasan. */
export function PriceForm({
  initial,
  submitLabel,
  pending,
  error,
  onSubmit,
  onCancel,
}: {
  initial?: Partial<PriceInput>
  submitLabel: string
  pending: boolean
  error: ApiError | null
  onSubmit: (input: PriceInput) => void
  onCancel?: () => void
}) {
  const [price, setPrice] = useState<number | null>(initial?.price ?? null)
  const [scheduled, setScheduled] = useState(!!initial?.effective_date)
  const [date, setDate] = useState(initial?.effective_date ?? todayWib())
  const [reason, setReason] = useState(initial?.reason ?? '')

  return (
    <form
      className="grid gap-4 md:grid-cols-2"
      onSubmit={(e) => {
        e.preventDefault()
        onSubmit({ price, effective_date: scheduled ? date : null, reason })
      }}
    >
      <Field id="price-value" label="Harga jual baru" error={error?.field('price') ?? error?.field('request')}>
        <RupiahInput id="price-value" value={price} onValueChange={setPrice} placeholder="0" />
      </Field>
      <fieldset className="space-y-1.5">
        <legend className="text-sm font-medium">Mulai berlaku</legend>
        <div className="flex h-10 flex-wrap items-center gap-4 text-sm">
          <label className="flex items-center gap-2">
            <input type="radio" name="price-when" checked={!scheduled} onChange={() => setScheduled(false)} />
            Langsung
          </label>
          <label className="flex items-center gap-2">
            <input type="radio" name="price-when" checked={scheduled} onChange={() => setScheduled(true)} />
            Tanggal
          </label>
          {scheduled && (
            <Input
              aria-label="Tanggal mulai berlaku"
              type="date"
              className="w-44"
              min={todayWib()}
              value={date}
              onChange={(e) => setDate(e.target.value)}
            />
          )}
        </div>
        {error?.field('effective_date') && <p className="text-sm text-destructive">{error.field('effective_date')}</p>}
        {scheduled && <p className="text-xs text-muted-foreground">Berlaku mulai pukul 00.00 WIB pada tanggal itu.</p>}
      </fieldset>
      <div className="md:col-span-2">
        <Field id="price-reason" label="Alasan (opsional)" error={error?.field('reason')}>
          <Input id="price-reason" value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Mis. harga bahan naik" />
        </Field>
      </div>
      <div className="flex gap-2 md:col-span-2">
        <Button type="submit" disabled={pending}>{submitLabel}</Button>
        {onCancel && <Button type="button" variant="ghost" onClick={onCancel}>Batal</Button>}
      </div>
    </form>
  )
}
