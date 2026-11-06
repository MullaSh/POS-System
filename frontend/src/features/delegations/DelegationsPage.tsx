import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { ErrorAlert, InfoBanner, Loading, PageHeader } from '@/components/ui/feedback'
import { Field } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { EmptyRow, Table, Td, Th } from '@/components/ui/table'
import { APPROVALS_KEY } from '@/features/approvals/queries'
import { hasRole, ME_KEY, useMe } from '@/features/auth/useAuth'
import { ApiError, errorText, http } from '@/lib/api'
import { APPROVAL_TYPE_LABEL, DELEGATION_STATUS_LABEL, ROLE_LABEL, formatDateTime } from '@/lib/format'
import type { ApprovalType, Delegation, DelegationOptions, DelegationStatus, RoleCode } from '@/lib/types'

const KEY = ['delegations']
const VARIANT: Record<DelegationStatus, 'default' | 'success' | 'warning'> = { ACTIVE: 'success', SCHEDULED: 'warning', EXPIRED: 'default', REVOKED: 'default' }

/** Delegasi persetujuan sementara (2E-Q2): pemberi memilih penerima, periode, dan jenis; dapat dicabut. */
export function DelegationsPage() {
  const { data: me } = useMe()
  const canGive = hasRole(me, 'OWNER') || hasRole(me, 'FINANCE')
  const [creating, setCreating] = useState(false)
  const list = useQuery({ queryKey: KEY, queryFn: () => http.get<{ data: Delegation[] }>('/delegations') })

  return (
    <div className="max-w-5xl space-y-4">
      <PageHeader
        title="Delegasi persetujuan"
        purpose="Titipkan wewenang persetujuan ke orang lain untuk sementara, mis. saat Anda tidak di tempat."
        actions={canGive && !creating && <Button onClick={() => setCreating(true)}>Buat delegasi</Button>}
      />
      <InfoBanner>
        Akun tidak dipakai bersama. Penerima memutuskan dengan akunnya sendiri, hanya untuk jenis dan periode yang dipilih, dan tidak boleh memutuskan pengajuannya sendiri.
        Setiap keputusan atas delegasi tercatat. Delegasi dapat dicabut kapan saja.
      </InfoBanner>
      {creating && <DelegationForm onDone={() => setCreating(false)} />}
      {list.isPending ? <Loading /> : list.error ? <ErrorAlert message={errorText(list.error)} /> : <DelegationTable rows={list.data.data} />}
    </div>
  )
}

function DelegationTable({ rows }: { rows: Delegation[] }) {
  const qc = useQueryClient()
  const revoke = useMutation({
    mutationFn: (id: number) => http.post(`/delegations/${id}/revoke`, {}),
    onSuccess: () => { qc.invalidateQueries({ queryKey: KEY }); qc.invalidateQueries({ queryKey: APPROVALS_KEY }) },
  })
  return (
    <>
      <Table>
        <thead><tr><Th>Nomor</Th><Th>Dari</Th><Th>Kepada</Th><Th>Jenis persetujuan</Th><Th>Periode</Th><Th>Status</Th><Th></Th></tr></thead>
        <tbody>
          {rows.length === 0 && <EmptyRow colSpan={7}>Belum ada delegasi.</EmptyRow>}
          {rows.map((d) => (
            <tr key={d.id}>
              <Td className="font-mono">{d.number}</Td>
              <Td>{d.delegator?.name}<p className="text-xs text-muted-foreground">sebagai {ROLE_LABEL[d.delegator_role]}</p></Td>
              <Td>{d.delegate?.name}</Td>
              <Td>{d.types.map((t) => APPROVAL_TYPE_LABEL[t]).join(', ')}{d.reason && <p className="text-xs text-muted-foreground">{d.reason}</p>}</Td>
              <Td>{formatDateTime(d.starts_at)} –<br />{formatDateTime(d.ends_at)}</Td>
              <Td>
                <Badge variant={VARIANT[d.status]}>{DELEGATION_STATUS_LABEL[d.status]}</Badge>
                {d.revoked_at && <p className="text-xs text-muted-foreground">{d.revoked_by?.name}, {formatDateTime(d.revoked_at)}</p>}
              </Td>
              <Td>{d.can.revoke && <Button size="sm" variant="outline" disabled={revoke.isPending} onClick={() => revoke.mutate(d.id)}>Cabut</Button>}</Td>
            </tr>
          ))}
        </tbody>
      </Table>
      <ErrorAlert message={errorText(revoke.error)} />
    </>
  )
}

