import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, UserCheck, UserX } from 'lucide-react'
import { useState } from 'react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { ErrorAlert, InfoBanner, Loading, PageHeader } from '@/components/ui/feedback'
import { Field } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { EmptyRow, Table, Td, Th } from '@/components/ui/table'
import { useMe } from '@/features/auth/useAuth'
import { ApiError, errorText, http } from '@/lib/api'
import { formatDateTime, ROLE_LABEL } from '@/lib/format'
import type { Location, RoleCode, User } from '@/lib/types'

const USERS_KEY = ['users']
const ASSIGNABLE: RoleCode[] = ['FINANCE', 'WAREHOUSE', 'CASHIER']

export function UsersPage() {
  const users = useQuery({ queryKey: USERS_KEY, queryFn: () => http.get<{ data: User[] }>('/users') })
  const [showCreate, setShowCreate] = useState(false)
  const [openUserId, setOpenUserId] = useState<number | null>(null)

  return (
    <div className="max-w-5xl">
      <PageHeader
        title="Pengguna & peran"
        purpose="Buat akun untuk staf dan tentukan peran serta lokasi kerjanya."
        actions={
          <Button onClick={() => setShowCreate((v) => !v)}>
            <Plus className="size-4" /> Tambah pengguna
          </Button>
        }
      />
      <InfoBanner className="mb-4">
        Pengguna baru memakai kata sandi sementara dan wajib menggantinya saat pertama masuk. Kasir hanya bisa bekerja
        di outlet yang ditugaskan. Pengguna tidak dihapus, hanya dinonaktifkan, agar riwayatnya tetap utuh.
      </InfoBanner>

      {showCreate && <CreateUserCard onDone={() => setShowCreate(false)} />}

      {users.isPending ? (
        <Loading />
      ) : users.error ? (
        <ErrorAlert message={errorText(users.error)} />
      ) : (
        <Table>
          <thead>
            <tr>
              <Th>Nama</Th>
              <Th>Username</Th>
              <Th>Peran</Th>
              <Th>Status</Th>
              <Th>Terakhir masuk</Th>
              <Th />
            </tr>
          </thead>
          <tbody>
            {users.data.data.length === 0 && <EmptyRow colSpan={6}>Belum ada pengguna.</EmptyRow>}
            {users.data.data.map((u) => (
              <UserRow key={u.id} user={u} open={openUserId === u.id} onToggle={() => setOpenUserId(openUserId === u.id ? null : u.id)} />
            ))}
          </tbody>
        </Table>
      )}
    </div>
  )
}

function roleText(user: User) {
  if (user.roles.length === 0) return <span className="text-muted-foreground">Belum ada</span>
  return (
    <div className="flex flex-wrap gap-1">
      {user.roles.map((r) => (
        <Badge key={r.assignment_id}>
          {ROLE_LABEL[r.code]}
          {r.scope === 'SELECTED' && r.code !== 'WAREHOUSE' && `: ${r.locations.map((l) => l.code).join(', ')}`}
        </Badge>
      ))}
    </div>
  )
}

function UserRow({ user, open, onToggle }: { user: User; open: boolean; onToggle: () => void }) {
  return (
    <>
      <tr>
        <Td className="font-medium">{user.name}</Td>
        <Td>{user.username}</Td>
        <Td>{roleText(user)}</Td>
        <Td>
          {user.is_active ? <Badge variant="success">Aktif</Badge> : <Badge variant="destructive">Nonaktif</Badge>}
          {user.must_change_password && <Badge variant="warning" className="ml-1">Kata sandi sementara</Badge>}
        </Td>
        <Td className="text-muted-foreground">{formatDateTime(user.last_login_at)}</Td>
        <Td className="text-right">
          <Button variant="outline" size="sm" onClick={onToggle} aria-expanded={open}>
            {open ? 'Tutup' : 'Kelola'}
          </Button>
        </Td>
      </tr>
      {open && (
        <tr>
          <td colSpan={6} className="border-t border-border bg-muted/40 p-4">
            <ManageUser user={user} />
          </td>
        </tr>
      )}
    </>
  )
}

