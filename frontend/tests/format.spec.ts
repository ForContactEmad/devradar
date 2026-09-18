import { describe, expect, it } from 'vitest'
import { compactNumber, freshness, handle, humanise, relativeTime, safeHref, scoreLabel, shortLink } from '../app/utils/format'
import enMessages from '../i18n/locales/en.json'
import arMessages from '../i18n/locales/ar.json'

/**
 * Formatters are pure, so they are the part of the frontend worth testing
 * hardest: every component depends on them and none of them needs a DOM.
 */

const NOW = new Date('2026-09-10T12:00:00Z')

describe('compactNumber', () => {
  it('leaves small numbers alone', () => {
    expect(compactNumber(0)).toBe('0')
    expect(compactNumber(999)).toBe('999')
  })

  it('compacts thousands and millions', () => {
    expect(compactNumber(1200)).toBe('1.2k')
    expect(compactNumber(12_000)).toBe('12k')
    expect(compactNumber(1_500_000)).toBe('1.5M')
  })

  it('distinguishes missing from zero', () => {
    // "We have not fetched this" and "nobody engaged" are different claims,
    // and the pipeline is careful to keep them apart.
    expect(compactNumber(null)).toBe('—')
    expect(compactNumber(undefined)).toBe('—')
    expect(compactNumber(0)).toBe('0')
  })
})

describe('relativeTime', () => {
  it('reads in the units a seven-day feed needs', () => {
    expect(relativeTime('2026-09-10T11:59:30Z', NOW)).toBe('just now')
    expect(relativeTime('2026-09-10T11:20:00Z', NOW)).toBe('40m ago')
    expect(relativeTime('2026-09-10T04:00:00Z', NOW)).toBe('8h ago')
    expect(relativeTime('2026-09-09T04:00:00Z', NOW)).toBe('yesterday')
    expect(relativeTime('2026-09-06T12:00:00Z', NOW)).toBe('4d ago')
  })

  it('treats a future timestamp as clock skew, not a prophecy', () => {
    expect(relativeTime('2026-09-11T00:00:00Z', NOW)).toBe('just now')
  })

  it('degrades on bad input instead of throwing', () => {
    expect(relativeTime(null)).toBe('—')
    expect(relativeTime('not a date')).toBe('—')
  })
})

describe('freshness', () => {
  it('runs 1 at publication down to 0 at the window edge', () => {
    expect(freshness('2026-09-10T12:00:00Z', 7, NOW)).toBe(1)
    expect(freshness('2026-09-03T12:00:00Z', 7, NOW)).toBe(0)
    expect(freshness('2026-09-07T00:00:00Z', 7, NOW)).toBeCloseTo(0.5, 1)
  })

  it('clamps rather than going negative past the window', () => {
    expect(freshness('2026-01-01T00:00:00Z', 7, NOW)).toBe(0)
  })
})

describe('scoreLabel', () => {
  it('rounds to whole numbers for the feed', () => {
    expect(scoreLabel(88.437)).toBe('88')
    expect(scoreLabel(0)).toBe('0')
    expect(scoreLabel(null)).toBe('—')
  })
})

describe('humanise', () => {
  it('uses sentence case, not title case', () => {
    expect(humanise('developer-tools')).toBe('Developer tools')
    expect(humanise('ai')).toBe('Ai')
    expect(humanise(null)).toBe('')
  })
})

describe('shortLink', () => {
  it('shows owner/repo for GitHub and the host for anything else', () => {
    expect(shortLink('https://github.com/acme/pgplan')).toBe('acme/pgplan')
    expect(shortLink('https://www.pgplan.dev/docs')).toBe('pgplan.dev')
    expect(shortLink('not a url')).toBe('not a url')
    expect(shortLink(null)).toBe('')
  })
})

describe('handle', () => {
  it('adds the @ without doubling it', () => {
    expect(handle('acmedev')).toBe('@acmedev')
    expect(handle('@acmedev')).toBe('@acmedev')
    expect(handle(null)).toBe('')
  })
})

describe('safeHref', () => {
  it('passes ordinary http and https links through', () => {
    expect(safeHref('https://github.com/acme/tool')).toBe('https://github.com/acme/tool')
    expect(safeHref('http://example.dev')).toBe('http://example.dev')
  })

  it('refuses script-bearing URIs', () => {
    // The whole point: these become executable when bound to an href.
    expect(safeHref('javascript:alert(1)')).toBeNull()
    expect(safeHref('data:text/html;base64,PHNjcmlwdD4=')).toBeNull()
    expect(safeHref('vbscript:msgbox(1)')).toBeNull()
  })

  it('is not fooled by casing or embedded whitespace', () => {
    // A naive prefix check misses both; the URL parser normalises them the
    // same way a browser does.
    expect(safeHref('JaVaScRiPt:alert(1)')).toBeNull()
    expect(safeHref('  javascript:alert(1)  ')).toBeNull()
    expect(safeHref('java\tscript:alert(1)')).toBeNull()
  })

  it('refuses protocol-relative and relative URLs', () => {
    // `//evil.example` inherits the page's scheme and is a real external link.
    expect(safeHref('//evil.example/x')).toBeNull()
    expect(safeHref('/relative/path')).toBeNull()
  })

  it('refuses empty and malformed input', () => {
    expect(safeHref(null)).toBeNull()
    expect(safeHref(undefined)).toBeNull()
    expect(safeHref('')).toBeNull()
    expect(safeHref('not a url')).toBeNull()
  })
})

describe('i18n catalogues', () => {
  // كتالوجان متباعدان يعنيان أن لغةً ما ستُظهر مفاتيح خاماً للمستخدم،
  // وهو عطل صامت لا يكشفه أي فحص أنواع ولا بناء.
  const flatten = (obj: Record<string, unknown>, prefix = ''): Record<string, string> =>
    Object.entries(obj).reduce((acc, [k, v]) => {
      const key = prefix ? `${prefix}.${k}` : k
      return typeof v === 'object' && v !== null
        ? { ...acc, ...flatten(v as Record<string, unknown>, key) }
        : { ...acc, [key]: String(v) }
    }, {} as Record<string, string>)

  const en = flatten(enMessages as Record<string, unknown>)
  const ar = flatten(arMessages as Record<string, unknown>)

  it('has the same keys in both languages', () => {
    expect(Object.keys(en).sort()).toEqual(Object.keys(ar).sort())
  })

  it('has no empty Arabic value', () => {
    const empty = Object.entries(ar).filter(([, v]) => !v.trim())
    expect(empty).toEqual([])
  })

  it('has no Arabic value left identical to the English one', () => {
    // نص عربي مطابق للإنجليزي يعني مفتاحاً نُسخ ولم يُترجَم.
    const untranslated = Object.keys(en).filter((k) => en[k] === ar[k] && !/^\d+$/.test(en[k]))
    expect(untranslated).toEqual([])
  })

  it('contains Arabic script in the Arabic catalogue', () => {
    const arabic = Object.values(ar).filter((v) => /[\u0621-\u064A]/.test(v))
    expect(arabic.length).toBeGreaterThan(100)
  })
})
