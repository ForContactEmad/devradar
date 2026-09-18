import type { ApiErrorBody, ApiErrorType, Envelope, PagedEnvelope } from '~/types/api'

/**
 * The only place in the frontend that performs HTTP.
 *
 * Everything above it -- composables, components, pages -- receives plain
 * data or an ApiError. No component ever sees a URL, a status code, or the
 * response envelope.
 */

export class ApiError extends Error {
  constructor(
    readonly type: ApiErrorType,
    message: string,
    readonly status: number,
    readonly details?: Record<string, string[]>,
    readonly requestId?: string,
  ) {
    super(message)
    this.name = 'ApiError'
  }

  /** Worth showing a retry button for; a 422 is not. */
  get isRetryable(): boolean {
    return this.type === 'internal_error' || this.type === 'network_error' || this.type === 'rate_limited'
  }

  get isNotFound(): boolean {
    return this.type === 'not_found'
  }
}

function baseUrl(): string {
  return useRuntimeConfig().public.apiBaseUrl || '/api/v1'
}

/**
 * Repeated parameters go out as repeated keys, which is what the backend's
 * form request expects. URLSearchParams handles arrays as a single joined
 * value otherwise, and the filter would silently match nothing.
 */
function toQuery(params: Record<string, unknown> = {}): Record<string, string | string[]> {
  const query: Record<string, string | string[]> = {}

  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === '') continue

    if (Array.isArray(value)) {
      if (value.length > 0) query[key] = value.map(String)
      continue
    }

    if (typeof value === 'boolean') {
      query[key] = value ? '1' : '0'
      continue
    }

    query[key] = String(value)
  }

  return query
}

async function request<T>(path: string, params?: Record<string, unknown>): Promise<T> {
  try {
    return await $fetch<T>(path, { baseURL: baseUrl(), query: toQuery(params) })
  } catch (caught: unknown) {
    throw normalise(caught)
  }
}

/**
 * Turns anything the network throws into one ApiError shape.
 *
 * A component that had to distinguish a fetch rejection from a 404 body from
 * a malformed response would carry three error paths; this leaves it one.
 */
function normalise(caught: unknown): ApiError {
  const error = caught as { status?: number; statusCode?: number; data?: ApiErrorBody; message?: string }
  const status = error.status ?? error.statusCode ?? 0
  const body = error.data

  if (body?.error) {
    return new ApiError(body.error.type, body.error.message, status, body.error.details, body.meta?.request_id)
  }

  if (status === 0) {
    return new ApiError('network_error', 'Could not reach the API.', 0)
  }

  if (status === 404) {
    return new ApiError('not_found', 'Not found.', 404)
  }

  return new ApiError('internal_error', error.message ?? 'Unexpected error.', status)
}

/** Unwraps {data, meta}; callers never see the envelope. */
export async function getItem<T>(path: string, params?: Record<string, unknown>): Promise<T> {
  const response = await request<Envelope<T>>(path, params)
  return response.data
}

export async function getCollection<T>(path: string, params?: Record<string, unknown>): Promise<T[]> {
  const response = await request<Envelope<T[]>>(path, params)
  return response.data
}

export interface Page<T> {
  items: T[]
  page: number
  perPage: number
  total: number
  lastPage: number
  hasMore: boolean
}

export async function getPage<T>(path: string, params?: Record<string, unknown>): Promise<Page<T>> {
  const response = await request<PagedEnvelope<T>>(path, params)

  return {
    items: response.data,
    page: response.meta.page,
    perPage: response.meta.per_page,
    total: response.meta.total,
    lastPage: response.meta.last_page,
    hasMore: response.meta.has_more,
  }
}
