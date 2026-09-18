import type { TaxonomyCount } from '~/types/api'
import { ApiError, taxonomyApi } from '~/api'

export function useCategories(includeEmpty = false) {
  const { data, status, error } = useAsyncData<TaxonomyCount[]>(
    `categories:${includeEmpty}`,
    () => taxonomyApi.categories(includeEmpty),
    { default: () => [] },
  )

  return { categories: data, status, error: computed(() => (error.value as ApiError | null) ?? null) }
}

export function useTechnologies(limit?: number) {
  const { data, status, error } = useAsyncData<TaxonomyCount[]>(
    `technologies:${limit ?? 'all'}`,
    () => taxonomyApi.technologies(limit),
    { default: () => [] },
  )

  return { technologies: data, status, error: computed(() => (error.value as ApiError | null) ?? null) }
}
