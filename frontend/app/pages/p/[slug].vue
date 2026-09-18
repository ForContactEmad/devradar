<script setup lang="ts">
const { t } = useI18n()
import { absoluteDate, compactNumber, exactNumber, handle, humanise, relativeTime, safeHref, shortLink } from '~/utils/format'

const route = useRoute()
const slug = computed(() => String(route.params.slug))

const { project, status, error, notFound, refresh } = await useProject(slug)

/*
 * A missing project is a 404 page with a 404 status, not an error banner
 * inside a layout that pretends the project exists. Thrown during setup,
 * before anything renders, so the status code is still ours to set.
 */
if (notFound.value) {
  /*
   * The status is set explicitly as well as thrown. Verified: throwing alone
   * rendered the correct error page with a 200, because the response status
   * had already been decided by the time the thrown error was handled. A
   * wrong status code breaks link previews, search indexing, and any client
   * that checks it -- so it is worth two lines rather than one.
   */
  setResponseStatus(useRequestEvent(), 404)
  throw createError({ statusCode: 404, statusMessage: 'Project not found', fatal: true })
}

useHead(() => ({
  title: project.value ? `${project.value.name} — DevRadar` : 'DevRadar',
  meta: project.value?.description
    ? [{ name: 'description', content: project.value.description }]
    : [],
}))

/**
 * The ranking, made legible.
 *
 * NO SCORING HAPPENS HERE. The backend computed every value, weight and
 * contribution; this reads the breakdown it sent and sorts by contribution so
 * the biggest reason a project ranks where it does comes first. Unavailable
 * components are shown as unavailable rather than as zero, because those are
 * different claims.
 */
const components = computed(() => {
  const breakdown = project.value?.score_breakdown
  if (!breakdown?.components) return []

  return Object.entries(breakdown.components)
    .map(([name, component]) => ({ name, ...component }))
    .sort((a, b) => b.contribution - a.contribution)
})
</script>

<template>
  <div>
    <StateBlock :status="status" :error="error" @retry="refresh" />

    <article v-if="project">
      <div class="page-head">
        <p class="crumb">
          <NuxtLink to="/latest">{{ t('project.allProjects') }}</NuxtLink>
          <NuxtLink :to="{ path: '/latest', query: { category: project.category } }">
            {{ humanise(project.category) }}
          </NuxtLink>
        </p>

        <div class="title">
          <h1 class="page-head__title title__name">{{ project.name }}</h1>
          <ScoreMeter :score="project.score" size="lg" />
        </div>

        <p v-if="project.description" class="page-head__lede">{{ project.description }}</p>

        <p class="byline">
          <span v-if="project.author">
            Announced by
            <a v-if="safeHref(project.links.source_post)" :href="safeHref(project.links.source_post)!"
              rel="noopener noreferrer nofollow" target="_blank">
              {{ handle(project.author) }}
            </a>
          </span>
          <time :datetime="project.discovered_at">
            {{ relativeTime(project.discovered_at) }} · {{ absoluteDate(project.discovered_at) }}
          </time>
        </p>
      </div>

      <nav class="actions" aria-label="Project links">
        <a v-if="safeHref(project.links.repository)" class="actions__primary"
          :href="safeHref(project.links.repository)!" rel="noopener noreferrer nofollow" target="_blank">
          Source code
        </a>
        <a v-if="safeHref(project.links.website)" :href="safeHref(project.links.website)!"
          rel="noopener noreferrer nofollow" target="_blank">{{ t('project.website') }}</a>
        <a v-if="safeHref(project.links.demo)" :href="safeHref(project.links.demo)!"
          rel="noopener noreferrer nofollow" target="_blank">{{ t('project.demo') }}</a>
        <a v-if="safeHref(project.links.source_post)" :href="safeHref(project.links.source_post)!"
          rel="noopener noreferrer nofollow" target="_blank">
          Announcement on X
        </a>
      </nav>

      <section v-if="project.technologies.length" class="block" aria-labelledby="stack-heading">
        <h2 id="stack-heading" class="block__title">{{ t('project.builtWith') }}</h2>
        <div class="tags">
          <TechBadge v-for="tech in project.technologies" :key="tech" :slug="tech" interactive />
        </div>
      </section>

      <section v-if="project.repository" class="block" aria-labelledby="repo-heading">
        <h2 id="repo-heading" class="block__title">
          Repository
          <a v-if="safeHref(project.repository.url)" :href="safeHref(project.repository.url)!"
            rel="noopener noreferrer nofollow" target="_blank">
            {{ shortLink(project.repository.url) }}
          </a>
        </h2>

        <dl class="facts">
          <div v-if="project.repository.stars !== null">
            <dt>{{ t('project.stars') }}</dt>
            <dd :title="exactNumber(project.repository.stars)">
              {{ compactNumber(project.repository.stars) }}
            </dd>
          </div>
          <div v-if="project.repository.forks !== null">
            <dt>{{ t('project.forks') }}</dt>
            <dd>{{ compactNumber(project.repository.forks) }}</dd>
          </div>
          <div v-if="project.repository.contributors !== null">
            <dt>{{ t('project.contributors') }}</dt>
            <dd>{{ compactNumber(project.repository.contributors) }}</dd>
          </div>
          <div v-if="project.repository.open_issues !== null">
            <dt>{{ t('project.openIssues') }}</dt>
            <dd>{{ compactNumber(project.repository.open_issues) }}</dd>
          </div>
          <div v-if="project.repository.primary_language">
            <dt>{{ t('project.language') }}</dt>
            <dd>{{ project.repository.primary_language }}</dd>
          </div>
          <div v-if="project.repository.license">
            <dt>{{ t('project.licence') }}</dt>
            <dd>{{ project.repository.license }}</dd>
          </div>
          <div v-if="project.repository.created_at">
            <dt>{{ t('project.created') }}</dt>
            <dd>{{ absoluteDate(project.repository.created_at) }}</dd>
          </div>
          <div v-if="project.repository.last_commit_at">
            <dt>{{ t('project.lastCommit') }}</dt>
            <dd>{{ relativeTime(project.repository.last_commit_at) }}</dd>
          </div>
        </dl>

        <p v-if="project.repository.is_archived" class="note">
          This repository is archived, so it is no longer maintained.
        </p>
        <p v-if="project.repository.fetched_at" class="note note--quiet">
          Repository data last checked {{ relativeTime(project.repository.fetched_at) }}.
        </p>
      </section>

      <section v-if="components.length" class="block" aria-labelledby="score-heading">
        <h2 id="score-heading" class="block__title">{{ t('project.whyRanked') }}</h2>

        <ul class="reasons">
          <li v-for="component in components" :key="component.name" class="reason">
            <div class="reason__head">
              <span class="reason__name">{{ humanise(component.name) }}</span>
              <span v-if="component.available" class="reason__weight">
                {{ Math.round(component.effective_weight * 100) }}% of the score
              </span>
              <span v-else class="reason__weight reason__weight--absent">not measured</span>
            </div>
            <p class="reason__why">{{ component.why }}</p>
          </li>
        </ul>
      </section>
    </article>
  </div>
