import { ArrowRightLeft, FileText, HandCoins, PackageCheck, BookOpen, Landmark, Scale, Wallet, Boxes, ClipboardCheck, ClipboardList, Clock, CreditCard, History, Home, MapPin, PackageMinus, PackageSearch, Receipt, ReceiptText, Ruler, Send, ShoppingCart, Truck, UserCheck, Users, Warehouse } from 'lucide-react'
import type { ComponentType } from 'react'
import type { RoleCode } from '@/lib/types'

export type NavItem = {
  to: string
  label: string
  icon: ComponentType<{ className?: string }>
  roles?: RoleCode[]
  /** Tampilkan jumlah pengajuan yang perlu perhatian. */
  badge?: 'approvals'
  /** Tampilkan juga bila user dapat memutuskan persetujuan lewat delegasi (2E-Q2). */
  orApprover?: boolean
}
type NavGroup = { title: string; items: NavItem[] }

/** Menu dikelompokkan per proses bisnis; tiap peran hanya melihat menunya (REC, Master §17). */
export const NAV: NavGroup[] = [
  {
    title: 'Umum',
    items: [
      { to: '/', label: 'Beranda', icon: Home },
      { to: '/persetujuan', label: 'Persetujuan', icon: ClipboardCheck, roles: ['OWNER', 'FINANCE'], badge: 'approvals', orApprover: true },
    ],
  },
  {
    title: 'Kasir',
    items: [
      { to: '/kasir', label: 'Kasir', icon: ShoppingCart, roles: ['CASHIER'] },
      { to: '/penjualan', label: 'Riwayat penjualan', icon: ReceiptText, roles: ['CASHIER', 'OWNER', 'FINANCE'] },
      { to: '/shift', label: 'Shift kasir', icon: Clock, roles: ['CASHIER', 'OWNER', 'FINANCE'] },
    ],
  },
  {
    title: 'Kas',
    items: [
      { to: '/kas', label: 'Ringkasan kas', icon: Wallet, roles: ['OWNER', 'FINANCE', 'WAREHOUSE', 'CASHIER'] },
      { to: '/kas/serah-terima', label: 'Serah terima kas', icon: ArrowRightLeft, roles: ['OWNER', 'FINANCE', 'WAREHOUSE', 'CASHIER'] },
      { to: '/kas/biaya', label: 'Biaya', icon: Receipt, roles: ['OWNER', 'FINANCE', 'CASHIER'] },
      { to: '/kas/rekonsiliasi', label: 'Pencocokan non-tunai', icon: Landmark, roles: ['OWNER', 'FINANCE'] },
      { to: '/kas/selisih', label: 'Selisih kas', icon: Scale, roles: ['OWNER', 'FINANCE'] },
    ],
  },
  {
    title: 'Pembelian',
    items: [
      { to: '/pembelian', label: 'Purchase Request', icon: FileText, roles: ['OWNER', 'FINANCE', 'WAREHOUSE'] },
      { to: '/pembelian/po', label: 'Purchase Order', icon: PackageCheck, roles: ['OWNER', 'FINANCE', 'WAREHOUSE'] },
      { to: '/pembelian/hutang', label: 'Hutang supplier', icon: HandCoins, roles: ['OWNER', 'FINANCE'] },
    ],
  },
  {
    title: 'Stok',
    items: [
      { to: '/stok', label: 'Stok', icon: Warehouse },
      { to: '/permintaan-stok', label: 'Permintaan stok', icon: Send },
      { to: '/pengiriman', label: 'Pengiriman', icon: PackageSearch },
      { to: '/stok/opname', label: 'Stock opname', icon: ClipboardList },
      { to: '/stok/catatan', label: 'Waste & saldo awal', icon: PackageMinus },
    ],
  },
  {
    title: 'Data master',
    items: [
      { to: '/barang', label: 'Barang & menu', icon: Boxes },
      { to: '/resep', label: 'Resep', icon: BookOpen },
      { to: '/satuan', label: 'Satuan', icon: Ruler, roles: ['OWNER'] },
      { to: '/supplier', label: 'Supplier', icon: Truck, roles: ['OWNER', 'FINANCE'] },
      { to: '/metode-bayar', label: 'Metode bayar', icon: CreditCard, roles: ['OWNER'] },
      { to: '/kategori-biaya', label: 'Kategori biaya', icon: Receipt, roles: ['OWNER'] },
      { to: '/lokasi', label: 'Gudang & outlet', icon: MapPin, roles: ['OWNER'] },
    ],
  },
  {
    title: 'Pengaturan',
    items: [
      { to: '/pengguna', label: 'Pengguna & peran', icon: Users, roles: ['OWNER'] },
      { to: '/delegasi', label: 'Delegasi persetujuan', icon: UserCheck, roles: ['OWNER', 'FINANCE'] },
      { to: '/jejak-audit', label: 'Jejak audit', icon: History, roles: ['OWNER'] },
    ],
  },
]
