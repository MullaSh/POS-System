import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { renderWithProviders } from '@/test/render'
import { LoginPage } from './LoginPage'

function jsonResponse(status: number, body: unknown) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

describe('LoginPage', () => {
  const fetchMock = vi.fn()

  beforeEach(() => {
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
  })
  afterEach(() => vi.unstubAllGlobals())

  it('menampilkan form login berbahasa Indonesia', () => {
    fetchMock.mockResolvedValue(jsonResponse(200, { status: 'ok', database: 'ok' }))
    renderWithProviders(<LoginPage />)

    expect(screen.getByRole('heading', { name: 'Masuk ke POS UMKM' })).toBeInTheDocument()
    expect(screen.getByLabelText('Username')).toBeInTheDocument()
    expect(screen.getByLabelText('Kata sandi')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Masuk' })).toBeInTheDocument()
  })

  it('menolak form kosong tanpa memanggil server login', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, { status: 'ok', database: 'ok' }))
    renderWithProviders(<LoginPage />)

    await userEvent.click(screen.getByRole('button', { name: 'Masuk' }))

    expect(await screen.findByText('Username wajib diisi')).toBeInTheDocument()
    expect(screen.getByText('Kata sandi wajib diisi')).toBeInTheDocument()
    const loginCalls = fetchMock.mock.calls.filter(([url]) => String(url).includes('/auth/login'))
    expect(loginCalls).toHaveLength(0)
  })

  it('menampilkan pesan yang mudah dipahami bila server menolak', async () => {
    fetchMock.mockImplementation((url: string) => {
      if (url.includes('/health')) return Promise.resolve(jsonResponse(200, { status: 'ok', database: 'ok' }))
      if (url.includes('/sanctum/csrf-cookie')) return Promise.resolve(new Response(null, { status: 204 }))
      return Promise.resolve(jsonResponse(404, { message: 'Not Found' }))
    })
    renderWithProviders(<LoginPage />)

    await userEvent.type(screen.getByLabelText('Username'), 'kasir1')
    await userEvent.type(screen.getByLabelText('Kata sandi'), 'rahasia')
    await userEvent.click(screen.getByRole('button', { name: 'Masuk' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Fitur ini belum tersedia di server.')
  })

  it('menunjukkan status server terhubung', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, { status: 'ok', database: 'ok' }))
    renderWithProviders(<LoginPage />)

    expect(await screen.findByText('Server terhubung')).toBeInTheDocument()
  })
})
