export type UnitRow = { unit_id: number | ''; factor_to_base: string; is_purchase_default: boolean }

/** Ubah isi editor konversi menjadi data untuk API; baris kosong diabaikan. */
export function toUnitPayload(rows: UnitRow[]) {
  return rows
    .filter((r) => r.unit_id !== '' && r.factor_to_base.trim() !== '')
    .map((r) => ({
      unit_id: Number(r.unit_id),
      factor_to_base: Number(r.factor_to_base.replace(',', '.')),
      is_purchase_default: r.is_purchase_default,
    }))
}
