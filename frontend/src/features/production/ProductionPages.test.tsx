import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from '@/App'
import { mockFetch, sampleUser } from '@/test/fetchMock'
import { renderWithProviders } from '@/test/render'

afterEach(() => vi.unstubAllGlobals())

const gudangUser = () => sampleUser({ id: 6, name: 'Gudang', username: 'gudang1', roles: [{ assignment_id: 6, code: 'WAREHOUSE', name: 'Gudang', scope: 'SELECTED_LOCATIONS', status: 'ACTIVE', locations: [{ id: 1, code: 'GDG', name: 'Gudang Pusat', type: 'WAREHOUSE' }] }] })

const pcs = { id: 1, code: 'pcs', name: 'potong' }
const pak = { id: 2, code: 'pak', name: 'pak' }
const kg = { id: 3, code: 'kg', name: 'kilogram' }
const g = { id: 4, code: 'g', name: 'gram' }
const dimsum = {
  id: 9, code: 'SJ-DIMSUM', name: 'Frozen Dimsum', item_type: 'SEMI_FINISHED', is_active: true, base_unit: pcs, shelf_life_days: 90,
  units: [{ unit_id: 1, factor_to_base: '1', unit: pcs }, { unit_id: 2, factor_to_base: '20', unit: pak }],
  recipe: { id: 1, version_no: 1, output_qty: '100.0000', output_unit_id: 2, output_entered_qty: '5.0000' },
}
const ayam = { id: 11, code: 'BB-AYAM', name: 'Daging ayam', item_type: 'RAW', base_unit: g }
const tepung = { id: 12, code: 'BB-TEPUNG', name: 'Tepung', item_type: 'RAW', base_unit: g }

function api(extra: (url: string, init?: RequestInit) => { status: number; body?: unknown } | undefined) {
  return mockFetch((url, init) => {
    const custom = extra(url, init)
    if (custom) return custom
    if (url.endsWith('/auth/me')) return { status: 200, body: { user: gudangUser() } }
    if (url.endsWith('/approvals/summary')) return { status: 200, body: { data: { waiting_my_decision: 0, returned_to_me: 0 } } }
    if (url.includes('/production/settings')) return { status: 200, body: { data: { variance_tolerance_pct: 5, can_edit: false } } }
    if (url.includes('/production/products')) return { status: 200, body: { data: [dimsum] } }
  })
}

const sent = (fetch: ReturnType<typeof api>, suffix: string) => {
  const call = fetch.mock.calls.find(([u, i]) => String(u).endsWith(suffix) && i?.method === 'POST')
  return call ? JSON.parse(String(call[1]?.body)) : undefined
}

