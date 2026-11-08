import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from '@/App'
import { cashierUser, mockFetch, sampleUser } from '@/test/fetchMock'
import { renderWithProviders } from '@/test/render'

afterEach(() => {
  vi.unstubAllGlobals()
  localStorage.clear()
})

describe('Menu per peran', () => {
  it('Owner melihat menu pengaturan dan data master', async () => {
    mockFetch((url) => (url.endsWith('/auth/me') ? { status: 200, body: { user: sampleUser() } } : undefined))
    renderWithProviders(<App />, '/')

    const nav = await screen.findByRole('navigation', { name: 'Menu utama' })
    const user = userEvent.setup()
    await user.click(within(nav).getByRole('button', { name: 'Data master' }))
    await user.click(within(nav).getByRole('button', { name: 'Pengaturan' }))
    for (const label of ['Beranda', 'Barang & menu', 'Satuan', 'Gudang & outlet', 'Pengguna & peran', 'Jejak audit']) {
      expect(within(nav).getByRole('link', { name: label })).toBeInTheDocument()
    }
  })

  it('Kasir tidak melihat menu khusus Owner', async () => {
    mockFetch((url) => (url.endsWith('/auth/me') ? { status: 200, body: { user: cashierUser() } } : undefined))
    renderWithProviders(<App />, '/')

    const nav = await screen.findByRole('navigation', { name: 'Menu utama' })
    await userEvent.setup().click(within(nav).getByRole('button', { name: 'Data master' }))
    expect(within(nav).getByRole('link', { name: 'Barang & menu' })).toBeInTheDocument()
    expect(within(nav).queryByRole('button', { name: 'Pengaturan' })).not.toBeInTheDocument()
    expect(within(nav).queryByRole('link', { name: 'Pengguna & peran' })).not.toBeInTheDocument()
    expect(within(nav).queryByRole('link', { name: 'Jejak audit' })).not.toBeInTheDocument()
    expect(screen.getByText('Outlet 1')).toBeInTheDocument()
  })

  it('Kelompok menu tertutup, kecuali Umum dan kelompok halaman yang sedang dibuka; pilihan diingat', async () => {
    mockFetch((url) => (url.endsWith('/auth/me') ? { status: 200, body: { user: sampleUser() } } : undefined))
    const { unmount } = renderWithProviders(<App />, '/pembelian/po')

    const nav = await screen.findByRole('navigation', { name: 'Menu utama' })
    expect(within(nav).getByRole('link', { name: 'Beranda' })).toBeInTheDocument()
    expect(within(nav).getByRole('button', { name: 'Pembelian' })).toHaveAttribute('aria-expanded', 'true')
    expect(within(nav).getByRole('link', { name: 'Purchase Order' })).toBeInTheDocument()
    expect(within(nav).getByRole('button', { name: 'Stok' })).toHaveAttribute('aria-expanded', 'false')
    expect(within(nav).queryByRole('link', { name: 'Stock opname' })).not.toBeInTheDocument()

    const user = userEvent.setup()
    await user.click(within(nav).getByRole('button', { name: 'Stok' }))
    expect(within(nav).getByRole('link', { name: 'Stock opname' })).toBeInTheDocument()
    await user.click(within(nav).getByRole('button', { name: 'Pembelian' }))
    expect(within(nav).queryByRole('link', { name: 'Purchase Order' })).not.toBeInTheDocument()

    unmount()
    renderWithProviders(<App />, '/')
    const again = await screen.findByRole('navigation', { name: 'Menu utama' })
    expect(within(again).getByRole('link', { name: 'Stock opname' })).toBeInTheDocument()
    expect(within(again).queryByRole('link', { name: 'Purchase Order' })).not.toBeInTheDocument()
  })

  it('Kasir yang membuka halaman Owner melihat pesan tidak berizin', async () => {
    mockFetch((url) => (url.endsWith('/auth/me') ? { status: 200, body: { user: cashierUser() } } : undefined))
    renderWithProviders(<App />, '/pengguna')

    expect(await screen.findByRole('alert')).toHaveTextContent('tidak memiliki izin')
  })

  it('Pengunjung yang belum masuk diarahkan ke halaman login', async () => {
    mockFetch((url) => (url.endsWith('/auth/me') ? { status: 401, body: {} } : undefined))
    renderWithProviders(<App />, '/barang')

    expect(await screen.findByRole('heading', { name: 'Masuk ke POS' })).toBeInTheDocument()
  })
})
