<script setup lang="ts">
const { t } = useI18n()
import { compactNumber, exactNumber } from '~/utils/format'

/**
 * The metrics strip on a project row.
 *
 * Every value is optional and absent values are omitted rather than shown as
 * zero -- "we have not fetched this yet" and "nobody engaged" are different
 * claims, and the pipeline is careful to keep them apart. Showing 0 stars for
 * a project with no repository would undo that.
 */
defineProps<{
  stars?: number | null
  /**
   * Total interactions on the source post.
   *
   * One number rather than a breakdown of likes, reposts and replies: a feed
   * row is scanned, not studied, and three near-identical figures cost more
   * attention than they return. The breakdown belongs on the detail page.
   */
  engagement?: number | null
}>()
</script>

<template>
  <dl class="metrics">
    <div v-if="stars !== null && stars !== undefined" class="metric">
      <dt>{{ t('project.stars') }}</dt>
      <dd :title="`${exactNumber(stars)} GitHub stars`">{{ compactNumber(stars) }}</dd>
    </div>
    <div v-if="engagement" class="metric">
      <dt>{{ t('project.interactions') }}</dt>
      <dd :title="`${exactNumber(engagement)} likes, reposts, replies and quotes on X`">
        {{ compactNumber(engagement) }}
      </dd>
    </div>
  </dl>
</template>

<style scoped>
.metrics { display: flex; gap: 1rem; margin: 0; flex-wrap: wrap; }
.metric { display: flex; align-items: baseline; gap: 0.3rem; }
.metric dt { font-size: 0.8125rem; color: var(--muted); }
.metric dd {
  margin: 0;
  font-size: 0.875rem;
  font-variant-numeric: tabular-nums;
  color: var(--ink);
}
</style>
