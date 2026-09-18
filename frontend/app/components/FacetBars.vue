<script setup lang="ts">
import type { TaxonomyTrend } from '~/types/api'

/**
 * Composition of the week, as proportional bars.
 *
 * Bars rather than a pie: comparing angles is harder than comparing lengths,
 * and a pie of ten categories is unreadable at any size. Each row shows the
 * share of the window, which is what makes a count comparable between a busy
 * week and a quiet one.
 *
 * Movement is a word, not a percentage. Category counts are small by nature
 * -- three projects is a normal week -- so "more than last week" is the most
 * these numbers honestly support.
 */
defineProps<{ facets: TaxonomyTrend[]; label: string; linkKey: 'category' | 'technology' }>()
</script>

<template>
  <section class="facets" :aria-label="label">
    <h3 class="facets__title">{{ label }}</h3>

    <ol class="facets__list">
      <li v-for="facet in facets" :key="facet.slug" class="facet">
        <NuxtLink class="facet__link" :to="{ path: '/latest', query: { [linkKey]: facet.slug } }">
          <span class="facet__name">{{ facet.name }}</span>
          <span class="facet__count">{{ facet.count }}</span>
        </NuxtLink>

        <span class="facet__track" aria-hidden="true">
          <span class="facet__fill" :style="{ inlineSize: `${Math.max(2, facet.share * 100)}%` }" />
        </span>

        <span class="facet__meta">
          {{ (facet.share * 100).toFixed(0) }}%
          <template v-if="facet.direction === 'rising'"> · more than last week</template>
          <template v-else-if="facet.direction === 'falling'"> · fewer than last week</template>
        </span>
      </li>
    </ol>
  </section>
</template>

<style scoped>
.facets { padding-block: 1.25rem; }
.facets__title { margin: 0 0 0.75rem; font-size: 0.9375rem; font-weight: 600; }
.facets__list { margin: 0; padding: 0; list-style: none; display: grid; gap: 0.6rem; }

.facet__link {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  font-size: 0.875rem;
  text-decoration: none;
  color: var(--ink);
}
.facet__link:hover .facet__name { text-decoration: underline; text-underline-offset: 3px; }
.facet__count { font-variant-numeric: tabular-nums; color: var(--muted); }

.facet__track {
  display: block;
  block-size: 4px;
  margin-block: 0.2rem;
  background: var(--paper-sunk);
  border-radius: 2px;
  overflow: hidden;
}
.facet__fill { display: block; block-size: 100%; background: var(--signal); }
.facet__meta { font-size: 0.75rem; color: var(--muted); font-variant-numeric: tabular-nums; }
</style>
