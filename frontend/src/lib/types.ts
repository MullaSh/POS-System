export type RoleCode = 'OWNER' | 'FINANCE' | 'WAREHOUSE' | 'CASHIER'
export type LocationType = 'WAREHOUSE' | 'OUTLET'
export type LocationStatus = 'ACTIVE' | 'INACTIVE' | 'CLOSED'
export type ItemType = 'RAW' | 'SEMI_FINISHED' | 'SUPPORTING' | 'PACKAGING' | 'MENU'

export type LocationRef = { id: number; code: string; name: string; type: LocationType }

export type RoleAssignment = {
  assignment_id: number
  code: RoleCode
  name: string
  scope: 'ALL_LOCATIONS' | 'SELECTED'
  status: 'PENDING' | 'ACTIVE'
  locations: LocationRef[]
}

export type User = {
  id: number
  name: string
  username: string
  is_active: boolean
  must_change_password: boolean
  last_login_at: string | null
  roles: RoleAssignment[]
  /** Jenis persetujuan yang dapat diputuskan user ini, termasuk lewat delegasi (hanya dari /auth/me). */
  approval_types?: ApprovalType[]
}

export type Location = LocationRef & {
  status: LocationStatus
  opened_at: string | null
  closed_at: string | null
}

export type Unit = { id: number; code: string; name: string }

export type ItemSummary = {
  id: number
  code: string
  name: string
  item_type: ItemType
  is_active: boolean
  base_unit: Unit
  current_price?: number | null
}

export type ItemUnit = {
  unit_id: number
  code: string
  name: string
  factor_to_base: string
  is_purchase_default: boolean
  is_usage_default: boolean
}

export type ItemDetail = ItemSummary & {
  is_stock_item: boolean
  notes: string | null
  units: ItemUnit[]
  min_stocks: { location_id: number; location_code: string; location_name: string; min_qty: string }[]
  price: ItemPriceInfo | null
}

export type ItemPriceRow = {
  id: number
  /** Jadwal yang digantikan keputusan lebih baru sebelum berlaku. */
  superseded: boolean
  price: number
  effective_from: string
  set_by: { id: number; name: string } | null
  approval_number: string | null
  reason: string | null
  created_at: string
}

export type PriceProposal = { price: number; effective_date: string | null; previous_price: number | null }

export type ItemPriceInfo = {
  current: ItemPriceRow | null
  upcoming: ItemPriceRow[]
  history: ItemPriceRow[]
  open_request: {
    id: number
    number: string
    status: ApprovalStatus
    payload: PriceProposal
    requester: { id: number; name: string } | null
  } | null
}

export type Supplier = {
  id: number
  code: string
  name: string
  contact_name: string | null
  phone: string | null
  address: string | null
  payment_term_days: number
  bank_account: string | null
  notes: string | null
  is_active: boolean
}

export type SimpleMaster = { id: number; code: string; name: string; is_active: boolean; is_cash?: boolean }

export type ApprovalStatus = 'PENDING' | 'RETURNED' | 'APPROVED' | 'REJECTED' | 'CANCELLED'
export type ApprovalAction = 'SUBMITTED' | 'RESUBMITTED' | 'APPROVED' | 'REJECTED' | 'RETURNED' | 'CANCELLED'

type PersonRef = { id: number; name: string; username: string }

export type ApprovalType = 'ITEM_PRICE' | 'STOCK_COUNT' | 'SALE_REFUND' | 'PURCHASE_REQUEST' | 'PURCHASE_ORDER'

export type Approval = {
  id: number
  number: string
  type: ApprovalType
  status: ApprovalStatus
  title: string
  summary: string
  subject: { type: string; id: number }
  payload: PriceProposal & Record<string, unknown>
  reason: string | null
  requester: PersonRef | null
  requester_role: { code: RoleCode; name: string } | null
  submitted_at: string
  decider: PersonRef | null
  decided_at: string | null
  decision_note: string | null
  delegation?: { number: string; delegator: { id: number; name: string } | null } | null
  can: { decide: boolean; resubmit: boolean; cancel: boolean }
  events: { action: ApprovalAction; user: PersonRef | null; note: string | null; created_at: string }[] | null
}

export type AuditLog = {
  id: number
  recorded_at: string
  occurred_at: string | null
  action: string
  subject_type: string | null
  subject_id: number | null
  subject_code: string | null
  changes: Record<string, unknown> | null
  reason: string | null
  flags: string[]
  ip_address: string | null
  user: { id: number; name: string; username: string } | null
  role: { id: number; code: RoleCode; name: string } | null
  location: { id: number; code: string; name: string } | null
}

