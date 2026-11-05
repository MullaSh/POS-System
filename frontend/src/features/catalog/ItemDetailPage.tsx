import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { ErrorAlert, Loading } from '@/components/ui/feedback'
import { Field } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Table, Td, Th } from '@/components/ui/table'
import { hasRole, useMe } from '@/features/auth/useAuth'
import { ApiError, errorText, http } from '@/lib/api'
import { formatQty, ITEM_TYPE_LABEL } from '@/lib/format'
import type { ItemDetail, Location } from '@/lib/types'
import { ItemRecipeCard } from '@/features/recipes/ItemRecipeCard'
import { PriceCard } from './PriceCard'
import { ITEMS_KEY, useUnits } from './queries'
import { toUnitPayload, type UnitRow } from './unitRows'
import { UnitRowsEditor } from './UnitRowsEditor'

export function ItemDetailPage() {
  const { id } = useParams()
  const { data: me } = useMe()
  const isOwner = hasRole(me, 'OWNER')
  const item = useQuery({ queryKey: [...ITEMS_KEY, 'detail', id], queryFn: () => http.get<{ data: ItemDetail }>(`/items/${id}`) })

  if (item.isPending) return <Loading />
  if (item.error) return <ErrorAlert message={errorText(item.error)} />
  const it = item.data.data

  return (
    <div className="max-w-4xl space-y-4">
      <Link to="/barang" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:underline">
        <ArrowLeft className="size-4" /> Kembali ke daftar barang
      </Link>
      <div className="flex flex-wrap items-center gap-3">
        <h1 className="text-2xl font-semibold">{it.name}</h1>
        <Badge>{ITEM_TYPE_LABEL[it.item_type]}</Badge>
        {it.is_active ? <Badge variant="success">Aktif</Badge> : <Badge>Nonaktif</Badge>}
      </div>
      <p className="text-sm text-muted-foreground">
        Kode <span className="font-mono">{it.code}</span> · satuan dasar <strong>{it.base_unit.code}</strong>
        {it.notes && <> · {it.notes}</>}
      </p>

      {it.price && <PriceCard item={it} />}
      <ItemRecipeCard item={it} />
      {isOwner && <EditBasics item={it} />}
      <Conversions item={it} canEdit={isOwner} />
      {it.is_stock_item && <MinStocks item={it} canEdit={isOwner} />}
    </div>
  )
}

function useRefreshItem(id: number) {
  const qc = useQueryClient()
  return () => {
    qc.invalidateQueries({ queryKey: ITEMS_KEY })
    qc.invalidateQueries({ queryKey: [...ITEMS_KEY, 'detail', String(id)] })
  }
}

function EditBasics({ item }: { item: ItemDetail }) {
  const refresh = useRefreshItem(item.id)
  const [name, setName] = useState(item.name)
  const [notes, setNotes] = useState(item.notes ?? '')
  const save = useMutation({ mutationFn: () => http.patch(`/items/${item.id}`, { name, notes }), onSuccess: refresh })
  const toggle = useMutation({ mutationFn: () => http.post(`/items/${item.id}/${item.is_active ? 'deactivate' : 'activate'}`), onSuccess: refresh })
  const err = save.error instanceof ApiError ? save.error : null

  return (
    <Card>
      <CardHeader><CardTitle className="text-base">Data barang</CardTitle></CardHeader>
      <CardContent>
        <form className="grid gap-4 md:grid-cols-2" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
          <Field id="edit-name" label="Nama" error={err?.field('name')}>
            <Input id="edit-name" value={name} onChange={(e) => setName(e.target.value)} />
          </Field>
          <Field id="edit-notes" label="Catatan" error={err?.field('notes')}>
            <Input id="edit-notes" value={notes} onChange={(e) => setNotes(e.target.value)} />
          </Field>
          <div className="flex flex-wrap items-center gap-2 md:col-span-2">
            <Button type="submit" disabled={save.isPending}>Simpan perubahan</Button>
            <Button type="button" variant="outline" disabled={toggle.isPending} onClick={() => toggle.mutate()}>
              {item.is_active ? 'Nonaktifkan barang' : 'Aktifkan barang'}
            </Button>
            {save.isSuccess && <span className="text-sm text-success">Tersimpan.</span>}
          </div>
          <div className="md:col-span-2"><ErrorAlert message={errorText(toggle.error)} /></div>
        </form>
      </CardContent>
    </Card>
  )
}

