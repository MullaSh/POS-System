import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from '@/App'
import { cashierUser, mockFetch, sampleUser } from '@/test/fetchMock'
import { renderWithProviders } from '@/test/render'
import { presets } from './queries'

afterEach(() => vi.unstubAllGlobals())

const financeUser = () => sampleUser({ id: 3, name: 'Finance', username: 'finance1', roles: [{ assignment_id: 3, code: 'FINANCE', name: 'Finance', scope: 'ALL_LOCATIONS', status: 'ACTIVE', locations: [] }] })

const zero = {
  sales_gross: 0, refunds: 0, net_sales: 0, hpp_sales: 0, hpp_refunded: 0, hpp: 0, gross_profit: 0, expenses: 0, expenses_pending: 0, waste: 0, transit_loss: 0,
  count_adjustment: 0, refund_made: 0, stock_loss: 0, price_difference: 0, cash_business_loss: 0, other_loss: 0, net_profit: 0, transactions: 0, refund_count: 0,
  hpp_incomplete: 0, expenses_pending_count: 0, price_difference_count: 0, cash_business_count: 0,
}
const outletRow = { ...zero, location: { id: 2, code: 'OUT1', name: 'Outlet 1', type: 'OUTLET' }, sales_gross: 100000, refunds: 25000, net_sales: 75000, hpp: 30000, gross_profit: 45000, net_profit: 45000, transactions: 4 }
const whRow = { ...zero, location: { id: 1, code: 'GDG', name: 'Gudang & pusat', type: 'WAREHOUSE' }, expenses: 20000, waste: 5000, stock_loss: 5000, net_profit: -25000 }

const dashboard = {
  today: '2026-11-07', month: { from: '2026-11-01', to: '2026-11-07' },
  attention: {
    discrepancies: { count: 2, amount: -7000 },
    stock: { negative: 1, low: 1, items: [{ item_id: 5, name: 'Paper Bowl', unit: 'pcs', location_id: 2, location: 'Outlet 1', qty: '40.0000', min_qty: '50.0000' }] },
    production_over_tolerance: 1,
    payables: { overdue: 0, overdue_count: 0, due_soon: 290000, due_soon_count: 1, days: 7 },
    price_differences: { count: 0, amount: 0 }, cash_at_warehouse: 0, expenses_to_check: 0,
  },
  sales: { rows: [{ location: { id: 2, name: 'Outlet 1' }, today: 50000, today_transactions: 2, month: 75000, month_transactions: 4 }], today: 50000, month: 75000 },
  profit: { net_sales: 75000, hpp: 30000, gross_profit: 45000, expenses: 20000, stock_loss: 5000, other_loss: 0, net_profit: 20000, hpp_incomplete: 0, expenses_pending_count: 0 },
  watch: {
    days: 7,
    counts: { refunds: 1, adjustments: 0, waste: 1, cancellations: 0, blocked: 1, access: 0, authority: 0, owner_direct: 0, single_person: 0 },
    latest: [{ id: 9, at: '2026-11-07T08:00:00+07:00', action: 'CONTROL_BLOCKED', group: 'blocked', user: 'Finance', location: 'Gudang Pusat', subject_code: 'PO-1', note: 'Pembuat PO tidak boleh menerima barangnya sendiri (AB-15).', flags: [] }],
  },
}

function api(user: ReturnType<typeof sampleUser>, extra?: (url: string, init?: RequestInit) => { status: number; body?: unknown } | undefined) {
  return mockFetch((url, init) => {
    const custom = extra?.(url, init)
    if (custom) return custom
    if (url.endsWith('/auth/me')) return { status: 200, body: { user } }
    if (url.endsWith('/approvals/summary')) return { status: 200, body: { data: { waiting_my_decision: 3, returned_to_me: 0 } } }
    if (url.endsWith('/stock/locations')) return { status: 200, body: { can_see_cost: true, data: [
      { id: 1, code: 'GDG', name: 'Gudang Pusat', type: 'WAREHOUSE', status: 'ACTIVE', can: { operate: false, request_stock: false, opening: true } },
      { id: 2, code: 'OUT1', name: 'Outlet 1', type: 'OUTLET', status: 'ACTIVE', can: { operate: false, request_stock: false, opening: true } },
    ] } }
    if (url.includes('/reports/dashboard')) return { status: 200, body: { data: dashboard } }
    if (url.includes('/reports/profit')) return { status: 200, body: { data: { period: { from: '2026-11-01', to: '2026-11-07' }, rows: [outletRow, whRow], total: { ...zero, sales_gross: 100000, refunds: 25000, net_sales: 75000, hpp: 30000, gross_profit: 45000, expenses: 20000, waste: 5000, stock_loss: 5000, net_profit: 20000, transactions: 4 } } } }
  })
}

