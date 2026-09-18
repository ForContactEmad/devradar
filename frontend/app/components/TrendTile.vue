<script setup lang="ts">
import type { Trend } from '~/types/api'
import { compactNumber } from '~/utils/format'

/**
 * One headline figure with its comparison.
 *
 * THE COMPARISON IS THE POINT. A tile showing "47" is a number; a tile
 * showing "47, up from 32" is a statistic. The previous value is never
 * hidden, even when the direction is not trustworthy.
 *
 * An unreliable trend shows the figures and says plainly that the sample is
 * too small, rather than drawing a confident arrow. Suppressing the numbers
 * would be a different dishonesty -- they are real, it is only the direction
 * that is not yet supportable.
 */
const props = defineProps<{ trend: Trend; label: string; unit?: string }>()

const glyph = computed(() => ({
  rising: '↑',
  falling: '↓',
  steady: '→',
  insufficient: '',
}[props.trend.direction]))

const value = computed(() =>
  props.unit === 'score'
    ? props.trend.current.toFixed(1)
    : compactNumber(Math.round(props.trend.current)),
)
</script>

<template>
  <div class="tile" :class="`tile--${trend.direction}`">
    <p class="tile__value">{{ value }}</p>
    <p class="tile__label">{{ label }}</p>

    <p class="tile__delta" :title="trend.explanation">
      <template v-if="trend.reliable && trend.change_percent !== null">
        <span class="tile__glyph" aria-hidden="true">{{ glyph }}</span>
        <span>{{ Math.abs(trend.change_percent).toFixed(0) }}%</span>
        <span class="tile__baseline">
          from {{ unit === 'score' ? trend.previous.toFixed(1) : compactNumber(Math.round(trend.previous)) }}
        </span>
      </template>
      <span v-else class="tile__unreliable">
        too few to compare
      </span>
    </p>

    <span class="visually-hidden">{{ trend.explanation }}</span>
  </div>
</template>

<style scoped>
.tile { padding-inline-end: 1.5rem; }
.tile__value {
  margin: 0;
  font-size: 1.75rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
  letter-spacing: -0.02em;
  line-height: 1.1;
  color: var(--ink);
}
.tile__label { margin: 0.2rem 0 0; font-size: 0.875rem; color: var(--ink-soft); }
.tile__delta {
  display: flex;
  align-items: baseline;
  gap: 0.3rem;
  margin: 0.3rem 0 0;
  font-size: 0.8125rem;
  font-variant-numeric: tabular-nums;
  color: var(--muted);
}
.tile__glyph { font-size: 0.9375rem; line-height: 1; }
.tile--rising .tile__glyph { color: var(--signal); }
.tile--falling .tile__glyph { color: var(--ink); }
.tile__baseline { color: var(--muted); }
.tile__unreliable { font-style: italic; }
</style>
