import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from '@/App'
import { cashierUser, mockFetch, sampleUser } from '@/test/fetchMock'
import { renderWithProviders } from '@/test/render'
import { paymentSummary } from './queries'

afterEach(() => vi.unstubAllGlobals())

const outlet = { id: 2, code: 'OTL01', name: 'Outlet 1' }
const kasir = { id: 2, name: 'Kasir Satu', username: 'kasir1' }
const shift = {
  id: 7, number: 'SHF-261106-0001', location: outlet, cashier: kasir, status: 'OPEN', opened_at: '2026-11-06T08:00:00+07:00',
  float_expected: 50000, float_counted: 50000, open_note: null, counted_cash: null, expected_cash: null, cash_difference: null,
  counted_at: null, close_note: null, float_kept: null, sales_cash: null, closed_at: null, is_mine: true,
}
const context = (withShift: boolean) => ({
  data: {
    outlets: [outlet], location: outlet, shift: withShift ? shift : null, float_expected: 50000,
    menu: [
      { id: 10, code: 'MN-DIMSUM4', name: 'Dimsum Mentai 4 pcs', price: 25000, sellable: true, problem: null },
      { id: 11, code: 'MN-EXTRA', name: 'Extra Saus Mentai', price: 5000, sellable: true, problem: null },
      { id: 12, code: 'MN-BARU', name: 'Menu Baru', price: null, sellable: false, problem: 'Belum punya harga jual' },
    ],
    payment_methods: [{ id: 1, code: 'CASH', name: 'Tunai', is_cash: true }, { id: 2, code: 'QRIS', name: 'QRIS', is_cash: false }],
  },
})

function api(user: unknown, extra: (url: string, init?: RequestInit) => { status: number; body?: unknown } | undefined = () => undefined) {
  return mockFetch((url, init) => {
    const custom = extra(url, init)
    if (custom) return custom
    if (url.endsWith('/auth/me')) return { status: 200, body: { user } }
    if (url.endsWith('/approvals/summary')) return { status: 200, body: { data: { waiting_my_decision: 0, returned_to_me: 0 } } }
  })
}

describe('Pembagian pembayaran', () => {
  const cash = new Set([1])
  it('menghitung kembalian hanya dari tunai dan menolak non-tunai berlebih', () => {
    expect(paymentSummary(25000, [{ key: 1, payment_method_id: 1, amount: 50000, reference: '' }], cash)).toMatchObject({ change: 25000, problem: null })
    expect(paymentSummary(25000, [{ key: 1, payment_method_id: 2, amount: 10000, reference: '' }, { key: 2, payment_method_id: 1, amount: 20000, reference: '' }], cash))
      .toMatchObject({ remaining: 15000, change: 5000, problem: null })
    expect(paymentSummary(25000, [{ key: 1, payment_method_id: 2, amount: 30000, reference: '' }], cash).problem).toMatch(/melebihi/)
    expect(paymentSummary(25000, [{ key: 1, payment_method_id: 1, amount: 20000, reference: '' }], cash).problem).toMatch(/kurang/)
  })
})

