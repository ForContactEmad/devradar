<?php

declare(strict_types=1);

namespace DevRadar\Domain\Processing;

/**
 * Reduces a URL to a stable identity. Pure, no network.
 *
 * This is DevRadar's strongest deduplication signal. Five accounts announcing
 * the same repository write five different sentences but link to one place --
 * once the tracking parameters, the shortener and the path noise are gone.
 *
 * NO REDIRECTS ARE FOLLOWED. X already reports the final destination in
 * `unwound_url`, and the provider mapper prefers it. Following redirects here
 * would mean an HTTP request per link, which at collection volume is a real
 * cost for something already paid for.
 *
 * REPOSITORY AWARENESS IS THE HIGH-VALUE PART. A launch post links to a repo
 * root, a README, a release tag or a specific file, and all four are the same
 * project. Collapsing them to owner/repo is what makes the deduplicator find
 * matches that parameter-stripping alone would miss.
 */
final class UrlCanonicalizer
{
    /**
     * Parameters that identify a campaign, not a resource. Stripping them is
     * what makes two links to the same page hash identically.
     */
    private const TRACKING_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'utm_id', 'utm_name', 'utm_reader', 'utm_social', 'utm_brand',
        'fbclid', 'gclid', 'dclid', 'msclkid', 'twclid', 'igshid', 'mc_cid',
        'mc_eid', 'ref', 'ref_src', 'ref_url', 'referrer', 'source',
        's', 't', 'si', 'feature', 'spm', 'yclid', '_hsenc', '_hsmi',
    ];

    /** Hosts whose paths reduce to owner/repo. */
    private const REPO_HOSTS = ['github.com', 'gitlab.com', 'bitbucket.org', 'codeberg.org'];

    /**
     * Shortener hosts. A URL still on one of these was never unwound, so its
     * identity is unknown and it must not be treated as canonical -- two
     * different shortened links to the same page would otherwise look like
     * two different projects, and two links to different pages could collide.
     */
    private const SHORTENERS = [
        't.co', 'bit.ly', 'buff.ly', 'tinyurl.com', 'ow.ly', 'lnkd.in',
        'goo.gl', 'is.gd', 'rebrand.ly', 'cutt.ly', 'shorturl.at',
    ];

    public function canonicalize(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $url = trim($url);

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower($parts['host']);
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        // An unresolved shortener has no identity to canonicalise.
        if (in_array($host, self::SHORTENERS, true)) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        $query = $this->cleanQuery($parts['query'] ?? '');

        if (in_array($host, self::REPO_HOSTS, true)) {
            $repoPath = $this->repositoryPath($path);

            if ($repoPath !== null) {
                // A repository is identified by owner/repo. Query strings and
                // fragments on a repo link are always navigation, never
                // identity.
                return "https://{$host}{$repoPath}";
            }
        }

        $path = $this->cleanPath($path);

        // Fragments are always client-side navigation, never identity.
        return "https://{$host}{$path}" . ($query === '' ? '' : "?{$query}");
    }

    /**
     * Stable hash of the canonical URL, for indexed lookup.
     *
     * URLs are hashed rather than indexed directly because a btree entry is
     * capped near 2700 bytes and tracking-laden URLs exceed it.
     */
    public function hash(?string $url): ?string
    {
        $canonical = $this->canonicalize($url);

        return $canonical === null ? null : hash('sha256', $canonical);
    }

    public function isShortener(?string $url): bool
    {
        if ($url === null) {
            return false;
        }

        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        return in_array($host, self::SHORTENERS, true);
    }

    /**
     * Reduces any repository URL to /owner/repo.
     *
     * Returns null for host-level or org-level paths, which are not projects.
     */
    private function repositoryPath(string $path): ?string
    {
        $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));

        if (count($segments) < 2) {
            return null;
        }

        $owner = $segments[0];
        $repo = preg_replace('/\.git$/', '', $segments[1]) ?? $segments[1];

        // Reserved paths that are not user content.
        if (in_array(strtolower($owner), ['orgs', 'topics', 'features', 'explore', 'sponsors', 'settings'], true)) {
            return null;
        }

        return '/' . strtolower($owner) . '/' . strtolower($repo);
    }

    private function cleanPath(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        // Collapse duplicate slashes; drop a trailing slash unless the path
        // is the root, so /docs and /docs/ are one page.
        $path = (string) preg_replace('#/+#', '/', $path);

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private function cleanQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        parse_str($query, $params);

        foreach (array_keys($params) as $key) {
            if (in_array(strtolower((string) $key), self::TRACKING_PARAMS, true)) {
                unset($params[$key]);
            }
        }

        if ($params === []) {
            return '';
        }

        // Sorted, so ?a=1&b=2 and ?b=2&a=1 are one URL.
        ksort($params);

        return http_build_query($params);
    }
}
