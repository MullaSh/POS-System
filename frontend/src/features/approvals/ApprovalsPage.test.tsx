import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from '@/App'
import { mockFetch, sampleUser } from '@/test/fetchMock'
import { renderWithProviders } from '@/test/render'

afterEach(() => vi.unstubAllGlobals())

const pending = {
  id: 7,
  number: 'APV-261105-0001',
  type: 'ITEM_PRICE',
  status: 'PENDING',
  title: 'Harga jual Lumpia Keju 4 pcs',
  summary: 'belum ada → Rp22.000, langsung berlaku',
  subject: { type: 'item', id: 3 },
  payload: { price: 22000, effective_date: null, previous_price: null },
  reason: 'Harga awal',
  requester: { id: 2, name: 'Finance Contoh', username: 'finance1' },
  requester_role: { code: 'FINANCE', name: 'Finance' },
  submitted_at: '2026-11-05T08:00:00+07:00',
  decider: null,
  decided_at: null,
  decision_note: null,
  can: { decide: true, resubmit: false, cancel: false },
  events: null,
}

function ownerApi(onPost: (url: string, body: unknown) => void) {
  return mockFetch((url, init) => {
    if (url.endsWith('/auth/me')) return { status: 200, body: { user: sampleUser() } }
    if (url.endsWith('/approvals/summary')) return { status: 200, body: { data: { waiting_my_decision: 1, returned_to_me: 0 } } }
    if (url.includes('/approvals?status=OPEN')) return { status: 200, body: { data: [pending], current_page: 1, last_page: 1, total: 1 } }
    if (init?.method === 'POST') {
      onPost(url, init.body ? JSON.parse(String(init.body)) : undefined)
      if (url.endsWith('/reject') && !(JSON.parse(String(init.body)) as { note?: string }).note)
        return { status: 422, body: { message: 'x', errors: { note: ['Tuliskan alasan penolakan.'] } } }
      return { status: 200, body: { data: { ...pending, status: 'APPROVED' } } }
    }
  })
}

describe('Persetujuan', () => {
  it('Owner melihat pengajuan dan penanda jumlah di menu, lalu menyetujui', async () => {
    const posts: string[] = []
    ownerApi((url) => posts.push(url))
    renderWithProviders(<App />, '/persetujuan')

    expect(await screen.findByText('belum ada → Rp22.000, langsung berlaku')).toBeInTheDocument()
    const nav = screen.getByRole('navigation', { name: 'Menu utama' })
    expect(within(nav).getByLabelText('1 perlu perhatian')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Setujui' }))
    expect(posts.some((u) => u.endsWith('/approvals/7/approve'))).toBe(true)
  })

  it('penolakan meminta alasan', async () => {
    const bodies: unknown[] = []
    ownerApi((_url, body) => bodies.push(body))
    renderWithProviders(<App />, '/persetujuan')

    await userEvent.click(await screen.findByRole('button', { name: 'Tolak' }))
    await userEvent.click(screen.getByRole('button', { name: 'Tolak pengajuan' }))
    expect(await screen.findByText('Tuliskan alasan penolakan.')).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText('Alasan penolakan'), 'Terlalu mahal')
    await userEvent.click(screen.getByRole('button', { name: 'Tolak pengajuan' }))
    expect(bodies).toContainEqual({ note: 'Terlalu mahal' })
  })

  it('Finance melihat menu Supplier dan Persetujuan, tetapi tidak Satuan', async () => {
    const finance = sampleUser({
      id: 2,
      name: 'Finance Contoh',
      roles: [{ assignment_id: 2, code: 'FINANCE', name: 'Finance', scope: 'ALL_LOCATIONS', status: 'ACTIVE', locations: [] }],
    })
    mockFetch((url) => {
      if (url.endsWith('/auth/me')) return { status: 200, body: { user: finance } }
      if (url.endsWith('/approvals/summary')) return { status: 200, body: { data: { waiting_my_decision: 0, returned_to_me: 0 } } }
    })
    renderWithProviders(<App />, '/')

    const nav = await screen.findByRole('navigation', { name: 'Menu utama' })
    expect(within(nav).getByRole('link', { name: 'Supplier' })).toBeInTheDocument()
    expect(within(nav).getByRole('link', { name: 'Persetujuan' })).toBeInTheDocument()
    expect(within(nav).queryByRole('link', { name: 'Satuan' })).not.toBeInTheDocument()
    expect(within(nav).queryByRole('link', { name: 'Metode bayar' })).not.toBeInTheDocument()
  })
})