describe('Layar kasir', () => {
  it('Tanpa shift: kasir membuka shift dan wajib menjelaskan Cash Float yang berbeda', async () => {
    const fetch = api(cashierUser(), (url, init) => {
      if (url.includes('/pos/context')) return { status: 200, body: context(false) }
      if (url.endsWith('/shifts') && init?.method === 'POST') return { status: 201, body: { data: shift } }
    })
    const user = userEvent.setup()
    renderWithProviders(<App />, '/kasir')

    const input = await screen.findByLabelText('Cash Float yang ada di laci')
    expect(input).toHaveValue('50.000')
    await user.clear(input)
    await user.type(input, '45000')
    expect(screen.getByLabelText('Penjelasan perbedaan (wajib)')).toBeInTheDocument()
    await user.type(screen.getByLabelText('Penjelasan perbedaan (wajib)'), 'Kurang dari kemarin')
    await user.click(screen.getByRole('button', { name: 'Buka shift' }))

    await waitFor(() => {
      const call = fetch.mock.calls.find(([u, i]) => String(u).endsWith('/shifts') && i?.method === 'POST')
      expect(JSON.parse(String(call?.[1]?.body))).toEqual({ location_id: 2, float_counted: 45000, note: 'Kurang dari kemarin' })
    })
  })

  it('Dengan shift: menambah menu, bayar campur QRIS + tunai, lalu menampilkan kembalian', async () => {
    const fetch = api(cashierUser(), (url, init) => {
      if (url.includes('/pos/context')) return { status: 200, body: context(true) }
      if (url.endsWith('/sales') && init?.method === 'POST') {
        return { status: 201, body: { data: { id: 99, number: 'TRX-261106-0001', total: 55000, change_total: 5000 }, warnings: ['Stok Paper Bowl di Outlet 1 sekarang minus. Periksa stok fisik dan catat lewat stock opname.'] } }
      }
    })
    const user = userEvent.setup()
    renderWithProviders(<App />, '/kasir')

    const menu = within(await screen.findByRole('region', { name: 'Menu' }))
    await user.click(await menu.findByRole('button', { name: /Dimsum Mentai 4 pcs/ }))
    await user.click(menu.getByRole('button', { name: /Dimsum Mentai 4 pcs/ }))
    await user.click(menu.getByRole('button', { name: /Extra Saus Mentai/ }))
    expect(menu.getByRole('button', { name: /Menu Baru/ })).toBeDisabled()
    expect(screen.getByTestId('pos-total')).toHaveTextContent('Rp55.000')

    await user.click(screen.getByRole('radio', { name: 'Bawa pulang' }))
    await user.click(screen.getByRole('button', { name: /Bayar campur/ }))
    const amounts = screen.getAllByLabelText(/Uang diterima|Jumlah dibayar/)
    await user.type(amounts[1], '25000')
    await user.type(amounts[0], '35000')
    expect(screen.getByText('Kembalian:')).toHaveTextContent('Rp5.000')

    await user.click(screen.getByRole('button', { name: 'Bayar Rp55.000' }))
    const done = await screen.findByRole('status')
    expect(within(done).getByText(/TRX-261106-0001/)).toBeInTheDocument()
    expect(within(done).getByText(/sekarang minus/)).toBeInTheDocument()
    const call = fetch.mock.calls.find(([u, i]) => String(u).endsWith('/sales') && i?.method === 'POST')
    expect(JSON.parse(String(call?.[1]?.body))).toEqual({
      location_id: 2, channel: 'TAKEAWAY', customer_note: null,
      lines: [{ item_id: 10, qty: 2 }, { item_id: 11, qty: 1 }],
      payments: [{ payment_method_id: 1, amount: 35000, reference: null }, { payment_method_id: 2, amount: 25000, reference: null }],
    })
    // Keranjang kosong lagi untuk transaksi berikutnya.
    expect(screen.getByTestId('pos-total')).toHaveTextContent('Rp0')
  })

  it('Tutup shift dengan hitung buta: angka sistem baru tampil setelah hasil hitung dikirim', async () => {
    let detail: Record<string, unknown> = { ...shift, sale_count: 3, totals: null, pending_refunds: [] }
    api(cashierUser(), (url, init) => {
      if (url.endsWith('/shifts/7/count') && init?.method === 'POST') {
        detail = { ...detail, status: 'COUNTED', counted_cash: 70000, expected_cash: 75000, cash_difference: -5000 }
        return { status: 200, body: { data: detail } }
      }
      if (url.endsWith('/shifts/7')) return { status: 200, body: { data: detail } }
    })
    const user = userEvent.setup()
    renderWithProviders(<App />, '/shift/7')

    await user.type(await screen.findByLabelText('Jumlah uang tunai di laci'), '70000')
    expect(screen.queryByText('Seharusnya menurut sistem')).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Kirim hasil hitung' }))

    expect(await screen.findByText('Seharusnya menurut sistem')).toBeInTheDocument()
    expect(screen.getByLabelText('Penjelasan selisih')).toBeInTheDocument()
    expect(screen.getAllByText('-Rp5.000').length).toBeGreaterThan(0)
  })
})

describe('Delegasi', () => {
  it('Penerima delegasi melihat menu Persetujuan walau bukan Owner atau Finance', async () => {
    api({ ...cashierUser(), approval_types: ['SALE_REFUND'] }, (url) => {
      if (url.includes('/approvals?')) return { status: 200, body: { data: [], current_page: 1, last_page: 1, total: 0 } }
    })
    renderWithProviders(<App />, '/persetujuan')
    expect(await screen.findByRole('link', { name: /Persetujuan/ })).toBeInTheDocument()
    expect(await screen.findByText(/Anda menerima delegasi untuk: Void \/ refund penjualan/)).toBeInTheDocument()
  })

  it('Owner membuat delegasi dengan penerima, jenis, dan periode', async () => {
    const fetch = api(sampleUser(), (url, init) => {
      if (url.endsWith('/delegations/options')) return { status: 200, body: { data: { roles: [{ code: 'OWNER', types: ['ITEM_PRICE', 'STOCK_COUNT', 'SALE_REFUND'] }], users: [{ id: 5, name: 'Finance Contoh', username: 'finance1' }], max_days: 31 } } }
      if (url.endsWith('/delegations') && init?.method === 'POST') return { status: 201, body: { data: {} } }
      if (url.endsWith('/delegations')) return { status: 200, body: { data: [] } }
    })
    const user = userEvent.setup()
    renderWithProviders(<App />, '/delegasi')

    await user.click(await screen.findByRole('button', { name: 'Buat delegasi' }))
    await user.selectOptions(await screen.findByLabelText('Penerima'), '5')
    await user.click(screen.getByLabelText('Void / refund penjualan'))
    await user.click(screen.getByRole('button', { name: 'Simpan delegasi' }))

    await waitFor(() => {
      const call = fetch.mock.calls.find(([u, i]) => String(u).endsWith('/delegations') && i?.method === 'POST')
      const body = JSON.parse(String(call?.[1]?.body))
      expect(body).toMatchObject({ delegator_role: 'OWNER', delegate_id: 5, types: ['SALE_REFUND'] })
      expect(body.ends_at).toMatch(/\+07:00$/)
    })
  })
})
