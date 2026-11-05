import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { ErrorAlert, Loading, PageHeader } from '@/components/ui/feedback'
import { Field } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Table, Td, Th } from '@/components/ui/table'
import { ApiError, errorText, http } from '@/lib/api'
import { UNITS_KEY, useUnits } from './queries'

export function UnitsPage() {
  const qc = useQueryClient()
  const units = useUnits()
  const [form, setForm] = useState({ code: '', name: '' })
  const create = useMutation({
    mutationFn: () => http.post('/units', form),
    onSuccess: () => {
      setForm({ code: '', name: '' })
      qc.invalidateQueries({ queryKey: UNITS_KEY })
    },
  })
  const err = create.error instanceof ApiError ? create.error : null

  return (
    <div className="max-w-3xl">
      <PageHeader title="Satuan" purpose="Satuan yang dipakai untuk membeli, menyimpan, dan memakai barang." />

      <Card className="mb-4">
        <CardContent className="pt-6">
          <form
            className="grid gap-4 md:grid-cols-[160px_1fr_auto] md:items-start"
            onSubmit={(e) => {
              e.preventDefault()
              create.mutate()
            }}
          >
            <Field id="unit-code" label="Kode" hint="Mis. kg, pcs, botol." error={err?.field('code')}>
              <Input id="unit-code" value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} />
            </Field>
            <Field id="unit-name" label="Nama" error={err?.field('name')}>
              <Input id="unit-name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            </Field>
            {/* Jarak atas = tinggi label, agar tombol sejajar dengan kotak isian. */}
            <div className="md:pt-[24px]">
              <Button type="submit" disabled={create.isPending}>Tambah satuan</Button>
            </div>
          </form>
        </CardContent>
      </Card>

      {units.isPending ? (
        <Loading />
      ) : units.error ? (
        <ErrorAlert message={errorText(units.error)} />
      ) : (
        <Table>
          <thead>
            <tr>
              <Th>Kode</Th>
              <Th>Nama</Th>
            </tr>
          </thead>
          <tbody>
            {units.data.data.map((u) => (
              <tr key={u.id}>
                <Td className="font-mono text-xs">{u.code}</Td>
                <Td>{u.name}</Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
    </div>
  )
}
