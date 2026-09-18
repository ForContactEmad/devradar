<script setup lang="ts">
const { t } = useI18n()
import type { ProjectSummary } from '~/types/api'
import { absoluteDate, freshness, handle, humanise, relativeTime, safeHref, shortLink } from '~/utils/format'

/**
 * One project in the feed.
 *
 * Receives data and emits nothing. It performs no fetch and holds no filter
 * state, which is what lets the same card appear on Overview, Trending,
 * Latest and a category page without any of them diverging.
 *
 * THE LEFT RULE ENCODES RECENCY. Its opacity tracks how fresh the project is
 * within the seven-day window, so a scanner sees age before reading a word.
 * That is structure carrying information rather than decorating the row --
 * and recency is the second-heaviest term in the ranking, so it deserves to
 * be visible.
 */
const props = defineProps<{
  project: ProjectSummary
  /** Ordinal in the list. Shown only where rank is the point. */
  rank?: number
}>()

const fresh = computed(() => freshness(props.project.discovered_at))

/** Floored so a week-old project still shows a faint rule rather than none. */
const ruleOpacity = computed(() => 0.15 + fresh.value * 0.85)

const links = computed(() => {
  const l = props.project.links
  const out: Array<{ href: string; label: string; text: string }> = []

  // Each link is scheme-checked before it becomes an href. An unusable URL
  // yields no link rather than a link that runs script.
  const repository = safeHref(l.repository)
  const website = safeHref(l.website)
  const demo = safeHref(l.demo)

  if (repository) out.push({ href: repository, label: `Source code for ${props.project.name}`, text: shortLink(repository) })
  if (website) out.push({ href: website, label: `Website for ${props.project.name}`, text: shortLink(website) })
  if (demo) out.push({ href: demo, label: `Live demo of ${props.project.name}`, text: 'Demo' })

  return out
})
</script>

<template>
  <article class="card" :style="{ '--rule-opacity': ruleOpacity }">
    <div class="card__head">
      <h3 class="card__name">
        <NuxtLink :to="`/p/${project.slug}`">{{ project.name }}</NuxtLink>
      </h3>
      <ScoreMeter :score="project.score" />
    </div>

    <p v-if="project.description" class="card__description">{{ project.description }}</p>
    <p v-else class="card__description card__description--absent">
      No description was given in the announcement.
    </p>

    <div class="card__tags">
      <NuxtLink class="card__category" :to="{ path: '/latest', query: { category: project.category } }">
        {{ humanise(project.category) }}
      </NuxtLink>
      <TechBadge v-for="tech in project.technologies" :key="tech" :slug="tech" interactive />
    </div>

    <MetricLine :stars="project.stars" :engagement="project.engagement" />

    <footer class="card__foot">
      <p class="card__meta">
        <span v-if="project.author">by {{ handle(project.author) }}</span>
        <time :datetime="project.discovered_at" :title="absoluteDate(project.discovered_at)">
          {{ relativeTime(project.discovered_at) }}
        </time>
      </p>

      <nav class="card__links" :aria-label="`Links for ${project.name}`">
        <a
          v-for="link in links"
          :key="link.href"
          :href="link.href"
          :aria-label="link.label"
          rel="noopener noreferrer nofollow"
          target="_blank"
        >{{ link.text }}</a>
        <a
          v-if="safeHref(project.links.source_post)"
          :href="safeHref(project.links.source_post)!"
          :aria-label="`Original announcement for ${project.name} on X`"
          rel="noopener noreferrer nofollow"
          target="_blank"
        >{{ t('project.announcement') }}</a>
      </nav>
    </footer>
  </article>
</template>

<style scoped>
.card {
  position: relative;
  padding-block: 1.375rem;
  padding-inline-start: 1.125rem;
  border-block-end: 1px solid var(--rule);
}

/* The freshness rule. Logical inset so it flips correctly in Arabic. */
.card::before {
  content: '';
  position: absolute;
  inset-block: 1.375rem;
  inset-inline-start: 0;
  inline-size: 3px;
  border-radius: 2px;
  background: var(--signal);
  opacity: var(--rule-opacity, 0.3);
}

.card__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-block-end: 0.4rem;
}

.card__name {
  margin: 0;
  font-size: 1.0625rem;
  font-weight: 600;
  letter-spacing: -0.011em;
  line-height: 1.3;
}
.card__name a { color: var(--ink); text-decoration: none; }
.card__name a:hover { text-decoration: underline; text-underline-offset: 3px; }

/* Serif for prose. The name is scanned; the description is read. */
.card__description {
  margin: 0 0 0.75rem;
  font-family: var(--font-prose);
  font-size: 0.9688rem;
  line-height: 1.6;
  color: var(--ink-soft);
  max-inline-size: 62ch;
}
.card__description--absent { color: var(--muted); font-style: italic; }

.card__tags {
  display: flex;
  flex-wrap: wrap;
  gap: 0.375rem;
  margin-block-end: 0.75rem;
}

.card__category {
  display: inline-block;
  padding: 0.125rem 0.5rem;
  font-size: 0.8125rem;
  line-height: 1.4;
  color: var(--signal);
  border: 1px solid color-mix(in srgb, var(--signal) 35%, transparent);
  border-radius: 3px;
  text-decoration: none;
}
.card__category:hover, .card__category:focus-visible {
  border-color: var(--signal);
}

.card__foot {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
  margin-block-start: 0.75rem;
}

.card__meta {
  display: flex;
  gap: 0.75rem;
  margin: 0;
  font-size: 0.8125rem;
  color: var(--muted);
}

.card__links { display: flex; gap: 0.875rem; flex-wrap: wrap; }
.card__links a {
  font-size: 0.8125rem;
  color: var(--muted);
  text-decoration: none;
  border-block-end: 1px solid var(--rule);
  padding-block-end: 1px;
}
.card__links a:hover, .card__links a:focus-visible {
  color: var(--ink);
  border-block-end-color: var(--ink);
}

@media (max-width: 34rem) {
  .card__head { flex-direction: column; gap: 0.375rem; }
}
</style>
