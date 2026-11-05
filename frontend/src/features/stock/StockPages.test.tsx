import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from '@/App'
import { cashierUser, mockFetch, sampleUser } from '@/test/fetchMock'
import { renderWithProviders } from '@/test/render'

afterEach(() => vi.unstubAllGlobals())

const unit = (id: number, code: string) => ({ id, code, name: code })
const ref = (id: number, code: string, name: string, item_type: string, base: string) => ({ id, code, name, item_type, is_active: true, base_unit: unit(100 + id, base) })
const outlet1 = { id: 2, code: 'OTL01', name: 'Outlet 1', type: 'OUTLET', status: 'ACTIVE' }
const gudang = { id: 1, code: 'GDG', name: 'Gudang Pusat', type: 'WAREHOUSE', status: 'ACTIVE' }
const person = { id: 9, name: 'Staf Gudang', username: 'gudang1' }

function balances(canSeeCost: boolean) {
  return {
    data: [
      { item: ref(3, 'PK-BOWL', 'Paper Bowl', 'PACKAGING', 'pcs'), qty: '30.0000', min_qty: '50.0000', is_negative: false, is_low: true, has_movements: true, last_movement_at: '2026-11-05T10:00:00+07:00', avg_cost: canSeeCost ? '500.0000' : null, value: canSeeCost ? '15000.00' : null },
      { item: ref(2, 'SJ-DIMSUM', 'Frozen Dimsum', 'SEMI_FINISHED', 'pcs'), qty: '-4.0000', min_qty: null, is_negative: true, is_low: false, has_movements: true, last_movement_at: '2026-11-05T10:00:00+07:00', avg_cost: canSeeCost ? '1500.0000' : null, value: null },
    ],
    summary: { item_count: 2, low_count: 1, negative_count: 1, total_value: canSeeCost ? '15000.00' : null },
    can_see_cost: canSeeCost,
  }
}

function api(user: unknown, extra: (url: string, init?: RequestInit) => { status: number; body?: unknown } | undefined = () => undefined, canSeeCost = false) {
  return mockFetch((url, init) => {
    const custom = extra(url, init)
    if (custom) return custom
    if (url.endsWith('/auth/me')) return { status: 200, body: { user } }
    if (url.endsWith('/approvals/summary')) return { status: 200, body: { data: { waiting_my_decision: 0, returned_to_me: 0 } } }
    if (url.endsWith('/stock/locations')) {
      return {
        status: 200,
        body: canSeeCost
          ? { data: [{ ...gudang, can: { operate: false, request_stock: false, opening: true } }, { ...outlet1, can: { operate: false, request_stock: false, opening: true } }], can_see_cost: true }
          : { data: [{ ...outlet1, can: { operate: true, request_stock: true, opening: false } }], can_see_cost: false },
      }
    }
    if (url.includes('/stock/balances')) return { status: 200, body: balances(canSeeCost) }
    if (url.includes('/items?')) return { status: 200, body: { data: [], current_page: 1, last_page: 1, total: 0 } }
  })
}

describe('Halaman stok', () => {
  it('Kasir melihat saldo outletnya tanpa harga pokok, dengan tindakan kasir', async () => {
    api(cashierUser())
    renderWithProviders(<App />, '/stok')

    expect(await screen.findByRole('link', { name: 'Paper Bowl' })).toBeInTheDocument()
    expect(screen.getByText('Rendah')).toBeInTheDocument()
    expect(screen.getByText('Minus')).toBeInTheDocument()
    expect(screen.queryByText('Nilai stok')).not.toBeInTheDocument()
    expect(screen.queryByText('Harga pokok rata-rata')).not.toBeInTheDocument()
    const main = within(screen.getByRole('main'))
    for (const label of ['Minta stok', 'Catat waste', 'Stock opname', 'Retur ke gudang']) {
      expect(main.getByRole('link', { name: label })).toBeInTheDocument()
    }
    expect(main.queryByRole('link', { name: 'Saldo awal' })).not.toBeInTheDocument()
  })

  it('Owner memilih lokasi dan melihat nilai stok', async () => {
    api(sampleUser(), undefined, true)
    renderWithProviders(<App />, '/stok?lokasi=2')

    expect(await screen.findByText('Nilai stok')).toBeInTheDocument()
    expect(screen.getAllByText('Rp15.000').length).toBeGreaterThan(0)
    expect(screen.getByLabelText('Lokasi')).toHaveValue('2')
    const main = within(screen.getByRole('main'))
    expect(main.getByRole('link', { name: 'Saldo awal' })).toBeInTheDocument()
    expect(main.queryByRole('link', { name: 'Catat waste' })).not.toBeInTheDocument()
  })
})

