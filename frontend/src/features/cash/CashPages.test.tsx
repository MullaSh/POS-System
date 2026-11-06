import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from '@/App'
import { cashierUser, mockFetch, sampleUser } from '@/test/fetchMock'
import { renderWithProviders } from '@/test/render'

afterEach(() => vi.unstubAllGlobals())

const outlet = { id: 2, code: 'OTL01', name: 'Outlet 1', type: 'OUTLET' }
const overview = {
  data: {
    outlets: [{ location: outlet, cash_float: 50000, cash_float_target: 50000, shift_running: false, sales_cash_at_outlet: 400000, shifts_to_hand_over: 2, sales_cash_in_transit: 0, petty_cash: 150000, petty_cash_target: 200000, expenses_to_check: 2, refill_requested: false }],
    warehouse: null, sees_all: false, open_discrepancies: null,
  },
}
const financeUser = () => sampleUser({ id: 5, name: 'Finance', username: 'finance1', roles: [{ assignment_id: 5, code: 'FINANCE', name: 'Finance', scope: 'ALL_LOCATIONS', status: 'ACTIVE', locations: [] }] })

function api(user: unknown, extra: (url: string, init?: RequestInit) => { status: number; body?: unknown } | undefined) {
  return mockFetch((url, init) => {
    const custom = extra(url, init)
    if (custom) return custom
    if (url.endsWith('/auth/me')) return { status: 200, body: { user } }
    if (url.endsWith('/approvals/summary')) return { status: 200, body: { data: { waiting_my_decision: 0, returned_to_me: 0 } } }
    if (url.endsWith('/cash/overview')) return { status: 200, body: overview }
  })
}

describe('Kas', () => {
  it('Kasir menyerahkan Sales Cash dari shift yang dipilih; jumlah berbeda meminta penjelasan', async () => {
    const fetch = api(cashierUser(), (url, init) => {
      if (url.includes('/cash/handovers/pending')) {
        return { status: 200, body: { data: { location: outlet, can_send: true, handovers: [], total: 400000, shifts: [
          { id: 11, number: 'SHF-1', cashier: { id: 2, name: 'Kasir Satu' }, closed_at: '2026-11-06T15:00:00+07:00', sales_cash: 300000 },
          { id: 12, number: 'SHF-2', cashier: { id: 2, name: 'Kasir Satu' }, closed_at: '2026-11-06T21:00:00+07:00', sales_cash: 100000 },
        ] } } }
      }
      if (url.endsWith('/cash/handovers') && init?.method === 'POST') return { status: 201, body: { data: { id: 40 } } }
    })
    const user = userEvent.setup()
    renderWithProviders(<App />, '/kas/serah-terima/baru')

    expect(await screen.findByText('Rp400.000')).toBeInTheDocument()
    await user.click(screen.getByRole('checkbox', { name: /SHF-2/ }))
    expect(screen.getByText('Seharusnya diserahkan:')).toHaveTextContent('Rp300.000')
    const amount = screen.getByLabelText('Uang yang diserahkan')
    await user.clear(amount)
    await user.type(amount, '295000')
    expect(screen.getByLabelText('Penjelasan perbedaan (wajib)')).toBeInTheDocument()
    await user.type(screen.getByLabelText('Penjelasan perbedaan (wajib)'), 'Kurang 5 ribu')
    await user.click(screen.getByRole('button', { name: 'Kirim serah terima' }))

    await waitFor(() => {
      const call = fetch.mock.calls.find(([u, i]) => String(u).endsWith('/cash/handovers') && i?.method === 'POST')
      expect(JSON.parse(String(call?.[1]?.body))).toEqual({ location_id: 2, shift_ids: [11], reported_amount: 295000, note: 'Kurang 5 ribu' })
    })
  })

  it('Finance memeriksa biaya Petty Cash dan melihat jumlah isi ulang sebelum mengisi', async () => {
    const expense = (id: number, amount: number) => ({ id, number: `BYA-${id}`, location: outlet, category: { id: 1, code: 'GAS', name: 'Gas' }, amount, description: 'Gas', evidence_note: null, paid_from: 'PETTY_CASH', status: 'RECORDED', business_date: '2026-11-06', recorded_by: null, created_at: '', decided_by: null, decided_at: null, decision_note: null, can: { cancel: false } })
    const fetch = api(financeUser(), (url, init) => {
      if (url.endsWith('/cash/petty-cash/2/refill') && init?.method === 'POST') return { status: 200, body: { data: { location: outlet, target: 200000, balance: 180000, pending: [], request: null, movements: [], can: { record: false, request: false, refill: true, set_target: false } } } }
      if (url.endsWith('/cash/petty-cash/2')) return { status: 200, body: { data: { location: outlet, target: 200000, balance: 150000, pending: [expense(7, 30000), expense(8, 20000)], request: null, movements: [], can: { record: false, request: false, refill: true, set_target: false } } } }
    })
    const user = userEvent.setup()
    renderWithProviders(<App />, '/kas/petty-cash/2')

    const button = await screen.findByRole('button', { name: 'Isi ulang Petty Cash' })
    expect(button).toBeDisabled()
    await user.selectOptions(screen.getByLabelText('Keputusan BYA-7'), 'VERIFIED')
    await user.selectOptions(screen.getByLabelText('Keputusan BYA-8'), 'REJECTED')
    await user.type(screen.getByLabelText('Alasan ditolak BYA-8'), 'Tanpa nota')
    expect(screen.getByText('Akan diisi:')).toHaveTextContent('Rp30.000')
    await user.click(button)

    await waitFor(() => {
      const call = fetch.mock.calls.find(([u, i]) => String(u).endsWith('/refill') && i?.method === 'POST')
      expect(JSON.parse(String(call?.[1]?.body))).toEqual({ decisions: { 7: { decision: 'VERIFIED', note: null }, 8: { decision: 'REJECTED', note: 'Tanpa nota' } } })
    })
  })

  it('Owner melihat selisih tetapi keputusan hanya oleh Finance', async () => {
    api(sampleUser(), (url) => {
      if (url.includes('/cash/discrepancies')) return { status: 200, body: { can_resolve: false, data: [
        { subject_type: 'cash_handover', subject_id: 1, number: 'STK-1', kind: 'Serah terima outlet → gudang', location: 'Outlet 1', person: 'Gudang', amount: -5000, explanation: 'Kurang selembar', at: '2026-11-06T10:00:00+07:00', link: '/kas/serah-terima/1', resolution: null },
      ] } }
    })
    renderWithProviders(<App />, '/kas/selisih')
    expect(await screen.findByText('-Rp5.000')).toBeInTheDocument()
    expect(screen.getByText('Menunggu keputusan Finance.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Simpan keputusan' })).not.toBeInTheDocument()
  })
})