export type Paginated<T> = {
  data: T[]
  current_page: number
  last_page: number
  total: number
}

export type ItemRef = { id: number; code: string; name: string; item_type: ItemType; is_active: boolean; base_unit: Unit }

/** Barang untuk pilihan bahan resep, lengkap dengan konversi satuannya. */
export type ItemWithUnits = ItemSummary & {
  units: { unit_id: number; factor_to_base: string; unit: Unit }[]
}

export type RecipeKind = 'MENU' | 'PRODUCTION'
export type CostSource = 'STOCK_AVERAGE' | 'FROM_RECIPE' | null

export type RecipeSummary = {
  id: number
  kind: RecipeKind
  output_item: ItemRef
  current: { version_no: number; effective_from: string; line_count: number; output_qty: string } | null
  upcoming_count: number
  cost: { total: string | null; per_output_unit: string | null; missing_count: number } | null
}

export type RecipeLine = {
  item: ItemRef
  qty: string
  entered_qty: string
  unit: Unit
  unit_cost: string | null
  line_cost: string | null
  cost_source: CostSource
}

export type RecipeVersion = {
  id: number
  version_no: number
  effective_from: string
  status: 'CURRENT' | 'UPCOMING' | 'PAST' | 'SUPERSEDED'
  output_qty: string
  output_entered_qty: string
  output_unit: Unit
  notes: string | null
  created_by: { id: number; name: string } | null
  created_at: string
  lines: RecipeLine[]
  cost: { total: string | null; per_output_unit: string | null; missing: string[] } | null
}

export type RecipeDetail = {
  id: number
  kind: RecipeKind
  output_item: ItemRef
  can_see_cost: boolean
  versions: RecipeVersion[]
}

// ---- Stok (irisan 4) ----

export type StockLocation = LocationRef & {
  status: LocationStatus
  can: { operate: boolean; request_stock: boolean; opening: boolean }
}

export type StockBalanceRow = {
  item: ItemRef
  qty: string
  min_qty: string | null
  is_negative: boolean
  is_low: boolean
  has_movements: boolean
  last_movement_at: string | null
  avg_cost: string | null
  value: string | null
}

export type StockBalances = {
  data: StockBalanceRow[]
  summary: { item_count: number; low_count: number; negative_count: number; total_value: string | null }
  can_see_cost: boolean
}

export type MovementType =
  | 'OPENING' | 'PURCHASE_RECEIPT' | 'PRODUCTION_CONSUME' | 'PRODUCTION_OUTPUT'
  | 'TRANSFER_OUT' | 'TRANSFER_IN' | 'TRANSFER_CANCEL' | 'TRANSFER_SHORT_RETURN' | 'TRANSFER_OVER_SENT'
  | 'SALE' | 'SALE_REVERSAL' | 'WASTE' | 'ADJUSTMENT' | 'RETURN'

export type StockMovementRow = {
  id: number
  type: MovementType
  item: ItemRef
  location: LocationRef
  batch: { id: number; batch_no: string; expiry_date: string | null } | null
  qty: string
  balance_after: string
  unit_cost: string | null
  value: string | null
  avg_cost_after: string | null
  source: { type: string; id: number; number: string }
  note: string | null
  user: { id: number; name: string; username: string } | null
  business_date: string
  occurred_at: string
}

export type StockBatchRow = { id: number; item_id: number; batch_no: string; expiry_date: string | null; qty_remaining: string; source: string }

type Person = { id: number; name: string; username: string }

export type StockEntry = {
  id: number
  number: string
  kind: 'OPENING' | 'WASTE'
  location: LocationRef
  reason_code: string | null
  notes: string | null
  created_by: Person | null
  created_at: string
  line_count: number
  movements?: StockMovementRow[]
  can_see_cost?: boolean
}

export type StockCountSummary = {
  id: number
  number: string
  location: LocationRef
  status: ApprovalStatus | null
  approval_id: number | null
  created_by: Person | null
  created_at: string
  applied_at: string | null
  line_count: number
  difference_count: number
}

export type StockCountDetail = StockCountSummary & {
  counted_at: string | null
  reason: string | null
  decider: Person | null
  decided_at: string | null
  decision_note: string | null
  lines: {
    item: ItemRef
    unit: Unit
    unit_id: number
    counted_entered: string
    counted_qty: string
    system_qty: string
    difference: string
    value: string | null
  }[]
  total_value: string | null
  value_is_estimate: boolean
  can_see_cost: boolean
  can: { resubmit: boolean; cancel: boolean; decide: boolean }
}

