import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { type ChangeEvent, useState } from 'react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { ErrorAlert, InfoBanner, Loading, PageHeader } from '@/components/ui/feedback'
import { Field } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { EmptyRow, Table, Td, Th } from '@/components/ui/table'
import { hasRole, useMe } from '@/features/auth/useAuth'
import { ApiError, errorText, http } from '@/lib/api'
import type { Paginated, Supplier } from '@/lib/types'

const KEY = ['suppliers']

type Form = Omit<Supplier, 'id' | 'is_active' | 'payment_term_days'> & { payment_term_days: string }

const empty: Form = { code: '', name: '', contact_name: '', phone: '', address: '', payment_term_days: '0', bank_account: '', notes: '' }

/** Data supplier (AB-17): dikelola Finance, dilihat Owner. */
export function SuppliersPage() {
  const { data: me } = useMe()
  const canEdit = hasRole(me, 'FINANCE')
  const [search, setSearch] = useState('')
  const [editing, setEditing] = useState<Supplier | 'new' | null>(null)
  const list = useQuery({
    queryKey: [...KEY, search.trim()],
    queryFn: () => http.get<Paginated<Supplier>>(`/suppliers?search=${encodeURIComponent(search.trim())}`),
  })

  return (
    <div className="max-w-5xl">
      <PageHeader
        title="Supplier"
        purpose="Daftar pemasok bahan dan barang untuk pembelian."
        actions={canEdit && editing === null && (
          <Button onClick={() => setEditing('new')}><Plus className="size-4" /> Tambah supplier</Button>
        )}
      />
      {!canEdit && <InfoBanner className="mb-4">Data supplier dikelola oleh Finance. Anda dapat melihatnya di sini.</InfoBanner>}

      {editing !== null && <SupplierForm supplier={editing === 'new' ? null : editing} onDone={() => setEditing(null)} />}

      <Input aria-label="Cari supplier" className="mb-3 w-64" placeholder="Cari nama atau kode…" value={search} onChange={(e) => setSearch(e.target.value)} />

      {list.isPending ? (
        <Loading />
      ) : list.error ? (
        <ErrorAlert message={errorText(list.error)} />
      ) : (
        <Table>
          <thead>
            <tr>
              <Th>Kode</Th>
              <Th>Nama</Th>
              <Th>Kontak</Th>
              <Th>Tempo bayar</Th>
              <Th>Rekening</Th>
              <Th>Status</Th>
              {canEdit && <Th />}
            </tr>
          </thead>
          <tbody>
            {list.data.data.length === 0 && <EmptyRow colSpan={canEdit ? 7 : 6}>Belum ada supplier.</EmptyRow>}
            {list.data.data.map((s) => (
              <SupplierRow key={s.id} supplier={s} canEdit={canEdit} onEdit={() => setEditing(s)} />
            ))}
          </tbody>
        </Table>
      )}
    </div>
  )
}