describe('Produksi', () => {
  it('Rencana dari permintaan stok: kebutuhan bahan, kekurangan, dan tombol Buat PR terisi', async () => {
    const fetch = api((url, init) => {
      if (url.includes('/production/preview')) {
        return { status: 200, body: { data: { planned_qty: '120.0000', recipe_version_no: 1, lines: [
          { item: ayam, unit: { id: 3, code: 'kg' }, factor: '1000', standard_qty: '1800.0000', standard_entered: '1.8000', stock_qty: '1000.0000', shortage_qty: '800.0000', shortage_entered: '0.8000' },
          { item: tepung, unit: { id: 3, code: 'kg' }, factor: '1000', standard_qty: '1200.0000', standard_entered: '1.2000', stock_qty: '5000.0000', shortage_qty: '0.0000', shortage_entered: '0.0000' },
        ] } } }
      }
      if (url.endsWith('/production/orders') && init?.method === 'POST') return { status: 201, body: { data: { id: 50 } } }
    })
    const user = userEvent.setup()
    renderWithProviders(<App />, '/produksi/baru?barang=9&satuan=1&jumlah=120&permintaan=7')

    expect(await screen.findByText(/untuk permintaan stok outlet/)).toBeInTheDocument()
    expect(await screen.findByText('0,8 kg')).toBeInTheDocument()
    const pr = screen.getByRole('link', { name: 'Buat PR untuk bahan yang kurang' })
    expect(decodeURIComponent(pr.getAttribute('href') ?? '')).toContain('isi=11:3:0.8')
    await user.click(screen.getByRole('button', { name: 'Simpan rencana' }))

    await waitFor(() => expect(sent(fetch, '/production/orders')).toEqual({
      output_item_id: 9, unit_id: 1, qty: '120', planned_date: null, notes: null, stock_request_id: 7,
    }))
  })

  it('Selesai: hasil gagal per alasan, pemakaian sebenarnya, dan alasan wajib di luar toleransi', async () => {
    const order = {
      id: 50, number: 'PRD-261106-0001', status: 'PLANNED', output_item: { id: 9, code: 'SJ-DIMSUM', name: 'Frozen Dimsum', item_type: 'SEMI_FINISHED', shelf_life_days: 90, base_unit: pcs },
      unit: { id: 2, code: 'pak' }, factor: '20.0000', planned_qty: '100.0000', planned_entered: '5.0000', planned_date: null, stock_request: null, notes: null,
      created_by: { id: 6, name: 'Gudang' }, created_at: '2026-11-06T09:00:00+07:00', good_qty: null, good_entered: null, failed_qty: null, failed_entered: null,
      output_variance_pct: null, over_tolerance: null, tolerance_pct: null, variance_note: null, completion_note: null, batch_no: null, expiry_date: null,
      completed_by: null, completed_at: null, cancelled_by: null, cancelled_at: null, cancel_reason: null, total_cost: null, unit_cost: null, cost_complete: null, failed_value: null,
      recipe_version_no: 1, recipe_id: 1, failures: [], can: { complete: true, cancel: true, request_purchase: true },
      lines: [
        { id: 71, item: ayam, unit: { id: 3, code: 'kg' }, factor: '1000', standard_qty: '1500.0000', standard_entered: '1.5000', stock_qty: '10000.0000', shortage_qty: '0.0000', shortage_entered: '0.0000' },
        { id: 72, item: tepung, unit: { id: 3, code: 'kg' }, factor: '1000', standard_qty: '1000.0000', standard_entered: '1.0000', stock_qty: '20000.0000', shortage_qty: '0.0000', shortage_entered: '0.0000' },
      ],
    }
    const fetch = api((url, init) => {
      if (url.endsWith('/production/orders/50')) return { status: 200, body: { data: order } }
      if (url.endsWith('/production/orders/50/complete') && init?.method === 'POST') return { status: 200, body: { data: order } }
    })
    const user = userEvent.setup()
    renderWithProviders(<App />, '/produksi/50')

    await user.click(await screen.findByRole('button', { name: 'Selesaikan produksi' }))
    await user.selectOptions(screen.getByLabelText('Satuan'), '1')
    const good = screen.getByLabelText('Hasil baik')
    await user.clear(good)
    await user.type(good, '96')
    await user.type(screen.getByLabelText('Bentuk rusak'), '4')
    expect(screen.queryByLabelText(/Alasan varians/)).not.toBeInTheDocument()

    const ayamUsed = screen.getByLabelText('Dipakai Daging ayam')
    await user.clear(ayamUsed)
    await user.type(ayamUsed, '1,8')
    expect(screen.getByText('+20%')).toBeInTheDocument()
    await user.type(screen.getByLabelText(/Alasan varians/), 'Daging berlemak')
    await user.click(screen.getByRole('button', { name: 'Simpan hasil produksi' }))

    await waitFor(() => {
      const body = sent(fetch, '/production/orders/50/complete')
      expect(body).toMatchObject({
        unit_id: 1, good_qty: '96', failures: [{ reason: 'MISSHAPEN', qty: '4', note: null }],
        lines: [{ line_id: 71, actual_qty: '1.8' }, { line_id: 72, actual_qty: '1' }], variance_note: 'Daging berlemak',
      })
      expect(body.expiry_date).toMatch(/^\d{4}-\d{2}-\d{2}$/)
    })
  })

  it('Form PR terisi dari bahan yang kurang', async () => {
    api((url) => {
      if (url.includes('/items')) return { status: 200, body: { data: [{ ...ayam, is_active: true, units: [{ unit_id: 4, factor_to_base: '1', unit: g }, { unit_id: 3, factor_to_base: '1000', unit: kg }] }], current_page: 1, last_page: 1, total: 1 } }
    })
    renderWithProviders(<App />, `/pembelian/pr/baru?isi=${encodeURIComponent('11:3:0.8')}&catatan=${encodeURIComponent('Bahan untuk PRD-1')}`)

    expect(await screen.findByLabelText('Jumlah baris 1')).toHaveValue('0,8')
    expect(screen.getByLabelText(/Alasan \/ catatan untuk Finance/)).toHaveValue('Bahan untuk PRD-1')
  })
})
