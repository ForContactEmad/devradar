<script setup lang="ts">
const { t } = useI18n()
import { compactNumber, relativeTime } from '~/utils/format'

/**
 * Statistics and trends.
 *
 * Every figure arrives computed. Nothing on this page aggregates, averages or
 * compares -- doing so would put a second implementation of the statistics
 * beside the backend's, and the two would disagree the first time one changed.
 */
const { report, status, error, refresh } = useTrends()

const LABELS: Record<string, { label: string; unit?: string }> = {
  projects_discovered: { label: 'Projects discovered' },
  total_engagement: { label: 'Total interactions' },
  average_score: { label: 'Average score', unit: 'score' },
  projects_with_repository: { label: 'With source code' },
}

useHead({ title: 'Statistics — DevRadar' })
</script>

<template>
  <div>
    <div class="page-head">
      <h1 class="page-head__title">{{ t('stats.title') }}</h1>
      <p class="page-head__lede">
        Every figure is shown against the equivalent period before it, because a number on its own
        cannot tell you whether anything is happening. Where there are too few projects to support
        a direction, the comparison says so rather than drawing an arrow.
      </p>
    </div>

    <StateBlock :status="status" :error="error" @retry="refresh" />

    <template v-if="report">
      <section class="headlines" aria-label="Headline figures">
        <TrendTile
          v-for="trend in report.headlines"
          :key="trend.metric"
          :trend="trend"
          :label="LABELS[trend.metric]?.label ?? trend.metric"
          :unit="LABELS[trend.metric]?.unit"
        />
      </section>

      <DailySeriesChart
        :series="report.projects_per_day"
        :window-days="report.window_days"
        label="Projects discovered per day"
      />

      <DailySeriesChart
        :series="report.engagement_per_day"
        :window-days="report.window_days"
        label="Interactions per day"
      />

      <!--
        Only drawn once enrichment has measured the same repositories twice.
        A flat line at zero would suggest a trend nobody observed.
      -->
      <DailySeriesChart
        v-if="report.repository_stars"
        :series="report.repository_stars"
        :window-days="report.window_days"
        label="GitHub stars across tracked repositories"
      />

      <div class="composition">
        <FacetBars :facets="report.top_categories" label="Categories this week" link-key="category" />
        <FacetBars :facets="report.top_technologies" label="Technologies this week" link-key="technology" />
      </div>

      <section v-if="report.top_projects.length" aria-labelledby="top-heading">
        <h2 id="top-heading" class="section-title">{{ t('stats.topProjects') }}</h2>

        <ol class="ranked">
          <li v-for="(project, index) in report.top_projects" :key="project.slug" class="ranked__row">
            <span class="ranked__position">{{ index + 1 }}</span>
            <NuxtLink class="ranked__name" :to="`/p/${project.slug}`">{{ project.name }}</NuxtLink>
            <span class="ranked__meta">
              {{ project.score.toFixed(0) }} · {{ compactNumber(project.engagement) }} interactions
              <template v-if="project.stars !== null"> · {{ compactNumber(project.stars) }} stars</template>
            </span>
          </li>
        </ol>
      </section>

      <p v-if="report.generated_at" class="generated">
        Figures computed {{ relativeTime(report.generated_at) }} and cached; they refresh with the
        pipeline rather than on every visit.
      </p>
    </template>
  </div>
</template>

<style scoped>
.headlines {
  display: flex;
  flex-wrap: wrap;
  gap: 1.5rem 0;
  padding-block: 1.25rem 1.75rem;
  border-block: 1px solid var(--rule);
}
.composition {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));
  gap: 0 2.5rem;
  border-block-start: 1px solid var(--rule);
}
.section-title {
  margin: 0 0 0.75rem;
  padding-block-start: 1.5rem;
  border-block-start: 1px solid var(--rule);
  font-size: 1.0625rem;
  font-weight: 600;
}
.ranked { margin: 0; padding: 0; list-style: none; }
.ranked__row {
  display: grid;
  grid-template-columns: 1.5rem 1fr auto;
  gap: 0.75rem;
  align-items: baseline;
  padding-block: 0.5rem;
  border-block-end: 1px solid var(--rule);
}
.ranked__row:last-child { border-block-end: none; }
.ranked__position { font-variant-numeric: tabular-nums; color: var(--muted); font-size: 0.8125rem; }
.ranked__name { font-weight: 500; text-decoration: none; color: var(--ink); }
.ranked__name:hover { text-decoration: underline; text-underline-offset: 3px; }
.ranked__meta { font-size: 0.8125rem; color: var(--muted); font-variant-numeric: tabular-nums; }
.generated { margin-block-start: 1.5rem; font-size: 0.75rem; color: var(--muted); }
</style>