function SupplierRow({ supplier: s, canEdit, onEdit }: { supplier: Supplier; canEdit: boolean; onEdit: () => void }) {
  const qc = useQueryClient()
  const toggle = useMutation({
    mutationFn: () => http.post(`/suppliers/${s.id}/${s.is_active ? 'deactivate' : 'activate'}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  })

  return (
    <tr>
      <Td className="whitespace-nowrap font-mono text-xs">{s.code}</Td>
      <Td>
        <p className="font-medium">{s.name}</p>
        {s.address && <p className="text-xs text-muted-foreground">{s.address}</p>}
      </Td>
      <Td>
        {s.contact_name ?? '–'}
        {s.phone && <p className="whitespace-nowrap text-xs text-muted-foreground">{s.phone}</p>}
      </Td>
      <Td>{s.payment_term_days === 0 ? 'Tunai / langsung' : `${s.payment_term_days} hari`}</Td>
      <Td className="text-xs">{s.bank_account ?? '–'}</Td>
      <Td>{s.is_active ? <Badge variant="success">Aktif</Badge> : <Badge>Nonaktif</Badge>}</Td>
      {canEdit && (
        <Td className="whitespace-nowrap text-right">
          <Button size="sm" variant="ghost" onClick={onEdit}>Ubah</Button>
          <Button size="sm" variant="ghost" disabled={toggle.isPending} onClick={() => toggle.mutate()}>
            {s.is_active ? 'Nonaktifkan' : 'Aktifkan'}
          </Button>
        </Td>
      )}
    </tr>
  )
}

function SupplierForm({ supplier, onDone }: { supplier: Supplier | null; onDone: () => void }) {
  const qc = useQueryClient()
  const [form, setForm] = useState<Form>(
    supplier ? { ...supplier, payment_term_days: String(supplier.payment_term_days) } : empty,
  )
  const save = useMutation({
    mutationFn: () => {
      const body = { ...form, payment_term_days: Number(form.payment_term_days || 0) }
      return supplier ? http.patch(`/suppliers/${supplier.id}`, body) : http.post('/suppliers', body)
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEY })
      onDone()
    },
  })
  const err = save.error instanceof ApiError ? save.error : null
  const set = (key: keyof Form) => (e: ChangeEvent<HTMLInputElement>) => setForm({ ...form, [key]: e.target.value })

  return (
    <Card className="mb-4">
      <CardHeader><CardTitle className="text-base">{supplier ? `Ubah ${supplier.name}` : 'Supplier baru'}</CardTitle></CardHeader>
      <CardContent>
        <form className="grid gap-4 md:grid-cols-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
          <Field id="sup-code" label="Kode" hint={supplier ? 'Kode tidak bisa diubah.' : 'Mis. SUP-AYAM.'} error={err?.field('code')}>
            <Input id="sup-code" value={form.code} disabled={!!supplier} onChange={set('code')} />
          </Field>
          <div className="md:col-span-2">
            <Field id="sup-name" label="Nama supplier" error={err?.field('name')}>
              <Input id="sup-name" value={form.name} onChange={set('name')} />
            </Field>
          </div>
          <Field id="sup-contact" label="Nama kontak" error={err?.field('contact_name')}>
            <Input id="sup-contact" value={form.contact_name ?? ''} onChange={set('contact_name')} />
          </Field>
          <Field id="sup-phone" label="Telepon / WhatsApp" error={err?.field('phone')}>
            <Input id="sup-phone" value={form.phone ?? ''} onChange={set('phone')} />
          </Field>
          <Field id="sup-term" label="Tempo bayar (hari)" hint="0 = dibayar langsung." error={err?.field('payment_term_days')}>
            <Input id="sup-term" inputMode="numeric" value={form.payment_term_days} onChange={(e) => setForm({ ...form, payment_term_days: e.target.value.replace(/\D/g, '').slice(0, 3) })} />
          </Field>
          <div className="md:col-span-2">
            <Field id="sup-address" label="Alamat" error={err?.field('address')}>
              <Input id="sup-address" value={form.address ?? ''} onChange={set('address')} />
            </Field>
          </div>
          <Field id="sup-bank" label="Rekening bank" hint="Mis. BCA 123456 a.n. Toko X." error={err?.field('bank_account')}>
            <Input id="sup-bank" value={form.bank_account ?? ''} onChange={set('bank_account')} />
          </Field>
          <div className="md:col-span-3">
            <Field id="sup-notes" label="Catatan" error={err?.field('notes')}>
              <Input id="sup-notes" value={form.notes ?? ''} onChange={set('notes')} />
            </Field>
          </div>
          <div className="flex gap-2 md:col-span-3">
            <Button type="submit" disabled={save.isPending}>Simpan</Button>
            <Button type="button" variant="ghost" onClick={onDone}>Batal</Button>
          </div>
        </form>
      </CardContent>
    </Card>
  )
}
