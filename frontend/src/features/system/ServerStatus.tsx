import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { cn } from '@/lib/utils'

type Health = { status: 'ok' | 'gangguan'; database: string }

/** Tanda kecil apakah server dan database dapat dihubungi. */
export function ServerStatus() {
  const { data, isPending, isError } = useQuery({
    queryKey: ['health'],
    queryFn: () => api<Health>('/health'),
    retry: false,
    refetchInterval: 30_000,
  })

  const ok = data?.status === 'ok'
  const label = isPending ? 'Memeriksa server…' : ok ? 'Server terhubung' : 'Server tidak terhubung'

  return (
    <p className="flex items-center justify-center gap-2 text-xs text-muted-foreground" role="status">
      <span
        aria-hidden
        className={cn(
          'size-2 rounded-full',
          isPending && 'bg-muted-foreground',
          ok && 'bg-success',
          (isError || (data && !ok)) && 'bg-destructive',
        )}
      />
      {label}
    </p>
  )
}
