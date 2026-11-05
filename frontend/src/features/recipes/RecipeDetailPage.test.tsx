import { screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from '@/App'
import { cashierUser, mockFetch, sampleUser } from '@/test/fetchMock'
import { renderWithProviders } from '@/test/render'

afterEach(() => vi.unstubAllGlobals())

const unit = (id: number, code: string) => ({ id, code, name: code })
const ref = (id: number, code: string, name: string, item_type: string, base: string) => ({ id, code, name, item_type, is_active: true, base_unit: unit(id + 100, base) })

function recipe(canSeeCost: boolean, withCost: boolean) {
  const cost = (v: string | null) => (canSeeCost && withCost ? v : null)
  return {
    id: 1,
    kind: 'MENU',
    output_item: ref(10, 'MN-DIMSUM4', 'Dimsum Mentai 4 pcs', 'MENU', 'porsi'),
    can_see_cost: canSeeCost,
    versions: [
      {
        id: 5, version_no: 1, effective_from: '2026-11-05T10:00:00+07:00', status: 'CURRENT', output_qty: '1.0000', output_entered_qty: '1.0000',
        output_unit: unit(110, 'porsi'), notes: null, created_by: { id: 1, name: 'Pemilik Usaha' }, created_at: '2026-11-05T10:00:00+07:00',
        lines: [
          { item: ref(2, 'SJ-DIMSUM', 'Frozen Dimsum', 'SEMI_FINISHED', 'pcs'), qty: '4.0000', entered_qty: '4.0000', unit: unit(102, 'pcs'), unit_cost: cost('1.9500'), line_cost: cost('7.80'), cost_source: withCost && canSeeCost ? 'FROM_RECIPE' : null },
          { item: ref(3, 'BP-MENTAI', 'Saus Mentai', 'SUPPORTING', 'ml'), qty: '30.0000', entered_qty: '30.0000', unit: unit(103, 'ml'), unit_cost: cost('0.0800'), line_cost: cost('2.40'), cost_source: withCost && canSeeCost ? 'STOCK_AVERAGE' : null },
        ],
        cost: canSeeCost ? (withCost ? { total: '10.20', per_output_unit: '10.2000', missing: [] } : { total: null, per_output_unit: null, missing: ['Frozen Dimsum', 'Saus Mentai'] }) : null,
      },
    ],
  }
}

function api(user: unknown, body: unknown) {
  mockFetch((url) => {
    if (url.endsWith('/auth/me')) return { status: 200, body: { user } }
    if (url.endsWith('/approvals/summary')) return { status: 200, body: { data: { waiting_my_decision: 0, returned_to_me: 0 } } }
    if (url.endsWith('/recipes/1')) return { status: 200, body: { data: body } }
    if (url.includes('/items?')) return { status: 200, body: { data: [], current_page: 1, last_page: 1, total: 0 } }
  })
}

describe('Detail resep', () => {
  it('Owner melihat HPP per porsi dan sumber harga pokok', async () => {
    api(sampleUser(), recipe(true, true))
    renderWithProviders(<App />, '/resep/1')

    expect(await screen.findByText(/HPP per porsi: Rp10,2/)).toBeInTheDocument()
    expect(screen.getByText('perkiraan dari resep produksi')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Ubah resep (versi baru)' })).toBeInTheDocument()
  })

  it('menjelaskan bahan yang harga pokoknya belum ada', async () => {
    api(sampleUser(), recipe(true, false))
    renderWithProviders(<App />, '/resep/1')

    expect(await screen.findByText(/belum ada: Frozen Dimsum, Saus Mentai/)).toBeInTheDocument()
  })

  it('Kasir melihat takaran tanpa harga dan tanpa tombol ubah', async () => {
    api(cashierUser(), recipe(false, true))
    renderWithProviders(<App />, '/resep/1')

    expect((await screen.findAllByText('Frozen Dimsum')).length).toBeGreaterThan(0)
    expect(screen.queryByText(/HPP/)).not.toBeInTheDocument()
    expect(screen.queryByRole('columnheader', { name: 'Harga pokok' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Ubah resep (versi baru)' })).not.toBeInTheDocument()
  })
})