/** Nilai untuk input datetime-local dalam WIB. */
function localWib(date: Date): string {
  const p = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(date)
  const v = (t: string) => p.find((x) => x.type === t)?.value
  return `${v('year')}-${v('month')}-${v('day')}T${v('hour')}:${v('minute')}`
}

function DelegationForm({ onDone }: { onDone: () => void }) {
  const qc = useQueryClient()
  const options = useQuery({ queryKey: [...KEY, 'options'], queryFn: () => http.get<{ data: DelegationOptions }>('/delegations/options') })
  const [role, setRole] = useState<RoleCode | ''>('')
  const [delegateId, setDelegateId] = useState('')
  const [types, setTypes] = useState<ApprovalType[]>([])
  const [startsAt, setStartsAt] = useState(() => localWib(new Date()))
  const [endsAt, setEndsAt] = useState(() => localWib(new Date(Date.now() + 3 * 86400_000)))
  const [reason, setReason] = useState('')
  const save = useMutation({
    mutationFn: (roleCode: RoleCode) => http.post('/delegations', {
      delegator_role: roleCode, delegate_id: Number(delegateId) || null, types,
      starts_at: `${startsAt}:00+07:00`, ends_at: `${endsAt}:00+07:00`, reason: reason || null,
    }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEY })
      qc.invalidateQueries({ queryKey: ME_KEY })
      onDone()
    },
  })
  const err = save.error instanceof ApiError ? save.error : null

  if (options.isPending) return <Loading />
  if (options.error) return <ErrorAlert message={errorText(options.error)} />
  const o = options.data.data
  const roleCode = (role || o.roles.find((r) => r.types.length > 0)?.code || o.roles[0]?.code) as RoleCode
  const available = o.roles.find((r) => r.code === roleCode)?.types ?? []

  return (
    <Card>
      <CardContent className="pt-6">
        <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); save.mutate(roleCode) }}>
          <div className="grid gap-4 md:grid-cols-2">
            {o.roles.length > 1 && (
              <Field id="dl-role" label="Delegasikan wewenang peran">
                <Select id="dl-role" value={roleCode} onChange={(e) => { setRole(e.target.value as RoleCode); setTypes([]) }}>
                  {o.roles.map((r) => <option key={r.code} value={r.code}>{ROLE_LABEL[r.code]}</option>)}
                </Select>
              </Field>
            )}
            <Field id="dl-user" label="Penerima" error={err?.field('delegate_id')}>
              <Select id="dl-user" value={delegateId} onChange={(e) => setDelegateId(e.target.value)}>
                <option value="">Pilih orang…</option>
                {o.users.map((u) => <option key={u.id} value={u.id}>{u.name} ({u.username})</option>)}
              </Select>
            </Field>
          </div>
          <fieldset>
            <legend className="mb-1 text-sm font-medium">Jenis persetujuan</legend>
            {available.length === 0 && <p className="text-sm text-muted-foreground">Belum ada jenis persetujuan yang diputuskan peran ini.</p>}
            <div className="flex flex-wrap gap-4">
              {available.map((t) => (
                <label key={t} className="flex items-center gap-2 text-sm">
                  <input type="checkbox" checked={types.includes(t)} onChange={(e) => setTypes(e.target.checked ? [...types, t] : types.filter((x) => x !== t))} />
                  {APPROVAL_TYPE_LABEL[t]}
                </label>
              ))}
            </div>
            {(err?.field('types') || err?.field('types.0')) && <p className="text-sm text-destructive">{err.field('types') ?? err.field('types.0')}</p>}
          </fieldset>
          <div className="grid gap-4 md:grid-cols-3">
            <Field id="dl-start" label="Mulai (WIB)" error={err?.field('starts_at')}><Input id="dl-start" type="datetime-local" value={startsAt} onChange={(e) => setStartsAt(e.target.value)} /></Field>
            <Field id="dl-end" label="Selesai (WIB)" error={err?.field('ends_at')} hint={`Paling lama ${o.max_days} hari.`}><Input id="dl-end" type="datetime-local" value={endsAt} onChange={(e) => setEndsAt(e.target.value)} /></Field>
            <Field id="dl-reason" label="Alasan (opsional)"><Input id="dl-reason" value={reason} onChange={(e) => setReason(e.target.value)} placeholder="mis. ke luar kota" /></Field>
          </div>
          {err && !err.field('delegate_id') && !err.field('ends_at') && !err.field('types') && !err.field('types.0') && <ErrorAlert message={err.message} />}
          <div className="flex gap-2">
            <Button type="submit" disabled={save.isPending || types.length === 0 || !delegateId}>Simpan delegasi</Button>
            <Button type="button" variant="ghost" onClick={onDone}>Batal</Button>
          </div>
        </form>
      </CardContent>
    </Card>
  )
}
