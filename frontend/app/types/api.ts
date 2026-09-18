/**
 * The API contract, mirrored from backend/docs/openapi.yaml.
 *
 * Hand-written rather than generated because the generator would also need to
 * run in CI to stay honest, and a stale generated file is worse than a
 * hand-written one somebody has read. When the spec changes, this changes,
 * and every consumer of it fails to compile -- which is the point.
 */

export interface Meta {
  request_id: string
  api_version: string
}

export interface PageMeta extends Meta {
  page: number
  per_page: number
  total: number
  last_page: number
  has_more: boolean
}

export interface PageLinks {
  self: string | null
  first: string | null
  last: string | null
  next: string | null
  prev: string | null
}

export interface Envelope<T> {
  data: T
  meta: Meta
}

export interface PagedEnvelope<T> {
  data: T[]
  meta: PageMeta
  links: PageLinks
}

export type ApiErrorType =
  | 'validation_failed'
  | 'invalid_query'
  | 'not_found'
  | 'unauthenticated'
  | 'rate_limited'
  | 'internal_error'
  | 'network_error'

export interface ApiErrorBody {
  error: { type: ApiErrorType; message: string; details?: Record<string, string[]> }
  meta: Meta
}

export interface ProjectLinks {
  repository: string | null
  website: string | null
  demo: string | null
  source_post: string
}

export interface ProjectSummary {
  slug: string
  name: string
  description: string | null
  category: string
  project_type: string | null
  /** Only technologies evidenced in the post. Empty means the post said nothing. */
  technologies: string[]
  links: ProjectLinks
  author: string | null
  /** 0-100 composite ranking. */
  score: number
  stars: number | null
  /** Total interactions on the source post, denormalised by the API. */
  engagement: number
  discovered_at: string
}

export interface RepositoryView {
  url: string
  stars: number | null
  forks: number | null
  open_issues: number | null
  contributors: number | null
  primary_language: string | null
  license: string | null
  topics: string[]
  last_commit_at: string | null
  created_at: string | null
  is_archived: boolean
  /** Distinguishes stale repository data from missing data. */
  fetched_at: string | null
}

export interface ProjectDetail extends ProjectSummary {
  score_breakdown: ScoreBreakdown | null
  extraction_confidence: number | null
  published_at: string
  repository: RepositoryView | null
  history: MetricPoint[]
}

export interface ScoreComponent {
  value: number
  available: boolean
  configured_weight: number
  effective_weight: number
  contribution: number
  why: string
}

export interface ScoreBreakdown {
  score: number
  dampener: number
  notes: string[]
  components: Record<string, ScoreComponent>
}

export interface MetricPoint {
  captured_at: string
  score: number | null
  engagement: number
}

export interface TaxonomyCount {
  slug: string
  name: string
  kind?: string
  project_count: number
}

export interface Statistics {
  window_days: number
  projects_in_window: number
  projects_published_today: number
  projects_with_repository: number
  average_score: number
  last_published_at: string | null
  top_categories: TaxonomyCount[]
  top_technologies: TaxonomyCount[]
}

export type ProjectSort = 'score' | 'trending' | 'latest' | 'oldest' | 'engagement' | 'relevance'

/** Mirrors the backend's ProjectQuery. Validation stays server-side. */
export interface ProjectFilters {
  page?: number
  per_page?: number
  sort?: ProjectSort
  q?: string
  category?: string[]
  technology?: string[]
  type?: string
  has_repository?: boolean
  min_score?: number
  within_days?: number
  min_engagement?: number
  since?: string
  until?: string
}

// ---------------------------------------------------------------- trends

export type TrendDirection = 'rising' | 'falling' | 'steady' | 'insufficient'

export interface Trend {
  metric: string
  current: number
  previous: number
  direction: TrendDirection
  change_percent: number | null
  sample_size: number
  /** False when the sample is too small to claim a direction. */
  reliable: boolean
  explanation: string
}

export interface SeriesPoint {
  date: string
  value: number
}

export interface TimeSeries {
  metric: string
  points: SeriesPoint[]
  total: number
  average: number
  peak: SeriesPoint | null
}

export interface TaxonomyTrend {
  slug: string
  name: string
  count: number
  previous_count: number
  /** Fraction of the window, 0-1. */
  share: number
  direction: TrendDirection
  kind: string | null
}

export interface RankedProject {
  slug: string
  name: string
  category: string
  score: number
  engagement: number
  stars: number | null
  discovered_at: string
}

export interface TrendsReport {
  window_days: number
  generated_at: string | null
  headlines: Trend[]
  projects_per_day: TimeSeries
  engagement_per_day: TimeSeries
  top_categories: TaxonomyTrend[]
  top_technologies: TaxonomyTrend[]
  top_projects: RankedProject[]
  /** Null until enrichment has measured the same repository twice. */
  repository_stars: TimeSeries | null
}
