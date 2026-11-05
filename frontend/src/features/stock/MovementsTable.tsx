import { Link } from 'react-router'
import { EmptyRow, Table, Td, Th } from '@/components/ui/table'
import { MOVEMENT_TYPE_LABEL, formatCost, formatDate, formatDateTime, formatQty, formatRupiah, formatSignedQty } from '@/lib/format'
import type { StockMovementRow } from '@/lib/types'
import { sourcePath } from './sourceLink'

/** Baris kartu stok. */
export function MovementsTable({ rows, canSeeCost, showItem = false }: { rows: StockMovementRow[]; canSeeCost: boolean; showItem?: boolean }) {
  return (
    <Table>
      <thead>
        <tr>
          <Th>Waktu</Th>
          {showItem && <Th>Barang</Th>}
          <Th>Jenis</Th>
          <Th>Dokumen</Th>
          <Th className="text-right">Masuk / keluar</Th>
          <Th className="text-right">Saldo</Th>
          {canSeeCost && <Th className="text-right">Harga pokok</Th>}
          {canSeeCost && <Th className="text-right">Nilai</Th>}
          <Th>Oleh</Th>
        </tr>
      </thead>
      <tbody>
        {rows.length === 0 && <EmptyRow colSpan={9}>Belum ada pergerakan stok.</EmptyRow>}
        {rows.map((m) => {
          const path = sourcePath(m.source)
          const unit = m.item.base_unit.code
          return (
            <tr key={m.id}>
              <Td className="whitespace-nowrap">{formatDateTime(m.occurred_at)}</Td>
              {showItem && <Td>{m.item.name}</Td>}
              <Td>
                {MOVEMENT_TYPE_LABEL[m.type] ?? m.type}
                {m.batch && <p className="text-xs text-muted-foreground">Batch {m.batch.batch_no}{m.batch.expiry_date ? ` · exp ${formatDate(m.batch.expiry_date)}` : ''}</p>}
                {m.note && <p className="text-xs text-muted-foreground">{m.note}</p>}
              </Td>
              <Td className="font-mono text-xs">{path ? <Link to={path} className="hover:underline">{m.source.number}</Link> : m.source.number}</Td>
              <Td className={`text-right font-medium ${Number(m.qty) < 0 ? 'text-destructive' : 'text-success'}`}>{formatSignedQty(m.qty)} {unit}</Td>
              <Td className="text-right">{formatQty(m.balance_after)} {unit}</Td>
              {canSeeCost && <Td className="text-right">{m.unit_cost === null ? '–' : formatCost(m.unit_cost)}</Td>}
              {canSeeCost && <Td className="text-right">{m.value === null ? '–' : formatRupiah(Math.round(Number(m.value)))}</Td>}
              <Td className="text-muted-foreground">{m.user?.name ?? 'Sistem'}</Td>
            </tr>
          )
        })}
      </tbody>
    </Table>
  )
}
