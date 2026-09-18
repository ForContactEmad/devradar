/**
 * Development mock of the DevRadar API.
 *
 * WHY THIS EXISTS. The Laravel backend cannot be installed without Composer
 * access, so the frontend would otherwise have nothing to develop against.
 * This serves the exact envelope and field names from
 * backend/docs/openapi.yaml, so a page that works here works against the real
 * API -- and if the two ever disagree, the disagreement is a bug in one of
 * them rather than a surprise in production.
 *
 * It holds no business logic worth having: filtering and sorting here are the
 * crudest thing that satisfies the contract, because the real ranking lives
 * in the backend and duplicating it would be exactly the mistake the brief
 * warns against.
 *
 *   node mock-api/server.mjs        # http://localhost:8000/api/v1
 */
import { createServer } from 'node:http'

const PORT = Number(process.env.MOCK_PORT ?? 8000)

/*
 * A DEVELOPMENT TOOL THAT MUST NOT RUN IN PRODUCTION.
 *
 * It serves fabricated projects with `Access-Control-Allow-Origin: *` and no
 * authentication. Started against a real deployment it would present invented
 * data as though it were the feed, which is worse than being down.
 *
 * Refusing to start is the only reliable guard: a comment saying "dev only"
 * has never stopped anybody.
 */
if (process.env.NODE_ENV === 'production') {
  console.error('mock-api refuses to run with NODE_ENV=production. It serves fabricated data.')
  process.exit(1)
}

// Loopback only. Binding every interface put an unauthenticated, wide-open
// API on whatever network the developer happened to be joined to.
const HOST = process.env.MOCK_HOST ?? '127.0.0.1'

const NAMES = [
  ['Pgplan', 'A Postgres query plan viewer that renders EXPLAIN output as a flame graph.', 'developer-tools', ['rust', 'postgresql']],
  ['Tinysched', 'A 400-line cron replacement with no daemon and no config file.', 'cli', ['go']],
  ['Vuekit', 'Accessible form components for Vue with validation built in.', 'library', ['vue', 'typescript']],
  ['Pytrace', 'Drop-in OpenTelemetry tracing for FastAPI, one import.', 'library', ['python', 'fastapi']],
  ['Edgeorm', 'A typed ORM that runs on edge runtimes without a connection pool.', 'framework', ['typescript', 'cloudflare']],
  ['Invoicer', 'Generates invoices from a plain-text ledger. Self-hosted.', 'saas', ['laravel', 'vue']],
  ['Lensdb', 'Embedded vector search you can ship inside a binary.', 'data', ['rust']],
  ['Shipcheck', 'Pre-deploy checklist that reads your CI config and warns about gaps.', 'devops', ['go', 'docker']],
  ['Promptlint', 'Static analysis for LLM prompts: catches injection-prone templates.', 'ai', ['python', 'openai']],
  ['Statusfold', 'A status page that builds itself from your uptime checks.', 'web-app', ['nextjs', 'postgresql']],
  ['Nudge', 'Tiny library for accessible toast notifications, 1.2kb.', 'library', ['typescript']],
  ['Ferrycache', 'Read-through cache with a Redis-compatible wire protocol.', 'data', ['rust', 'redis']],
]

const projects = NAMES.map(([name, description, category, technologies], index) => {
  const hoursAgo = Math.floor((index * 13) % 160)
  const discovered = new Date(Date.now() - hoursAgo * 3_600_000).toISOString()
  const hasRepo = index % 4 !== 3

  return {
    slug: name.toLowerCase(),
    name,
    description,
    category,
    project_type: ['application', 'library', 'cli'][index % 3],
    technologies,
    links: {
      repository: hasRepo ? `https://github.com/acme/${name.toLowerCase()}` : null,
      website: index % 3 === 0 ? `https://${name.toLowerCase()}.dev` : null,
      demo: index % 5 === 0 ? `https://${name.toLowerCase()}.vercel.app` : null,
      source_post: `https://x.com/acmedev/status/18000000000000000${index}`,
    },
    author: ['acmedev', 'rustacean', 'vuebuilder', 'pyfolk'][index % 4],
    score: Math.round((96 - index * 4.3 + (index % 3) * 2) * 100) / 100,
    stars: hasRepo ? Math.round(120 * (12 - index) ** 1.4) : null,
    discovered_at: discovered,
    engagement: (90 + 12 + 4) * (13 - index),
  }
})

