import { vi } from 'vitest'

type Handler = (url: string, init?: RequestInit) => { status: number; body?: unknown } | undefined

export function json(status: number, body?: unknown) {
  return new Response(body === undefined ? null : JSON.stringify(body), {
    status,
    headers: body === undefined ? {} : { 'Content-Type': 'application/json' },
  })
}

/** Ganti fetch dengan pengendali sederhana berbasis URL. */
export function mockFetch(handler: Handler) {
  const fn = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    if (url.includes('/sanctum/csrf-cookie')) return json(204)
    if (url.includes('/health')) return json(200, { status: 'ok', database: 'ok' })
    const res = handler(url, init)
    return res ? json(res.status, res.body) : json(404, { message: 'Not Found' })
  })
  vi.stubGlobal('fetch', fn)
  return fn
}

export const sampleUser = (overrides: Record<string, unknown> = {}) => ({
  id: 1,
  name: 'Pemilik Usaha',
  username: 'owner',
  is_active: true,
  must_change_password: false,
  last_login_at: null,
  roles: [{ assignment_id: 1, code: 'OWNER', name: 'Owner', scope: 'ALL_LOCATIONS', status: 'ACTIVE', locations: [] }],
  ...overrides,
})

export const cashierUser = () =>
  sampleUser({
    id: 2,
    name: 'Kasir Satu',
    username: 'kasir1',
    roles: [
      {
        assignment_id: 2,
        code: 'CASHIER',
        name: 'Kasir',
        scope: 'SELECTED',
        status: 'ACTIVE',
        locations: [{ id: 2, code: 'OTL01', name: 'Outlet 1', type: 'OUTLET' }],
      },
    ],
  })
