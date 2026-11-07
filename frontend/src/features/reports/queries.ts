import { BarChart3, Boxes, Factory, HandCoins, LineChart, PackageSearch, Receipt, Scale, ShoppingBag, Truck, Wallet } from 'lucide-react'
import { useQuery } from '@tanstack/react-query'
import { useSearchParams } from 'react-router'
import { http } from '@/lib/api'
import { formatRupiah, todayWib } from '@/lib/format'

export const REPORTS_KEY = ['reports']

function shift(date: string, months: number, day: 'start' | 'end'): string {
  const [y, m] = date.split('-').map(Number)
  const d = day === 'start' ? new Date(Date.UTC(y, m - 1 + months, 1)) : new Date(Date.UTC(y, m + months, 0))
  return d.toISOString().slice(0, 10)
}

/** Pilihan cepat periode, dalam WIB. */
export function presets(today = todayWib()) {
  return [
    { key: 'today', label: 'Hari ini', from: today, to: today },
    { key: 'month', label: 'Bulan ini', from: shift(today, 0, 'start'), to: today },
    { key: 'last', label: 'Bulan lalu', from: shift(today, -1, 'start'), to: shift(today, -1, 'end') },
  ]
}

export type Mode = 'period' | 'asOf' | 'none'

/** Filter laporan disimpan di alamat halaman, sehingga bisa dibagikan atau dibuka lagi. */
export function useReportParams(mode: Mode, withLocation: boolean) {
  const [params, setParams] = useSearchParams()
  const month = presets()[1]
  const from = params.get('dari') ?? month.from
  const to = params.get('sampai') ?? month.to
  const asOf = params.get('per') ?? todayWib()
  const location = withLocation ? params.get('lokasi') ?? '' : ''
  const query = new URLSearchParams()
  if (mode === 'period') {
    query.set('from', from)
    query.set('to', to)
  }
  if (mode === 'asOf') query.set('as_of', asOf)
  if (location) query.set('location_id', location)
  const set = (patch: Record<string, string>) => {
    const next = new URLSearchParams(params)
    for (const [k, v] of Object.entries(patch)) {
      if (v) next.set(k, v)
      else next.delete(k)
    }
    setParams(next, { replace: true })
  }
  return { from, to, asOf, location, query: query.toString(), set }
}

export function useReport<T>(path: string, query: string, enabled = true) {
  return useQuery({
    queryKey: [...REPORTS_KEY, path, query],
    queryFn: () => http.get<{ data: T }>(`/reports/${path}${query ? `?${query}` : ''}`).then((r) => r.data),
    enabled,
  })
}

export const rp = (v: number | null | undefined) => formatRupiah(v ?? 0)
/** Rupiah yang mengurangi: tampil dengan tanda minus bila bukan nol. */
export const minus = (v: number) => (v === 0 ? formatRupiah(0) : formatRupiah(-v))
export const profitTone = (v: number) => (v > 0 ? 'good' : v < 0 ? 'bad' : undefined)

/** Daftar laporan MVP (dokumen 11 §3.1 nomor 12). Urutan mengikuti pertanyaan Owner: jual berapa, untung berapa, uang di mana, stok berapa. */
export const REPORTS = [
  { to: 'laba', title: 'Laba rugi', desc: 'Penjualan, HPP, laba kotor, biaya, kerugian stok, dan laba bersih per outlet.', icon: LineChart, group: 'Penjualan & laba' },
  { to: 'penjualan', title: 'Penjualan per outlet', desc: 'Transaksi, void/refund, kanal, metode bayar, dan penjualan per hari.', icon: ShoppingBag, group: 'Penjualan & laba' },
  { to: 'hpp-menu', title: 'HPP per menu', desc: 'Porsi terjual, pendapatan, HPP terkunci, dan margin tiap menu.', icon: BarChart3, group: 'Penjualan & laba' },
  { to: 'biaya', title: 'Biaya operasional', desc: 'Biaya per kategori dan lokasi; biaya yang ditolak Finance.', icon: Receipt, group: 'Penjualan & laba' },
  { to: 'arus-kas', title: 'Arus kas', desc: 'Uang masuk dan keluar, serta uang yang masih di jalan.', icon: Wallet, group: 'Kas' },
  { to: 'rekonsiliasi-kas', title: 'Rekonsiliasi kas', desc: 'Tutup shift, serah terima, pencocokan non-tunai, dan keputusan selisih.', icon: Scale, group: 'Kas' },
  { to: 'nilai-stok', title: 'Nilai stok', desc: 'Nilai persediaan per lokasi dan kelompok barang pada satu tanggal.', icon: Boxes, group: 'Stok & produksi' },
  { to: 'pergerakan-stok', title: 'Pergerakan stok', desc: 'Saldo awal, masuk, keluar, dan saldo akhir tiap barang.', icon: PackageSearch, group: 'Stok & produksi' },
  { to: 'produksi-waste', title: 'Produksi, waste & varians', desc: 'Hasil produksi, varians, waste, susut kiriman, dan selisih opname.', icon: Factory, group: 'Stok & produksi' },
  { to: 'pembelian', title: 'Pembelian', desc: 'Pesanan, penerimaan, barang ditolak, dan beda harga per supplier.', icon: Truck, group: 'Pembelian' },
  { to: 'hutang', title: 'Hutang supplier', desc: 'Sisa tagihan menurut jatuh tempo.', icon: HandCoins, group: 'Pembelian' },
] as const