function CreateUserCard({ onDone }: { onDone: () => void }) {
  const qc = useQueryClient()
  const [form, setForm] = useState({ name: '', username: '', password: '' })
  const create = useMutation({
    mutationFn: () => http.post('/users', form),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: USERS_KEY })
      onDone()
    },
  })
  const err = create.error instanceof ApiError ? create.error : null

  return (
    <Card className="mb-4">
      <CardHeader>
        <CardTitle className="text-base">Pengguna baru</CardTitle>
      </CardHeader>
      <CardContent>
        <form
          className="grid gap-4 md:grid-cols-3"
          onSubmit={(e) => {
            e.preventDefault()
            create.mutate()
          }}
        >
          <Field id="new-name" label="Nama" error={err?.field('name')}>
            <Input id="new-name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          </Field>
          <Field id="new-username" label="Username" hint="Huruf kecil, angka, titik, atau garis." error={err?.field('username')}>
            <Input id="new-username" value={form.username} onChange={(e) => setForm({ ...form, username: e.target.value })} />
          </Field>
          <Field id="new-password" label="Kata sandi sementara" hint="Minimal 8 karakter." error={err?.field('password')}>
            <Input id="new-password" type="text" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} />
          </Field>
          <div className="flex items-center gap-2 md:col-span-3">
            <Button type="submit" disabled={create.isPending}>{create.isPending ? 'Menyimpan…' : 'Simpan pengguna'}</Button>
            <Button type="button" variant="ghost" onClick={onDone}>Batal</Button>
          </div>
        </form>
      </CardContent>
    </Card>
  )
}

