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
import { SuppliersPage } from '@/features/suppliers/SuppliersPage'
import { UsersPage } from '@/features/users/UsersPage'

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route path="/ganti-kata-sandi" element={<RequireAuth><ChangePasswordPage /></RequireAuth>} />
      <Route element={<RequireAuth><AppLayout /></RequireAuth>}>
        <Route index element={<HomePage />} />
        <Route path="barang" element={<ItemsPage />} />
        <Route path="barang/:id" element={<ItemDetailPage />} />
        <Route path="resep" element={<RecipesPage />} />
        <Route path="resep/:id" element={<RecipeDetailPage />} />
        <Route path="persetujuan" element={<RequireAuth role={['OWNER', 'FINANCE']}><ApprovalsPage /></RequireAuth>} />
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
