<script setup lang="ts">
const { t } = useI18n()
import type { TimeSeries } from '~/types/api'
import { compactNumber } from '~/utils/format'

/**
 * A daily series across both comparison periods.
 *
 * THE CHART SHOWS THE COMPARISON rather than asserting it. A tile can say
 * "up 47%"; only the chart shows whether that came from steady growth or one
 * loud Tuesday. The boundary between the previous period and the current one
 * is drawn, so the two halves can be read against each other.
 *
 * Bars, not a line. The values are daily counts -- discrete events, not a
 * continuous quantity -- and a line between them implies intermediate values
 * that do not exist.
 *
 * Rendered as plain elements rather than a charting library: it is a bar per
 * day across two weeks, and 90KB of dependency to draw fourteen rectangles
 * would be the wrong trade on a page that must stay fast.
 */
const props = defineProps<{
  series: TimeSeries
  label: string
  windowDays: number
}>()

const peak = computed(() => Math.max(1, ...props.series.points.map((p) => p.value)))

/** The index at which the current period begins. */
const boundary = computed(() => Math.max(0, props.series.points.length - props.windowDays))

const bars = computed(() =>
  props.series.points.map((point, index) => ({
    ...point,
    height: (point.value / peak.value) * 100,
    current: index >= boundary.value,
    weekday: new Date(point.date).toLocaleDateString('en', { weekday: 'short' }),
  })),
)
</script>

<template>
  <figure class="chart">
    <figcaption class="chart__caption">
      <span>{{ label }}</span>
      <span class="chart__summary">
        {{ compactNumber(series.total) }} total · {{ compactNumber(Math.round(series.average)) }}/day average
      </span>
    </figcaption>

    <ol class="chart__bars">
      <li
        v-for="bar in bars"
        :key="bar.date"
        class="chart__bar"
        :class="{ 'chart__bar--current': bar.current }"
      >
        <span
          class="chart__fill"
          :style="{ blockSize: `${Math.max(bar.value > 0 ? 3 : 1, bar.height)}%` }"
        />
        <span class="visually-hidden">{{ bar.date }}: {{ bar.value }}</span>
      </li>
    </ol>

    <div class="chart__axis" aria-hidden="true">
      <span>{{ windowDays }} days earlier</span>
      <span>this week</span>
    </div>

    <!-- A table so the numbers are reachable without reading a picture. -->
    <table class="visually-hidden">
      <caption>{{ label }}</caption>
      <thead><tr><th scope="col">Date</th><th scope="col">{{ t('stats.value') }}</th></tr></thead>
      <tbody>
        <tr v-for="bar in bars" :key="`row-${bar.date}`">
          <th scope="row">{{ bar.date }}</th>
          <td>{{ bar.value }}</td>
        </tr>
      </tbody>
    </table>
  </figure>
</template>

<style scoped>
.chart { margin: 0; padding-block: 1.25rem; }
.chart__caption {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 0.5rem;
  margin-block-end: 0.75rem;
  font-size: 0.875rem;
  font-weight: 600;
}
.chart__summary { font-weight: 400; color: var(--muted); font-variant-numeric: tabular-nums; }

.chart__bars {
  display: grid;
  grid-auto-flow: column;
  grid-auto-columns: 1fr;
  gap: 3px;
  align-items: end;
  block-size: 5rem;
  margin: 0;
  padding: 0;
  list-style: none;
  border-block-end: 1px solid var(--rule);
}
.chart__bar { block-size: 100%; display: flex; align-items: flex-end; }
.chart__fill {
  inline-size: 100%;
  background: var(--rule);
  border-radius: 2px 2px 0 0;
}
/* Only the current period carries the accent, so the eye reads the split. */
.chart__bar--current .chart__fill { background: var(--signal); }

.chart__axis {
  display: flex;
  justify-content: space-between;
  margin-block-start: 0.35rem;
  font-size: 0.75rem;
  color: var(--muted);
}
</style>
