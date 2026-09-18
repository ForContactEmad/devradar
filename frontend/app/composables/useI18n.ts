import ar from '~~/i18n/locales/ar.json'
import en from '~~/i18n/locales/en.json'

export type Locale = 'en' | 'ar'

const MESSAGES: Record<Locale, Record<string, unknown>> = { en, ar }

/**
 * Translation, locale state and text direction.
 *
 * WHY NOT @nuxtjs/i18n. That module gives per-locale URL prefixes and better
 * SEO, and it is the right answer once search traffic matters. It also
 * rewrites every internal link and every page test, and this project has no
 * way to verify that end to end yet. A hundred lines that can be tested here
 * beat a module that cannot be.
 *
 * The upgrade path stays open: message catalogues are already in the shape
 * @nuxtjs/i18n expects, so switching means changing this file and the links,
 * not the translations.
 *
 * LOCALE LIVES IN A COOKIE, NOT useState. A plain useState resets on every
 * refresh and never reaches the server, so the first server-rendered paint
 * was always English — the page visibly flipped to Arabic after hydration.
 * A cookie is sent with the request, so SSR renders the right language the
 * first time.
 *
 * `?lang=ar` overrides the cookie and persists it. Without that a reader
 * cannot share a link in the language they are reading.
 */
export function useI18n() {
  const route = useRoute()

  // Read by the server on the very first request, which is what makes SSR
  // render the correct language rather than flipping after hydration.
  const cookie = useCookie<Locale>('devradar_locale', {
    default: () => 'en',
    maxAge: 60 * 60 * 24 * 365,
    sameSite: 'lax',
    // Not httpOnly: the client toggle has to write it.
    httpOnly: false,
  })

  const fromQuery = computed<Locale | null>(() => {
    const value = route.query.lang
    return value === 'ar' || value === 'en' ? value : null
  })

  // A query parameter wins, and is written back so the choice survives
  // navigation away from the link that carried it.
  if (fromQuery.value && fromQuery.value !== cookie.value) {
    cookie.value = fromQuery.value
  }

  const locale = computed<Locale>(() => fromQuery.value ?? cookie.value ?? 'en')

  const isRtl = computed(() => locale.value === 'ar')
  const dir = computed(() => (isRtl.value ? 'rtl' : 'ltr'))

  /**
   * Translate a dotted key, with optional {placeholder} substitution.
   *
   * A missing key returns the key itself rather than an empty string. Blank
   * text hides the problem; a visible `filters.searchButton` in the interface
   * is ugly and gets fixed.
   */
  function t(key: string, params?: Record<string, string | number>): string {
    const resolve = (catalogue: Record<string, unknown>): string | null => {
      const value = key.split('.').reduce<unknown>(
        (node, part) => (node && typeof node === 'object' ? (node as Record<string, unknown>)[part] : undefined),
        catalogue,
      )
      return typeof value === 'string' ? value : null
    }

    // Falls back to English before giving up, so a key translated in only one
    // catalogue still renders readable text.
    const message = resolve(MESSAGES[locale.value]) ?? resolve(MESSAGES.en) ?? key

    if (!params) {
      return message
    }

    return message.replace(/\{(\w+)\}/g, (match, name: string) =>
      name in params ? String(params[name]) : match)
  }

  function setLocale(next: Locale) {
    cookie.value = next
  }

  function toggle() {
    setLocale(locale.value === 'en' ? 'ar' : 'en')
  }

  return { locale, isRtl, dir, t, setLocale, toggle }
}

/**
 * Format a number in the reader's locale.
 *
 * Arabic here uses Western digits (`ar` with `latn`) rather than Eastern
 * Arabic numerals. Developer-facing figures — star counts, version numbers,
 * scores — are read as Western digits by practically every Arabic-speaking
 * developer, and mixing numeral systems inside a mostly-Latin technical
 * interface costs more legibility than it gains authenticity.
 */
export function useLocaleNumber() {
  const { locale } = useI18n()

  return (value: number, options?: Intl.NumberFormatOptions): string =>
    new Intl.NumberFormat(locale.value === 'ar' ? 'ar-SA-u-nu-latn' : 'en-US', options).format(value)
}
