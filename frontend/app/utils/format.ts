/**
 * Presentation helpers. Pure functions, no Vue, no network.
 *
 * FORMATTING IS NOT BUSINESS LOGIC. Nothing here computes a score, decides an
 * ordering, or filters anything -- the backend owns all of that. These turn
 * numbers the API already decided into strings a person can read, and they
 * are separated out so the same rules apply everywhere rather than being
 * re-improvised inside each component.
 */

/** 1 234 -> "1.2k". Keeps a scan-line of metrics the same width. */
export function compactNumber(value: number | null | undefined): string {
  if (value === null || value === undefined) return '—'
  if (value < 1000) return String(value)
  if (value < 1_000_000) {
    const thousands = value / 1000
    return `${thousands < 10 ? thousands.toFixed(1).replace(/\.0$/, '') : Math.round(thousands)}k`
  }
  const millions = value / 1_000_000
  return `${millions < 10 ? millions.toFixed(1).replace(/\.0$/, '') : Math.round(millions)}M`
}

/** Full value for screen readers and title attributes. */
export function exactNumber(value: number | null | undefined): string {
  return value === null || value === undefined ? 'unknown' : value.toLocaleString('en')
}

/**
 * "4h ago", "2d ago".
 *
 * The feed is a seven-day window, so nothing needs weeks or months, and a
 * unit that never appears is a branch that never gets tested.
 */
export function relativeTime(iso: string | null | undefined, now: Date = new Date()): string {
  if (!iso) return '—'

  const then = new Date(iso)
  if (Number.isNaN(then.getTime())) return '—'

  const seconds = Math.floor((now.getTime() - then.getTime()) / 1000)

  // Clock skew between client and server, not a post from the future.
  if (seconds < 60) return 'just now'
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`

  const days = Math.floor(seconds / 86400)
  return days === 1 ? 'yesterday' : `${days}d ago`
}

export function absoluteDate(iso: string | null | undefined): string {
  if (!iso) return 'unknown'
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return 'unknown'

  return date.toLocaleDateString('en', { day: 'numeric', month: 'short', year: 'numeric' })
}

/**
 * Hours old, capped at the window.
 *
 * Drives the left-rule opacity on a project row, so freshness is visible
 * before any text is read.
 */
export function freshness(iso: string | null | undefined, windowDays = 7, now: Date = new Date()): number {
  if (!iso) return 0

  const then = new Date(iso)
  if (Number.isNaN(then.getTime())) return 0

  const hours = (now.getTime() - then.getTime()) / 3_600_000
  const windowHours = windowDays * 24

  return Math.max(0, Math.min(1, 1 - hours / windowHours))
}

/** Score arrives 0-100 with decimals; the feed shows whole numbers. */
export function scoreLabel(score: number | null | undefined): string {
  return score === null || score === undefined ? '—' : String(Math.round(score))
}

/** "developer-tools" -> "Developer tools". Sentence case, not title case. */
export function humanise(slug: string | null | undefined): string {
  if (!slug) return ''
  const words = slug.replace(/[-_]+/g, ' ').trim()
  return words.charAt(0).toUpperCase() + words.slice(1)
}

/** github.com/acme/pgplan -> "acme/pgplan"; anything else -> its host. */
export function shortLink(url: string | null | undefined): string {
  if (!url) return ''

  try {
    const parsed = new URL(url)
    const host = parsed.hostname.replace(/^www\./, '')
    const path = parsed.pathname.replace(/^\/|\/$/g, '')

    if (host === 'github.com' && path) return path
    return host
  } catch {
    return url
  }
}

/** X handles are stored bare; the UI shows them with the @. */
export function handle(author: string | null | undefined): string {
  if (!author) return ''
  return author.startsWith('@') ? author : `@${author}`
}

/**
 * An href that cannot execute script.
 *
 * DEFENCE IN DEPTH, not the primary control. The API validates URL schemes
 * and the ingestion layer rejects non-http links before storage -- but the
 * frontend previously bound whatever it was handed straight into `:href`,
 * which meant one changed writer anywhere upstream became stored XSS here.
 *
 * A component should not have to trust that every producer of a URL, now and
 * in future, remembered to check it.
 *
 * Returns null for anything that is not http(s), so the caller renders no
 * link at all rather than a link that does something else.
 */
export function safeHref(url: string | null | undefined): string | null {
  if (!url || typeof url !== 'string') return null

  const trimmed = url.trim()

  // Parsed rather than pattern-matched: `java\tscript:alert(1)` and
  // `JaVaScRiPt:` both defeat a naive prefix check, and the URL parser
  // normalises them the same way a browser would.
  try {
    const parsed = new URL(trimmed)
    return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? trimmed : null
  } catch {
    // Not an absolute URL. Relative hrefs are never external links here, and
    // treating one as such is how a protocol-relative `//evil.example` slips
    // through.
    return null
  }
}
