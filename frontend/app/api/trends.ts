import type { TrendsReport } from '~/types/api'
import { getItem } from './http'

export const trendsApi = {
  /**
   * The whole report in one request.
   *
   * One call rather than one per metric: the aggregation is already assembled
   * and cached on the backend, and eight round trips would make the page as
   * slow as its slowest query.
   */
  report(): Promise<TrendsReport> {
    return getItem<TrendsReport>('/trends')
  },
}