describe('Pengiriman', () => {
  const transfer = (overrides: Record<string, unknown> = {}) => ({
    id: 7, number: 'TRF-261105-0001', kind: 'DISTRIBUTION', status: 'IN_TRANSIT', from_location: gudang, to_location: outlet1,
    stock_request: null, reason_code: null, notes: null, sent_by: person, sent_at: '2026-11-05T09:00:00+07:00', received_by: null, received_at: null,
    line_count: 1, unexplained_count: 0, can: { receive: true, cancel: false, resolve: false }, receive_note: null, cancelled_by: null, cancelled_at: null, cancel_note: null,
    can_see_cost: false,
    lines: [{
      id: 70, item: ref(2, 'SJ-DIMSUM', 'Frozen Dimsum', 'SEMI_FINISHED', 'pcs'), unit: unit(5, 'pak'), factor: '20.0000', sent_qty: '80.0000', sent_entered: '4.0000',
      received_qty: null, received_entered: null, difference: null, unit_cost: null, difference_value: null, needs_resolution: false, resolution: null, resolution_note: null, resolved_by: null, resolved_at: null,
    }],
    ...overrides,
  })

  it('Kasir menerima barang dengan jumlah yang benar-benar diterima', async () => {
    const fetch = api(cashierUser(), (url, init) => {
      if (url.endsWith('/stock-transfers/7/receive') && init?.method === 'POST') return { status: 200, body: { data: transfer({ status: 'RECEIVED' }) } }
      if (url.endsWith('/stock-transfers/7')) return { status: 200, body: { data: transfer() } }
    })
    renderWithProviders(<App />, '/pengiriman/7')

    const input = await screen.findByLabelText('Diterima Frozen Dimsum')
    expect(input).toHaveValue('4')
    await userEvent.clear(input)
    await userEvent.type(input, '3,5')
    await userEvent.click(screen.getByRole('button', { name: 'Konfirmasi penerimaan' }))

    await waitFor(() => {
      const call = fetch.mock.calls.find(([u, i]) => String(u).endsWith('/receive') && i?.method === 'POST')
      expect(call).toBeTruthy()
      expect(JSON.parse(String(call![1]!.body))).toEqual({ note: null, lines: [{ line_id: 70, received_qty: '3.5' }] })
    })
  })

  it('Gudang menjelaskan selisih kiriman', async () => {
    const received = transfer({
      status: 'RECEIVED', unexplained_count: 1, can: { receive: false, cancel: false, resolve: true },
      lines: [{ ...transfer().lines[0], received_qty: '75.0000', received_entered: '3.7500', difference: '5.0000', needs_resolution: true }],
    })
    api(sampleUser(), (url) => (url.endsWith('/stock-transfers/7') ? { status: 200, body: { data: received } } : undefined))
    renderWithProviders(<App />, '/pengiriman/7')

    expect(await screen.findByText('Diterima kurang 5 pcs')).toBeInTheDocument()
    const select = screen.getByLabelText('Penjelasan Frozen Dimsum')
    expect(within(select).getAllByRole('option').map((o) => o.textContent)).toEqual([
      'Kurang kirim — barang kembali ke stok pengirim',
      'Hilang / rusak di perjalanan — dicatat sebagai susut',
    ])
  })
})

describe('Stock opname', () => {
  it('Owner melihat nilai selisih dan menyetujui lewat persetujuan', async () => {
    const detail = {
      id: 4, number: 'OPN-261105-0001', location: outlet1, status: 'PENDING', approval_id: 12, created_by: { id: 2, name: 'Kasir Satu', username: 'kasir1' },
      created_at: '2026-11-05T10:00:00+07:00', applied_at: null, line_count: 1, difference_count: 1, counted_at: '2026-11-05T10:00:00+07:00', reason: 'Opname mingguan',
      decider: null, decided_at: null, decision_note: null, total_value: '-7500.00', value_is_estimate: true, can_see_cost: true,
      can: { resubmit: false, cancel: false, decide: true },
      lines: [{ item: ref(2, 'SJ-DIMSUM', 'Frozen Dimsum', 'SEMI_FINISHED', 'pcs'), unit: unit(102, 'pcs'), unit_id: 102, counted_entered: '45.0000', counted_qty: '45.0000', system_qty: '50.0000', difference: '-5.0000', value: '-7500.00' }],
    }
    const fetch = api(sampleUser(), (url, init) => {
      if (url.endsWith('/approvals/12/approve') && init?.method === 'POST') return { status: 200, body: { data: {} } }
      if (url.endsWith('/stock/counts/4')) return { status: 200, body: { data: detail } }
    }, true)
    renderWithProviders(<App />, '/stok/opname/4')

    expect(await screen.findByText('-5 pcs')).toBeInTheDocument()
    expect(screen.getByText(/perkiraan dengan harga pokok rata-rata/)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Setujui penyesuaian' }))
    await waitFor(() => expect(fetch.mock.calls.some(([u, i]) => String(u).endsWith('/approvals/12/approve') && i?.method === 'POST')).toBe(true))
  })
})
