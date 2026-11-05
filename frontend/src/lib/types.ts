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
