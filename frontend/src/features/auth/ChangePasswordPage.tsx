import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { KeyRound } from 'lucide-react'
import { useForm } from 'react-hook-form'
import { useNavigate } from 'react-router'
import { z } from 'zod'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { ErrorAlert } from '@/components/ui/feedback'
import { Field } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { ApiError, http } from '@/lib/api'
import type { User } from '@/lib/types'
import { ME_KEY, useMe } from './useAuth'

const schema = z
  .object({
    current_password: z.string().min(1, 'Kata sandi lama wajib diisi'),
    password: z.string().min(8, 'Kata sandi baru minimal 8 karakter'),
    password_confirmation: z.string(),
  })
  .refine((v) => v.password === v.password_confirmation, {
    path: ['password_confirmation'],
    message: 'Konfirmasi kata sandi tidak cocok',
  })
  .refine((v) => v.password !== v.current_password, {
    path: ['password'],
    message: 'Kata sandi baru harus berbeda dari kata sandi lama',
  })

type Form = z.infer<typeof schema>

export function ChangePasswordPage() {
  const qc = useQueryClient()
  const navigate = useNavigate()
  const { data: user } = useMe()
  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<Form>({ resolver: zodResolver(schema) })

  const change = useMutation({
    mutationFn: (v: Form) => http.post<{ user: User }>('/auth/change-password', v),
    onSuccess: ({ user }) => {
      qc.setQueryData(ME_KEY, user)
      navigate('/', { replace: true })
    },
  })
  const apiErr = change.error instanceof ApiError ? change.error : null

  return (
    <main className="flex min-h-svh items-center justify-center bg-muted px-4 py-10">
      <Card className="w-full max-w-sm">
        <CardHeader>
          <CardTitle>Ganti kata sandi</CardTitle>
          <CardDescription>
            {user?.must_change_password
              ? 'Ini kata sandi sementara. Buat kata sandi baru milik Anda sendiri sebelum melanjutkan.'
              : 'Buat kata sandi baru untuk akun Anda.'}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <form className="space-y-4" onSubmit={handleSubmit((v) => change.mutate(v))} noValidate>
            <Field id="current_password" label="Kata sandi lama" error={errors.current_password?.message ?? apiErr?.field('current_password')}>
              <Input id="current_password" type="password" autoComplete="current-password" {...register('current_password')} />
            </Field>
            <Field id="password" label="Kata sandi baru" hint="Minimal 8 karakter." error={errors.password?.message ?? apiErr?.field('password')}>
              <Input id="password" type="password" autoComplete="new-password" {...register('password')} />
            </Field>
            <Field id="password_confirmation" label="Ulangi kata sandi baru" error={errors.password_confirmation?.message}>
              <Input id="password_confirmation" type="password" autoComplete="new-password" {...register('password_confirmation')} />
            </Field>
            <ErrorAlert message={apiErr && !Object.keys(apiErr.errors).length ? apiErr.message : null} />
            <Button type="submit" className="w-full" disabled={change.isPending}>
              <KeyRound className="size-4" aria-hidden />
              {change.isPending ? 'Menyimpan…' : 'Simpan kata sandi baru'}
            </Button>
          </form>
        </CardContent>
      </Card>
    </main>
  )
}
