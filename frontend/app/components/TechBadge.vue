<script setup lang="ts">
import { humanise } from '~/utils/format'

/**
 * A technology tag.
 *
 * Clickable ones navigate to a filtered feed; static ones are plain text.
 * A badge that looks interactive but is not is a small lie the user pays for
 * with a wasted click.
 */
const props = defineProps<{ slug: string; interactive?: boolean }>()

const label = computed(() => humanise(props.slug))
</script>

<template>
  <NuxtLink
    v-if="interactive"
    :to="{ path: '/latest', query: { technology: slug } }"
    class="tech tech--link"
  >{{ label }}</NuxtLink>
  <span v-else class="tech">{{ label }}</span>
</template>

<style scoped>
.tech {
  display: inline-block;
  padding: 0.125rem 0.5rem;
  font-size: 0.8125rem;
  line-height: 1.4;
  color: var(--muted);
  border: 1px solid var(--rule);
  border-radius: 3px;
  background: transparent;
  white-space: nowrap;
}
.tech--link { text-decoration: none; transition: color 120ms, border-color 120ms; }
.tech--link:hover, .tech--link:focus-visible { color: var(--ink); border-color: var(--ink); }
</style>
