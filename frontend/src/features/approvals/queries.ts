import { useQuery } from '@tanstack/react-query'
import { http } from '@/lib/api'

export const APPROVALS_KEY = ['approvals']

export type ApprovalSummary = { waiting_my_decision: number; returned_to_me: number }

/** Jumlah pengajuan yang perlu perhatian user ini (penanda di menu dan beranda). */
export function useApprovalSummary(enabled = true) {
  return useQuery({
    queryKey: [...APPROVALS_KEY, 'summary'],
    queryFn: () => http.get<{ data: ApprovalSummary }>('/approvals/summary'),
    enabled,
    refetchInterval: 60_000,
  })
}
