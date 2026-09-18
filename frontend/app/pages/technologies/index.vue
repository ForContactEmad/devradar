<script setup lang="ts">
const { t } = useI18n()
import { humanise } from '~/utils/format'

const { technologies, status, error } = useTechnologies(120)

/**
 * Grouped by kind, which is a presentation choice: a flat list of sixty tags
 * is hard to scan, and "language / framework / database" is how a developer
 * already thinks about a stack. The grouping key comes from the API.
 */
const groups = computed(() => {
  const byKind = new Map<string, typeof technologies.value>()

  for (const tech of technologies.value) {
    const kind = tech.kind ?? 'other'
    if (!byKind.has(kind)) byKind.set(kind, [])
    byKind.get(kind)!.push(tech)
  }

  return [...byKind.entries()]
    .map(([kind, items]) => ({ kind, items }))
    .sort((a, b) => b.items.length - a.items.length)
})

useHead({ title: 'Technologies — DevRadar' })
</script>

<template>
  <div>
    <div class="page-head">
      <h1 class="page-head__title">{{ t('nav.technologies') }}</h1>
      <p class="page-head__lede">
        Only technologies a project's announcement actually mentioned. Nothing here is inferred
        from what a project looks like, so an absent tag means the post was silent rather than
        that the project does not use it.
      </p>
    </div>

    <StateBlock :status="status" :error="error" :empty="technologies.length === 0"
      empty-title="No technologies recorded yet"
      empty-body="Tags appear as projects are published with evidence of their stack." />

    <section v-for="group in groups" :key="group.kind" class="group">
      <h2 class="group__title">{{ humanise(group.kind) }}</h2>
      <ul class="group__list">
        <li v-for="tech in group.items" :key="tech.slug">
          <NuxtLink :to="{ path: '/latest', query: { technology: tech.slug } }">
            {{ tech.name }}
            <span>{{ tech.project_count }}</span>
          </NuxtLink>
        </li>
      </ul>
    </section>
  </div>
</template>

<style scoped>
.group { padding-block: 1.25rem; border-block-end: 1px solid var(--rule); }
.group:last-of-type { border-block-end: none; }
.group__title { margin: 0 0 0.75rem; font-size: 1rem; font-weight: 600; color: var(--ink-soft); }
.group__list { display: flex; flex-wrap: wrap; gap: 0.5rem; margin: 0; padding: 0; list-style: none; }
.group__list a {
  display: inline-flex;
  gap: 0.4rem;
  padding: 0.3rem 0.65rem;
  font-size: 0.875rem;
  text-decoration: none;
  border: 1px solid var(--rule);
  border-radius: 3px;
}
.group__list a:hover { border-color: var(--ink); }
.group__list span { color: var(--muted); font-variant-numeric: tabular-nums; }
</style>
