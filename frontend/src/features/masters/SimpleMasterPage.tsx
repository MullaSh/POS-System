import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { ErrorAlert, InfoBanner, Loading, PageHeader } from '@/components/ui/feedback'
import { Field } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { EmptyRow, Table, Td, Th } from '@/components/ui/table'
import { ApiError, errorText, http } from '@/lib/api'
import type { SimpleMaster } from '@/lib/types'

type Config = {
  path: string
  title: string
  purpose: string
  info: string
  codeHint: string
  singular: string
}

/** Data master sederhana (kode + nama + status), dikelola Owner (dokumen 12 §9 Q4). */
function SimpleMasterPage({ path, title, purpose, info, codeHint, singular }: Config) {
  const qc = useQueryClient()
  const key = [path]
  const list = useQuery({ queryKey: key, queryFn: () => http.get<{ data: SimpleMaster[] }>(`/${path}`) })
  const [form, setForm] = useState({ code: '', name: '' })
  const create = useMutation({
    mutationFn: () => http.post(`/${path}`, form),
    onSuccess: () => {
      setForm({ code: '', name: '' })
      qc.invalidateQueries({ queryKey: key })
    },
  })
  const err = create.error instanceof ApiError ? create.error : null

  return (
    <div className="max-w-3xl">
      <PageHeader title={title} purpose={purpose} />
      <InfoBanner className="mb-4">{info}</InfoBanner>

      <Card className="mb-4">
        <CardContent className="pt-6">
          <form
            className="grid gap-4 md:grid-cols-[180px_1fr_auto] md:items-start"
            onSubmit={(e) => {
              e.preventDefault()
              create.mutate()
            }}
          >
            <Field id={`${path}-code`} label="Kode" hint={codeHint} error={err?.field('code')}>
              <Input id={`${path}-code`} value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} />
            </Field>
            <Field id={`${path}-name`} label="Nama" error={err?.field('name')}>
              <Input id={`${path}-name`} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            </Field>
            <div className="md:pt-[24px]">
              <Button type="submit" disabled={create.isPending}>Tambah {singular}</Button>
            </div>
          </form>
        </CardContent>
      </Card>

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
              <Th>Status</Th>
              <Th />
            </tr>
          </thead>
          <tbody>
            {list.data.data.length === 0 && <EmptyRow colSpan={4}>Belum ada data.</EmptyRow>}
            {list.data.data.map((r) => <MasterRow key={r.id} path={path} record={r} />)}
          </tbody>
        </Table>
      )}
    </div>
  )
}

function MasterRow({ path, record: r }: { path: string; record: SimpleMaster }) {
  const qc = useQueryClient()
  const [editing, setEditing] = useState(false)
  const [name, setName] = useState(r.name)
  const refresh = () => qc.invalidateQueries({ queryKey: [path] })
  const rename = useMutation({
    mutationFn: () => http.patch(`/${path}/${r.id}`, { name }),
    onSuccess: () => { setEditing(false); refresh() },
  })
  const toggle = useMutation({ mutationFn: () => http.post(`/${path}/${r.id}/${r.is_active ? 'deactivate' : 'activate'}`), onSuccess: refresh })
  const err = rename.error ?? toggle.error

  return (
    <tr>
      <Td className="font-mono text-xs">{r.code}</Td>
      <Td>
        {editing ? (
          <form className="flex gap-2" onSubmit={(e) => { e.preventDefault(); rename.mutate() }}>
            <Input aria-label="Nama baru" value={name} onChange={(e) => setName(e.target.value)} />
            <Button type="submit" size="sm" className="h-10" disabled={rename.isPending}>Simpan</Button>
            <Button type="button" size="sm" variant="ghost" className="h-10" onClick={() => setEditing(false)}>Batal</Button>
          </form>
        ) : (
          <>
            {r.name}
            {r.is_cash && <span className="ml-2 text-xs text-muted-foreground">(uang tunai di laci kasir)</span>}
          </>
        )}
        {err && <p className="mt-1 text-sm text-destructive">{errorText(err)}</p>}
      </Td>
      <Td>{r.is_active ? <Badge variant="success">Aktif</Badge> : <Badge>Nonaktif</Badge>}</Td>
      <Td className="whitespace-nowrap text-right">
        {!editing && <Button size="sm" variant="ghost" onClick={() => setEditing(true)}>Ubah nama</Button>}
        {!r.is_cash && (
          <Button size="sm" variant="ghost" disabled={toggle.isPending} onClick={() => toggle.mutate()}>
            {r.is_active ? 'Nonaktifkan' : 'Aktifkan'}
          </Button>
        )}
      </Td>
    </tr>
  )
}

export function PaymentMethodsPage() {
  return (
    <SimpleMasterPage
      path="payment-methods"
      title="Metode bayar"
      purpose="Cara pelanggan membayar di kasir."
      info="Tunai selalu aktif. Metode lain (QRIS, transfer, e-wallet, atau yang Anda tambahkan) dianggap non-tunai: uangnya masuk ke rekening usaha pusat dan dicocokkan Finance dengan mutasi rekening."
      codeHint="Mis. GOPAY. Tidak bisa diubah."
      singular="metode"
    />
  )
}

export function ExpenseCategoriesPage() {
  return (
    <SimpleMasterPage
      path="expense-categories"
      title="Kategori biaya"
      purpose="Pengelompokan biaya operasional untuk laporan."
      info="Kategori dipakai saat mencatat biaya outlet dan gudang. Kategori yang tidak dipakai lagi cukup dinonaktifkan; riwayatnya tetap tersimpan."
      codeHint="Mis. LISTRIK. Tidak bisa diubah."
      singular="kategori"
    />
  )
}
