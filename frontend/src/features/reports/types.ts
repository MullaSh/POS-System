// Bentuk data laporan (irisan 9). Semua rupiah berupa bilangan bulat; jumlah barang berupa teks desimal.

export type Period = { from: string; to: string }
type Loc = { id: number; code?: string; name: string; type?: string }

export type ProfitRow = {
  location: Loc & { type: string }
  sales_gross: number
  refunds: number
  net_sales: number
  hpp_sales: number
  hpp_refunded: number
  hpp: number
  gross_profit: number
  expenses: number
  expenses_pending: number
  waste: number
  transit_loss: number
  count_adjustment: number
  refund_made: number
  stock_loss: number
  price_difference: number
  cash_business_loss: number
  other_loss: number
  net_profit: number
  transactions: number
  refund_count: number
  hpp_incomplete: number
  expenses_pending_count: number
  price_difference_count: number
  cash_business_count: number
}
export type ProfitReport = { period: Period; rows: ProfitRow[]; total: Omit<ProfitRow, 'location'> }

export type SalesRow = {
  location: Loc
  transactions: number
  sales_gross: number
  refunds: number
  refund_count: number
  net_sales: number
  average: number
  dine_in: number
  takeaway: number
  methods: { name: string; amount: number }[]
}
export type SalesReport = {
  period: Period
  rows: SalesRow[]
  total: Omit<SalesRow, 'location' | 'methods'>
  daily: { date: string; net_sales: number; by_location: { location_id: number; net_sales: number }[] }[]
}

export type MenuHppRow = {
  item: { id: number; code: string; name: string }
  qty: number
  qty_refunded: number
  revenue: number
  hpp: number
  hpp_per_portion: number | null
  gross_profit: number
  margin_pct: number | null
  incomplete: number
}
export type MenuHppReport = { period: Period; rows: MenuHppRow[]; total: { qty: number; revenue: number; hpp: number; gross_profit: number; margin_pct: number | null; incomplete: number } }

export type ExpenseReport = {
  period: Period
  rows: { category: { id: number; name: string }; location: Loc; count: number; amount: number; pending: number }[]
  by_category: { category: { id: number; name: string }; amount: number }[]
  rejected: { id: number; number: string; date: string; amount: number; description: string; reason: string | null; location: string }[]
  total: { amount: number; pending: number; rejected: number }
}

type ItemRef = { id: number; code: string; name: string; type: string; unit: string }
export type ValuationReport = {
  as_of: string
  rows: { location: Loc; item: ItemRef; qty: string; avg_cost: string | null; value: number | null; negative: boolean }[]
  locations: { location: Loc; value: number; by_type: Record<string, number>; no_cost: number; negative: number }[]
  total: { value: number; by_type: Record<string, number>; no_cost: number; negative: number }
}

export type MovementReport = {
  period: Period
  rows: {
    location: Loc
    item: ItemRef
    opening_qty: string
    opening_value: number
    in_qty: string
    in_value: number
    out_qty: string
    out_value: number
    closing_qty: string
    closing_value: number
    by_type: Record<string, string>
  }[]
  total: { opening_value: number; in_value: number; out_value: number; closing_value: number }
}

export type ProductionWasteReport = {
  period: Period
  production: {
    id: number
    number: string
    completed_at: string
    item: { id: number; code: string; name: string; unit: string }
    planned_qty: string
    good_qty: string
    failed_qty: string
    output_variance_pct: number | null
    materials_over: number
    over_tolerance: boolean
    variance_note: string | null
    total_cost: number | null
    unit_cost: string | null
    failed_value: number | null
    cost_complete: boolean
  }[]
  production_total: { orders: number; over_tolerance: number; total_cost: number; failed_value: number }
  waste: { location: Loc; reason: string; item: { id: number; code: string; name: string; unit: string }; qty: string; value: number }[]
  waste_total: number
  transit_loss: { transfer_id: number; number: string; from: string; to: string; item: string; qty: string; value: number; note: string | null }[]
  transit_total: number
  count_adjustments: { location: Loc; short_value: number; over_value: number }[]
  refund_made: { location: Loc; portions: number; value: number }[]
}

export type CashPositions = {
  sales_cash_at_outlets: number
  shifts_not_handed_over: number
  in_transit_to_warehouse: number
  at_warehouse: number
  in_transit_to_finance: number
  noncash_unmatched: number
  noncash_unmatched_count: number
}
type FlowLine = { key: string; label: string; amount: number }
export type CashFlowReport = { period: Period; in: FlowLine[]; out: FlowLine[]; in_total: number; out_total: number; net: number; positions: CashPositions }

export type CashReconReport = {
  period: Period
  shifts: { count: number; expected: number; counted: number; difference: number; with_difference: number }
  handovers: { stage: string; label: string; count: number; expected: number; reported: number; received: number; difference: number; with_difference: number }[]
  noncash: { method: { id: number; name: string }; days: number; system: number; bank: number; difference: number }[]
  categories: Record<'OPEN' | 'STAFF' | 'BUSINESS' | 'RECORDING_ERROR' | 'NONE', { count: number; amount: number }>
  discrepancies: { subject_type: string; subject_id: number; number: string; kind: string; location: string | null; amount: number; at: string; link: string; resolution: { decision: string; category: string | null } | null }[]
  positions: CashPositions
}

export type PurchasingReport = {
  period: Period
  rows: {
    supplier: { id: number; code: string; name: string }
    orders: number
    ordered: number
    receipts: number
    received: number
    rejected_lines: number
    rejected_value: number
    invoices: number
    invoiced: number
    price_difference: number
    price_difference_count: number
    paid: number
  }[]
  differences: { id: number; number: string; invoice_no: string; date: string; supplier: string; expected: number; amount: number; difference: number; note: string | null }[]
  total: Record<string, number>
}

export type PayablesReport = {
  as_of: string
  due_soon_days: number
  buckets: { overdue_30: number; overdue: number; due_soon: number; later: number }
  total: number
  suppliers: { supplier: { id: number; name: string }; overdue_30: number; overdue: number; due_soon: number; later: number; total: number }[]
  invoices: { id: number; number: string; invoice_no: string; supplier: string; due_date: string; amount: number; paid: number; outstanding: number; bucket: string; days: number }[]
}

export type WatchGroup = 'refunds' | 'adjustments' | 'waste' | 'cancellations' | 'blocked' | 'access' | 'authority' | 'owner_direct' | 'single_person'
export type Dashboard = {
  today: string
  month: Period
  attention: {
    discrepancies: { count: number; amount: number }
    stock: { negative: number; low: number; items: { item_id: number; name: string; unit: string; location_id: number; location: string; qty: string; min_qty: string | null }[] }
    production_over_tolerance: number
    payables: { overdue: number; overdue_count: number; due_soon: number; due_soon_count: number; days: number }
    price_differences: { count: number; amount: number }
    cash_at_warehouse: number
    expenses_to_check: number
  }
  sales: { rows: { location: Loc; today: number; today_transactions: number; month: number; month_transactions: number }[]; today: number; month: number }
  profit: { net_sales: number; hpp: number; gross_profit: number; expenses: number; stock_loss: number; other_loss: number; net_profit: number; hpp_incomplete: number; expenses_pending_count: number }
  watch: {
    days: number
    counts: Record<WatchGroup, number>
    latest: { id: number; at: string; action: string; group: WatchGroup; user: string | null; location: string | null; subject_code: string | null; note: string | null; flags: string[] }[]
  }
}
