<script setup lang="ts">
const { t } = useI18n()
import type { ProjectFilters, ProjectSort, TaxonomyCount } from '~/types/api'
import { humanise } from '~/utils/format'

/**
 * Search, sort and filter controls.
 *
 * HOLDS NO STATE. The URL is the source of truth; this component says what
 * the person asked for and renders what the URL currently says. A local copy
 * would be a second thing to keep in step, and it would fall out of step the
 * first time somebody used the back button.
 *
 * FILTERING HAPPENS IN THE DATABASE. Every control emits an intent, the URL
 * changes, and the API returns a new page. Narrowing a list client-side would
 * mean downloading the whole dataset to hide most of it, which gets slower
 * every week the feed runs.
 */
const props = defineProps<{
  filters: ProjectFilters
  categories: TaxonomyCount[]
  technologies: TaxonomyCount[]
  isFiltered: boolean
  activeFilters: Array<{ key: string; label: string; remove: Partial<ProjectFilters> }>
  total?: number
}>()

const emit = defineEmits<{
  apply: [patch: Partial<ProjectFilters>]
  toggle: [key: 'category' | 'technology', value: string]
  clear: []
}>()

/**
 * Search submits rather than filtering as you type.
 *
 * Every keystroke would be a request, and the API rejects terms under two
 * characters -- so a live search spends three round trips discovering that
 * "pos" is too short on the way to "postgres".
 */
const term = ref(props.filters.q ?? '')
watch(() => props.filters.q, (next) => { term.value = next ?? '' })

const sorts: Array<{ value: ProjectSort; label: string }> = [
  { value: 'score', label: 'Best' },
  { value: 'trending', label: 'Rising' },
  { value: 'latest', label: 'Newest' },
  { value: 'engagement', label: 'Most discussed' },
]

/**
 * The categories worth a one-tap shortcut.
 *
 * Not every category: thirteen chips is a second navigation bar. These four
 * are the ones people arrive looking for; the full list stays in the panel.
 */
const QUICK = ['ai', 'saas', 'open-source', 'developer-tools']

const quickFilters = computed(() =>
  QUICK.map((slug) => ({
    slug,
    label: humanise(slug),
    count: props.categories.find((c) => c.slug === slug)?.project_count ?? 0,
    active: (props.filters.category ?? []).includes(slug),
  })).filter((q) => q.count > 0 || q.active),
)

const ages = [
  { value: 1, label: '24 hours' },
  { value: 3, label: '3 days' },
  { value: 7, label: 'This week' },
]

const scores = [50, 70, 85]
const engagements = [100, 500, 2000]

const panelOpen = ref(false)

function submitSearch() {
  const q = term.value.trim()
  emit('apply', { q: q.length >= 2 ? q : undefined })
}

/** A second click on the same value clears it, so a chip is its own undo. */
function pick<K extends keyof ProjectFilters>(key: K, value: ProjectFilters[K]) {
  emit('apply', { [key]: props.filters[key] === value ? undefined : value } as Partial<ProjectFilters>)
}
</script>

