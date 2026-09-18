import type { ProjectFilters, ProjectSort, ProjectSummary } from '~/types/api'
import { ApiError, projectsApi, type Page } from '~/api'

/**
 * The project feed: fetching, filter state, and the URL those filters live in.
 *
 * FILTER STATE LIVES IN THE URL, not in a store. A filtered feed should be
 * shareable, bookmarkable and survive a refresh -- and the moment it does,
 * the URL is already the source of truth. A parallel store would be a second
 * copy to keep in sync with it.
 *
 * No component calls the API. Components receive `projects`, `status` and
 * `error` as props or from this composable, and never learn there was an
 * HTTP request involved.
 */
export function useProjectFeed(defaults: Partial<ProjectFilters> = {}) {
  const route = useRoute()
  const router = useRouter()

  const filters = computed<ProjectFilters>(() => {
    const query = route.query

    return {
      page: toInt(query.page) ?? 1,
      per_page: defaults.per_page ?? 20,
      sort: (toStr(query.sort) as ProjectSort) ?? defaults.sort ?? 'score',
      q: toStr(query.q) ?? undefined,
      category: toList(query.category ?? defaults.category),
      technology: toList(query.technology ?? defaults.technology),
      has_repository: toBool(query.repo),
      min_score: toNum(query.min_score),
      min_engagement: toInt(query.min_engagement),
      within_days: toInt(query.within_days),
      type: toStr(query.type),
    }
  })

  const { data, status, error, refresh } = useAsyncData<Page<ProjectSummary>>(
    () => `projects:${JSON.stringify(filters.value)}`,
    () => projectsApi.list(filters.value),
    {
      watch: [filters],
      // An empty page is a valid answer, so the UI can render structure
      // while the first request is in flight instead of flashing a spinner.
      default: () => ({ items: [], page: 1, perPage: 20, total: 0, lastPage: 1, hasMore: false }),
    },
  )

  /** Merging rather than replacing, so changing a category keeps the sort. */
  function apply(patch: Partial<ProjectFilters>) {
    // `has_repository` travels as `repo` in the URL: shorter, and the query
    // string is something people read and share.
    const mapped: Record<string, unknown> = { ...patch }

    if ('has_repository' in patch) {
      mapped.repo = patch.has_repository === undefined ? undefined : (patch.has_repository ? '1' : '0')
      delete mapped.has_repository
    }

    const next: Record<string, unknown> = { ...route.query, ...mapped }

    // Any filter change invalidates the current page: page 3 of a different
    // result set is a different, usually empty, thing.
    if (!('page' in patch)) delete next.page

    for (const [key, value] of Object.entries(next)) {
      if (value === undefined || value === null || value === '' || (Array.isArray(value) && value.length === 0)) {
        delete next[key]
      }
    }

    router.push({ query: next as Record<string, string> })
  }

  function goToPage(page: number) {
    apply({ page: Math.max(1, page) })
  }

  function clear() {
    router.push({ query: route.query.sort ? { sort: route.query.sort } : {} })
  }

  const isFiltered = computed(() =>
    Boolean(
      filters.value.q
      || filters.value.category?.length
      || filters.value.technology?.length
      || filters.value.type
      || filters.value.has_repository !== undefined
      || filters.value.min_score !== undefined
      || filters.value.min_engagement !== undefined
      || filters.value.within_days !== undefined,
    ),
  )

  /**
   * The filters in force, each with the patch that removes it.
   *
   * Built here rather than in the component so that removing a filter is a
   * described operation rather than a component reaching into the query
   * string and guessing which key to delete.
   */
  const activeFilters = computed(() => {
    const f = filters.value
    const chips: Array<{ key: string; label: string; remove: Partial<ProjectFilters> }> = []

    if (f.q) chips.push({ key: 'q', label: `"${f.q}"`, remove: { q: undefined } })

    for (const category of f.category ?? []) {
      chips.push({
        key: `category:${category}`,
        label: category.replace(/-/g, ' '),
        remove: { category: (f.category ?? []).filter((c) => c !== category) },
      })
    }

    for (const technology of f.technology ?? []) {
      chips.push({
        key: `technology:${technology}`,
        label: technology,
        remove: { technology: (f.technology ?? []).filter((t) => t !== technology) },
      })
    }

    if (f.type) chips.push({ key: 'type', label: f.type, remove: { type: undefined } })

    if (f.has_repository !== undefined) {
      chips.push({
        key: 'repo',
        label: f.has_repository ? 'with source code' : 'without source code',
        remove: { has_repository: undefined },
      })
    }

    if (f.min_score !== undefined) {
      chips.push({ key: 'min_score', label: `score ${f.min_score}+`, remove: { min_score: undefined } })
    }

    if (f.min_engagement !== undefined) {
      chips.push({
        key: 'min_engagement',
        label: `${f.min_engagement}+ interactions`,
        remove: { min_engagement: undefined },
      })
    }

    if (f.within_days !== undefined) {
      chips.push({
        key: 'within_days',
        label: f.within_days === 1 ? 'last 24 hours' : `last ${f.within_days} days`,
        remove: { within_days: undefined },
      })
    }

    return chips
  })

  /** Add or remove one value from a repeated filter without touching the rest. */
  function toggle(key: 'category' | 'technology', value: string) {
    const current = filters.value[key] ?? []
    const next = current.includes(value) ? current.filter((v) => v !== value) : [...current, value]

    apply({ [key]: next } as Partial<ProjectFilters>)
  }

  return {
    projects: computed(() => data.value?.items ?? []),
    page: computed(() => data.value ?? null),
    filters,
    isFiltered,
    activeFilters,
    toggle,
    status,
    error: computed(() => (error.value as ApiError | null) ?? null),
    apply,
    goToPage,
    clear,
    refresh,
  }
}

function toStr(value: unknown): string | undefined {
  return typeof value === 'string' && value !== '' ? value : undefined
}

function toInt(value: unknown): number | undefined {
  const parsed = Number.parseInt(String(value ?? ''), 10)
  return Number.isFinite(parsed) ? parsed : undefined
}

function toNum(value: unknown): number | undefined {
  const parsed = Number.parseFloat(String(value ?? ''))
  return Number.isFinite(parsed) ? parsed : undefined
}

function toBool(value: unknown): boolean | undefined {
  if (value === '1' || value === 'true') return true
  if (value === '0' || value === 'false') return false
  return undefined
}

function toList(value: unknown): string[] {
  if (Array.isArray(value)) return value.map(String).filter(Boolean)
  if (typeof value === 'string' && value !== '') return value.split(',').filter(Boolean)
  return []
}
