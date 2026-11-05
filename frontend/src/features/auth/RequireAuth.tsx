import type { ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router'
import { ErrorAlert, Loading } from '@/components/ui/feedback'
import { errorText } from '@/lib/api'
import type { RoleCode } from '@/lib/types'
import { hasRole, useMe } from './useAuth'

/**
 * Halaman yang butuh login. Bila wajib ganti kata sandi, user diarahkan ke
 * halaman ganti kata sandi lebih dulu. `role` membatasi halaman untuk satu peran.
 */
export function RequireAuth({ children, role }: { children: ReactNode; role?: RoleCode }) {
  const { data: user, isPending, error } = useMe()
  const location = useLocation()

  if (isPending) return <Loading label="Memeriksa sesi…" />
  if (error) return <div className="p-6"><ErrorAlert message={errorText(error)} /></div>
  if (!user) return <Navigate to="/login" replace state={{ from: location.pathname }} />
  if (user.must_change_password && location.pathname !== '/ganti-kata-sandi') {
    return <Navigate to="/ganti-kata-sandi" replace />
  }
  if (role && !hasRole(user, role)) {
    return (
      <div className="p-6">
        <ErrorAlert message="Halaman ini hanya untuk peran tertentu. Anda tidak memiliki izin membukanya." />
      </div>
    )
  }
  return children
}