<template>
  <section class="filters" aria-label="Search and filter projects">
    <div class="filters__row">
      <form class="filters__search" role="search" @submit.prevent="submitSearch">
        <label class="visually-hidden" for="feed-search">{{ t('filters.search') }}</label>
        <input
          id="feed-search"
          v-model="term"
          type="search"
          placeholder="Search names and descriptions"
          autocomplete="off"
        >
        <button type="submit">{{ t('filters.searchButton') }}</button>
      </form>

      <div class="filters__row-end">
        <label class="visually-hidden" for="feed-sort">{{ t('filters.order') }}</label>
        <select
          id="feed-sort"
          :value="filters.sort"
          @change="emit('apply', { sort: ($event.target as HTMLSelectElement).value as ProjectSort })"
        >
          <option v-for="option in sorts" :key="option.value" :value="option.value">{{ option.label }}</option>
        </select>

        <button
          type="button"
          class="filters__more"
          :aria-expanded="panelOpen"
          aria-controls="filter-panel"
          @click="panelOpen = !panelOpen"
        >{{ panelOpen ? 'Fewer filters' : 'More filters' }}</button>
      </div>
    </div>

    <div v-if="quickFilters.length" class="quick">
      <button
        v-for="quick in quickFilters"
        :key="quick.slug"
        type="button"
        class="chip"
        :class="{ 'chip--on': quick.active }"
        :aria-pressed="quick.active"
        @click="emit('toggle', 'category', quick.slug)"
      >
        {{ quick.label }}
        <span class="chip__count">{{ quick.count }}</span>
      </button>
    </div>

    <div v-show="panelOpen" id="filter-panel" class="panel">
      <fieldset class="panel__group">
        <legend>{{ t('filters.category') }}</legend>
        <label class="visually-hidden" for="feed-category">{{ t('filters.category') }}</label>
        <select
          id="feed-category"
          :value="filters.category?.[0] ?? ''"
          @change="emit('apply', { category: ($event.target as HTMLSelectElement).value ? [($event.target as HTMLSelectElement).value] : [] })"
        >
          <option value="">{{ t('filters.allCategories') }}</option>
          <option v-for="category in categories" :key="category.slug" :value="category.slug">
            {{ humanise(category.slug) }} ({{ category.project_count }})
          </option>
        </select>
      </fieldset>

      <fieldset class="panel__group">
        <legend>{{ t('filters.technology') }}</legend>
        <label class="visually-hidden" for="feed-technology">{{ t('filters.technology') }}</label>
        <select
          id="feed-technology"
          :value="filters.technology?.[0] ?? ''"
          @change="emit('apply', { technology: ($event.target as HTMLSelectElement).value ? [($event.target as HTMLSelectElement).value] : [] })"
        >
          <option value="">{{ t('filters.anyTechnology') }}</option>
          <option v-for="tech in technologies" :key="tech.slug" :value="tech.slug">
            {{ tech.name }} ({{ tech.project_count }})
          </option>
        </select>
      </fieldset>

      <fieldset class="panel__group">
        <legend>{{ t('filters.published') }}</legend>
        <div class="panel__options">
          <button
            v-for="age in ages"
            :key="age.value"
            type="button"
            class="chip"
            :class="{ 'chip--on': filters.within_days === age.value }"
            :aria-pressed="filters.within_days === age.value"
            @click="pick('within_days', age.value)"
          >{{ age.label }}</button>
        </div>
      </fieldset>

      <fieldset class="panel__group">
        <legend>{{ t('filters.minimumScore') }}</legend>
        <div class="panel__options">
          <button
            v-for="score in scores"
            :key="score"
            type="button"
            class="chip"
            :class="{ 'chip--on': filters.min_score === score }"
            :aria-pressed="filters.min_score === score"
            @click="pick('min_score', score)"
          >{{ score }}+</button>
        </div>
      </fieldset>

      <fieldset class="panel__group">
        <legend>{{ t('filters.minimumEngagement') }}</legend>
        <div class="panel__options">
          <button
            v-for="amount in engagements"
            :key="amount"
            type="button"
            class="chip"
            :class="{ 'chip--on': filters.min_engagement === amount }"
            :aria-pressed="filters.min_engagement === amount"
            @click="pick('min_engagement', amount)"
          >{{ amount }}+</button>
        </div>
      </fieldset>

      <fieldset class="panel__group">
        <legend>{{ t('filters.sourceCode') }}</legend>
        <div class="panel__options">
          <button
            type="button"
            class="chip"
            :class="{ 'chip--on': filters.has_repository === true }"
            :aria-pressed="filters.has_repository === true"
            @click="pick('has_repository', true)"
          >{{ t('filters.hasRepository') }}</button>
        </div>
      </fieldset>
    </div>

    <div v-if="activeFilters.length" class="active">
      <p class="active__label" aria-live="polite">
        {{ total ?? 0 }} {{ total === 1 ? 'project' : 'projects' }} matching
      </p>

      <ul class="active__list">
        <li v-for="chip in activeFilters" :key="chip.key">
          <button
            type="button"
            class="chip chip--on chip--removable"
            :aria-label="`Remove filter: ${chip.label}`"
            @click="emit('apply', chip.remove)"
          >{{ chip.label }} <span aria-hidden="true">×</span></button>
        </li>
      </ul>

      <button type="button" class="active__clear" @click="emit('clear')">{{ t('filters.clearAll') }}</button>
    </div>
  </section>
</template>

<style scoped>
.filters { padding-block: 1rem; border-block-end: 1px solid var(--rule); }

.filters__row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
}
.filters__search { display: flex; gap: 0.375rem; }
.filters__row-end { display: flex; gap: 0.375rem; }

input, select, button {
  font: inherit;
  font-size: 0.875rem;
  padding: 0.375rem 0.5rem;
  color: var(--ink);
  background: var(--paper);
  border: 1px solid var(--rule);
  border-radius: 3px;
}
input { min-inline-size: 15rem; }
button { cursor: pointer; }
button:hover, select:hover, input:hover { border-color: var(--ink); }

.quick { display: flex; flex-wrap: wrap; gap: 0.375rem; margin-block-start: 0.75rem; }

.chip {
  display: inline-flex;
  align-items: baseline;
  gap: 0.35rem;
  padding: 0.25rem 0.6rem;
  font-size: 0.8125rem;
  border-radius: 999px;
}
.chip--on { color: var(--paper); background: var(--signal); border-color: var(--signal); }
.chip--on:hover { background: var(--ink); border-color: var(--ink); }
.chip__count { font-variant-numeric: tabular-nums; opacity: 0.7; }
.chip--removable { padding-inline-end: 0.45rem; }

.panel {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
  gap: 1rem;
  margin-block-start: 1rem;
  padding-block-start: 1rem;
  border-block-start: 1px dashed var(--rule);
}
.panel__group { margin: 0; padding: 0; border: none; }
.panel__group legend { padding: 0; margin-block-end: 0.4rem; font-size: 0.8125rem; color: var(--muted); }
.panel__options { display: flex; flex-wrap: wrap; gap: 0.375rem; }
.panel__group select { inline-size: 100%; }

.active { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; margin-block-start: 1rem; }
.active__label { margin: 0; font-size: 0.8125rem; color: var(--muted); }
.active__list { display: flex; flex-wrap: wrap; gap: 0.375rem; margin: 0; padding: 0; list-style: none; }
.active__clear { color: var(--muted); border-style: dashed; font-size: 0.8125rem; }
.active__clear:hover { color: var(--ink); }

@media (max-width: 34rem) {
  .filters__row { flex-direction: column; align-items: stretch; }
  .filters__search { inline-size: 100%; }
  .filters__search input { flex: 1; min-inline-size: 0; }
}
</style>
