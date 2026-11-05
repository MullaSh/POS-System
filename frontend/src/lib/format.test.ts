import { describe, expect, it } from 'vitest'
import { toUnitPayload } from '@/features/catalog/unitRows'
import { auditActionLabel, formatDateTime, formatQty, formatRupiah } from './format'

describe('format', () => {
  it('menampilkan jumlah tanpa nol berlebih dengan pemisah Indonesia', () => {
    expect(formatQty('1000.0000')).toBe('1.000')
    expect(formatQty('0.5000')).toBe('0,5')
  })

  it('menampilkan rupiah minus dengan tanda di depan', () => {
    expect(formatRupiah(-25000)).toBe('-Rp25.000')
    expect(formatRupiah(15000)).toBe('Rp15.000')
  })

  it('menampilkan waktu dalam WIB dengan format DD/MM/YYYY', () => {
    expect(formatDateTime('2026-11-05T01:30:00Z')).toMatch(/^05\/11\/2026/)
    expect(formatDateTime(null)).toBe('–')
  })

  it('memberi label tindakan audit, atau kode aslinya bila belum dikenal', () => {
    expect(auditActionLabel('LOGIN_FAILED')).toBe('Gagal masuk')
    expect(auditActionLabel('BARU')).toBe('BARU')
  })

  it('mengabaikan baris konversi kosong dan menerima koma desimal', () => {
    expect(
      toUnitPayload([
        { unit_id: 2, factor_to_base: '1000', is_purchase_default: true },
        { unit_id: '', factor_to_base: '5', is_purchase_default: false },
        { unit_id: 3, factor_to_base: '0,5', is_purchase_default: false },
      ]),
    ).toEqual([
      { unit_id: 2, factor_to_base: 1000, is_purchase_default: true },
      { unit_id: 3, factor_to_base: 0.5, is_purchase_default: false },
    ])
  })
})
