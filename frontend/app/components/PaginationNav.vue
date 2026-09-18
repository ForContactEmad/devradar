<script setup lang="ts">
const { t } = useI18n()
/**
 * Previous / next with position.
 *
 * Numbered pages are omitted deliberately: a seven-day window holds tens of
 * projects, so the list is one or two pages and a row of page numbers would
 * be chrome around a control nobody needs.
 */
const props = defineProps<{ page: number; lastPage: number; total: number }>()
const emit = defineEmits<{ go: [page: number] }>()

const hasPrev = computed(() => props.page > 1)
const hasNext = computed(() => props.page < props.lastPage)
</script>

<template>
  <nav v-if="lastPage > 1" class="pager" aria-label="Pagination">
    <button type="button" :disabled="!hasPrev" @click="emit('go', page - 1)">{{ t('pagination.previous') }}</button>
    <p class="pager__position" aria-live="polite">
      Page {{ page }} of {{ lastPage }} · {{ total }} projects
    </p>
    <button type="button" :disabled="!hasNext" @click="emit('go', page + 1)">Next</button>
  </nav>
</template>

<style scoped>
.pager {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding-block: 1.5rem;
}
.pager button {
  padding: 0.375rem 0.75rem;
  font: inherit;
  font-size: 0.875rem;
  color: var(--ink);
  background: transparent;
  border: 1px solid var(--rule);
  border-radius: 3px;
  cursor: pointer;
}
.pager button:hover:not(:disabled) { border-color: var(--ink); }
.pager button:disabled { color: var(--muted); cursor: not-allowed; opacity: 0.5; }
.pager__position { margin: 0; font-size: 0.8125rem; color: var(--muted); }
</style>
