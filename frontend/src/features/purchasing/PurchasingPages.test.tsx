import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from '@/App'
import { mockFetch, sampleUser } from '@/test/fetchMock'
import { renderWithProviders } from '@/test/render'

afterEach(() => vi.unstubAllGlobals())

const financeUser = () => sampleUser({ id: 5, name: 'Finance', username: 'finance1', roles: [{ assignment_id: 5, code: 'FINANCE', name: 'Finance', scope: 'ALL_LOCATIONS', status: 'ACTIVE', locations: [] }] })
const gudangUser = () => sampleUser({ id: 6, name: 'Gudang', username: 'gudang1', roles: [{ assignment_id: 6, code: 'WAREHOUSE', name: 'Gudang', scope: 'SELECTED_LOCATIONS', status: 'ACTIVE', locations: [{ id: 1, code: 'GDG', name: 'Gudang Pusat' }] }] })

function api(user: unknown, extra: (url: string, init?: RequestInit) => { status: number; body?: unknown } | undefined) {
  return mockFetch((url, init) => {
    const custom = extra(url, init)
    if (custom) return custom
    if (url.endsWith('/auth/me')) return { status: 200, body: { user } }
    if (url.endsWith('/approvals/summary')) return { status: 200, body: { data: { waiting_my_decision: 0, returned_to_me: 0 } } }
    if (url.includes('/purchasing/settings')) return { status: 200, body: { data: { finance_limit: 5000000, can_edit: false } } }
  })
}

const pr = {
  id: 3, number: 'PR-261106-0001', status: 'APPROVED', requested_by: { id: 6, name: 'Gudang' }, created_at: '2026-11-06T09:00:00+07:00', needed_by: null,
  reason: null, approval: null, close_note: null, orders: [], can: { order: true, close: true },
  lines: [
    { id: 21, item: { id: 1, code: 'BB-AYAM', name: 'Daging ayam' }, unit: { id: 2, code: 'kg' }, qty: '10.0000', unit_price: null, line_total: null },
    { id: 22, item: { id: 2, code: 'BB-TEPUNG', name: 'Tepung' }, unit: { id: 2, code: 'kg' }, qty: '5.0000', unit_price: null, line_total: null },
  ],
}

