import { KeyRound, LogOut } from 'lucide-react'
import { NavLink, Outlet, useNavigate } from 'react-router'
import { Button } from '@/components/ui/button'
import { useApprovalSummary } from '@/features/approvals/queries'
import { hasRole, useLogout, useMe } from '@/features/auth/useAuth'
import { ROLE_LABEL } from '@/lib/format'
import { cn } from '@/lib/utils'
import { NAV } from './nav'

export function AppLayout() {
  const { data: user } = useMe()
  const logout = useLogout()
  const navigate = useNavigate()

  const groups = NAV.map((g) => ({
    ...g,
    items: g.items.filter((i) => !i.roles || i.roles.some((r) => hasRole(user, r)) || (i.orApprover && (user?.approval_types?.length ?? 0) > 0)),
  })).filter((g) => g.items.length > 0)

  const canSeeApprovals = hasRole(user, 'OWNER') || hasRole(user, 'FINANCE') || (user?.approval_types?.length ?? 0) > 0
  const summary = useApprovalSummary(canSeeApprovals)
  const approvalCount = (summary.data?.data.waiting_my_decision ?? 0) + (summary.data?.data.returned_to_me ?? 0)

  const roleNames = (user?.roles ?? []).filter((r) => r.status === 'ACTIVE').map((r) => ROLE_LABEL[r.code])

  return (
    <div className="flex min-h-svh">
      <aside className="flex w-60 shrink-0 flex-col border-r border-border bg-muted/50">
        <div className="px-5 py-4 text-lg font-semibold">POS</div>
        <nav aria-label="Menu utama" className="flex-1 space-y-5 px-3">
          {groups.map((g) => (
            <div key={g.title}>
              <p className="px-2 pb-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">{g.title}</p>
              <ul className="space-y-0.5">
                {g.items.map((i) => (
                  <li key={i.to}>
                    <NavLink
                      to={i.to}
                      end={i.to === '/'}
                      className={({ isActive }) =>
                        cn(
                          'flex items-center gap-2 rounded-lg px-2 py-2 text-sm hover:bg-muted',
                          isActive && 'bg-background font-medium shadow-sm',
                        )
                      }
                    >
                      <i.icon className="size-4" />
                      {i.label}
                      {i.badge === 'approvals' && approvalCount > 0 && (
                        <span
                          aria-label={`${approvalCount} perlu perhatian`}
                          className="ml-auto rounded-full bg-amber-500 px-1.5 text-xs font-semibold text-white"
                        >
                          {approvalCount}
                        </span>
                      )}
                    </NavLink>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </nav>
        <div className="space-y-2 border-t border-border p-3">
          <div className="px-2">
            <p className="truncate text-sm font-medium">{user?.name}</p>
            <p className="truncate text-xs text-muted-foreground">{roleNames.join(', ') || 'Belum punya peran'}</p>
          </div>
          <Button variant="ghost" size="sm" className="w-full justify-start" onClick={() => navigate('/ganti-kata-sandi')}>
            <KeyRound className="size-4" /> Ganti kata sandi
          </Button>
          <Button
            variant="ghost"
            size="sm"
            className="w-full justify-start"
            onClick={() => logout.mutate(undefined, { onSettled: () => navigate('/login', { replace: true }) })}
          >
            <LogOut className="size-4" /> Keluar
          </Button>
        </div>
      </aside>
      <main className="min-w-0 flex-1 px-8 py-6">
        <Outlet />
      </main>
    </div>
  )
}
