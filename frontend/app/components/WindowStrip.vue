<script setup lang="ts">
const { t } = useI18n()
import type { ProjectSummary } from '~/types/api'

/**
 * The seven-day window, drawn.
 *
 * This is the product's central idea made visible: DevRadar is not "all
 * software", it is "what shipped this week", and the window is what makes it
 * useful. A big total with a gradient behind it would say nothing about that.
 *
 * No analytics here -- it counts projects per day and nothing else. The
 * counting is done from data already fetched for the feed, so it costs no
 * extra request.
 */
const props = defineProps<{ projects: ProjectSummary[]; windowDays?: number }>()

const days = computed(() => {
  const span = props.windowDays ?? 7
  const today = new Date()
  today.setHours(0, 0, 0, 0)

  const buckets: Array<{ key: string; label: string; count: number; isToday: boolean }> = []

  for (let offset = span - 1; offset >= 0; offset--) {
    const day = new Date(today)
    day.setDate(day.getDate() - offset)

    buckets.push({
      key: day.toISOString().slice(0, 10),
      label: day.toLocaleDateString('en', { weekday: 'short' }),
      count: 0,
      isToday: offset === 0,
    })
  }

  for (const project of props.projects) {
    const key = project.discovered_at?.slice(0, 10)
    const bucket = buckets.find((b) => b.key === key)
    if (bucket) bucket.count++
  }

  return buckets
})

const busiest = computed(() => Math.max(1, ...days.value.map((d) => d.count)))
</script>

<template>
  <section class="strip" aria-labelledby="strip-heading">
    <h2 id="strip-heading" class="visually-hidden">{{ t('feed.perDayCaption') }}</h2>

    <ol class="strip__days">
      <li
        v-for="day in days"
        :key="day.key"
        class="strip__day"
        :class="{ 'strip__day--today': day.isToday }"
      >
        <span
          class="strip__bar"
          :style="{ blockSize: `${Math.max(2, (day.count / busiest) * 100)}%` }"
        />
        <span class="strip__count">{{ day.count }}</span>
        <span class="strip__label">{{ day.isToday ? 'Today' : day.label }}</span>
      </li>
    </ol>
  </section>
</template>

<style scoped>
.strip { padding-block: 1.5rem 1.25rem; }

.strip__days {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 0.5rem;
  margin: 0;
  padding: 0;
  list-style: none;
  align-items: end;
}

.strip__day {
  display: grid;
  grid-template-rows: 3.5rem auto auto;
  gap: 0.3rem;
  align-items: end;
  justify-items: center;
  text-align: center;
}

.strip__bar {
  inline-size: 100%;
  max-inline-size: 2.5rem;
  background: color-mix(in srgb, var(--signal) 30%, transparent);
  border-radius: 2px 2px 0 0;
  align-self: end;
}
.strip__day--today .strip__bar { background: var(--signal); }

.strip__count {
  font-size: 0.9375rem;
  font-variant-numeric: tabular-nums;
  color: var(--ink);
}
.strip__label { font-size: 0.75rem; color: var(--muted); }
.strip__day--today .strip__label { color: var(--ink); }

@media (max-width: 30rem) {
  .strip__day { grid-template-rows: 2.5rem auto auto; }
  .strip__label { font-size: 0.6875rem; }
}
</style>