describe('Pembelian', () => {
  it('Finance membuat PO dari PR: harga per satuan, total, dan peringatan di atas batas Finance', async () => {
    const fetch = api(financeUser(), (url, init) => {
      if (url.endsWith('/purchasing/requests/3')) return { status: 200, body: { data: pr } }
      if (url.includes('/suppliers')) return { status: 200, body: { data: [{ id: 9, code: 'SUP-AYAM', name: 'CV Ayam', payment_term_days: 14, is_active: true }], current_page: 1, last_page: 1, total: 1 } }
      if (url.endsWith('/purchasing/orders') && init?.method === 'POST') return { status: 201, body: { data: { id: 40 } } }
    })
    const user = userEvent.setup()
    renderWithProviders(<App />, '/pembelian/po/baru?pr=3')

    await user.selectOptions(await screen.findByLabelText('Supplier'), '9')
    expect(screen.getByText(/14 hari setelah tanggal tagihan/)).toBeInTheDocument()
    await user.type(screen.getByLabelText('Harga Daging ayam per kg'), '500000')
    await user.type(screen.getByLabelText('Harga Tepung per kg'), '12000')
    expect(screen.getByText('Rp5.060.000')).toBeInTheDocument()
    expect(screen.getByText(/menunggu persetujuan Owner sebelum dipesan/)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Buat PO' }))

    await waitFor(() => {
      const call = fetch.mock.calls.find(([u, i]) => String(u).endsWith('/purchasing/orders') && i?.method === 'POST')
      expect(JSON.parse(String(call?.[1]?.body))).toEqual({
        purchase_request_id: 3, supplier_id: 9, expected_date: null, note: null,
        lines: [{ purchase_line_id: 21, qty: '10', unit_price: 500000 }, { purchase_line_id: 22, qty: '5', unit_price: 12000 }],
      })
    })
  })

  it('Gudang menerima PO tanpa melihat harga; barang ditolak wajib beralasan', async () => {
    const po = {
      id: 40, number: 'PO-261106-0001', status: 'ORDERED', request: { id: 3, number: pr.number }, supplier: { id: 9, code: 'SUP-AYAM', name: 'CV Ayam', phone: null, payment_term_days: 14 },
      total: null, expected_date: null, note: null, created_by: { id: 5, name: 'Finance' }, created_at: '2026-11-06T10:00:00+07:00', ordered_at: null,
      closed_by: null, closed_at: null, close_note: null, approval: null, receipts: [], can: { receive: true, cancel: false, close: false, invoice: false },
      lines: [{ id: 31, item: { id: 1, name: 'Daging ayam' }, unit: { id: 2, code: 'kg' }, qty: '10.0000', unit_price: null, line_total: null, remaining: '10.0000' }],
    }
    const fetch = api(gudangUser(), (url, init) => {
      if (url.endsWith('/purchasing/orders/40')) return { status: 200, body: { data: po } }
      if (url.endsWith('/purchasing/orders/40/receive') && init?.method === 'POST') return { status: 201, body: { data: po } }
    })
    const user = userEvent.setup()
    renderWithProviders(<App />, '/pembelian/po/40')

    expect(await screen.findByText('PO PO-261106-0001')).toBeInTheDocument()
    expect(screen.queryByText('Harga')).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Terima barang' }))
    const good = screen.getByLabelText('Diterima baik Daging ayam')
    expect(good).toHaveValue('10')
    await user.clear(good)
    await user.type(good, '9')
    expect(screen.getByLabelText('Alasan ditolak Daging ayam')).toBeDisabled()
    await user.type(screen.getByLabelText('Ditolak Daging ayam'), '1')
    await user.selectOptions(screen.getByLabelText('Alasan ditolak Daging ayam'), 'DAMAGED')
    await user.type(screen.getByLabelText('Kedaluwarsa Daging ayam'), '2026-11-20')
    await user.click(screen.getByRole('button', { name: 'Simpan penerimaan' }))

    await waitFor(() => {
      const call = fetch.mock.calls.find(([u, i]) => String(u).endsWith('/receive') && i?.method === 'POST')
      expect(JSON.parse(String(call?.[1]?.body))).toEqual({
        supplier_doc: null, note: null,
        lines: [{ purchase_line_id: 31, qty_accepted: '9', qty_rejected: '1', reject_reason: 'DAMAGED', reject_note: null, batch_no: null, expiry_date: '2026-11-20' }],
      })
    })
  })

  it('Finance mencatat tagihan; jumlah berbeda dari nilai diterima meminta alasan', async () => {
    const payables = {
      invoices: [], by_supplier: [], total_outstanding: 0, can_act: true,
      uninvoiced: [{ id: 7, number: 'TRM-261106-0001', po: 'PO-261106-0001', supplier: 'CV Ayam', received_at: '2026-11-06T11:00:00+07:00', accepted_value: 360000, supplier_doc: 'SJ-9', term_days: 14 }],
    }
    const fetch = api(financeUser(), (url, init) => {
      if (url.includes('/purchasing/payables')) return { status: 200, body: { data: payables } }
      if (url.endsWith('/purchasing/receipts/7/invoice') && init?.method === 'POST') return { status: 201, body: { data: { id: 1 } } }
    })
    const user = userEvent.setup()
    renderWithProviders(<App />, '/pembelian/hutang')

    await user.click(await screen.findByRole('button', { name: 'Catat tagihan' }))
    expect(screen.getByLabelText('No. tagihan / nota supplier')).toHaveValue('SJ-9')
    expect(screen.queryByLabelText('Alasan jumlah berbeda dari nilai barang diterima')).not.toBeInTheDocument()
    const amount = screen.getByLabelText('Jumlah tagihan')
    await user.clear(amount)
    await user.type(amount, '370000')
    await user.type(screen.getByLabelText('Alasan jumlah berbeda dari nilai barang diterima'), 'Harga naik')
    await user.click(screen.getByRole('button', { name: 'Simpan tagihan' }))

    await waitFor(() => {
      const call = fetch.mock.calls.find(([u, i]) => String(u).endsWith('/invoice') && i?.method === 'POST')
      expect(JSON.parse(String(call?.[1]?.body))).toMatchObject({ invoice_no: 'SJ-9', amount: 370000, difference_note: 'Harga naik' })
    })
  })
})