export type StockRequestStatus = 'SUBMITTED' | 'IN_TRANSIT' | 'COMPLETED' | 'REJECTED' | 'CANCELLED'

export type StockRequestSummary = {
  id: number
  number: string
  location: LocationRef
  status: StockRequestStatus
  needed_date: string | null
  notes: string | null
  requested_by: Person | null
  created_at: string
  closed_by: Person | null
  closed_at: string | null
  close_note: string | null
  line_count: number
  transfer: { id: number; number: string; status: TransferStatus } | null
  can: { fulfil: boolean; reject: boolean; cancel: boolean }
}

export type StockRequestDetail = StockRequestSummary & {
  lines: { item: ItemRef; unit: Unit; factor: string; qty: string; entered_qty: string; warehouse_qty: string | null }[]
}

export type TransferStatus = 'IN_TRANSIT' | 'RECEIVED' | 'CANCELLED'
export type TransferKind = 'DISTRIBUTION' | 'RETURN'
export type TransferResolution = 'SHORT_SHIPPED' | 'LOST_IN_TRANSIT' | 'OVER_SENT'

export type StockTransferSummary = {
  id: number
  number: string
  kind: TransferKind
  status: TransferStatus
  from_location: LocationRef
  to_location: LocationRef
  stock_request: { id: number; number: string } | null
  reason_code: string | null
  notes: string | null
  sent_by: Person | null
  sent_at: string
  received_by: Person | null
  received_at: string | null
  line_count: number
  unexplained_count: number
  can: { receive: boolean; cancel: boolean; resolve: boolean }
}

export type StockTransferLine = {
  id: number
  item: ItemRef
  unit: Unit
  factor: string
  sent_qty: string
  sent_entered: string
  received_qty: string | null
  received_entered: string | null
  difference: string | null
  unit_cost: string | null
  difference_value: string | null
  needs_resolution: boolean
  resolution: TransferResolution | null
  resolution_note: string | null
  resolved_by: Person | null
  resolved_at: string | null
}

export type StockTransferDetail = StockTransferSummary & {
  receive_note: string | null
  cancelled_by: Person | null
  cancelled_at: string | null
  cancel_note: string | null
  can_see_cost: boolean
  lines: StockTransferLine[]
}

// --- POS (irisan 5) ---

export type SaleChannel = 'DINE_IN' | 'TAKEAWAY'
export type ShiftStatus = 'OPEN' | 'COUNTED' | 'CLOSED'
export type SaleStatus = 'PAID' | 'PARTLY_REFUNDED' | 'VOIDED'
export type RefundCondition = 'NOT_MADE' | 'MADE'
export type RefundReason = 'WRONG_INPUT' | 'CUSTOMER_CANCEL' | 'PRODUCT_ISSUE' | 'OTHER'
type OutletRef = { id: number; code: string; name: string }

export type Shift = {
  id: number
  number: string
  location: OutletRef
  cashier: PersonRef | null
  status: ShiftStatus
  opened_at: string
  float_expected: number
  float_counted: number
  open_note: string | null
  counted_cash: number | null
  expected_cash: number | null
  cash_difference: number | null
  counted_at: string | null
  close_note: string | null
  float_kept: number | null
  sales_cash: number | null
  closed_at: string | null
  sale_count?: number
  is_mine?: boolean
}

export type ShiftDetail = Shift & {
  is_mine: boolean
  sale_count: number
  totals: {
    sales: number
    cash_in: number
    cash_out: number
    by_method: { payment_method_id: number; name: string | null; is_cash: boolean; amount: number; count: number; refunded: number }[]
  } | null
  pending_refunds: { id: number; number: string; sale_id: number }[]
}

export type PosMenuItem = { id: number; code: string; name: string; price: number | null; sellable: boolean; problem: string | null }
export type PosPaymentMethod = { id: number; code: string; name: string; is_cash: boolean }

export type PosContext = {
  outlets: OutletRef[]
  location: OutletRef | null
  shift: (Shift & { is_mine: boolean }) | null
  float_expected: number
  menu: PosMenuItem[]
  payment_methods: PosPaymentMethod[]
}

export type SaleSummary = {
  id: number
  number: string
  location: OutletRef
  shift_id: number
  cashier: PersonRef | null
  channel: SaleChannel
  total: number
  paid_at: string
  business_date: string
  customer_note: string | null
  refunded_amount: number
  status: SaleStatus
  has_pending_refund: boolean
  methods: string[]
  hpp_total: string | null
}

