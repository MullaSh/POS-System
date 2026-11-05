import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { ErrorAlert, InfoBanner, Loading, PageHeader } from '@/components/ui/feedback'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { EmptyRow, Table, Td, Th } from '@/components/ui/table'
import { errorText, http } from '@/lib/api'
import { AUDIT_ACTION_LABEL, auditActionLabel, formatDateTime, ROLE_LABEL } from '@/lib/format'
import type { AuditLog, Paginated } from '@/lib/types'

const FLAG_LABEL: Record<string, string> = {
  SYSTEM: 'Sistem',
  OWNER_DIRECT: 'Keputusan langsung Owner',
  DELEGATED: 'Atas delegasi',
  FINANCE_SKIPPED: 'Tingkat Finance dilewati',
  SINGLE_PERSON: 'Dikerjakan satu orang',
}

/** Penelusuran jejak audit oleh Owner (3F-Q1). Catatan tidak bisa diubah atau dihapus. */
export function AuditLogPage() {
  const [action, setAction] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [page, setPage] = useState(1)

  const params = new URLSearchParams({ page: String(page) })
  if (action) params.set('action', action)
  if (dateFrom) params.set('date_from', dateFrom)
  if (dateTo) params.set('date_to', dateTo)

  const logs = useQuery({
    queryKey: ['audit-logs', action, dateFrom, dateTo, page],
    queryFn: () => http.get<Paginated<AuditLog>>(`/audit-logs?${params.toString()}`),
  })

  return (
    <div className="max-w-6xl">
      <PageHeader title="Jejak audit" purpose="Siapa melakukan apa, kapan, dan sebagai peran apa." />
      <InfoBanner className="mb-4">
        Setiap tindakan yang mengubah data tercatat di sini secara otomatis. Catatan ini tidak dapat diubah atau dihapus
        oleh siapa pun, termasuk Owner.
      </InfoBanner>

      <div className="mb-3 flex flex-wrap items-end gap-2">
        <Select aria-label="Saring tindakan" className="w-64" value={action} onChange={(e) => { setAction(e.target.value); setPage(1) }}>
          <option value="">Semua tindakan</option>
          {Object.entries(AUDIT_ACTION_LABEL).map(([k, v]) => (
            <option key={k} value={k}>{v}</option>
          ))}
        </Select>
        <label className="text-xs text-muted-foreground">
          Dari
          <Input type="date" className="w-40" value={dateFrom} onChange={(e) => { setDateFrom(e.target.value); setPage(1) }} />
        </label>
        <label className="text-xs text-muted-foreground">
          Sampai
          <Input type="date" className="w-40" value={dateTo} onChange={(e) => { setDateTo(e.target.value); setPage(1) }} />
        </label>
      </div>

      {logs.isPending ? (
        <Loading />
      ) : logs.error ? (
        <ErrorAlert message={errorText(logs.error)} />
      ) : (
        <>
          <Table>
            <thead>
              <tr>
                <Th>Waktu</Th>
                <Th>Pelaku</Th>
                <Th>Tindakan</Th>
                <Th>Objek</Th>
                <Th>Rincian</Th>
              </tr>
            </thead>
            <tbody>
              {logs.data.data.length === 0 && <EmptyRow colSpan={5}>Tidak ada catatan untuk saringan ini.</EmptyRow>}
              {logs.data.data.map((l) => (
                <tr key={l.id}>
                  <Td className="whitespace-nowrap text-muted-foreground">{formatDateTime(l.recorded_at)}</Td>
                  <Td>
                    {l.user ? (
                      <>
                        <span className="font-medium">{l.user.name}</span>
                        {l.role && <span className="text-muted-foreground"> · {ROLE_LABEL[l.role.code]}</span>}
                      </>
                    ) : l.flags.includes('SYSTEM') ? (
                      <span className="text-muted-foreground">Sistem</span>
                    ) : (
                      <span className="text-muted-foreground">Tidak diketahui</span>
                    )}
                  </Td>
                  <Td>
                    {auditActionLabel(l.action)}
                    {l.flags.filter((f) => f !== 'SYSTEM').map((f) => (
                      <Badge key={f} variant="warning" className="ml-1">{FLAG_LABEL[f] ?? f}</Badge>
                    ))}
                  </Td>
                  <Td className="font-mono text-xs">{l.subject_code ?? '–'}</Td>
                  <Td className="max-w-md text-xs">
                    {l.reason && <p><span className="text-muted-foreground">Alasan:</span> {l.reason}</p>}
                    {l.changes && <ChangesView changes={l.changes} />}
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
          <div className="mt-3 flex items-center gap-2 text-sm">
            <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>Sebelumnya</Button>
            <span className="text-muted-foreground">Halaman {logs.data.current_page} dari {logs.data.last_page} · {logs.data.total} catatan</span>
            <Button variant="outline" size="sm" disabled={page >= logs.data.last_page} onClick={() => setPage(page + 1)}>Berikutnya</Button>
          </div>
        </>
      )}
    </div>
  )
}

function ChangesView({ changes }: { changes: Record<string, unknown> }) {
  return (
    <dl className="space-y-0.5">
      {Object.entries(changes).map(([k, v]) => (
        <div key={k} className="flex gap-1">
          <dt className="text-muted-foreground">{k}:</dt>
          <dd className="break-words">{describe(v)}</dd>
        </div>
      ))}
    </dl>
  )
}

function describe(v: unknown): string {
  if (v && typeof v === 'object' && !Array.isArray(v) && 'dari' in v && 'menjadi' in v) {
    const c = v as { dari: unknown; menjadi: unknown }
    return `${describe(c.dari)} → ${describe(c.menjadi)}`
  }
  if (Array.isArray(v)) return v.map(describe).join(', ') || '–'
  if (v && typeof v === 'object') return Object.entries(v).map(([k, x]) => `${k} ${describe(x)}`).join(', ') || '–'
  if (v === null || v === undefined || v === '') return '–'
  return String(v)
}
