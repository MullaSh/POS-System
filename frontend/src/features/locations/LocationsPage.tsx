import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useState } from 'react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { ErrorAlert, InfoBanner, Loading, PageHeader } from '@/components/ui/feedback'
import { Field } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Table, Td, Th } from '@/components/ui/table'
import { ApiError, errorText, http } from '@/lib/api'
import { formatDateTime, LOCATION_STATUS_LABEL } from '@/lib/format'
import type { Location } from '@/lib/types'

const KEY = ['locations']

export function LocationsPage() {
  const list = useQuery({ queryKey: KEY, queryFn: () => http.get<{ data: Location[] }>('/locations') })
  const [showCreate, setShowCreate] = useState(false)

  return (
    <div className="max-w-5xl">
      <PageHeader
        title="Gudang & outlet"
        purpose="Daftar lokasi usaha: satu gudang pusat dan outlet-outletnya."
        actions={
          <Button onClick={() => setShowCreate((v) => !v)}>
            <Plus className="size-4" /> Buka outlet baru
          </Button>
        }
      />
      <InfoBanner className="mb-4">
        Kode lokasi tidak bisa diubah setelah dibuat. Outlet yang ditutup tidak bisa dibuka lagi, tetapi riwayatnya tetap
        tersimpan. Untuk tutup sementara (mis. renovasi), pakai Nonaktifkan.
      </InfoBanner>

      {showCreate && <CreateOutlet onDone={() => setShowCreate(false)} />}

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
              <Th>Jenis</Th>
              <Th>Status</Th>
              <Th>Dibuka</Th>
              <Th />
            </tr>
          </thead>
          <tbody>
            {list.data.data.map((l) => (
              <LocationRow key={l.id} location={l} />
            ))}
          </tbody>
        </Table>
      )}
    </div>
  )
}

function CreateOutlet({ onDone }: { onDone: () => void }) {
  const qc = useQueryClient()
  const [form, setForm] = useState({ code: '', name: '' })
  const create = useMutation({
    mutationFn: () => http.post('/locations', form),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEY })
      onDone()
    },
  })
  const err = create.error instanceof ApiError ? create.error : null

  return (
    <Card className="mb-4">
      <CardContent className="pt-6">
        <form
          className="grid gap-4 md:grid-cols-[180px_1fr_auto] md:items-start"
          onSubmit={(e) => {
            e.preventDefault()
            create.mutate()
          }}
        >
          <Field id="loc-code" label="Kode outlet" hint="Mis. OTL03. Tidak bisa diubah." error={err?.field('code')}>
            <Input id="loc-code" value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} />
          </Field>
          <Field id="loc-name" label="Nama outlet" error={err?.field('name')}>
            <Input id="loc-name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          </Field>
          <div className="flex gap-2 md:pt-[24px]">
            <Button type="submit" disabled={create.isPending}>Simpan</Button>
            <Button type="button" variant="ghost" onClick={onDone}>Batal</Button>
          </div>
        </form>
      </CardContent>
    </Card>
  )
}

function LocationRow({ location: l }: { location: Location }) {
  const qc = useQueryClient()
  const refresh = () => qc.invalidateQueries({ queryKey: KEY })
  const [mode, setMode] = useState<'none' | 'rename' | 'close'>('none')
  const [name, setName] = useState(l.name)
  const [reason, setReason] = useState('')

  const rename = useMutation({ mutationFn: () => http.patch(`/locations/${l.id}`, { name }), onSuccess: () => { setMode('none'); refresh() } })
  const status = useMutation({ mutationFn: (action: 'deactivate' | 'activate') => http.post(`/locations/${l.id}/${action}`), onSuccess: refresh })
  const close = useMutation({ mutationFn: () => http.post(`/locations/${l.id}/close`, { reason }), onSuccess: () => { setMode('none'); refresh() } })

  const variant = l.status === 'ACTIVE' ? 'success' : l.status === 'INACTIVE' ? 'warning' : 'destructive'
  const isOutlet = l.type === 'OUTLET'
  const closed = l.status === 'CLOSED'

  return (
    <>
      <tr>
        <Td className="font-mono text-xs">{l.code}</Td>
        <Td className="font-medium">{l.name}</Td>
        <Td>{isOutlet ? 'Outlet' : 'Gudang pusat'}</Td>
        <Td><Badge variant={variant}>{LOCATION_STATUS_LABEL[l.status]}</Badge></Td>
        <Td className="text-muted-foreground">{formatDateTime(l.opened_at)}</Td>
        <Td className="space-x-1 text-right whitespace-nowrap">
          {!closed && <Button variant="ghost" size="sm" onClick={() => setMode(mode === 'rename' ? 'none' : 'rename')}>Ganti nama</Button>}
          {isOutlet && !closed && (
            <Button variant="ghost" size="sm" disabled={status.isPending} onClick={() => status.mutate(l.status === 'ACTIVE' ? 'deactivate' : 'activate')}>
              {l.status === 'ACTIVE' ? 'Nonaktifkan' : 'Aktifkan'}
            </Button>
          )}
          {isOutlet && !closed && <Button variant="ghost" size="sm" onClick={() => setMode(mode === 'close' ? 'none' : 'close')}>Tutup permanen</Button>}
        </Td>
      </tr>
      {(mode !== 'none' || status.error) && (
        <tr>
          <td colSpan={6} className="border-t border-border bg-muted/40 p-4">
            <ErrorAlert message={errorText(status.error)} />
            {mode === 'rename' && (
              <form className="flex max-w-lg items-end gap-2" onSubmit={(e) => { e.preventDefault(); rename.mutate() }}>
                <div className="flex-1">
                  <Field id={`rename-${l.id}`} label="Nama baru" error={rename.error instanceof ApiError ? rename.error.field('name') : undefined}>
                    <Input id={`rename-${l.id}`} value={name} onChange={(e) => setName(e.target.value)} />
                  </Field>
                </div>
                <Button type="submit" disabled={rename.isPending}>Simpan</Button>
              </form>
            )}
            {mode === 'close' && (
              <form className="max-w-lg space-y-2" onSubmit={(e) => { e.preventDefault(); close.mutate() }}>
                <p className="text-sm">
                  Menutup <strong>{l.name}</strong> bersifat permanen. Outlet tidak bisa dipakai untuk transaksi lagi.
                </p>
                <Field id={`reason-${l.id}`} label="Alasan penutupan" error={close.error instanceof ApiError ? close.error.field('reason') ?? close.error.message : undefined}>
                  <Input id={`reason-${l.id}`} value={reason} onChange={(e) => setReason(e.target.value)} />
                </Field>
                <Button type="submit" disabled={close.isPending || reason.trim() === ''}>Ya, tutup outlet</Button>
              </form>
            )}
          </td>
        </tr>
      )}
    </>
  )
}
