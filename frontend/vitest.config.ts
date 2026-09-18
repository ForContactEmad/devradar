import { defineConfig } from 'vitest/config'

// Frontend tests cover composables and the API client contract.
// Component and journey coverage arrives with the dashboard phase.
export default defineConfig({
  test: {
    environment: 'happy-dom',
    include: ['tests/**/*.spec.ts'],
  },
})
