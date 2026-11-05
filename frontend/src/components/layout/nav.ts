import { BookOpen, Boxes, ClipboardCheck, CreditCard, History, Home, MapPin, Receipt, Ruler, Truck, Users } from 'lucide-react'
import type { ComponentType } from 'react'
import type { RoleCode } from '@/lib/types'

export type NavItem = {
  to: string
  label: string
  icon: ComponentType<{ className?: string }>
  roles?: RoleCode[]
  /** Tampilkan jumlah pengajuan yang perlu perhatian. */
  badge?: 'approvals'
}
type NavGroup = { title: string; items: NavItem[] }

/** Menu dikelompokkan per proses bisnis; tiap peran hanya melihat menunya (REC, Master §17). */
export const NAV: NavGroup[] = [
  {
    title: 'Umum',
    items: [
      { to: '/', label: 'Beranda', icon: Home },
      { to: '/persetujuan', label: 'Persetujuan', icon: ClipboardCheck, roles: ['OWNER', 'FINANCE'], badge: 'approvals' },
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
      { to: '/jejak-audit', label: 'Jejak audit', icon: History, roles: ['OWNER'] },
    ],
  },
]
