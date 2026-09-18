// DevRadar frontend configuration.
//
// Two rendering strategies in one application, matching the architecture:
//   - public routes are server-rendered so the weekly feed is discoverable;
//   - admin routes are client-only, because they are an authenticated
//     spend-authorising surface with no SEO value.
//
// No business logic belongs in this file. The API base URL is the only
// environment-dependent value and it is injected at runtime.

export default defineNuxtConfig({
  compatibilityDate: '2025-01-01',

  srcDir: 'app/',

  devtools: { enabled: true },

  css: ['~/assets/css/main.css'],

  runtimeConfig: {
    public: {
      // Overridden at runtime by NUXT_PUBLIC_API_BASE_URL.
      apiBaseUrl: '',
    },
  },

  routeRules: {
    /*
     * Everything is server-rendered. The feed only changes when a pipeline
     * run publishes, so it is highly cacheable, and a project page rendered
     * on the server is one a search engine and a link preview can both read.
     *
     * The short SWR windows are deliberate rather than absent: a stale feed
     * for a minute is fine, and it means a burst of readers costs one query
     * rather than one each.
     */
    '/': { swr: 60 },
    '/trending': { swr: 60 },
    '/latest': { swr: 60 },
    '/categories': { swr: 300 },
    '/technologies': { swr: 300 },
    // Aggregations are already cached server-side; this stops a burst of
    // readers turning into a burst of API calls on top of that.
    '/stats': { swr: 300 },
    /*
     * Project pages deliberately do NOT use swr.
     *
     * Nitro's route cache serves a cached render with a 200, which swallows
     * the 404 a missing project must return -- verified: the body was the
     * error page, the status was 200. A wrong status code breaks link
     * previews, search indexing and any client that checks it.
     *
     * Caching moves to a cache-control header instead, where a reverse proxy
     * or CDN handles each status correctly.
     */
    '/p/**': { headers: { 'cache-control': 'public, s-maxage=120, stale-while-revalidate=600' } },
  },

  typescript: {
    strict: true,
  },
})
