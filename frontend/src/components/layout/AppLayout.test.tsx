import { screen, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from '@/App'
import { cashierUser, mockFetch, sampleUser } from '@/test/fetchMock'
import { renderWithProviders } from '@/test/render'

afterEach(() => vi.unstubAllGlobals())

describe('Menu per peran', () => {
  it('Owner melihat menu pengaturan dan data master', async () => {
    mockFetch((url) => (url.endsWith('/auth/me') ? { status: 200, body: { user: sampleUser() } } : undefined))
    renderWithProviders(<App />, '/')

    const nav = await screen.findByRole('navigation', { name: 'Menu utama' })
    for (const label of ['Beranda', 'Barang & menu', 'Satuan', 'Gudang & outlet', 'Pengguna & peran', 'Jejak audit']) {
      expect(within(nav).getByRole('link', { name: label })).toBeInTheDocument()
    }
  })

  it('Kasir tidak melihat menu khusus Owner', async () => {
    mockFetch((url) => (url.endsWith('/auth/me') ? { status: 200, body: { user: cashierUser() } } : undefined))
    renderWithProviders(<App />, '/')

    const nav = await screen.findByRole('navigation', { name: 'Menu utama' })
    expect(within(nav).getByRole('link', { name: 'Barang & menu' })).toBeInTheDocument()
    expect(within(nav).queryByRole('link', { name: 'Pengguna & peran' })).not.toBeInTheDocument()
    expect(within(nav).queryByRole('link', { name: 'Jejak audit' })).not.toBeInTheDocument()
    expect(screen.getByText('Outlet 1')).toBeInTheDocument()
  })

  it('Kasir yang membuka halaman Owner melihat pesan tidak berizin', async () => {
    mockFetch((url) => (url.endsWith('/auth/me') ? { status: 200, body: { user: cashierUser() } } : undefined))
    renderWithProviders(<App />, '/pengguna')

    expect(await screen.findByRole('alert')).toHaveTextContent('tidak memiliki izin')
  })

  it('Pengunjung yang belum masuk diarahkan ke halaman login', async () => {
    mockFetch((url) => (url.endsWith('/auth/me') ? { status: 401, body: {} } : undefined))
    renderWithProviders(<App />, '/barang')

    expect(await screen.findByRole('heading', { name: 'Masuk ke POS UMKM' })).toBeInTheDocument()
  })
})
