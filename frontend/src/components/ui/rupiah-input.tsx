import type { ComponentProps } from 'react'
import { Input } from './input'

const fmt = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 })

/** Isian Rupiah: hanya angka, ditampilkan dengan titik ribuan (mis. 25.000). */
export function RupiahInput({
  value,
  onValueChange,
  ...props
}: Omit<ComponentProps<'input'>, 'value' | 'onChange' | 'type'> & {
  value: number | null
  onValueChange: (value: number | null) => void
}) {
  return (
    <div className="relative">
      <span className="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-muted-foreground">Rp</span>
      <Input
        {...props}
        inputMode="numeric"
        className="pl-9"
        value={value === null ? '' : fmt.format(value)}
        onChange={(e) => {
          const digits = e.target.value.replace(/\D/g, '').slice(0, 12)
          onValueChange(digits === '' ? null : Number(digits))
        }}
      />
    </div>
  )
}
