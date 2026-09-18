<script setup lang="ts">
/**
 * Direction is resolved once, here. Components use logical properties
 * (inline-start, block-end) throughout, so the Arabic tree is the same
 * components rather than a parallel set with mirrored rules.
 */
// Locale now comes from a cookie so the FIRST server-rendered paint is in the
// right language. The previous useState reset on refresh and never reached
// the server, so every page loaded in English and flipped after hydration.
const { locale, dir, t, toggle } = useI18n()

useHead({
  htmlAttrs: computed(() => ({ lang: locale.value, dir: dir.value })),
  meta: [{ name: 'color-scheme', content: 'light' }],
})

const nav = [
  { to: '/', key: 'nav.thisWeek' },
  { to: '/trending', key: 'nav.trending' },
  { to: '/latest', key: 'nav.latest' },
  { to: '/categories', key: 'nav.categories' },
  { to: '/technologies', key: 'nav.technologies' },
  { to: '/stats', key: 'nav.statistics' },
]
</script>

<template>
  <div>
    <a class="skip-link" href="#main">{{ t('nav.skipToContent') }}</a>

    <header class="masthead">
      <div class="shell masthead__inner">
        <NuxtLink to="/" class="masthead__brand">
          DevRadar
          <span class="masthead__tagline">what shipped this week</span>
        </NuxtLink>

        <nav class="masthead__nav" aria-label="Sections">
          <NuxtLink v-for="item in nav" :key="item.to" :to="item.to">{{ t(item.key) }}</NuxtLink>
        </nav>

        <button
          type="button"
          class="masthead__locale"
          :aria-label="t('nav.switchLanguage')"
          @click="toggle()"
        >{{ locale === 'en' ? 'العربية' : 'English' }}</button>
      </div>
    </header>

    <main id="main" class="shell">
      <slot />
    </main>

    <footer class="footer">
      <div class="shell">
        <p>
          Projects are discovered from public posts on X, classified, and ranked on a rolling
          seven-day window. Links go to their authors.
        </p>
      </div>
    </footer>
  </div>
</template>

<style scoped>
.masthead { border-block-end: 1px solid var(--rule); background: var(--paper); }
.masthead__inner {
  display: flex;
  align-items: baseline;
  gap: 1.5rem;
  padding-block: 1rem;
  flex-wrap: wrap;
}

.masthead__brand {
  display: flex;
  align-items: baseline;
  gap: 0.5rem;
  font-size: 1.0625rem;
  font-weight: 600;
  letter-spacing: -0.02em;
  color: var(--ink);
  text-decoration: none;
}
.masthead__tagline {
  font-family: var(--font-prose);
  font-size: 0.8125rem;
  font-weight: 400;
  font-style: italic;
  color: var(--muted);
}

.masthead__nav { display: flex; gap: 1.125rem; margin-inline-end: auto; flex-wrap: wrap; }
.masthead__nav a {
  font-size: 0.9375rem;
  color: var(--muted);
  text-decoration: none;
  padding-block-end: 2px;
  border-block-end: 2px solid transparent;
}
.masthead__nav a:hover { color: var(--ink); }
.masthead__nav a.router-link-active {
  color: var(--ink);
  border-block-end-color: var(--signal);
}

.masthead__locale {
  font: inherit;
  font-size: 0.8125rem;
  color: var(--muted);
  background: transparent;
  border: 1px solid var(--rule);
  border-radius: 999px;
  padding: 0.2rem 0.7rem;
  cursor: pointer;
}
.masthead__locale:hover { color: var(--ink); border-color: var(--ink); }

.footer {
  margin-block-start: 3rem;
  padding-block: 1.5rem 2.5rem;
  border-block-start: 1px solid var(--rule);
}
.footer p {
  margin: 0;
  font-size: 0.8125rem;
  color: var(--muted);
  max-inline-size: 60ch;
}

@media (max-width: 40rem) {
  .masthead__inner { gap: 0.75rem; }
  .masthead__nav { order: 3; inline-size: 100%; gap: 0.875rem; }
}
</style>
