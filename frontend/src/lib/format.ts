import type { ItemType, LocationStatus, RoleCode } from './types'

const dateTime = new Intl.DateTimeFormat('id-ID', {
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
  timeZone: 'Asia/Jakarta',
})

/** Tanggal dan jam WIB, format DD/MM/YYYY HH.mm. */
export function formatDateTime(value: string | null | undefined): string {
  if (!value) return '–'
  return dateTime.format(new Date(value))
}

const qty = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 4 })

/** Jumlah tanpa nol berlebih, mis. "1000.0000" → "1.000", "0.5000" → "0,5". */
export function formatQty(value: string | number): string {
  return qty.format(Number(value))
}

export const ROLE_LABEL: Record<RoleCode, string> = {
  OWNER: 'Owner',
  FINANCE: 'Finance',
  WAREHOUSE: 'Gudang',
  CASHIER: 'Kasir',
}

export const ITEM_TYPE_LABEL: Record<ItemType, string> = {
  RAW: 'Bahan baku',
  SEMI_FINISHED: 'Barang setengah jadi',
  SUPPORTING: 'Bahan pendukung',
  PACKAGING: 'Packaging',
  MENU: 'Menu',
}

export const LOCATION_STATUS_LABEL: Record<LocationStatus, string> = {
  ACTIVE: 'Aktif',
  INACTIVE: 'Nonaktif sementara',
  CLOSED: 'Ditutup',
}

export const AUDIT_ACTION_LABEL: Record<string, string> = {
  LOGIN: 'Masuk',
  LOGOUT: 'Keluar',
  LOGIN_FAILED: 'Gagal masuk',
  LOGIN_REJECTED_INACTIVE: 'Masuk ditolak (akun nonaktif)',
  PASSWORD_CHANGED: 'Ganti kata sandi',
  USER_CREATED: 'Buat user',
  USER_UPDATED: 'Ubah user',
  USER_DEACTIVATED: 'Nonaktifkan user',
  USER_ACTIVATED: 'Aktifkan user',
  USER_PASSWORD_RESET: 'Atur ulang kata sandi',
  ROLE_ASSIGNED: 'Beri peran',
  ROLE_REVOKED: 'Cabut peran',
  OWNER_ACCOUNT_CREATED: 'Akun Owner dibuat',
  LOCATION_CREATED: 'Lokasi dibuat',
  OUTLET_OPENED: 'Buka outlet',
  LOCATION_RENAMED: 'Ganti nama lokasi',
  OUTLET_DEACTIVATED: 'Nonaktifkan outlet',
  OUTLET_ACTIVATED: 'Aktifkan outlet',
  OUTLET_CLOSED: 'Tutup outlet',
  UNIT_CREATED: 'Buat satuan',
  UNIT_RENAMED: 'Ganti nama satuan',
  ITEM_CREATED: 'Buat barang',
  ITEM_UPDATED: 'Ubah barang',
  ITEM_ACTIVATED: 'Aktifkan barang',
  ITEM_DEACTIVATED: 'Nonaktifkan barang',
  ITEM_UNITS_CHANGED: 'Ubah konversi satuan',
  ITEM_MIN_STOCK_CHANGED: 'Ubah stok minimum',
}

export function auditActionLabel(action: string): string {
  return AUDIT_ACTION_LABEL[action] ?? action
}
