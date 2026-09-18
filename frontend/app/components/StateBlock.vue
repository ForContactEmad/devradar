<script setup lang="ts">
import type { ApiError } from '~/api'

/**
 * Loading, empty and error in one place.
 *
 * These three states get improvised differently in every component that
 * invents them, and the error one is usually the worst: a shrug and a sad
 * face. An error should say what happened and what to do about it, in the
 * interface's voice.
 */
const props = defineProps<{
  status: 'idle' | 'pending' | 'success' | 'error'
  error?: ApiError | null
  empty?: boolean
  /** What the person can do when there is nothing here. */
  emptyTitle?: string
  emptyBody?: string
}>()

const emit = defineEmits<{ retry: [] }>()

const message = computed(() => {
  const error = props.error
  if (!error) return null

  switch (error.type) {
    case 'network_error':
      return { title: 'The API is not responding', body: 'Check that the backend is running, then try again.' }
    case 'invalid_query':
    case 'validation_failed':
      // The caller's own mistake, and the backend says exactly what is wrong.
      return { title: 'That filter is not valid', body: error.message }
    case 'rate_limited':
      return { title: 'Too many requests', body: 'Wait a moment, then try again.' }
    default:
      return {
        title: 'Something went wrong on our side',
        body: error.requestId ? `Quote request ${error.requestId} if you report this.` : 'Try again in a moment.',
      }
  }
})
</script>

<template>
  <p v-if="status === 'pending'" class="state" role="status">Loading projects…</p>

  <div v-else-if="message" class="state state--error" role="alert">
    <p class="state__title">{{ message.title }}</p>
    <p class="state__body">{{ message.body }}</p>
    <button v-if="error?.isRetryable" type="button" class="state__action" @click="emit('retry')">
      Try again
    </button>
  </div>

  <div v-else-if="empty" class="state">
    <p class="state__title">{{ emptyTitle ?? 'Nothing here yet' }}</p>
    <p class="state__body">{{ emptyBody ?? 'Projects appear as the pipeline discovers them.' }}</p>
  </div>
</template>

<style scoped>
.state { padding-block: 2.5rem; color: var(--muted); font-size: 0.9375rem; }
.state--error { border-inline-start: 3px solid var(--ink); padding-inline-start: 1rem; }
.state__title { margin: 0 0 0.25rem; color: var(--ink); font-weight: 600; }
.state__body { margin: 0; max-inline-size: 52ch; }
.state__action {
  margin-block-start: 0.75rem;
  padding: 0.375rem 0.75rem;
  font: inherit;
  font-size: 0.875rem;
  color: var(--ink);
  background: transparent;
  border: 1px solid var(--ink);
  border-radius: 3px;
  cursor: pointer;
}
.state__action:hover { background: var(--ink); color: var(--paper); }
</style>
