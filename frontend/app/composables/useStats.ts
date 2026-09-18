import type { Statistics } from '~/types/api'
import { ApiError, statsApi } from '~/api'

export function useStats() {
  const { data, status, error } = useAsyncData<Statistics | null>(
    'stats',
    () => statsApi.overview(),
    { default: () => null },
  )

  return { stats: data, status, error: computed(() => (error.value as ApiError | null) ?? null) }
}
