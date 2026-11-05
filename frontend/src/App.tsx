import { Navigate, Route, Routes } from 'react-router'
import { LoginPage } from '@/features/auth/LoginPage'

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route path="*" element={<Navigate to="/login" replace />} />
    </Routes>
  )
}
