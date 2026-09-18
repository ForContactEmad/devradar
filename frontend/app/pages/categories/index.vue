<script setup lang="ts">
const { t } = useI18n()
import { humanise } from '~/utils/format'

const { categories, status, error } = useCategories()

useHead({ title: 'Categories — DevRadar' })
</script>

<template>
  <div>
    <div class="page-head">
      <h1 class="page-head__title">{{ t('nav.categories') }}</h1>
      <p class="page-head__lede">
        What kind of thing each project is. Counts cover the current seven-day window, so a
        category with nothing in it this week is not listed.
      </p>
    </div>

    <StateBlock :status="status" :error="error" :empty="categories.length === 0"
      empty-title="No categories yet"
      empty-body="Categories appear once the pipeline has published its first projects." />

    <ul v-if="categories.length" class="taxonomy">
      <li v-for="category in categories" :key="category.slug">
        <NuxtLink :to="{ path: '/latest', query: { category: category.slug } }">
          <span class="taxonomy__name">{{ humanise(category.slug) }}</span>
          <span class="taxonomy__count">{{ category.project_count }}</span>
        </NuxtLink>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.taxonomy { margin: 0; padding: 0; list-style: none; }
.taxonomy a {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 1rem;
  padding-block: 0.75rem;
  border-block-end: 1px solid var(--rule);
  text-decoration: none;
}
.taxonomy a:hover .taxonomy__name { text-decoration: underline; text-underline-offset: 3px; }
.taxonomy__name { font-weight: 500; }
.taxonomy__count { color: var(--muted); font-variant-numeric: tabular-nums; font-size: 0.9375rem; }
</style>
