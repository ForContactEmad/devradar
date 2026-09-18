<script setup lang="ts">
import type { ProjectSummary } from '~/types/api'

/**
 * A list of project cards.
 *
 * Exists so every page renders the feed the same way, and so the list
 * semantics (ol, li) live in one place rather than being re-decided per page.
 */
defineProps<{ projects: ProjectSummary[]; ranked?: boolean }>()
</script>

<template>
  <ol class="list">
    <li v-for="(project, index) in projects" :key="project.slug">
      <ProjectCard :project="project" :rank="ranked ? index + 1 : undefined" />
    </li>
  </ol>
</template>

<style scoped>
.list { margin: 0; padding: 0; list-style: none; }
.list > li:last-child :deep(.card) { border-block-end: none; }
</style>
