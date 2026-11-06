import type { ApprovalAction, ApprovalStatus, ApprovalType, DelegationStatus, RefundCondition, RefundReason, SaleChannel, SaleStatus, ShiftStatus, ItemType, LocationStatus, MovementType, RoleCode, StockRequestStatus, TransferResolution, TransferStatus } from './types'

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

const rupiah = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 })

/** Rupiah tanpa desimal, mis. 25000 → "Rp25.000" (dokumen 12 §2.3). */
export function formatRupiah(value: number | null | undefined): string {
  if (value === null || value === undefined) return '–'
  return value < 0 ? `-Rp${rupiah.format(-value)}` : `Rp${rupiah.format(value)}`
}

const dateOnly = new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'Asia/Jakarta' })

/** Tanggal saja (WIB), DD/MM/YYYY. Menerima "2026-12-01" atau waktu lengkap. */
export function formatDate(value: string | null | undefined): string {
  if (!value) return '–'
  const d = /^\d{4}-\d{2}-\d{2}$/.test(value) ? new Date(`${value}T00:00:00+07:00`) : new Date(value)
  return dateOnly.format(d)
}

/** Hari ini menurut WIB dalam format YYYY-MM-DD (untuk input tanggal). */
export function todayWib(): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(new Date())
}

/** Rupiah dengan desimal bila perlu, untuk harga pokok per gram/ml, mis. "Rp1,95". */
export function formatCost(value: string | number | null | undefined): string {
  if (value === null || value === undefined) return '–'
  return `Rp${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(Number(value))}`
}

export const APPROVAL_STATUS_LABEL: Record<ApprovalStatus, string> = {
  PENDING: 'Menunggu keputusan',
  RETURNED: 'Dikembalikan untuk diperbaiki',
  APPROVED: 'Disetujui',
  REJECTED: 'Ditolak',
  CANCELLED: 'Dibatalkan',
}

export const APPROVAL_ACTION_LABEL: Record<ApprovalAction, string> = {
  SUBMITTED: 'Diajukan',
  RESUBMITTED: 'Diajukan ulang',
  APPROVED: 'Disetujui',
  REJECTED: 'Ditolak',
  RETURNED: 'Dikembalikan',
  CANCELLED: 'Dibatalkan',
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
  ITEM_PRICE_SET: 'Harga jual ditetapkan',
  SUPPLIER_CREATED: 'Buat supplier',
  SUPPLIER_UPDATED: 'Ubah supplier',
  SUPPLIER_ACTIVATED: 'Aktifkan supplier',
  SUPPLIER_DEACTIVATED: 'Nonaktifkan supplier',
  PAYMENT_METHOD_CREATED: 'Buat metode bayar',
  PAYMENT_METHOD_UPDATED: 'Ubah metode bayar',
  PAYMENT_METHOD_ACTIVATED: 'Aktifkan metode bayar',
  PAYMENT_METHOD_DEACTIVATED: 'Nonaktifkan metode bayar',
  EXPENSE_CATEGORY_CREATED: 'Buat kategori biaya',
  EXPENSE_CATEGORY_UPDATED: 'Ubah kategori biaya',
  EXPENSE_CATEGORY_ACTIVATED: 'Aktifkan kategori biaya',
  EXPENSE_CATEGORY_DEACTIVATED: 'Nonaktifkan kategori biaya',
  APPROVAL_SUBMITTED: 'Pengajuan dibuat',
  APPROVAL_RESUBMITTED: 'Pengajuan diajukan ulang',
  APPROVAL_APPROVED: 'Pengajuan disetujui',
  APPROVAL_REJECTED: 'Pengajuan ditolak',
  APPROVAL_RETURNED: 'Pengajuan dikembalikan',
  APPROVAL_CANCELLED: 'Pengajuan dibatalkan',
  RECIPE_CREATED: 'Resep dibuat',
  RECIPE_VERSION_CREATED: 'Versi resep baru',
  STOCK_OPENING_RECORDED: 'Saldo awal stok',
  STOCK_WASTE_RECORDED: 'Waste dicatat',
  STOCK_ADJUSTMENT_APPLIED: 'Penyesuaian opname berlaku',
  STOCK_REQUEST_CREATED: 'Permintaan stok dibuat',
  STOCK_REQUEST_CANCELLED: 'Permintaan stok dibatalkan',
  STOCK_REQUEST_REJECTED: 'Permintaan stok ditolak',
  STOCK_TRANSFER_SENT: 'Barang dikirim',
  STOCK_TRANSFER_RECEIVED: 'Barang diterima',
  STOCK_TRANSFER_CANCELLED: 'Pengiriman dibatalkan',
  STOCK_TRANSFER_DIFFERENCE_RESOLVED: 'Selisih kiriman dijelaskan',
  SHIFT_OPENED: 'Buka shift',
  SHIFT_COUNTED: 'Hitung uang laci',
  SHIFT_CLOSED: 'Tutup shift',
  SALE_RECORDED: 'Transaksi penjualan',
  SALE_REFUND_APPLIED: 'Void/refund berlaku',
  DELEGATION_CREATED: 'Delegasi dibuat',
  DELEGATION_REVOKED: 'Delegasi dicabut',
}

