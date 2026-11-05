import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { http } from '@/lib/api'
import type { ItemDetail, ItemRef, RecipeKind, RecipeSummary } from '@/lib/types'
import { KIND_LABEL, kindFor, RECIPES_KEY } from './queries'

/** Di halaman barang: resep untuk barang ini, dan resep yang memakai barang ini sebagai bahan. */
export function ItemRecipeCard({ item }: { item: ItemDetail }) {
  const kind = kindFor(item.item_type)
  const own = useQuery({
    queryKey: [...RECIPES_KEY, 'for-item', item.id],
    queryFn: () => http.get<{ data: RecipeSummary[] }>(`/recipes?output_item_id=${item.id}`),
    enabled: kind !== null,
  })
  const usage = useQuery({
    queryKey: [...RECIPES_KEY, 'usage', item.id],
    queryFn: () => http.get<{ data: { id: number; kind: RecipeKind; output_item: ItemRef }[] }>(`/recipes/usage?item_id=${item.id}`),
    enabled: item.is_stock_item,
  })
  const recipe = own.data?.data[0]

  return (
    <Card>
      <CardHeader><CardTitle className="text-base">Resep</CardTitle></CardHeader>
      <CardContent className="space-y-2 text-sm">
        {kind && (
          <p>
            {recipe ? (
              <Link to={`/resep/${recipe.id}`} className="font-medium underline">Lihat {KIND_LABEL[kind].toLowerCase()}</Link>
            ) : (
              <span className="text-muted-foreground">Belum punya {KIND_LABEL[kind].toLowerCase()}. Buat di menu Resep.</span>
            )}
          </p>
        )}
        {item.is_stock_item && (
          usage.data && usage.data.data.length > 0 ? (
            <div>
              <p className="text-muted-foreground">Dipakai di {usage.data.data.length} resep:</p>
              <ul className="mt-1 flex flex-wrap gap-2">
                {usage.data.data.map((u) => (
                  <li key={u.id}><Link to={`/resep/${u.id}`} className="rounded-md bg-muted px-2 py-1 hover:underline">{u.output_item.name}</Link></li>
                ))}
              </ul>
            </div>
          ) : (
            <p className="text-muted-foreground">Belum dipakai di resep mana pun.</p>
          )
        )}
      </CardContent>
    </Card>
  )
}
