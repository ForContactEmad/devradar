<script setup lang="ts">
const { t } = useI18n()
const { categories } = useCategories()
// Capped: the panel is a dropdown, not a directory, and the long tail of
// one-project technologies is noise in a picker.
const { technologies } = useTechnologies(40)

const { projects, page, filters, isFiltered, activeFilters, status, error, apply, toggle, goToPage, clear, refresh }
  = useProjectFeed({ sort: 'trending' })

useHead({ title: 'Trending — DevRadar' })
</script>

<template>
  <div>
    <div class="page-head">
      <h1 class="page-head__title">{{ t('feed.risingNow') }}</h1>
      <p class="page-head__lede">
        Ordered by how fast a project is gaining attention, which is a different question from
        which is currently biggest. A project needs two measurements before it has a trajectory,
        so the newest arrivals appear here a few hours after they are found.
      </p>
    </div>

    <FilterBar
      :filters="filters"
      :categories="categories"
      :technologies="technologies"
      :is-filtered="isFiltered"
      :active-filters="activeFilters"
      :total="page?.total"
      @apply="apply"
      @toggle="toggle"
      @clear="clear"
    />

    <StateBlock
      :status="status" :error="error" :empty="projects.length === 0"
      empty-title="Nothing is trending under these filters"
      empty-body="Try clearing a filter, or look at the highest ranked projects instead."
      @retry="refresh" />

    <ProjectList v-if="projects.length" :projects="projects" />

    <PaginationNav v-if="page" :page="page.page" :last-page="page.lastPage" :total="page.total"
      @go="goToPage" />
  </div>
</template>
