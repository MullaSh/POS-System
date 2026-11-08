// Ambil screenshot layar utama dari aplikasi yang berjalan di Docker (dipakai CI untuk cek tampilan).
// Jalankan: node screenshots.mjs <folder-hasil>
import { chromium } from 'playwright'

const base = 'http://localhost:8080'
const out = process.argv[2] ?? 'screenshots'
const browser = await chromium.launch()

async function session(username, shots) {
  const page = await browser.newPage({ viewport: { width: 1400, height: 900 }, colorScheme: 'dark' })
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

{
  const page = await browser.newPage({ viewport: { width: 1400, height: 900 }, colorScheme: 'dark' })
  await page.goto(`${base}/login`)
  await page.waitForLoadState('networkidle')
  await page.screenshot({ path: `${out}/login.png` })
  console.log('login')
  await page.close()
}

await session('owner', [
  ['beranda', '/'],
  ['pengguna', '/pengguna'],
  ['barang-ayam', '/barang', 'tbody a:has-text("Daging Ayam")'],
  ['resep-detail', '/resep', 'tbody a:has-text("Dimsum Mentai")'],
  ['laporan', '/laporan'],
  ['laba', '/laporan/laba'],
  ['penjualan-laporan', '/laporan/penjualan'],
  ['hpp-menu', '/laporan/hpp-menu'],
  ['arus-kas', '/laporan/arus-kas'],
  ['rekonsiliasi-kas', '/laporan/rekonsiliasi-kas'],
  ['nilai-stok', '/laporan/nilai-stok'],
  ['pergerakan-stok', '/laporan/pergerakan-stok'],
  ['produksi-waste', '/laporan/produksi-waste'],
  ['stok-gudang', '/stok'],
  ['kartu-stok', '/stok/kartu', null],
  ['persetujuan', '/persetujuan'],
  ['resep', '/resep'],
  ['penjualan', '/penjualan'],
  ['penjualan-detail', '/penjualan', 'tbody a'],
  ['shift', '/shift'],
  ['delegasi', '/delegasi'],
  ['selisih', '/kas/selisih'],
  ['produksi', '/produksi'],
  ['produksi-selesai', '/produksi/1'],
])
await session('finance1', [
  ['beranda', '/'],
  ['biaya-laporan', '/laporan/biaya'],
  ['pembelian-laporan', '/laporan/pembelian'],
  ['hutang-laporan', '/laporan/hutang'],
  ['persetujuan', '/persetujuan'],
  ['kas', '/kas'],
  ['petty-cash', '/kas', 'a[href^="/kas/petty-cash"]'],
  ['rekonsiliasi', '/kas/rekonsiliasi'],
  ['pr', '/pembelian/pr'],
  ['po', '/pembelian/po'],
  ['po-detail', '/pembelian/po/1'],
  ['hutang', '/pembelian/hutang'],
])
await session('gudang1', [
  ['kas', '/kas'],
  ['permintaan', '/permintaan-stok'],
  ['permintaan-detail', '/permintaan-stok', 'tbody a'],
  ['pengiriman-baru', '/pengiriman/baru?jenis=DISTRIBUTION'],
  ['pr', '/pembelian/pr'],
  ['pr-baru', '/pembelian/pr/baru'],
  ['po-terima', '/pembelian/po/1', 'button:has-text("Terima barang")'],
  ['produksi', '/produksi'],
  ['produksi-baru', '/produksi/baru?barang=3&satuan=6&jumlah=40'],
  ['produksi-rencana', '/produksi/2'],
  ['produksi-selesaikan', '/produksi/2', 'button:has-text("Selesaikan produksi")'],
  ['permintaan-outlet2', '/permintaan-stok/2'],
  ['pr-dari-produksi', '/pembelian/pr/baru?isi=1%3A2%3A3.55&catatan=Bahan%20untuk%20produksi'],
])
await session('kasir1', [
  ['kasir', '/kasir'],
  ['shift-tutup', '/shift', 'tbody a'],
  ['penjualan', '/penjualan'],
  ['biaya', '/kas/biaya'],
  ['serah-terima-baru', '/kas/serah-terima/baru'],
  ['stok-outlet', '/stok'],
  ['opname-baru', '/stok/opname/baru'],
  ['waste-baru', '/stok/waste/baru'],
])
await session('kasir2', [
  ['pengiriman', '/pengiriman'],
  ['terima', '/pengiriman', 'tbody a'],
])
await browser.close()
