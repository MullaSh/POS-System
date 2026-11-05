import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from '@/App'
import { mockFetch, sampleUser } from '@/test/fetchMock'
import { renderWithProviders } from '@/test/render'

afterEach(() => vi.unstubAllGlobals())

describe('Halaman login', () => {
  it('menampilkan form login berbahasa Indonesia', async () => {
    mockFetch((url) => (url.endsWith('/auth/me') ? { status: 401, body: { message: 'Unauthenticated.' } } : undefined))
    renderWithProviders(<App />, '/login')

    expect(await screen.findByRole('heading', { name: 'Masuk ke POS UMKM' })).toBeInTheDocument()
    expect(screen.getByLabelText('Username')).toBeInTheDocument()
    expect(screen.getByLabelText('Kata sandi')).toBeInTheDocument()
    expect(await screen.findByText('Server terhubung')).toBeInTheDocument()
  })

  it('menolak form kosong tanpa memanggil server login', async () => {
    const fetchFn = mockFetch((url) => (url.endsWith('/auth/me') ? { status: 401, body: {} } : undefined))
    renderWithProviders(<App />, '/login')

    await userEvent.click(await screen.findByRole('button', { name: 'Masuk' }))

    expect(await screen.findByText('Username wajib diisi')).toBeInTheDocument()
    expect(screen.getByText('Kata sandi wajib diisi')).toBeInTheDocument()
    expect(fetchFn.mock.calls.filter(([u]) => String(u).includes('/auth/login'))).toHaveLength(0)
  })

  it('menampilkan pesan dari server bila username atau kata sandi salah', async () => {
    mockFetch((url) => {
      if (url.endsWith('/auth/me')) return { status: 401, body: {} }
      if (url.endsWith('/auth/login'))
        return { status: 422, body: { message: 'x', errors: { username: ['Username atau kata sandi salah.'] } } }
    })
    renderWithProviders(<App />, '/login')

    await userEvent.type(await screen.findByLabelText('Username'), 'kasir1')
    await userEvent.type(screen.getByLabelText('Kata sandi'), 'salah')
    await userEvent.click(screen.getByRole('button', { name: 'Masuk' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Username atau kata sandi salah.')
  })

  it('mengarahkan ke ganti kata sandi bila masih memakai kata sandi sementara', async () => {
    let loggedIn = false
    mockFetch((url) => {
      if (url.endsWith('/auth/me'))
        return loggedIn ? { status: 200, body: { user: sampleUser({ must_change_password: true }) } } : { status: 401, body: {} }
      if (url.endsWith('/auth/login')) {
        loggedIn = true
        return { status: 200, body: { user: sampleUser({ must_change_password: true }) } }
      }
    })
    renderWithProviders(<App />, '/login')

    await userEvent.type(await screen.findByLabelText('Username'), 'owner')
    await userEvent.type(screen.getByLabelText('Kata sandi'), 'GantiSegera123')
    await userEvent.click(screen.getByRole('button', { name: 'Masuk' }))

    expect(await screen.findByRole('heading', { name: 'Ganti kata sandi' })).toBeInTheDocument()
    expect(screen.getByText(/kata sandi sementara/i)).toBeInTheDocument()
  })
})