const meta = () => ({ request_id: crypto.randomUUID(), api_version: 'v1' })

function fail(res, status, type, message) {
  send(res, status, { error: { type, message }, meta: meta() })
}

function send(res, status, body) {
  res.writeHead(status, {
    'content-type': 'application/json',
    'access-control-allow-origin': '*',
    'cache-control': 'no-store',
  })
  res.end(JSON.stringify(body))
}

function list(url, res) {
  const q = url.searchParams
  const page = Number(q.get('page') ?? 1)
  const perPage = Number(q.get('per_page') ?? 20)
  const sort = q.get('sort') ?? 'score'
  const term = q.get('q')

  // Mirrors the backend's validation so the frontend's error path is real.
  if (sort === 'relevance' && !term) {
    return fail(res, 422, 'invalid_query', 'sort=relevance requires a search term.')
  }
  if (term && term.trim().length < 2) {
    return fail(res, 422, 'invalid_query', 'search must be at least 2 characters.')
  }
  if (perPage < 1 || perPage > 100) {
    return fail(res, 422, 'invalid_query', 'per_page must be between 1 and 100.')
  }

  const categories = q.getAll('category').flatMap((c) => c.split(','))
  const technologies = q.getAll('technology').flatMap((t) => t.split(','))

  let matching = projects.slice()
  const minScore = Number(q.get('min_score') ?? NaN)
  const minEngagement = Number(q.get('min_engagement') ?? NaN)
  const withinDays = Number(q.get('within_days') ?? NaN)
  const repo = q.get('repo')

  if (withinDays && (withinDays < 1 || withinDays > 7)) {
    return fail(res, 422, 'invalid_query', 'within_days must be between 1 and 7.')
  }
  if (!Number.isNaN(minScore) && (minScore < 0 || minScore > 100)) {
    return fail(res, 422, 'invalid_query', 'min_score must be between 0 and 100.')
  }

  if (categories.length) matching = matching.filter((p) => categories.includes(p.category))
  if (!Number.isNaN(minScore)) matching = matching.filter((p) => p.score >= minScore)
  if (!Number.isNaN(minEngagement)) matching = matching.filter((p) => p.engagement >= minEngagement)
  if (repo === '1') matching = matching.filter((p) => p.links.repository)
  if (repo === '0') matching = matching.filter((p) => !p.links.repository)
  if (withinDays) {
    const cutoff = Date.now() - withinDays * 86_400_000
    matching = matching.filter((p) => new Date(p.discovered_at).getTime() >= cutoff)
  }
  if (technologies.length) matching = matching.filter((p) => p.technologies.some((t) => technologies.includes(t)))
  if (term) {
    const needle = term.toLowerCase()
    matching = matching.filter((p) => `${p.name} ${p.description}`.toLowerCase().includes(needle))
  }

  if (sort === 'latest') matching.sort((a, b) => b.discovered_at.localeCompare(a.discovered_at))
  else if (sort === 'oldest') matching.sort((a, b) => a.discovered_at.localeCompare(b.discovered_at))
  else if (sort === 'engagement') matching.sort((a, b) => b.engagement - a.engagement)
  else if (sort === 'trending') matching.sort((a, b) => (b.stars ?? 0) - (a.stars ?? 0))
  else matching.sort((a, b) => b.score - a.score)

  const total = matching.length
  const lastPage = Math.max(1, Math.ceil(total / perPage))

  send(res, 200, {
    data: matching.slice((page - 1) * perPage, page * perPage),
    meta: { ...meta(), page, per_page: perPage, total, last_page: lastPage, has_more: page < lastPage },
    links: { self: null, first: null, last: null, next: null, prev: null },
  })
}

