import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, ChevronDown, ChevronUp } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { ErrorAlert, InfoBanner, Loading } from '@/components/ui/feedback'
import { Table, Td, Th } from '@/components/ui/table'
import { hasRole, useMe } from '@/features/auth/useAuth'
import { ApiError, errorText, http } from '@/lib/api'
import { formatCost, formatDate, formatDateTime, formatQty, ITEM_TYPE_LABEL } from '@/lib/format'
import type { ItemWithUnits, Paginated, RecipeDetail, RecipeVersion } from '@/lib/types'
import { KIND_LABEL, RECIPES_KEY } from './queries'
import { RecipeEditor, type RecipeInput } from './RecipeEditor'

const STATUS: Record<RecipeVersion['status'], { label: string; variant: 'success' | 'warning' | 'default' }> = {
  CURRENT: { label: 'Berlaku', variant: 'success' },
  UPCOMING: { label: 'Terjadwal', variant: 'warning' },
  PAST: { label: 'Versi lama', variant: 'default' },
  SUPERSEDED: { label: 'Tidak jadi berlaku', variant: 'default' },
}

const SOURCE_LABEL = { STOCK_AVERAGE: 'rata-rata stok', FROM_RECIPE: 'perkiraan dari resep produksi' } as const

export function RecipeDetailPage() {
  const { id } = useParams()
  const { data: me } = useMe()
  const isOwner = hasRole(me, 'OWNER')
  const qc = useQueryClient()
  const [editing, setEditing] = useState(false)
  const recipe = useQuery({ queryKey: [...RECIPES_KEY, 'detail', id], queryFn: () => http.get<{ data: RecipeDetail }>(`/recipes/${id}`) })
  const outputWithUnits = useQuery({
    queryKey: ['items', 'with-units', 'output', recipe.data?.data.output_item.id],
    queryFn: () => http.get<Paginated<ItemWithUnits>>(`/items?with_units=1&search=${encodeURIComponent(recipe.data!.data.output_item.code)}`),
    enabled: !!recipe.data,
  })
  const addVersion = useMutation({
    mutationFn: (input: RecipeInput) => http.post(`/recipes/${id}/versions`, input),
    onSuccess: () => {
      setEditing(false)
      qc.invalidateQueries({ queryKey: RECIPES_KEY })
    },
  })
  const err = addVersion.error instanceof ApiError ? addVersion.error : null

  if (recipe.isPending) return <Loading />
  if (recipe.error) return <ErrorAlert message={errorText(recipe.error)} />
  const r = recipe.data.data
  const current = r.versions.find((v) => v.status === 'CURRENT')
  const upcoming = r.versions.filter((v) => v.status === 'UPCOMING')
  const output = outputWithUnits.data?.data.find((i) => i.id === r.output_item.id) ?? null

  return (
    <div className="max-w-5xl space-y-4">
      <Link to="/resep" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:underline">
        <ArrowLeft className="size-4" /> Kembali ke daftar resep
      </Link>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-semibold">{r.output_item.name}</h1>
          <Badge>{KIND_LABEL[r.kind]}</Badge>
        </div>
        {isOwner && !editing && (
          <Button onClick={() => { addVersion.reset(); setEditing(true) }} disabled={!output}>Ubah resep (versi baru)</Button>
        )}
      </div>
      <p className="text-sm text-muted-foreground">
        Kode <Link to={`/barang/${r.output_item.id}`} className="font-mono hover:underline">{r.output_item.code}</Link> ·{' '}
        {ITEM_TYPE_LABEL[r.output_item.item_type]}
      </p>

      {editing && output && (
        <Card>
          <CardHeader><CardTitle className="text-base">Versi baru</CardTitle></CardHeader>
          <CardContent>
            <p className="mb-4 text-sm text-muted-foreground">Isi diambil dari versi yang berlaku. Versi lama tetap tersimpan dan tidak berubah.</p>
            <RecipeEditor
              kind={r.kind}
              output={output}
              from={upcoming[upcoming.length - 1] ?? current ?? r.versions[0]}
              submitLabel="Simpan versi baru"
              pending={addVersion.isPending}
              error={err}
              onSubmit={(i) => addVersion.mutate(i)}
              onCancel={() => setEditing(false)}
            />
          </CardContent>
        </Card>
      )}

      {upcoming.map((v) => (
        <InfoBanner key={v.id}>
          Versi {v.version_no} terjadwal berlaku mulai {formatDate(v.effective_from)}{v.notes ? ` — ${v.notes}` : ''}.
        </InfoBanner>
      ))}

      {current ? <VersionCard recipe={r} version={current} title={`Versi ${current.version_no} (berlaku)`} /> : (
        <InfoBanner>Belum ada versi yang berlaku. Versi pertama akan berlaku sesuai tanggalnya.</InfoBanner>
      )}
      {upcoming.map((v) => <VersionCard key={v.id} recipe={r} version={v} title={`Versi ${v.version_no} (terjadwal)`} />)}

      <History recipe={r} />
    </div>
  )
}

