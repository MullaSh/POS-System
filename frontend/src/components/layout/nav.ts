import { Boxes, History, Home, MapPin, Ruler, Users } from 'lucide-react'
import type { ComponentType } from 'react'
import type { RoleCode } from '@/lib/types'

type NavItem = { to: string; label: string; icon: ComponentType<{ className?: string }>; roles?: RoleCode[] }
type NavGroup = { title: string; items: NavItem[] }

/** Menu dikelompokkan per proses bisnis; tiap peran hanya melihat menunya (REC, Master §17). */
export const NAV: NavGroup[] = [
  { title: 'Umum', items: [{ to: '/', label: 'Beranda', icon: Home }] },
  {
    title: 'Data master',
    items: [
      { to: '/barang', label: 'Barang & menu', icon: Boxes },
      { to: '/satuan', label: 'Satuan', icon: Ruler, roles: ['OWNER'] },
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