export type SaleRefundRow = {
  id: number
  number: string
  kind: 'VOID' | 'REFUND' | null
  amount: number | null
  reason_code: RefundReason
  reason: string | null
  requested_by: PersonRef | null
  created_at: string
  applied_at: string | null
  approval: {
    id: number
    number: string
    status: ApprovalStatus
    decider: PersonRef | null
    decided_at: string | null
    decision_note: string | null
    delegation: { number: string; delegator: { id: number; name: string } | null } | null
  } | null
  lines: { sale_line_id: number; item_name: string; qty: number; amount: number; condition: RefundCondition }[]
  payments: { payment_method_id: number; method_name: string; is_cash: boolean; amount: number }[]
  can_cancel: boolean
}

export type SaleDetail = SaleSummary & {
  subtotal: number
  discount_total: number
  tax_total: number
  paid_total: number
  change_total: number
  hpp_complete: boolean | null
  shift: { id: number; number: string; status: ShiftStatus } | null
  lines: {
    id: number
    item: { id: number; code: string; name: string }
    qty: number
    unit_price: number
    line_total: number
    refunded_qty: number
    recipe_version: { id: number; version_no: number } | null
    unit_hpp: string | null
    hpp_total: string | null
    components: { item: { id: number; code: string; name: string }; base_unit: string | null; qty: string; unit_cost: string | null; cost_source: string | null }[]
  }[]
  payments: { payment_method_id: number; name: string; is_cash: boolean; amount: number; received: number | null; change: number | null; reference: string | null }[]
  refunds: SaleRefundRow[]
  can_see_cost: boolean
  can: { refund: boolean }
  refund_blocker: string | null
}

export type DelegationStatus = 'ACTIVE' | 'SCHEDULED' | 'EXPIRED' | 'REVOKED'

export type Delegation = {
  id: number
  number: string
  delegator: PersonRef | null
  delegator_role: RoleCode
  delegate: PersonRef | null
  types: ApprovalType[]
  starts_at: string
  ends_at: string
  reason: string | null
  status: DelegationStatus
  revoked_at: string | null
  revoked_by: PersonRef | null
  revoke_note: string | null
  created_at: string
  can: { revoke: boolean }
}

export type DelegationOptions = {
  roles: { code: RoleCode; types: ApprovalType[] }[]
  users: PersonRef[]
  max_days: number
}

// --- Kas (irisan 6) ---

export type HandoverStage = 'OUTLET_TO_WAREHOUSE' | 'WAREHOUSE_TO_FINANCE'
type LocRef = { id: number; code: string; name: string; type?: LocationType }

export type CashHandover = {
  id: number
  number: string
  stage: HandoverStage
  status: 'SENT' | 'RECEIVED'
  from: LocRef
  to: LocRef | null
  expected_amount: number
  reported_amount: number
  received_amount: number | null
  difference: number | null
  sender: PersonRef | null
  sent_at: string
  sender_note: string | null
  receiver: PersonRef | null
  received_at: string | null
  receiver_note: string | null
  evidence_note: string | null
  items: { amount: number; shift: { id: number; number: string; cashier: string | null; closed_at: string } | null; source: { id: number; number: string; from: string | null } | null }[] | null
  can: { receive: boolean }
}

export type HandoverPending = {
  location: LocRef
  can_send: boolean
  shifts: { id: number; number: string; cashier: { id: number; name: string } | null; closed_at: string; sales_cash: number }[]
  handovers: { id: number; number: string; from: { id: number; name: string } | null; received_at: string; received_amount: number }[]
  total: number
}

export type CashOverview = {
  outlets: {
    location: LocRef
    cash_float: number | null
    cash_float_target: number
    shift_running: boolean
    sales_cash_at_outlet: number
    shifts_to_hand_over: number
    sales_cash_in_transit: number
    petty_cash: number
    petty_cash_target: number | null
    expenses_to_check: number
    refill_requested: boolean
  }[]
  warehouse: { location: LocRef; incoming: number; at_warehouse: number; to_finance: number } | null
  sees_all: boolean
  open_discrepancies: number | null
}

export type ExpenseStatus = 'RECORDED' | 'VERIFIED' | 'REJECTED' | 'CANCELLED'

export type Expense = {
  id: number
  number: string
  location: LocRef
  category: { id: number; code: string; name: string }
  amount: number
  description: string
  evidence_note: string | null
  paid_from: 'PETTY_CASH' | 'FINANCE'
  status: ExpenseStatus
  business_date: string
  recorded_by: PersonRef | null
  created_at: string
  decided_by: PersonRef | null
  decided_at: string | null
  decision_note: string | null
  can: { cancel: boolean }
}