</template>

<style scoped>
.crumb { display: flex; gap: 0.5rem; margin: 0 0 0.75rem; font-size: 0.8125rem; color: var(--muted); }
.crumb a { color: var(--muted); text-decoration: none; }
.crumb a:hover { color: var(--ink); }
.crumb a + a::before { content: '/'; margin-inline-end: 0.5rem; color: var(--rule); }

.title { display: flex; align-items: baseline; justify-content: space-between; gap: 1.5rem; flex-wrap: wrap; }
.title__name { font-size: 1.875rem; }

.byline { display: flex; gap: 0.75rem; flex-wrap: wrap; margin: 0.75rem 0 0; font-size: 0.875rem; color: var(--muted); }
.byline a { color: var(--ink); }

.actions { display: flex; gap: 0.5rem; flex-wrap: wrap; padding-block: 1.25rem; border-block-end: 1px solid var(--rule); }
.actions a {
  padding: 0.4rem 0.8rem;
  font-size: 0.875rem;
  text-decoration: none;
  border: 1px solid var(--rule);
  border-radius: 3px;
}
.actions a:hover { border-color: var(--ink); }
.actions__primary { background: var(--ink); color: var(--paper); border-color: var(--ink); }
.actions__primary:hover { background: var(--ink-soft); }

.block { padding-block: 1.5rem; border-block-end: 1px solid var(--rule); }
.block:last-of-type { border-block-end: none; }
.block__title {
  display: flex;
  align-items: baseline;
  gap: 0.75rem;
  margin: 0 0 0.875rem;
  font-size: 1.0625rem;
  font-weight: 600;
}
.block__title a { font-size: 0.875rem; font-weight: 400; color: var(--muted); }

.tags { display: flex; flex-wrap: wrap; gap: 0.375rem; }

.facts { display: grid; grid-template-columns: repeat(auto-fill, minmax(9rem, 1fr)); gap: 1rem; margin: 0; }
.facts dt { font-size: 0.8125rem; color: var(--muted); }
.facts dd { margin: 0.1rem 0 0; font-size: 1rem; font-variant-numeric: tabular-nums; }

.note { margin: 1rem 0 0; font-size: 0.875rem; color: var(--ink-soft); }
.note--quiet { color: var(--muted); font-size: 0.8125rem; }

.reasons { margin: 0; padding: 0; list-style: none; display: grid; gap: 0.875rem; }
.reason { padding-inline-start: 0.875rem; border-inline-start: 2px solid var(--rule); }
.reason__head { display: flex; align-items: baseline; gap: 0.625rem; flex-wrap: wrap; }
.reason__name { font-weight: 600; font-size: 0.9375rem; }
.reason__weight { font-size: 0.8125rem; color: var(--muted); font-variant-numeric: tabular-nums; }
.reason__weight--absent { font-style: italic; }
.reason__why {
  margin: 0.2rem 0 0;
  font-family: var(--font-prose);
  font-size: 0.9375rem;
  line-height: 1.55;
  color: var(--ink-soft);
  max-inline-size: 62ch;
}
</style>
