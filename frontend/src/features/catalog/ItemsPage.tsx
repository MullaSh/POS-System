import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { ErrorAlert, InfoBanner, Loading, PageHeader } from '@/components/ui/feedback'
import { Field } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { EmptyRow, Table, Td, Th } from '@/components/ui/table'
import { hasRole, useMe } from '@/features/auth/useAuth'
import { ApiError, errorText, http } from '@/lib/api'
import { ITEM_TYPE_LABEL } from '@/lib/format'
import type { ItemDetail, ItemSummary, ItemType, Paginated } from '@/lib/types'
import { ITEMS_KEY, useUnits } from './queries'
import { toUnitPayload, type UnitRow } from './unitRows'
import { UnitRowsEditor } from './UnitRowsEditor'

const TYPES = Object.keys(ITEM_TYPE_LABEL) as ItemType[]

export function ItemsPage() {
  const { data: me } = useMe()
  const isOwner = hasRole(me, 'OWNER')
  const [type, setType] = useState<ItemType | ''>('')
  const [search, setSearch] = useState('')
  const [showCreate, setShowCreate] = useState(false)

  const params = new URLSearchParams()
  if (type) params.set('type', type)
  if (search.trim()) params.set('search', search.trim())
  const items = useQuery({
    queryKey: [...ITEMS_KEY, type, search.trim()],
    queryFn: () => http.get<Paginated<ItemSummary>>(`/items?${params.toString()}`),
  })

  return (
    <div className="max-w-5xl">
      <PageHeader
        title="Barang & menu"
        purpose="Daftar bahan baku, barang setengah jadi, bahan pendukung, packaging, dan menu."
        actions={
          isOwner && (
            <Button onClick={() => setShowCreate((v) => !v)}>
              <Plus className="size-4" /> Tambah barang
            </Button>
          )
        }
      />
      <InfoBanner className="mb-4">
        Stok dicatat dalam satuan dasar setiap barang. Konversi satuan (mis. 1 kg = 1.000 g) dipakai saat membeli dan
        memakai barang. Jenis barang dan satuan dasar tidak bisa diubah setelah dibuat. Menu bukan stok; menu memotong
        stok bahan lewat resep (menyusul).
      </InfoBanner>

      {showCreate && <CreateItemCard onDone={() => setShowCreate(false)} />}

      <div className="mb-3 flex flex-wrap gap-2">
        <Select aria-label="Saring jenis" className="w-56" value={type} onChange={(e) => setType(e.target.value as ItemType | '')}>
          <option value="">Semua jenis</option>
          {TYPES.map((t) => (
            <option key={t} value={t}>{ITEM_TYPE_LABEL[t]}</option>
          ))}
        </Select>
        <Input aria-label="Cari barang" className="w-64" placeholder="Cari nama atau kode…" value={search} onChange={(e) => setSearch(e.target.value)} />
      </div>

      {items.isPending ? (
        <Loading />
      ) : items.error ? (
        <ErrorAlert message={errorText(items.error)} />
      ) : (
        <Table>
          <thead>
            <tr>
              <Th>Kode</Th>
              <Th>Nama</Th>
              <Th>Jenis</Th>
              <Th>Satuan dasar</Th>
              <Th>Status</Th>
            </tr>
          </thead>
          <tbody>
            {items.data.data.length === 0 && <EmptyRow colSpan={5}>Tidak ada barang yang cocok.</EmptyRow>}
            {items.data.data.map((i) => (
              <tr key={i.id}>
                <Td className="font-mono text-xs">{i.code}</Td>
                <Td>
                  <Link className="font-medium underline-offset-2 hover:underline" to={`/barang/${i.id}`}>{i.name}</Link>
                </Td>
                <Td>{ITEM_TYPE_LABEL[i.item_type]}</Td>
                <Td>{i.base_unit.code}</Td>
                <Td>{i.is_active ? <Badge variant="success">Aktif</Badge> : <Badge>Nonaktif</Badge>}</Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
    </div>
  )
}

function CreateItemCard({ onDone }: { onDone: () => void }) {
  const qc = useQueryClient()
  const navigate = useNavigate()
  const units = useUnits()
  const [form, setForm] = useState({ code: '', name: '', item_type: 'RAW' as ItemType, base_unit_id: '' as number | '', notes: '' })
  const [rows, setRows] = useState<UnitRow[]>([])

  const create = useMutation({
    mutationFn: () =>
      http.post<{ data: ItemDetail }>('/items', {
        ...form,
        base_unit_id: form.base_unit_id === '' ? null : form.base_unit_id,
        units: toUnitPayload(rows),
      }),
    onSuccess: ({ data }) => {
      qc.invalidateQueries({ queryKey: ITEMS_KEY })
      onDone()
      navigate(`/barang/${data.id}`)
    },
  })
  const err = create.error instanceof ApiError ? create.error : null
  const unitList = units.data?.data ?? []
  const firstUnitError = err && Object.entries(err.errors).find(([k]) => k.startsWith('units'))?.[1]?.[0]

  return (
    <Card className="mb-4">
      <CardHeader>
        <CardTitle className="text-base">Barang baru</CardTitle>
      </CardHeader>
      <CardContent>
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault()
            create.mutate()
          }}
        >
          <div className="grid gap-4 md:grid-cols-2">
            <Field id="item-code" label="Kode" hint="Mis. BB-AYAM. Tidak bisa diubah." error={err?.field('code')}>
              <Input id="item-code" value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} />
            </Field>
            <Field id="item-name" label="Nama" error={err?.field('name')}>
              <Input id="item-name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            </Field>
            <Field id="item-type" label="Jenis" error={err?.field('item_type')}>
              <Select id="item-type" value={form.item_type} onChange={(e) => setForm({ ...form, item_type: e.target.value as ItemType })}>
                {TYPES.map((t) => (
                  <option key={t} value={t}>{ITEM_TYPE_LABEL[t]}</option>
                ))}
              </Select>
            </Field>
            <Field id="item-base" label="Satuan dasar" hint="Satuan terkecil untuk mencatat stok, mis. gram." error={err?.field('base_unit_id')}>
              <Select id="item-base" value={form.base_unit_id} onChange={(e) => setForm({ ...form, base_unit_id: e.target.value ? Number(e.target.value) : '' })}>
                <option value="">Pilih…</option>
                {unitList.map((u) => (
                  <option key={u.id} value={u.id}>{u.code} ({u.name})</option>
                ))}
              </Select>
            </Field>
          </div>
          <div className="space-y-2">
            <p className="text-sm font-medium">Konversi satuan (opsional)</p>
            <UnitRowsEditor rows={rows} onChange={setRows} units={unitList} baseUnitId={form.base_unit_id} />
            <ErrorAlert message={firstUnitError} />
          </div>
          <Field id="item-notes" label="Catatan (opsional)" error={err?.field('notes')}>
            <Input id="item-notes" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
          </Field>
          <div className="flex gap-2">
            <Button type="submit" disabled={create.isPending}>{create.isPending ? 'Menyimpan…' : 'Simpan barang'}</Button>
            <Button type="button" variant="ghost" onClick={onDone}>Batal</Button>
          </div>
        </form>
      </CardContent>
    </Card>
  )
}
