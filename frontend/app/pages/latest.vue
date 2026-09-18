<script setup lang="ts">
const { t } = useI18n()
const { categories } = useCategories()
// Capped: the panel is a dropdown, not a directory, and the long tail of
// one-project technologies is noise in a picker.
const { technologies } = useTechnologies(40)

const { projects, page, filters, isFiltered, activeFilters, status, error, apply, toggle, goToPage, clear, refresh }
  = useProjectFeed({ sort: 'latest' })

useHead({ title: 'Latest — DevRadar' })
</script>

<template>
  <div>
    <div class="page-head">
      <h1 class="page-head__title">{{ t('feed.latestTitle') }}</h1>
      <p class="page-head__lede">
        Everything found this week in publication order, ignoring score. Use the filters to narrow
        by category or technology.
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
      empty-title="No projects match these filters"
      empty-body="Clear a filter to widen the search."
      @retry="refresh" />

    <ProjectList v-if="projects.length" :projects="projects" />

    <PaginationNav v-if="page" :page="page.page" :last-page="page.lastPage" :total="page.total"
      @go="goToPage" />
  </div>
</template>