describe('Laporan dan beranda Owner', () => {
  it('Beranda Owner: persetujuan, perlu perhatian, penjualan, laba, dan pengawasan', async () => {
    api(sampleUser())
    renderWithProviders(<App />, '/')
    expect(await screen.findByText('3 pengajuan menunggu keputusan Anda.')).toBeInTheDocument()
    expect(screen.getByText(/2 selisih kas belum diputuskan Finance/)).toBeInTheDocument()
    expect(screen.getByText('1 barang stoknya minus')).toBeInTheDocument()
    expect(screen.getByText(/1 tagihan supplier jatuh tempo dalam 7 hari/)).toBeInTheDocument()
    expect(screen.getByText('Laba bersih').parentElement).toHaveTextContent('Rp20.000')
    expect(screen.getByText(/Percobaan ditolak kontrol/)).toBeInTheDocument()
    expect(screen.getByText('Ditolak kontrol pemisahan tugas')).toBeInTheDocument()
  })

  it('Laporan laba rugi: kolom per lokasi, baris Gudang & pusat, dan total', async () => {
    const fetch = api(financeUser())
    renderWithProviders(<App />, '/laporan/laba')
    const table = await screen.findByRole('table')
    expect(within(table).getByText('Gudang & pusat')).toBeInTheDocument()
    const net = within(table).getByText('Laba bersih').closest('tr')!
    expect(net).toHaveTextContent('Rp45.000')
    expect(net).toHaveTextContent('-Rp25.000')
    expect(net).toHaveTextContent('Rp20.000')
    expect(within(table).getByText('Void/refund').closest('tr')).toHaveTextContent('-Rp25.000')

    await userEvent.setup().click(screen.getByRole('button', { name: 'Bulan lalu' }))
    const last = presets()[2]
    await waitFor(() => expect(fetch.mock.calls.some(([u]) => String(u).includes(`/reports/profit?from=${last.from}&to=${last.to}`))).toBe(true))
  })

  it('Kasir tidak mendapat menu Laporan dan tidak dapat membukanya', async () => {
    api(cashierUser())
    renderWithProviders(<App />, '/laporan/laba')
    await waitFor(() => expect(screen.queryByRole('heading', { name: 'Laba rugi' })).not.toBeInTheDocument())
    expect(screen.queryByRole('link', { name: 'Laporan' })).not.toBeInTheDocument()
  })

  it('Finance wajib memilih jenis penyelesaian selisih kas', async () => {
    const fetch = api(financeUser(), (url, init) => {
      if (url.includes('/cash/discrepancies/resolve') && init?.method === 'POST') return { status: 201, body: { data: {} } }
      if (url.includes('/cash/discrepancies')) return { status: 200, body: { can_resolve: true, data: [
        { subject_type: 'cashier_shift', subject_id: 4, number: 'SHF-4', kind: 'Tutup shift', location: 'Outlet 1', person: 'Kasir Satu', amount: -2000, explanation: 'Kurang', at: '2026-11-07T10:00:00+07:00', link: '/shift/4', resolution: null },
      ] } }
    })
    const user = userEvent.setup()
    renderWithProviders(<App />, '/kas/selisih')
    await user.selectOptions(await screen.findByLabelText('Jenis penyelesaian'), 'BUSINESS')
    await user.type(screen.getByLabelText('Catatan keputusan'), 'Uang tidak ditemukan')
    await user.click(screen.getByRole('button', { name: 'Simpan keputusan' }))
    await waitFor(() => {
      const call = fetch.mock.calls.find(([u, i]) => String(u).endsWith('/cash/discrepancies/resolve') && i?.method === 'POST')
      expect(JSON.parse(String(call?.[1]?.body))).toEqual({ subject_type: 'cashier_shift', subject_id: 4, decision: 'Uang tidak ditemukan', category: 'BUSINESS' })
    })
  })
})