function detail(slug, res) {
  const project = projects.find((p) => p.slug === slug)
  if (!project) return fail(res, 404, 'not_found', 'The requested resource does not exist.')

  send(res, 200, {
    data: {
      ...project,
      published_at: project.discovered_at,
      extraction_confidence: 0.93,
      score_breakdown: {
        score: project.score,
        dampener: 1,
        notes: project.links.repository ? [] : ['github unavailable: project has no repository link'],
        components: {
          engagement: { value: 0.88, available: true, configured_weight: 0.35, effective_weight: 0.41, contribution: 0.36, why: 'Weighted engagement 1180 at a rate of 0.0421 per follower.' },
          recency: { value: 0.74, available: true, configured_weight: 0.25, effective_weight: 0.29, contribution: 0.21, why: '19.4 hours old; half-life 36 hours gives 0.687.' },
          confidence: { value: 0.91, available: true, configured_weight: 0.15, effective_weight: 0.18, contribution: 0.16, why: '0.912 from blended classification and extraction confidence.' },
          github: project.links.repository
            ? { value: 0.72, available: true, configured_weight: 0.15, effective_weight: 0.12, contribution: 0.09, why: `${project.stars} stars (0.81), ${Math.round((project.stars ?? 0) / 15)} forks (0.62), freshness 0.94.` }
            : { value: 0, available: false, configured_weight: 0.15, effective_weight: 0, contribution: 0, why: 'No repository data (project has no repository link, or enrichment has not run).' },
          growth: { value: 0, available: false, configured_weight: 0.1, effective_weight: 0, contribution: 0, why: 'Fewer than two metric snapshots; no trajectory exists yet.' },
        },
      },
      repository: project.links.repository
        ? {
            url: project.links.repository,
            stars: project.stars,
            forks: Math.round((project.stars ?? 0) / 15),
            open_issues: 17,
            contributors: 12,
            primary_language: project.technologies[0] === 'rust' ? 'Rust' : 'TypeScript',
            license: 'MIT',
            topics: project.technologies,
            last_commit_at: new Date(Date.now() - 6 * 3_600_000).toISOString(),
            created_at: new Date(Date.now() - 40 * 86_400_000).toISOString(),
            is_archived: false,
            fetched_at: new Date(Date.now() - 2 * 3_600_000).toISOString(),
          }
        : null,
      history: [],
    },
    meta: meta(),
  })
}

function taxonomy(kind, url, res) {
  const counts = new Map()

  for (const project of projects) {
    const keys = kind === 'categories' ? [project.category] : project.technologies
    for (const key of keys) counts.set(key, (counts.get(key) ?? 0) + 1)
  }

  const kinds = { rust: 'language', go: 'language', python: 'language', typescript: 'language',
    vue: 'framework', nextjs: 'framework', laravel: 'framework', fastapi: 'framework',
    postgresql: 'database', redis: 'database', docker: 'tool', cloudflare: 'platform', openai: 'ai' }

  const data = [...counts.entries()]
    .map(([slug, count]) => ({
      slug,
      name: slug.charAt(0).toUpperCase() + slug.slice(1).replace(/-/g, ' '),
      ...(kind === 'technologies' ? { kind: kinds[slug] ?? 'other' } : {}),
      project_count: count,
    }))
    .sort((a, b) => b.project_count - a.project_count)

  const limit = Number(url.searchParams.get('limit') ?? 0)
  send(res, 200, { data: limit ? data.slice(0, limit) : data, meta: meta() })
}