function VersionCard({ recipe: r, version: v, title }: { recipe: RecipeDetail; version: RecipeVersion; title: string }) {
  const unit = r.output_item.base_unit.code
  return (
    <Card>
      <CardHeader className="flex flex-wrap items-baseline justify-between gap-2 space-y-0">
        <CardTitle className="text-base">{title}</CardTitle>
        <p className="text-sm text-muted-foreground">
          {r.kind === 'MENU' ? 'Untuk 1 porsi' : `Hasil ${formatQty(v.output_entered_qty)} ${v.output_unit.code}${v.output_unit.code !== unit ? ` (${formatQty(v.output_qty)} ${unit})` : ''}`}
          {' '}· sejak {formatDateTime(v.effective_from)}
        </p>
      </CardHeader>
      <CardContent className="space-y-3">
        <VersionLines recipe={r} version={v} />
        {r.can_see_cost && v.cost && (
          <div className="flex flex-wrap items-baseline justify-between gap-2 rounded-lg bg-muted px-4 py-3">
            {v.cost.total !== null ? (
              <>
                <p className="text-sm">Total biaya bahan: <strong>{formatCost(v.cost.total)}</strong></p>
                <p className="text-lg font-semibold">
                  HPP {r.kind === 'MENU' ? 'per porsi' : `per ${unit}`}: {formatCost(v.cost.per_output_unit)}
                </p>
              </>
            ) : (
              <p className="text-sm text-muted-foreground">
                HPP belum bisa dihitung karena harga pokok bahan berikut belum ada: {v.cost.missing.join(', ')}. Harga pokok
                terisi otomatis setelah ada pembelian atau saldo awal stok.
              </p>
            )}
          </div>
        )}
        {v.notes && <p className="text-sm text-muted-foreground">Catatan: {v.notes}</p>}
      </CardContent>
    </Card>
  )
}

function VersionLines({ recipe: r, version: v }: { recipe: RecipeDetail; version: RecipeVersion }) {
  return (
    <Table>
      <thead>
        <tr>
          <Th>Bahan</Th>
          <Th className="text-right">Jumlah</Th>
          {r.can_see_cost && <Th className="text-right">Harga pokok</Th>}
          {r.can_see_cost && <Th className="text-right">Biaya</Th>}
        </tr>
      </thead>
      <tbody>
        {v.lines.map((l) => (
          <tr key={l.item.id}>
            <Td>
              <Link to={`/barang/${l.item.id}`} className="hover:underline">{l.item.name}</Link>
              <p className="text-xs text-muted-foreground">{ITEM_TYPE_LABEL[l.item.item_type]}</p>
            </Td>
            <Td className="text-right">
              {formatQty(l.entered_qty)} {l.unit.code}
              {l.unit.code !== l.item.base_unit.code && <p className="text-xs text-muted-foreground">{formatQty(l.qty)} {l.item.base_unit.code}</p>}
            </Td>
            {r.can_see_cost && (
              <Td className="text-right">
                {l.unit_cost !== null ? <>{formatCost(l.unit_cost)}/{l.item.base_unit.code}<p className="text-xs text-muted-foreground">{l.cost_source ? SOURCE_LABEL[l.cost_source] : ''}</p></> : <span className="text-muted-foreground">Belum ada</span>}
              </Td>
            )}
            {r.can_see_cost && <Td className="text-right">{formatCost(l.line_cost)}</Td>}
          </tr>
        ))}
      </tbody>
    </Table>
  )
}

function History({ recipe: r }: { recipe: RecipeDetail }) {
  const [open, setOpen] = useState<number | null>(null)
  return (
    <Card>
      <CardHeader><CardTitle className="text-base">Riwayat versi</CardTitle></CardHeader>
      <CardContent>
        <ul className="divide-y divide-border">
          {r.versions.map((v) => (
            <li key={v.id} className="py-2">
              <button type="button" className="flex w-full flex-wrap items-center gap-2 text-left text-sm" onClick={() => setOpen(open === v.id ? null : v.id)}>
                {open === v.id ? <ChevronUp className="size-4" /> : <ChevronDown className="size-4" />}
                <span className="font-medium">Versi {v.version_no}</span>
                <Badge variant={STATUS[v.status].variant}>{STATUS[v.status].label}</Badge>
                <span className="text-muted-foreground">
                  berlaku {formatDateTime(v.effective_from)} · dibuat {v.created_by?.name} · {v.lines.length} bahan
                  {v.notes ? ` · ${v.notes}` : ''}
                </span>
              </button>
              {open === v.id && <div className="mt-2"><VersionLines recipe={{ ...r, can_see_cost: false }} version={v} /></div>}
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  )
}
