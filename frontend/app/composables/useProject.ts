import type { ProjectDetail } from '~/types/api'
import { ApiError, projectsApi } from '~/api'

/**
 * One project by slug.
 *
 * AWAITS THE FETCH so a missing project can become a real 404 with a real
 * status code. Resolving it after render is too late: the response has gone.
 *
 * NOT-FOUND IS SIGNALLED BY A NULL RESULT, not by inspecting the error.
 * Nuxt wraps whatever a fetcher throws in its own NuxtError, which discards
 * the ApiError instance and its `isNotFound` getter with it -- so a check
 * against the error object silently never matched, and every missing project
 * rendered as a normal page. Catching the 404 in the fetcher and returning
 * null makes the signal a value rather than a type, which nothing can strip.
 */
export async function useProject(slug: MaybeRefOrGetter<string>) {
  const key = computed(() => `project:${toValue(slug)}`)

  const { data, status, error, refresh } = await useAsyncData<ProjectDetail | null>(
    () => key.value,
    () => projectsApi.detail(toValue(slug)).catch((caught: unknown) => {
      if (caught instanceof ApiError && caught.isNotFound) return null
      throw caught
    }),
    { watch: [key], default: () => null },
  )

  return {
    project: data,
    status,
    error: computed(() => (error.value as ApiError | null) ?? null),
    // Resolved without an error and still nothing: the project does not exist.
    notFound: computed(() => status.value !== 'pending' && !error.value && data.value === null),
    refresh,
  }
}
