import type { Statistics } from '~/types/api'
import { getItem } from './http'

export const statsApi = {
  overview(): Promise<Statistics> {
    return getItem<Statistics>('/stats')
  },
}
