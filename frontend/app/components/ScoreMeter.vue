<script setup lang="ts">
import { scoreLabel } from '~/utils/format'

/**
 * The composite score as a number plus a short bar.
 *
 * The bar is not decoration: a bare 68 means nothing without a sense of the
 * range, and a bar shows position in the scale at a glance. Rendered in ink
 * rather than colour, so the one accent stays reserved for freshness.
 */
const props = defineProps<{ score: number; size?: 'sm' | 'lg' }>()

const filled = computed(() => Math.max(0, Math.min(100, props.score)))
</script>

<template>
  <div class="score" :class="`score--${size ?? 'sm'}`">
    <span class="score__value">{{ scoreLabel(score) }}</span>
    <span
      class="score__track"
      role="img"
      :aria-label="`Score ${Math.round(filled)} out of 100`"
    >
      <span class="score__fill" :style="{ inlineSize: `${filled}%` }" />
    </span>
  </div>
</template>

<style scoped>
.score { display: inline-flex; align-items: center; gap: 0.5rem; }
.score__value {
  font-variant-numeric: tabular-nums;
  font-weight: 600;
  color: var(--ink);
  line-height: 1;
}
.score--sm .score__value { font-size: 0.9375rem; }
.score--lg .score__value { font-size: 1.75rem; letter-spacing: -0.02em; }

.score__track {
  display: block;
  inline-size: 3rem;
  block-size: 3px;
  background: var(--rule);
  border-radius: 2px;
  overflow: hidden;
}
.score--lg .score__track { inline-size: 5rem; block-size: 4px; }
.score__fill { display: block; block-size: 100%; background: var(--ink); }
</style>
