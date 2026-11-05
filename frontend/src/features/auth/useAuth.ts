import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ApiError, http } from '@/lib/api'
import type { RoleCode, User } from '@/lib/types'

export const ME_KEY = ['me'] as const

/** User yang sedang masuk, atau null bila belum masuk. */
export function useMe() {
  return useQuery({
    queryKey: ME_KEY,
    queryFn: async () => {
      try {
        const res = await http.get<{ user: User }>('/auth/me')
        return res.user
      } catch (e) {
        if (e instanceof ApiError && e.status === 401) return null
        throw e
      }
    },
    staleTime: 60_000,
    retry: false,
  })
}

export function hasRole(user: User | null | undefined, role: RoleCode): boolean {
  return !!user?.roles.some((r) => r.code === role && r.status === 'ACTIVE')
}

export function useLogout() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: () => http.post('/auth/logout'),
    onSettled: () => {
      qc.clear()
      qc.setQueryData(ME_KEY, null)
    },
  })
}
