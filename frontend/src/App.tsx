import { Navigate, Route, Routes } from 'react-router'
import { AppLayout } from '@/components/layout/AppLayout'
import { ApprovalsPage } from '@/features/approvals/ApprovalsPage'
import { AuditLogPage } from '@/features/audit/AuditLogPage'
import { ChangePasswordPage } from '@/features/auth/ChangePasswordPage'
import { LoginPage } from '@/features/auth/LoginPage'
import { RequireAuth } from '@/features/auth/RequireAuth'
import { ItemDetailPage } from '@/features/catalog/ItemDetailPage'
import { ItemsPage } from '@/features/catalog/ItemsPage'
import { UnitsPage } from '@/features/catalog/UnitsPage'
import { HomePage } from '@/features/home/HomePage'
import { LocationsPage } from '@/features/locations/LocationsPage'
import { ExpenseCategoriesPage, PaymentMethodsPage } from '@/features/masters/SimpleMasterPage'
import { RecipeDetailPage } from '@/features/recipes/RecipeDetailPage'
import { RecipesPage } from '@/features/recipes/RecipesPage'
import { CountDetailPage, CountFormPage, CountsPage } from '@/features/stock/CountPages'
import { EntriesPage, EntryDetailPage, OpeningFormPage, WasteFormPage } from '@/features/stock/EntryPages'
import { RequestDetailPage, RequestFormPage, RequestsPage } from '@/features/stock/RequestPages'
import { StockCardPage } from '@/features/stock/StockCardPage'
import { StockPage } from '@/features/stock/StockPage'
import { TransferDetailPage, TransferFormPage, TransfersPage } from '@/features/stock/TransferPages'
import { SuppliersPage } from '@/features/suppliers/SuppliersPage'
import { UsersPage } from '@/features/users/UsersPage'
import { CashOverviewPage, DiscrepanciesPage, ExpensesPage, HandoverDetailPage, HandoverFormPage, HandoversPage, PettyCashPage, ReconciliationPage } from '@/features/cash/CashPages'
import { DelegationsPage } from '@/features/delegations/DelegationsPage'
import { PosPage } from '@/features/pos/PosPage'
import { ShiftDetailPage, ShiftsPage } from '@/features/pos/ShiftPages'
import { ReceiptPage, SaleDetailPage, SalesPage } from '@/features/sales/SalesPages'

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route path="/ganti-kata-sandi" element={<RequireAuth><ChangePasswordPage /></RequireAuth>} />
      <Route path="/penjualan/:id/struk" element={<RequireAuth role={['CASHIER', 'OWNER', 'FINANCE']}><ReceiptPage /></RequireAuth>} />
      <Route element={<RequireAuth><AppLayout /></RequireAuth>}>
        <Route index element={<HomePage />} />
        <Route path="barang" element={<ItemsPage />} />
        <Route path="barang/:id" element={<ItemDetailPage />} />
        <Route path="resep" element={<RecipesPage />} />
        <Route path="resep/:id" element={<RecipeDetailPage />} />
        <Route path="stok" element={<StockPage />} />
        <Route path="stok/kartu" element={<StockCardPage />} />
        <Route path="stok/saldo-awal/baru" element={<RequireAuth role={['OWNER', 'FINANCE']}><OpeningFormPage /></RequireAuth>} />
        <Route path="stok/waste/baru" element={<WasteFormPage />} />
        <Route path="stok/catatan" element={<EntriesPage />} />
        <Route path="stok/catatan/:id" element={<EntryDetailPage />} />
        <Route path="stok/opname" element={<CountsPage />} />
        <Route path="stok/opname/baru" element={<CountFormPage />} />
        <Route path="stok/opname/:id" element={<CountDetailPage />} />
        <Route path="permintaan-stok" element={<RequestsPage />} />
        <Route path="permintaan-stok/baru" element={<RequestFormPage />} />
        <Route path="permintaan-stok/:id" element={<RequestDetailPage />} />
        <Route path="pengiriman" element={<TransfersPage />} />
        <Route path="pengiriman/baru" element={<TransferFormPage />} />
        <Route path="pengiriman/:id" element={<TransferDetailPage />} />
        <Route path="persetujuan" element={<ApprovalsPage />} />
        <Route path="kasir" element={<RequireAuth role="CASHIER"><PosPage /></RequireAuth>} />
        <Route path="penjualan" element={<RequireAuth role={['CASHIER', 'OWNER', 'FINANCE']}><SalesPage /></RequireAuth>} />
        <Route path="penjualan/:id" element={<RequireAuth role={['CASHIER', 'OWNER', 'FINANCE']}><SaleDetailPage /></RequireAuth>} />
        <Route path="shift" element={<RequireAuth role={['CASHIER', 'OWNER', 'FINANCE']}><ShiftsPage /></RequireAuth>} />
        <Route path="shift/:id" element={<RequireAuth role={['CASHIER', 'OWNER', 'FINANCE']}><ShiftDetailPage /></RequireAuth>} />
        <Route path="kas" element={<CashOverviewPage />} />
        <Route path="kas/serah-terima" element={<HandoversPage />} />
        <Route path="kas/serah-terima/baru" element={<HandoverFormPage />} />
        <Route path="kas/serah-terima/:id" element={<HandoverDetailPage />} />
        <Route path="kas/biaya" element={<RequireAuth role={['OWNER', 'FINANCE', 'CASHIER']}><ExpensesPage /></RequireAuth>} />
        <Route path="kas/petty-cash/:id" element={<PettyCashPage />} />
        <Route path="kas/rekonsiliasi" element={<RequireAuth role={['OWNER', 'FINANCE']}><ReconciliationPage /></RequireAuth>} />
        <Route path="kas/selisih" element={<RequireAuth role={['OWNER', 'FINANCE']}><DiscrepanciesPage /></RequireAuth>} />
        <Route path="delegasi" element={<RequireAuth role={['OWNER', 'FINANCE']}><DelegationsPage /></RequireAuth>} />
        <Route path="supplier" element={<RequireAuth role={['OWNER', 'FINANCE']}><SuppliersPage /></RequireAuth>} />
        <Route path="metode-bayar" element={<RequireAuth role="OWNER"><PaymentMethodsPage /></RequireAuth>} />
        <Route path="kategori-biaya" element={<RequireAuth role="OWNER"><ExpenseCategoriesPage /></RequireAuth>} />
        <Route path="satuan" element={<RequireAuth role="OWNER"><UnitsPage /></RequireAuth>} />
        <Route path="lokasi" element={<RequireAuth role="OWNER"><LocationsPage /></RequireAuth>} />
        <Route path="pengguna" element={<RequireAuth role="OWNER"><UsersPage /></RequireAuth>} />
        <Route path="jejak-audit" element={<RequireAuth role="OWNER"><AuditLogPage /></RequireAuth>} />
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}
