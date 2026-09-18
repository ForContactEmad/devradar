import type { TrendsReport } from '~/types/api'
import { ApiError, trendsApi } from '~/api'

/**
 * The statistics report.
 *
 * No arithmetic here. Every figure, comparison and direction arrives already
 * computed by the backend, which is where the data is and where the result
 * can be cached for every reader at once. Recomputing shares or percentages
 * in the browser would put a second implementation of the statistics beside
 * the first, and they would disagree.
 */
export function useTrends() {
  const { data, status, error, refresh } = useAsyncData<TrendsReport | null>(
    'trends',
    () => trendsApi.report(),
    { default: () => null },
  )

  return {
    report: data,
    status,
    error: computed(() => (error.value as ApiError | null) ?? null),
    refresh,
  }
}
