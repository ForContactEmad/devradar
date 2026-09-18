import type { TaxonomyCount } from '~/types/api'
import { getCollection } from './http'

export const taxonomyApi = {
  categories(includeEmpty = false): Promise<TaxonomyCount[]> {
    return getCollection<TaxonomyCount>('/categories', { include_empty: includeEmpty })
  },

  technologies(limit?: number): Promise<TaxonomyCount[]> {
    return getCollection<TaxonomyCount>('/technologies', { limit })
  },
}
