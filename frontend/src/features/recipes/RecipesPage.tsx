import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { ErrorAlert, InfoBanner, Loading, PageHeader } from '@/components/ui/feedback'
import { Field } from '@/components/ui/field'
import { Select } from '@/components/ui/select'
import { EmptyRow, Table, Td, Th } from '@/components/ui/table'
import { hasRole, useMe } from '@/features/auth/useAuth'
import { ApiError, errorText, http } from '@/lib/api'
import { formatCost, formatDate, formatQty } from '@/lib/format'
import type { RecipeDetail, RecipeKind, RecipeSummary } from '@/lib/types'
import { cn } from '@/lib/utils'
import { KIND_LABEL, PRODUCIBLE_TYPES, RECIPES_KEY, useItemsWithUnits } from './queries'
import { RecipeEditor, type RecipeInput } from './RecipeEditor'

/** Daftar resep menu dan resep produksi (C2, KP-05). */
export function RecipesPage() {
  const { data: me } = useMe()
  const isOwner = hasRole(me, 'OWNER')
  const [kind, setKind] = useState<RecipeKind>('MENU')
  const [creating, setCreating] = useState(false)
  const list = useQuery({
    queryKey: [...RECIPES_KEY, 'list', kind],
    queryFn: () => http.get<{ data: RecipeSummary[] }>(`/recipes?kind=${kind}`),
  })
  const seeCost = isOwner || hasRole(me, 'FINANCE')

  return (
    <div className="max-w-5xl">
      <PageHeader
        title="Resep"
        purpose="Takaran bahan untuk setiap menu dan setiap produksi di gudang."
        actions={isOwner && !creating && <Button onClick={() => setCreating(true)}><Plus className="size-4" /> Buat resep</Button>}
      />
      <InfoBanner className="mb-4">
        Resep menu memotong stok outlet saat menu terjual. Resep produksi dipakai gudang saat memproduksi. Setiap
        perubahan resep menjadi versi baru dengan tanggal berlaku; versi lama tetap tersimpan sehingga laporan lama
        tidak berubah. HPP dihitung dari bahan dan kemasan saja.
      </InfoBanner>

      {creating && <CreateRecipe kind={kind} onDone={() => setCreating(false)} />}

      <div role="tablist" className="mb-4 inline-flex rounded-lg border border-border p-1">
        {(['MENU', 'PRODUCTION'] as const).map((k) => (
          <button
            key={k}
            role="tab"
            aria-selected={kind === k}
            onClick={() => { setKind(k); setCreating(false) }}
            className={cn('rounded-md px-3 py-1.5 text-sm', kind === k ? 'bg-muted font-medium' : 'text-muted-foreground')}
          >
            {KIND_LABEL[k]}
          </button>
        ))}
      </div>

      {list.isPending ? (
        <Loading />
      ) : list.error ? (
        <ErrorAlert message={errorText(list.error)} />
      ) : (
        <Table>
          <thead>
            <tr>
              <Th>{kind === 'MENU' ? 'Menu' : 'Hasil produksi'}</Th>
              {kind === 'PRODUCTION' && <Th>Hasil per produksi</Th>}
              <Th>Jumlah bahan</Th>
              <Th>Versi berlaku</Th>
              {seeCost && <Th className="text-right">{kind === 'MENU' ? 'HPP per porsi' : 'HPP per satuan'}</Th>}
            </tr>
          </thead>
          <tbody>
            {list.data.data.length === 0 && <EmptyRow colSpan={5}>Belum ada resep.</EmptyRow>}
            {list.data.data.map((r) => (
              <tr key={r.id}>
                <Td>
                  <Link to={`/resep/${r.id}`} className="font-medium hover:underline">{r.output_item.name}</Link>
                  <p className="font-mono text-xs text-muted-foreground">{r.output_item.code}</p>
                </Td>
                {kind === 'PRODUCTION' && <Td>{r.current ? `${formatQty(r.current.output_qty)} ${r.output_item.base_unit.code}` : '–'}</Td>}
                <Td>{r.current?.line_count ?? '–'}</Td>
                <Td>
                  {r.current ? `Versi ${r.current.version_no} · sejak ${formatDate(r.current.effective_from)}` : 'Belum berlaku'}
                  {r.upcoming_count > 0 && <> <Badge variant="warning">Ada versi terjadwal</Badge></>}
                </Td>
                {seeCost && (
                  <Td className="text-right">
                    {r.cost?.per_output_unit ? formatCost(r.cost.per_output_unit) : <span className="text-muted-foreground">Harga bahan belum ada</span>}
                  </Td>
                )}
              </tr>
            ))}
          </tbody>
        </Table>
      )}
    </div>
  )
}

function CreateRecipe({ kind, onDone }: { kind: RecipeKind; onDone: () => void }) {
  const qc = useQueryClient()
  const navigate = useNavigate()
  const candidates = useItemsWithUnits(kind === 'MENU' ? ['MENU'] : PRODUCIBLE_TYPES)
  const existing = useQuery({ queryKey: [...RECIPES_KEY, 'list', kind], queryFn: () => http.get<{ data: RecipeSummary[] }>(`/recipes?kind=${kind}`) })
  const [outputId, setOutputId] = useState('')
  const taken = new Set(existing.data?.data.map((r) => r.output_item.id))
  const choices = (candidates.data?.data ?? []).filter((i) => !taken.has(i.id))
  const output = choices.find((i) => String(i.id) === outputId) ?? null

  const create = useMutation({
    mutationFn: (input: RecipeInput) => http.post<{ data: RecipeDetail }>('/recipes', { output_item_id: Number(outputId), ...input }),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: RECIPES_KEY })
      navigate(`/resep/${res.data.id}`)
    },
  })
  const err = create.error instanceof ApiError ? create.error : null

  return (
    <Card className="mb-4">
      <CardHeader><CardTitle className="text-base">{kind === 'MENU' ? 'Resep menu baru' : 'Resep produksi baru'}</CardTitle></CardHeader>
      <CardContent className="space-y-4">
        <Field
          id="recipe-output"
          label={kind === 'MENU' ? 'Menu' : 'Barang yang diproduksi'}
          hint={kind === 'MENU' ? 'Menu yang belum punya resep.' : 'Barang setengah jadi atau bahan pendukung buatan sendiri yang belum punya resep.'}
          error={err?.field('output_item_id')}
        >
          <Select id="recipe-output" className="md:w-96" value={outputId} onChange={(e) => setOutputId(e.target.value)}>
            <option value="">Pilih…</option>
            {choices.map((c) => <option key={c.id} value={c.id}>{c.name} ({c.code})</option>)}
          </Select>
        </Field>
        {choices.length === 0 && candidates.data && (
          <p className="text-sm text-muted-foreground">Semua {kind === 'MENU' ? 'menu' : 'barang produksi'} sudah punya resep, atau barangnya belum dibuat di Barang & menu.</p>
        )}
        {output ? (
          <RecipeEditor key={output.id} kind={kind} output={output} submitLabel="Simpan resep" pending={create.isPending} error={err} onSubmit={(i) => create.mutate(i)} onCancel={onDone} />
        ) : (
          <Button variant="ghost" onClick={onDone}>Batal</Button>
        )}
      </CardContent>
    </Card>
  )
}