function Conversions({ item, canEdit }: { item: ItemDetail; canEdit: boolean }) {
  const refresh = useRefreshItem(item.id)
  const units = useUnits()
  const [editing, setEditing] = useState(false)
  const initial: UnitRow[] = item.units
    .filter((u) => u.unit_id !== item.base_unit.id)
    .map((u) => ({ unit_id: u.unit_id, factor_to_base: String(Number(u.factor_to_base)), is_purchase_default: u.is_purchase_default }))
  const [rows, setRows] = useState<UnitRow[]>(initial)
  const save = useMutation({
    mutationFn: () => http.put(`/items/${item.id}/units`, { units: toUnitPayload(rows) }),
    onSuccess: () => { setEditing(false); refresh() },
  })
  const err = save.error instanceof ApiError ? save.error : null
  const firstErr = err && (Object.values(err.errors)[0]?.[0] ?? err.message)

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between">
        <CardTitle className="text-base">Konversi satuan</CardTitle>
        {canEdit && !editing && <Button variant="outline" size="sm" onClick={() => setEditing(true)}>Ubah</Button>}
      </CardHeader>
      <CardContent>
        {editing ? (
          <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
            <UnitRowsEditor rows={rows} onChange={setRows} units={units.data?.data ?? []} baseUnitId={item.base_unit.id} />
            <ErrorAlert message={firstErr} />
            <div className="flex gap-2">
              <Button type="submit" disabled={save.isPending}>Simpan konversi</Button>
              <Button type="button" variant="ghost" onClick={() => { setRows(initial); setEditing(false) }}>Batal</Button>
            </div>
          </form>
        ) : (
          <ul className="space-y-1 text-sm">
            {item.units.map((u) => (
              <li key={u.unit_id}>
                1 {u.code} = {formatQty(u.factor_to_base)} {item.base_unit.code}
                {u.is_purchase_default && <Badge className="ml-2">satuan beli</Badge>}
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  )
}

function MinStocks({ item, canEdit }: { item: ItemDetail; canEdit: boolean }) {
  const refresh = useRefreshItem(item.id)
  const locations = useQuery({ queryKey: ['locations'], queryFn: () => http.get<{ data: Location[] }>('/locations') })
  const current = Object.fromEntries(item.min_stocks.map((m) => [m.location_id, String(Number(m.min_qty))]))
  const [values, setValues] = useState<Record<number, string>>(current)
  const save = useMutation({
    mutationFn: () =>
      http.put(`/items/${item.id}/min-stocks`, {
        min_stocks: Object.entries(values)
          .filter(([, v]) => v.trim() !== '')
          .map(([loc, v]) => ({ location_id: Number(loc), min_qty: Number(v.replace(',', '.')) })),
      }),
    onSuccess: refresh,
  })
  const active = (locations.data?.data ?? []).filter((l) => l.status !== 'CLOSED')
  const err = save.error instanceof ApiError ? save.error : null

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Stok minimum</CardTitle>
        <p className="text-sm text-muted-foreground">
          Opsional. Bila stok di suatu lokasi turun di bawah angka ini, sistem akan memberi peringatan. Kosongkan bila
          tidak perlu.
        </p>
      </CardHeader>
      <CardContent>
        <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
          <Table>
            <thead>
              <tr><Th>Lokasi</Th><Th>Minimum ({item.base_unit.code})</Th></tr>
            </thead>
            <tbody>
              {active.map((l) => (
                <tr key={l.id}>
                  <Td>{l.name} <span className="text-muted-foreground">({l.code})</span></Td>
                  <Td>
                    <Input
                      aria-label={`Stok minimum ${l.name}`}
                      className="w-36"
                      inputMode="decimal"
                      disabled={!canEdit}
                      value={values[l.id] ?? ''}
                      onChange={(e) => setValues({ ...values, [l.id]: e.target.value })}
                    />
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
          <ErrorAlert message={err && (Object.values(err.errors)[0]?.[0] ?? err.message)} />
          {canEdit && (
            <div className="flex items-center gap-2">
              <Button type="submit" disabled={save.isPending}>Simpan stok minimum</Button>
              {save.isSuccess && <span className="text-sm text-success">Tersimpan.</span>}
            </div>
          )}
        </form>
      </CardContent>
    </Card>
  )
}
