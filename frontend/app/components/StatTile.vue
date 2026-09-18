<script setup lang="ts">
defineProps<{ value: string | number; label: string; hint?: string }>()
</script>

<template>
  <div class="tile">
    <p class="tile__value">{{ value }}</p>
    <p class="tile__label">{{ label }}</p>
    <p v-if="hint" class="tile__hint">{{ hint }}</p>
  </div>
</template>

<style scoped>
.tile { padding-inline-end: 1.5rem; }
.tile__value {
  margin: 0;
  font-size: 1.5rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
  letter-spacing: -0.02em;
  color: var(--ink);
  line-height: 1.1;
}
.tile__label { margin: 0.2rem 0 0; font-size: 0.875rem; color: var(--ink-soft); }
.tile__hint { margin: 0.1rem 0 0; font-size: 0.75rem; color: var(--muted); }
</style>