export type PettyCash = {
  location: LocRef
  target: number | null
  balance: number
  pending: Expense[]
  request: { id: number; number: string; by: string | null; at: string; note: string | null } | null
  movements: { id: number; type: 'REFILL' | 'EXPENSE' | 'EXPENSE_CANCEL'; amount: number; balance_after: number; source: { type: string; id: number; number: string }; user: string | null; created_at: string }[]
  can: { record: boolean; request: boolean; refill: boolean; set_target: boolean }
}

export type ReconciliationRow = {
  business_date: string
  method: { id: number; code: string; name: string }
  system_amount: number
  reconciliation: { id: number; bank_amount: number; difference: number; note: string | null; by: string | null; at: string } | null
}

export type Discrepancy = {
  subject_type: 'cashier_shift' | 'cash_handover' | 'expense' | 'noncash_reconciliation'
  subject_id: number
  number: string
  kind: string
  location: string | null
  person: string | null
  amount: number
  explanation: string | null
  at: string
  link: string
  resolution: { decision: string; by: string | null; at: string } | null
}

// --- Pembelian (irisan 7) ---

export type PurchaseRequestStatus = 'PENDING' | 'APPROVED' | 'ORDERED' | 'REJECTED' | 'CANCELLED' | 'CLOSED'
export type PurchaseOrderStatus = 'WAITING_OWNER' | 'ORDERED' | 'PARTIAL' | 'RECEIVED' | 'CLOSED' | 'REJECTED' | 'CANCELLED'
export type RejectReason = 'DAMAGED' | 'EXPIRED' | 'WRONG_ITEM' | 'OTHER'

/** Harga null bagi gudang (2G-Q2). */
export type PurchaseLineRow = {
  id: number | null
  item: { id: number; code?: string; name: string }
  unit: { id: number; code: string | null }
  qty: string
  unit_price: number | null
  line_total: number | null
  remaining?: string | null
}

export type PurchaseRequest = {
  id: number
  number: string
  status: PurchaseRequestStatus
  requested_by: { id: number; name: string } | null
  created_at: string
  needed_by: string | null
  reason: string | null
  approval: { id: number; number: string; status: ApprovalStatus; decision_note: string | null } | null
  close_note: string | null
  lines: PurchaseLineRow[]
  orders: { id: number; number: string; status: PurchaseOrderStatus }[] | null
  can: { order: boolean; close: boolean }
}

export type GoodsReceiptRow = {
  id: number
  number: string
  received_by: { id: number; name: string } | null
  received_at: string
  supplier_doc: string | null
  note: string | null
  accepted_value: number | null
  invoice: { id: number; number: string } | null
  lines: { item: { id: number; name: string }; qty_accepted: string; qty_rejected: string; reject_reason: RejectReason | null; reject_note: string | null; batch_no: string | null; expiry_date: string | null }[]
}

export type PurchaseOrder = {
  id: number
  number: string
  status: PurchaseOrderStatus
  request: { id: number; number: string } | null
  supplier: { id: number; code: string; name: string; phone: string | null; payment_term_days: number } | null
  total: number | null
  expected_date: string | null
  note: string | null
  created_by: { id: number; name: string } | null
  created_at: string
  ordered_at: string | null
  closed_by: { id: number; name: string } | null
  closed_at: string | null
  close_note: string | null
  approval: { id: number; number: string; status: ApprovalStatus } | null
  lines: PurchaseLineRow[] | null
  receipts: GoodsReceiptRow[] | null
  can: { receive: boolean; cancel: boolean; close: boolean; invoice: boolean }
}

export type SupplierInvoice = {
  id: number
  number: string
  invoice_no: string
  supplier: { id: number; code: string; name: string } | null
  receipt: { id: number; number: string; po: { id: number; number: string } | null } | null
  invoice_date: string
  due_date: string
  expected_amount: number
  amount: number
  difference_note: string | null
  paid: number
  outstanding: number
  overdue: boolean
  recorded_by: { id: number; name: string } | null
  payments: { id: number; number: string; amount: number; paid_date: string; method: 'TRANSFER' | 'CASH'; reference: string | null; by: string | null }[]
}

export type Payables = {
  invoices: SupplierInvoice[]
  uninvoiced: { id: number; number: string; po: string | null; supplier: string | null; received_at: string; accepted_value: number; supplier_doc: string | null; term_days: number | null }[]
  by_supplier: { supplier: { id: number; code: string; name: string } | null; outstanding: number; overdue: number }[]
  total_outstanding: number
  can_act: boolean
}