function ManageUser({ user }: { user: User }) {
  const qc = useQueryClient()
  const { data: me } = useMe()
  const isSelf = me?.id === user.id
  const isOwnerAccount = user.roles.some((r) => r.code === 'OWNER')
  const refresh = () => qc.invalidateQueries({ queryKey: USERS_KEY })

  const locations = useQuery({ queryKey: ['locations'], queryFn: () => http.get<{ data: Location[] }>('/locations') })
  const outlets = (locations.data?.data ?? []).filter((l) => l.type === 'OUTLET' && l.status !== 'CLOSED')

  const [role, setRole] = useState<RoleCode>('CASHIER')
  const [outletIds, setOutletIds] = useState<number[]>([])
  const [tempPassword, setTempPassword] = useState('')

  const assign = useMutation({
    mutationFn: () => http.post(`/users/${user.id}/role-assignments`, { role, location_ids: role === 'CASHIER' ? outletIds : [] }),
    onSuccess: () => {
      setOutletIds([])
      refresh()
    },
  })
  const revoke = useMutation({ mutationFn: (id: number) => http.post(`/role-assignments/${id}/revoke`), onSuccess: refresh })
  const toggleActive = useMutation({
    mutationFn: () => http.post(`/users/${user.id}/${user.is_active ? 'deactivate' : 'activate'}`),
    onSuccess: refresh,
  })
  const reset = useMutation({
    mutationFn: () => http.post(`/users/${user.id}/reset-password`, { password: tempPassword }),
    onSuccess: () => {
      setTempPassword('')
      refresh()
    },
  })
  const assignErr = assign.error instanceof ApiError ? assign.error : null

  return (
    <div className="grid gap-6 md:grid-cols-2">
      <section className="space-y-3">
        <h3 className="text-sm font-semibold">Peran</h3>
        {user.roles.length === 0 && <p className="text-sm text-muted-foreground">Belum ada peran.</p>}
        <ul className="space-y-2">
          {user.roles.map((r) => (
            <li key={r.assignment_id} className="flex items-center justify-between gap-2 rounded-lg border border-border bg-background px-3 py-2 text-sm">
              <span>
                <span className="font-medium">{ROLE_LABEL[r.code]}</span>{' '}
                <span className="text-muted-foreground">
                  {r.scope === 'ALL_LOCATIONS' ? '· semua lokasi' : `· ${r.locations.map((l) => l.name).join(', ')}`}
                </span>
              </span>
              {r.code !== 'OWNER' && (
                <Button variant="ghost" size="sm" disabled={revoke.isPending} onClick={() => revoke.mutate(r.assignment_id)}>
                  Cabut
                </Button>
              )}
            </li>
          ))}
        </ul>
        <ErrorAlert message={errorText(revoke.error)} />

        {!isOwnerAccount && (
          <form
            className="space-y-3 rounded-lg border border-border bg-background p-3"
            onSubmit={(e) => {
              e.preventDefault()
              assign.mutate()
            }}
          >
            <Field id={`role-${user.id}`} label="Tambah peran" error={assignErr?.field('role')}>
              <Select id={`role-${user.id}`} value={role} onChange={(e) => setRole(e.target.value as RoleCode)}>
                {ASSIGNABLE.map((r) => (
                  <option key={r} value={r}>{ROLE_LABEL[r]}</option>
                ))}
              </Select>
            </Field>
            {role === 'CASHIER' && (
              <fieldset className="space-y-1.5">
                <legend className="text-sm font-medium">Outlet tempat bertugas</legend>
                {outlets.length === 0 && <p className="text-sm text-muted-foreground">Belum ada outlet aktif.</p>}
                {outlets.map((o) => (
                  <label key={o.id} className="flex items-center gap-2 text-sm">
                    <input
                      type="checkbox"
                      checked={outletIds.includes(o.id)}
                      onChange={(e) => setOutletIds(e.target.checked ? [...outletIds, o.id] : outletIds.filter((x) => x !== o.id))}
                    />
                    {o.name} <span className="text-muted-foreground">({o.code})</span>
                  </label>
                ))}
              </fieldset>
            )}
            {role === 'FINANCE' && <p className="text-xs text-muted-foreground">Finance bekerja untuk semua lokasi.</p>}
            {role === 'WAREHOUSE' && <p className="text-xs text-muted-foreground">Peran Gudang bekerja di gudang pusat.</p>}
            <ErrorAlert message={assignErr?.field('location_ids') ?? (assignErr && !assignErr.field('role') ? assignErr.message : null)} />
            <Button type="submit" size="sm" disabled={assign.isPending}>Berikan peran</Button>
          </form>
        )}
      </section>

      <section className="space-y-4">
        <h3 className="text-sm font-semibold">Akun</h3>
        {!isSelf && !isOwnerAccount && (
          <div className="space-y-2">
            <Button variant="outline" size="sm" disabled={toggleActive.isPending} onClick={() => toggleActive.mutate()}>
              {user.is_active ? <><UserX className="size-4" /> Nonaktifkan</> : <><UserCheck className="size-4" /> Aktifkan kembali</>}
            </Button>
            <ErrorAlert message={errorText(toggleActive.error)} />
          </div>
        )}
        {!isSelf && (
          <form
            className="space-y-2"
            onSubmit={(e) => {
              e.preventDefault()
              reset.mutate()
            }}
          >
            <Field
              id={`reset-${user.id}`}
              label="Atur ulang kata sandi"
              hint="Berikan kata sandi sementara; pengguna wajib menggantinya saat masuk."
              error={reset.error instanceof ApiError ? reset.error.field('password') : undefined}
            >
              <Input id={`reset-${user.id}`} value={tempPassword} onChange={(e) => setTempPassword(e.target.value)} />
            </Field>
            <Button type="submit" variant="outline" size="sm" disabled={reset.isPending || tempPassword.length === 0}>
              Simpan kata sandi sementara
            </Button>
            {reset.isSuccess && <p className="text-sm text-success">Kata sandi sementara tersimpan.</p>}
          </form>
        )}
        {isSelf && <p className="text-sm text-muted-foreground">Ini akun Anda. Ganti kata sandi lewat menu di kiri bawah.</p>}
      </section>
    </div>
  )
}
