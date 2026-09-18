import type { ProjectDetail, ProjectFilters, ProjectSummary } from '~/types/api'
import { getPage, getItem, type Page } from './http'

/**
 * Project endpoints.
 *
 * Note there is no `trending()` or `latest()` function: the backend treats
 * those as sort values on one collection rather than separate resources, and
 * mirroring that here keeps one code path instead of three that drift.
 */
export const projectsApi = {
  list(filters: ProjectFilters = {}): Promise<Page<ProjectSummary>> {
    return getPage<ProjectSummary>('/projects', filters)
  },

  detail(slug: string): Promise<ProjectDetail> {
    return getItem<ProjectDetail>(`/projects/${encodeURIComponent(slug)}`)
  },
}
