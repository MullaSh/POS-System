import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { LogIn } from 'lucide-react'
import { useForm } from 'react-hook-form'
import { Navigate, useNavigate } from 'react-router'
import { z } from 'zod'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { ErrorAlert } from '@/components/ui/feedback'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { ServerStatus } from '@/features/system/ServerStatus'
import { ApiError, http } from '@/lib/api'
import type { User } from '@/lib/types'
import { ME_KEY, useMe } from './useAuth'

const schema = z.object({
  username: z.string().trim().min(1, 'Username wajib diisi'),
  password: z.string().min(1, 'Kata sandi wajib diisi'),
})

type LoginForm = z.infer<typeof schema>

/** Halaman masuk. Login dengan username + kata sandi (dokumen 12 §9 Q3). */
export function LoginPage() {
  const qc = useQueryClient()
  const navigate = useNavigate()
  const me = useMe()
  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<LoginForm>({ resolver: zodResolver(schema) })

  const login = useMutation({
    mutationFn: (values: LoginForm) => http.post<{ user: User }>('/auth/login', values),
    onSuccess: ({ user }) => {
      qc.setQueryData(ME_KEY, user)
      navigate(user.must_change_password ? '/ganti-kata-sandi' : '/', { replace: true })
    },
  })

  if (me.data) return <Navigate to="/" replace />

  const serverError =
    login.error instanceof ApiError ? (login.error.field('username') ?? login.error.message) : null

  return (
    <main className="flex min-h-svh items-center justify-center bg-muted px-4 py-10">
      <div className="w-full max-w-sm space-y-4">
        <Card>
          <CardHeader>
            <CardTitle>Masuk ke POS</CardTitle>
            <CardDescription>Gunakan username dan kata sandi yang diberikan pemilik usaha.</CardDescription>
          </CardHeader>
          <CardContent>
            <form className="space-y-4" onSubmit={handleSubmit((v) => login.mutate(v))} noValidate>
              <div className="space-y-2">
                <Label htmlFor="username">Username</Label>
                <Input
                  id="username"
                  autoComplete="username"
                  autoFocus
                  aria-invalid={!!errors.username}
                  {...register('username')}
                />
                {errors.username && <p className="text-sm text-destructive">{errors.username.message}</p>}
              </div>
              <div className="space-y-2">
                <Label htmlFor="password">Kata sandi</Label>
                <Input
                  id="password"
                  type="password"
                  autoComplete="current-password"
                  aria-invalid={!!errors.password}
                  {...register('password')}
                />
                {errors.password && <p className="text-sm text-destructive">{errors.password.message}</p>}
              </div>
              <ErrorAlert message={serverError} />
              <Button type="submit" className="w-full" disabled={login.isPending}>
                <LogIn className="size-4" aria-hidden />
                {login.isPending ? 'Memeriksa…' : 'Masuk'}
              </Button>
            </form>
          </CardContent>
        </Card>
        <ServerStatus />
      </div>
    </main>
  )
}
