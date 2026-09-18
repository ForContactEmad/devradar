<script setup lang="ts">
const { t } = useI18n()
import { humanise } from '~/utils/format'

/** Overview: the week at a glance, then the best of it. */
const { stats } = useStats()
const { projects, status, error, refresh } = useProjectFeed({ per_page: 8, sort: 'score' })

useHead({ title: 'DevRadar — what shipped this week' })
</script>

<template>
  <div>
    <div class="page-head">
      <h1 class="page-head__title">{{ t('feed.title') }}</h1>
      <p class="page-head__lede">
        Projects launched or open sourced in the last seven days, found on X and ranked by how
        much they earned attention rather than how loud the announcement was.
      </p>
    </div>

    <WindowStrip :projects="projects" :window-days="stats?.window_days ?? 7" />

    <section v-if="stats" class="summary" aria-label="This week in numbers">
      <StatTile :value="stats.projects_in_window" label="Projects this week" />
      <StatTile :value="stats.projects_published_today" label="Found today" />
      <StatTile
        :value="stats.projects_with_repository"
        label="With source code"
        :hint="`of ${stats.projects_in_window}`"
      />
      <StatTile :value="stats.average_score.toFixed(1)" label="Average score" hint="out of 100" />
    </section>

    <section aria-labelledby="best-heading">
      <div class="section-head">
        <h2 id="best-heading">{{ t('feed.highestRanked') }}</h2>
        <NuxtLink to="/trending">{{ t('feed.seeRising') }}</NuxtLink>
      </div>

      <StateBlock
        :status="status"
        :error="error"
        :empty="projects.length === 0"
        empty-title="No projects yet this week"
        empty-body="The pipeline publishes projects as it finds them. Check back after the next collection run."
        @retry="refresh"
      />

      <ProjectList v-if="projects.length" :projects="projects" ranked />
    </section>

    <section v-if="stats?.top_categories?.length" class="jump" aria-labelledby="jump-heading">
      <h2 id="jump-heading" class="jump__title">{{ t('feed.browseByCategory') }}</h2>
      <ul class="jump__list">
        <li v-for="category in stats.top_categories" :key="category.slug">
          <NuxtLink :to="{ path: '/latest', query: { category: category.slug } }">
            {{ humanise(category.slug) }}
            <span>{{ category.project_count }}</span>
          </NuxtLink>
        </li>
      </ul>
    </section>
  </div>
</template>

<style scoped>
.summary {
  display: flex;
  flex-wrap: wrap;
  gap: 1.5rem 0;
  padding-block: 1.25rem 1.75rem;
  border-block: 1px solid var(--rule);
}
.section-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 1rem;
  padding-block: 1.75rem 0.5rem;
}
.section-head h2 { margin: 0; font-size: 1.125rem; font-weight: 600; }
.section-head a { font-size: 0.875rem; color: var(--muted); text-decoration: none; }
.section-head a:hover { color: var(--ink); text-decoration: underline; text-underline-offset: 3px; }

.jump { padding-block: 2rem 0; }
.jump__title { margin: 0 0 0.75rem; font-size: 1.125rem; font-weight: 600; }
.jump__list { display: flex; flex-wrap: wrap; gap: 0.5rem; margin: 0; padding: 0; list-style: none; }
.jump__list a {
  display: inline-flex;
  gap: 0.4rem;
  padding: 0.3rem 0.65rem;
  font-size: 0.875rem;
  text-decoration: none;
  border: 1px solid var(--rule);
  border-radius: 3px;
}
.jump__list a:hover { border-color: var(--ink); }
.jump__list span { color: var(--muted); font-variant-numeric: tabular-nums; }
</style>