export function auditActionLabel(action: string): string {
  return AUDIT_ACTION_LABEL[action] ?? action
}

export const MOVEMENT_TYPE_LABEL: Record<MovementType, string> = {
  OPENING: 'Saldo awal',
  PURCHASE_RECEIPT: 'Terima pembelian',
  PRODUCTION_CONSUME: 'Dipakai produksi',
  PRODUCTION_OUTPUT: 'Hasil produksi',
  TRANSFER_OUT: 'Dikirim',
  TRANSFER_IN: 'Diterima',
  TRANSFER_CANCEL: 'Kiriman dibatalkan',
  TRANSFER_SHORT_RETURN: 'Kurang kirim (kembali)',
  TRANSFER_OVER_SENT: 'Kelebihan kirim',
  SALE: 'Penjualan',
  SALE_REVERSAL: 'Pembatalan penjualan',
  WASTE: 'Waste',
  ADJUSTMENT: 'Penyesuaian opname',
  RETURN: 'Retur',
}

export const WASTE_REASON_LABEL: Record<string, string> = {
  DAMAGED: 'Rusak',
  EXPIRED: 'Kedaluwarsa',
  SPILLED: 'Jatuh / tumpah',
  QUALITY: 'Tidak lolos mutu',
  OTHER: 'Lainnya',
}

export const RETURN_REASON_LABEL: Record<string, string> = {
  EXCESS: 'Stok berlebih',
  DAMAGED: 'Rusak',
  NEAR_EXPIRY: 'Mendekati kedaluwarsa',
  OTHER: 'Lainnya',
}

export const STOCK_REQUEST_STATUS_LABEL: Record<StockRequestStatus, string> = {
  SUBMITTED: 'Menunggu gudang',
  IN_TRANSIT: 'Dalam pengiriman',
  COMPLETED: 'Selesai',
  REJECTED: 'Ditolak gudang',
  CANCELLED: 'Dibatalkan',
}

export const TRANSFER_STATUS_LABEL: Record<TransferStatus, string> = {
  IN_TRANSIT: 'Dalam perjalanan',
  RECEIVED: 'Sudah diterima',
  CANCELLED: 'Dibatalkan',
}

export const RESOLUTION_LABEL: Record<TransferResolution, string> = {
  SHORT_SHIPPED: 'Kurang kirim — barang kembali ke stok pengirim',
  LOST_IN_TRANSIT: 'Hilang / rusak di perjalanan — dicatat sebagai susut',
  OVER_SENT: 'Kelebihan kirim — stok pengirim dikurangi',
}

/** Jumlah bertanda, mis. "+10" atau "-2,5". */
export function formatSignedQty(value: string | number): string {
  const n = Number(value)
  return `${n > 0 ? '+' : ''}${formatQty(n)}`
}

export const APPROVAL_TYPE_LABEL: Record<ApprovalType, string> = {
  ITEM_PRICE: 'Perubahan harga jual',
  STOCK_COUNT: 'Penyesuaian stock opname',
  SALE_REFUND: 'Void / refund penjualan',
}

export const CHANNEL_LABEL: Record<SaleChannel, string> = {
  DINE_IN: 'Makan di tempat',
  TAKEAWAY: 'Bawa pulang',
}

export const SHIFT_STATUS_LABEL: Record<ShiftStatus, string> = {
  OPEN: 'Berjalan',
  COUNTED: 'Menunggu penjelasan selisih',
  CLOSED: 'Ditutup',
}

export const SALE_STATUS_LABEL: Record<SaleStatus, string> = {
  PAID: 'Lunas',
  PARTLY_REFUNDED: 'Sebagian di-refund',
  VOIDED: 'Dibatalkan',
}

export const REFUND_REASON_LABEL: Record<RefundReason, string> = {
  WRONG_INPUT: 'Salah input',
  CUSTOMER_CANCEL: 'Pelanggan batal',
  PRODUCT_ISSUE: 'Produk bermasalah',
  OTHER: 'Lainnya',
}

export const REFUND_CONDITION_LABEL: Record<RefundCondition, string> = {
  NOT_MADE: 'Belum dibuat — bahan kembali ke stok',
  MADE: 'Sudah dibuat / dibuang — bahan tidak kembali',
}

export const DELEGATION_STATUS_LABEL: Record<DelegationStatus, string> = {
  ACTIVE: 'Berlaku',
  SCHEDULED: 'Terjadwal',
  EXPIRED: 'Selesai',
  REVOKED: 'Dicabut',
}

/** Rupiah bertanda untuk selisih, mis. "+Rp5.000" atau "-Rp5.000". */
export function formatSignedRupiah(value: number | null | undefined): string {
  if (value === null || value === undefined) return '–'
  return value > 0 ? `+${formatRupiah(value)}` : formatRupiah(value)
}
