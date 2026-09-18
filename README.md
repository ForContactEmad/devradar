# DevRadar

Finds software projects that launched on X in the last seven days, works out which
announcements are real, ranks them, and serves them as a feed.

**Status: pre-alpha.** The pipeline is built and tested; it has never run against the
live X API. See [Honest status](#honest-status) before deploying anything.

---

## Why it exists

Developer launches are announced on X and buried within hours. There is no reliable way
to ask "what shipped this week" — the timeline is chronological and optimised for
engagement, not discovery, and search returns as much commentary about launches as it
does launches.

DevRadar answers that one question. The seven-day window is the product: a feed of
*everything* would be another package registry, and there are good ones already.

## What it does

```
X search → collect → normalise → deduplicate → pre-filter → classify (AI)
        → extract (AI) → enrich (GitHub) → score → serve
```

Each stage drains its own input state on its own schedule. Nothing is chained, which is
why a broken AI stage does not stop collection, and an unreachable GitHub does not stop
anything.

**Features actually implemented:**

- Paid X search over the rolling 7-day window, with a spend ceiling that aborts
  collection rather than exceeding it
- Three-level deduplication: post id → canonical URL → text fingerprint (Arabic-aware)
- A free keyword pre-filter with 98 tuned phrases, so the AI only sees candidates
- AI classification (is this a launch?) and extraction (name, stack, links), behind a
  provider abstraction with two real implementations
- Evidence-based tech detection — a technology is attached only if the post mentions it
- GitHub enrichment using conditional requests, off the critical path
- A six-component ranking score that explains itself in words
- Batch compliance reconciliation, so posts deleted on X stop being displayed here
- A REST API and a server-rendered bilingual dashboard (EN/AR, RTL)

## Architecture

A modular monolith with a hexagonal dependency rule, enforced by a script that runs
without Composer:

```
delivery/ → application/ → domain/ ← ports ← infrastructure/
```

```bash
cd backend && php bin/arch-check.php     # 185 files, dependency direction: inward only
```

Two runtime paths that never mix: a **write path** (scheduled, paid, queued) and a
**read path** (user-facing, free) that queries precomputed serving tables and cannot
reach a provider. A page view is structurally incapable of spending money.

Detail: [ARCHITECTURE.md](ARCHITECTURE.md).

## Tech stack

| | |
|---|---|
| Backend | PHP 8.3, Laravel 12 |
| Frontend | Nuxt 4, Vue 3, TypeScript |
| Database | PostgreSQL 16 (`pg_trgm`, full-text search) |
| Queue / cache | Redis 7 |
| AI | Provider-agnostic; Anthropic and any OpenAI-compatible endpoint |
| Containers | Docker Compose, 7 services |

227 backend PHP files (~17,150 lines), 35 frontend files, 14 config files, 18 migrations.

## Installation

```bash
git clone <repo> devradar && cd devradar
cp .env.example .env
# set POSTGRES_PASSWORD and REDIS_PASSWORD — compose refuses to start without them
docker compose up
docker compose exec api php artisan migrate
```

- Dashboard — <http://localhost:3000>
- API — <http://localhost:8000/api/v1/projects>

No credentials are needed to start. The stages that need them — collect, classify,
extract and comply — **skip** and record why in `stage_runs`; everything else runs. See [SETUP.md](SETUP.md) for running without
Docker.

## Environment variables

**124 variables**, all documented in [docs/ENVIRONMENT.md](docs/ENVIRONMENT.md), which
is generated from `backend/config/*.php` so it cannot drift out of step with the code.

Required — no defaults, deliberately:

| Variable | Why |
|---|---|
| `POSTGRES_PASSWORD` | Compose fails loudly rather than shipping a known password |
| `REDIS_PASSWORD` | Redis holds the queue; an open instance lets anyone write job payloads |

Worth setting before the pipeline does anything useful:

| Variable | Effect if unset |
|---|---|
| `X_API_BEARER_TOKEN` | Collection and **compliance** are skipped, recorded as `skipped` in `stage_runs`. Everything else runs |
| `DEVRADAR_AI_PROVIDER` | Classify and extract are skipped. Set to `anthropic` or `openai_compatible` |
| `GITHUB_TOKEN` | 60 req/hr, and `304` responses still cost quota |
| `DEVRADAR_ADMIN_TOKEN` | Every `/admin/*` request is denied (fail-closed, by design) |

## Running the project

Every command below names a command that exists in the code and an entry point that
now exists on disk. **None has ever been executed** — see [Honest status](#honest-status).

```bash
# the whole stack
docker compose up

# one pipeline stage at a time
docker compose exec api php artisan devradar:collect --dry-run   # plan, spend nothing
docker compose exec api php artisan devradar:collect             # PAID
docker compose exec api php artisan devradar:process --stage=all
docker compose exec api php artisan devradar:filter
docker compose exec api php artisan devradar:classify            # PAID
docker compose exec api php artisan devradar:extract             # PAID
docker compose exec api php artisan devradar:enrich
docker compose exec api php artisan devradar:score               # free

# operator view: per-stage health, failures, last success
docker compose exec api php artisan devradar:status --hours=24

# score one piece of text against the pre-filter, without touching the database
docker compose exec api php artisan devradar:filter --explain="Just launched my tool" --repo
```

The scheduler runs all of these on their own cadence; the commands exist for debugging
and for the first manual run.

## Testing

```bash
cd backend && bash tests/Support/run-all.sh
```

Static checks, 593 unit tests and 27 SQL schema tests in one command. With no database
reachable it reports `SUITE PASSED — BUT DATABASE TESTS WERE SKIPPED`, never a clean
pass.

```bash
cd frontend && npx vitest run          # 17 tests
cd .. && python3 docker/validate.py    # 70 container assertions
```

[TESTING.md](TESTING.md) has the coverage breakdown and the gaps — which are
substantial and worth reading before trusting any of this.

## Docker

Seven services, each because the architecture needs it: `postgres`, `redis`, `api`,
`worker`, `worker-ingestion` (separate, because it must run exactly one job at a time —
two concurrent runs mean duplicate *paid* fetches), `scheduler`, `frontend`. `nginx`
exists only under `--profile production`.

```bash
docker compose ps                   # health of every service
docker compose logs -f scheduler    # what the pipeline is firing
```

[DEPLOYMENT.md](DEPLOYMENT.md) covers images, health checks and the production profile.

## API

```
GET /api/v1/health           GET /api/v1/projects
GET /api/v1/health/ready     GET /api/v1/projects/{slug}
GET /api/v1/categories       GET /api/v1/stats
GET /api/v1/technologies     GET /api/v1/trends

GET /api/v1/admin/search-runs    (token required)
GET /api/v1/admin/health         (token required)
```

Trending, latest and search are **not** separate endpoints — they are orderings and
filters of one collection (`?sort=trending`, `?q=postgres`). [API.md](API.md) explains
why; `backend/docs/openapi.yaml` is the machine-readable contract.

## Documentation

| | |
|---|---|
| [ARCHITECTURE.md](ARCHITECTURE.md) | Layers, the dependency rule, pipeline stages |
| [SETUP.md](SETUP.md) | Local development, with and without Docker |
| [API.md](API.md) | Endpoints, filters, envelope, errors |
| [DATABASE.md](DATABASE.md) | 12 tables, constraints, indexes |
| [SECURITY.md](SECURITY.md) | Audit findings, fixes, what remains |
| [COST.md](COST.md) | Verified pricing and cost scenarios |
| [TESTING.md](TESTING.md) | Suites, coverage, gaps |
| [DEPLOYMENT.md](DEPLOYMENT.md) | Containers, health checks, production profile |
| [DEPLOYMENT-PLAN.md](DEPLOYMENT-PLAN.md) | Production deployment design — **not yet executed** |
| [ROADMAP.md](ROADMAP.md) | What is done, what is not |
| [CHANGELOG.md](CHANGELOG.md) | Phase-by-phase history |
| [docs/ENVIRONMENT.md](docs/ENVIRONMENT.md) | All 124 variables (generated) |

## Honest status

Worth reading before you deploy anything.

- **Composer has never run in the build environment.** No `composer.lock`, no `vendor`.
  Every Eloquent repository, controller, middleware, job and service provider is
  lint-clean and logically reviewed but **has never executed**. SQL semantics are
  verified against a live PostgreSQL 16; the Laravel code around them is not.
- **The application skeleton was missing entirely until the readiness audit.**
  `artisan`, `bootstrap/app.php`, `public/index.php` and the framework config did not
  exist, so nothing could boot and no documented command could run. They have been
  written and are lint-clean; they have **never been executed**, because that still
  needs `composer install`.
- **No integration, API or end-to-end tests exist.** `tests/Feature` and
  `tests/Pipeline` are empty. Framework-bound class coverage is 13%; business-logic
  coverage is 89%.
- **Nothing has ever called the live X, Anthropic or GitHub APIs.** All provider tests
  use scripted responses.
- **No Docker image has been built.** `docker/validate.py` asserts 70 static properties
  of the container setup; it is not a substitute for `docker compose build`.
- **The prompts are untested against a real model.** They are written and versioned;
  their precision is unknown.
- **The ranking weights are priors, not measurements.**

The single highest-value next step is `composer install`.

## Licence

Not yet chosen.
