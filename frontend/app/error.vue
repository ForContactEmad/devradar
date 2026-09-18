<script setup lang="ts">
const { t } = useI18n()
import type { NuxtError } from '#app'

/**
 * The error page.
 *
 * Without this file Nuxt falls back to its own, which announces the framework
 * and, in development, prints a stack trace. Neither is what a reader who
 * followed a dead link should see.
 *
 * A 404 is treated differently from everything else because it is usually the
 * reader's own mistake and is recoverable by them: a project that aged out of
 * the seven-day window is the most common cause, and saying so is more useful
 * than an apology.
 */
const props = defineProps<{ error: NuxtError }>()

const isNotFound = computed(() => props.error.statusCode === 404)

useHead({ title: isNotFound.value ? 'Not found — DevRadar' : 'Something went wrong — DevRadar' })
</script>

<template>
  <div class="shell error">
    <p class="error__code">{{ error.statusCode }}</p>

    <template v-if="isNotFound">
      <h1 class="error__title">{{ t('error.notFoundTitle') }}</h1>
      <p class="error__body">
        DevRadar only keeps a rolling seven-day window, so a project that was here last
        week has since aged out. The link is not broken; the project simply left the feed.
      </p>
    </template>

    <template v-else>
      <h1 class="error__title">{{ t('state.errorTitle') }}</h1>
      <p class="error__body">
        This is our problem, not yours. The feed is usually back within a few minutes.
      </p>
    </template>

    <nav class="error__actions">
      <NuxtLink to="/">{{ t('error.backHome') }}</NuxtLink>
      <NuxtLink to="/latest">{{ t('error.browseAll') }}</NuxtLink>
    </nav>
  </div>
</template>

<style scoped>
.error { padding-block: 5rem 3rem; max-inline-size: 44ch; }
.error__code {
  margin: 0;
  font-size: 0.8125rem;
  font-variant-numeric: tabular-nums;
  color: var(--muted);
}
.error__title { margin: 0.25rem 0 0.75rem; font-size: 1.5rem; font-weight: 600; }
.error__body {
  margin: 0;
  font-family: var(--font-prose);
  font-size: 1rem;
  line-height: 1.6;
  color: var(--ink-soft);
}
.error__actions { display: flex; gap: 1rem; margin-block-start: 1.5rem; }
.error__actions a {
  font-size: 0.875rem;
  color: var(--ink);
  text-decoration: none;
  border-block-end: 1px solid var(--rule);
  padding-block-end: 2px;
}
.error__actions a:hover { border-block-end-color: var(--ink); }
</style>
