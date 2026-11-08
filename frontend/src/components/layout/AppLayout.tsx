import { ChevronRight, KeyRound, LogOut } from 'lucide-react'
import { useState } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router'
import { Button } from '@/components/ui/button'
import { useApprovalSummary } from '@/features/approvals/queries'
import { hasRole, useLogout, useMe } from '@/features/auth/useAuth'
import { ROLE_LABEL } from '@/lib/format'
import { cn } from '@/lib/utils'
import { NAV, type NavGroup } from './nav'

const STORAGE_KEY = 'pos.menu.open'

/** Kelompok yang berisi halaman saat ini (pencocokan alamat terpanjang). */
function activeGroup(groups: NavGroup[], pathname: string): string | undefined {
  let best: { title: string; len: number } | undefined
  for (const g of groups) {
    for (const i of g.items) {
      const match = i.to === '/' ? pathname === '/' : pathname === i.to || pathname.startsWith(`${i.to}/`)
      if (match && (!best || i.to.length > best.len)) best = { title: g.title, len: i.to.length }
    }
  }
  return best?.title
}

function readOpen(): string[] {
  try {
    const v: unknown = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '[]')
    return Array.isArray(v) ? v.filter((x): x is string => typeof x === 'string') : []
  } catch {
    return []
  }
}

function saveOpen(open: string[]) {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(open))
  } catch {
    // Penyimpanan browser tidak tersedia; pilihan buka-tutup hanya berlaku sampai halaman dimuat ulang.
  }
}

export function AppLayout() {
  const { data: user } = useMe()
  const logout = useLogout()
  const navigate = useNavigate()

  const groups = NAV.map((g) => ({
    ...g,
    items: g.items.filter((i) => !i.roles || i.roles.some((r) => hasRole(user, r)) || (i.orApprover && (user?.approval_types?.length ?? 0) > 0)),
  })).filter((g) => g.items.length > 0)

  const { pathname } = useLocation()
  const current = activeGroup(groups, pathname)
  const [open, setOpen] = useState<string[]>(() => {
    const saved = readOpen()
    return current && !saved.includes(current) ? [...saved, current] : saved
  })
  // Pindah halaman (juga lewat tautan di luar menu): buka kelompok halaman tujuan.
  const [seen, setSeen] = useState(current)
  if (current !== seen) {
    setSeen(current)
    if (current && !open.includes(current)) setOpen([...open, current])
  }
  const toggle = (title: string) =>
    setOpen((o) => {
      const next = o.includes(title) ? o.filter((t) => t !== title) : [...o, title]
      saveOpen(next)
      return next
    })

  const canSeeApprovals = hasRole(user, 'OWNER') || hasRole(user, 'FINANCE') || (user?.approval_types?.length ?? 0) > 0
  const summary = useApprovalSummary(canSeeApprovals)
  const approvalCount = (summary.data?.data.waiting_my_decision ?? 0) + (summary.data?.data.returned_to_me ?? 0)

  const roleNames = (user?.roles ?? []).filter((r) => r.status === 'ACTIVE').map((r) => ROLE_LABEL[r.code])

  return (
    <div className="flex min-h-svh">
      <aside className="flex w-60 shrink-0 flex-col border-r border-border bg-muted/50">
        <div className="px-5 py-4 text-lg font-semibold">POS</div>
        <nav aria-label="Menu utama" className="flex-1 space-y-3 px-3">
          {groups.map((g) => {
            const isOpen = g.pinned || open.includes(g.title)
            const listId = `menu-${g.title.toLowerCase().replace(/\s+/g, '-')}`
            return (
              <div key={g.title}>
                {g.pinned ? (
                  <p className="px-2 pb-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">{g.title}</p>
                ) : (
                  <button
                    type="button"
                    aria-expanded={isOpen}
                    aria-controls={listId}
                    onClick={() => toggle(g.title)}
                    className={cn(
                      'flex w-full items-center gap-1 rounded-md px-2 py-1 text-xs font-medium uppercase tracking-wide text-muted-foreground hover:bg-muted hover:text-foreground',
                      g.title === current && 'text-foreground',
                    )}
                  >
                    {g.title}
                    <ChevronRight className={cn('ml-auto size-3.5 transition-transform', isOpen && 'rotate-90')} aria-hidden />
                  </button>
                )}
                {isOpen && (
                  <ul id={listId} className="mt-0.5 space-y-0.5">
                    {g.items.map((i) => (
                      <li key={i.to}>
                        <NavLink
                          to={i.to}
                          end={i.to === '/' || i.to === '/kas'}
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
                )}
              </div>
            )
          })}
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