createServer((req, res) => {
  if (req.method === 'OPTIONS') {
    res.writeHead(204, { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*' })
    return res.end()
  }

  const url = new URL(req.url ?? '/', `http://localhost:${PORT}`)
  const path = url.pathname.replace(/^\/api\/v1/, '')

  if (path === '/projects') return list(url, res)
  if (path.startsWith('/projects/')) return detail(decodeURIComponent(path.slice('/projects/'.length)), res)
  if (path === '/categories') return taxonomy('categories', url, res)
  if (path === '/technologies') return taxonomy('technologies', url, res)

  if (path === '/trends') {
    const days = []
    for (let i = 13; i >= 0; i--) {
      const d = new Date(Date.now() - i * 86_400_000)
      // A deliberate step up in the second week, so the chart has something
      // to show and the comparison is exercised rather than assumed.
      const base = i >= 7 ? 1 : 2
      days.push({ date: d.toISOString().slice(0, 10), value: base + (i % 3) })
    }

    const series = (name, scale) => ({
      metric: name,
      points: days.map((d) => ({ date: d.date, value: d.value * scale })),
      total: days.reduce((s, d) => s + d.value * scale, 0),
      average: Math.round((days.reduce((s, d) => s + d.value * scale, 0) / days.length) * 100) / 100,
      peak: days.map((d) => ({ date: d.date, value: d.value * scale }))
        .reduce((b, p) => (p.value > b.value ? p : b)),
    })

    const current = days.slice(7).reduce((s, d) => s + d.value, 0)
    const previous = days.slice(0, 7).reduce((s, d) => s + d.value, 0)
    const change = Math.round(((current - previous) / previous) * 1000) / 10

    const headline = (metric, cur, prev, reliable = true) => ({
      metric,
      current: cur,
      previous: prev,
      direction: !reliable ? 'insufficient' : cur > prev * 1.05 ? 'rising' : cur < prev * 0.95 ? 'falling' : 'steady',
      change_percent: prev === 0 ? null : Math.round(((cur - prev) / prev) * 1000) / 10,
      sample_size: Math.max(cur, prev),
      reliable,
      explanation: reliable
        ? `${cur} vs ${prev} in the previous period (${change > 0 ? '+' : ''}${change}%).`
        : `Only ${Math.max(cur, prev)} observations; too few to call a direction (8 needed).`,
    })

    return send(res, 200, {
      data: {
        window_days: 7,
        generated_at: new Date().toISOString(),
        headlines: [
          headline('projects_discovered', current, previous),
          headline('total_engagement', current * 420, previous * 380),
          headline('average_score', 74.4, 71.2),
          // Deliberately unreliable, so the "too few to compare" path renders.
          headline('projects_with_repository', 6, 4, false),
        ],
        projects_per_day: series('projects_per_day', 1),
        engagement_per_day: series('engagement_per_day', 420),
        top_categories: [...new Set(projects.map((p) => p.category))].map((slug, i) => ({
          slug,
          name: slug.charAt(0).toUpperCase() + slug.slice(1).replace(/-/g, ' '),
          count: projects.filter((p) => p.category === slug).length,
          previous_count: Math.max(0, projects.filter((p) => p.category === slug).length - (i % 3) + 1),
          share: projects.filter((p) => p.category === slug).length / projects.length,
          direction: ['rising', 'steady', 'falling'][i % 3],
          kind: null,
        })),
        top_technologies: [...new Set(projects.flatMap((p) => p.technologies))].slice(0, 8).map((slug, i) => ({
          slug,
          name: slug.charAt(0).toUpperCase() + slug.slice(1),
          count: projects.filter((p) => p.technologies.includes(slug)).length,
          previous_count: 1,
          share: projects.filter((p) => p.technologies.includes(slug)).length / projects.length,
          direction: i % 2 ? 'rising' : 'steady',
          kind: 'language',
        })),
        top_projects: projects.slice(0, 10).map((p) => ({
          slug: p.slug, name: p.name, category: p.category, score: p.score,
          engagement: p.engagement, stars: p.stars, discovered_at: p.discovered_at,
        })),
        // Null exercises the omit-the-chart path; flip to a series to see it.
        repository_stars: null,
      },
      meta: meta(),
    })
  }

  if (path === '/stats') {
    const today = new Date().toISOString().slice(0, 10)
    return send(res, 200, {
      data: {
        window_days: 7,
        projects_in_window: projects.length,
        projects_published_today: projects.filter((p) => p.discovered_at.startsWith(today)).length,
        projects_with_repository: projects.filter((p) => p.links.repository).length,
        average_score: Math.round((projects.reduce((s, p) => s + p.score, 0) / projects.length) * 100) / 100,
        last_published_at: projects[0].discovered_at,
        top_categories: [...new Set(projects.map((p) => p.category))].slice(0, 5).map((slug) => ({
          slug, name: slug, project_count: projects.filter((p) => p.category === slug).length,
        })),
        top_technologies: [],
      },
      meta: meta(),
    })
  }

  fail(res, 404, 'not_found', 'The requested resource does not exist.')
}).listen(PORT, HOST, () => console.log(`mock DevRadar API on http://${HOST}:${PORT}/api/v1 (development only)`))
