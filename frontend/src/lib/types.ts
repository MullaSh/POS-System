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

export type Approval = {
  id: number
  number: string
  type: 'ITEM_PRICE'
  status: ApprovalStatus
  title: string
  summary: string
  subject: { type: string; id: number }
  payload: PriceProposal
  reason: string | null
  requester: PersonRef | null
  requester_role: { code: RoleCode; name: string } | null
  submitted_at: string
  decider: PersonRef | null
  decided_at: string | null
  decision_note: string | null
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
