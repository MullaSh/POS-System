import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'

export function ErrorAlert({ message }: { message: string | null | undefined }) {
  if (!message) return null
  return (
    <p role="alert" className="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive">
      {message}
    </p>
  )
}

export function FieldError({ message }: { message: string | undefined }) {
  if (!message) return null
  return <p className="text-sm text-destructive">{message}</p>
}

export function Loading({ label = 'Memuat…' }: { label?: string }) {
  return <p className="py-6 text-sm text-muted-foreground">{label}</p>
}

/** Judul halaman dengan satu kalimat tujuan (pola REC, Master §4). */
export function PageHeader({ title, purpose, actions }: { title: string; purpose: string; actions?: ReactNode }) {
  return (
    <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 className="text-2xl font-semibold">{title}</h1>
        <p className="mt-1 text-sm text-muted-foreground">{purpose}</p>
      </div>
      {actions}
    </div>
  )
}

export function InfoBanner({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <div className={cn('rounded-lg border border-border bg-muted px-4 py-3 text-sm text-muted-foreground', className)}>
      {children}
    </div>
  )
}
