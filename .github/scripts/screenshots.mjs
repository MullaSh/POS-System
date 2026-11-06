// Ambil screenshot layar utama dari aplikasi yang berjalan di Docker (dipakai CI untuk cek tampilan).
// Jalankan: node screenshots.mjs <folder-hasil>
import { chromium } from 'playwright'

const base = 'http://localhost:8080'
const out = process.argv[2] ?? 'screenshots'
const browser = await chromium.launch()

async function session(username, shots) {
  const page = await browser.newPage({ viewport: { width: 1400, height: 900 } })
  await page.goto(`${base}/login`)
  await page.fill('#username', username)
  await page.fill('#password', 'demo12345')
  await page.click('button[type=submit]')
  await page.waitForURL((u) => !u.pathname.startsWith('/login'))
  for (const [name, path, click] of shots) {
    await page.goto(`${base}${path}`)
    await page.waitForLoadState('networkidle')
    if (click) {
      await page.locator(click).first().click()
      await page.waitForLoadState('networkidle')
    }
    await page.waitForTimeout(300)
    await page.screenshot({ path: `${out}/${username}-${name}.png`, fullPage: true })
    console.log(`${username}-${name}: ${page.url()}`)
  }
  await page.close()
}

await session('owner', [
  ['stok-gudang', '/stok'],
  ['kartu-stok', '/stok/kartu', null],
  ['persetujuan', '/persetujuan'],
  ['resep', '/resep'],
  ['penjualan', '/penjualan'],
  ['penjualan-detail', '/penjualan', 'tbody a'],
  ['shift', '/shift'],
  ['delegasi', '/delegasi'],
])
await session('finance1', [
  ['persetujuan', '/persetujuan'],
])
await session('gudang1', [
  ['permintaan', '/permintaan-stok'],
  ['permintaan-detail', '/permintaan-stok', 'tbody a'],
  ['pengiriman-baru', '/pengiriman/baru?jenis=DISTRIBUTION'],
])
await session('kasir1', [
  ['kasir', '/kasir'],
  ['shift-tutup', '/shift', 'tbody a'],
  ['penjualan', '/penjualan'],
  ['stok-outlet', '/stok'],
  ['opname-baru', '/stok/opname/baru'],
  ['waste-baru', '/stok/waste/baru'],
])
await session('kasir2', [
  ['pengiriman', '/pengiriman'],
  ['terima', '/pengiriman', 'tbody a'],
])
await browser.close()
