<?php

declare(strict_types=1);

namespace DevRadar\Domain\Extraction;

/**
 * Sorts a project's links into repository, website and demo. Pure, no network.
 *
 * Classification is by host and path shape, never by asking a model. A model
 * asked "which of these is the demo" will answer confidently about links it
 * cannot see, and the answer is unverifiable.
 *
 * A link that fits nothing becomes the website only if no better candidate
 * exists, because a wrong website link is a dead end for the reader.
 */
final readonly class UrlClassifier
{
    private const REPOSITORY_HOSTS = ['github.com', 'gitlab.com', 'bitbucket.org', 'codeberg.org', 'sr.ht'];

    /** Hosts that host a running thing rather than describing one. */
    private const DEMO_HOSTS = [
        'vercel.app', 'netlify.app', 'pages.dev', 'fly.dev', 'railway.app',
        'streamlit.app', 'huggingface.co', 'replit.app', 'surge.sh',
        'github.io', 'gitlab.io', 'onrender.com',
    ];

    /** Paths that indicate a live instance rather than a landing page. */
    private const DEMO_PATH_HINTS = ['/demo', '/playground', '/try', '/live', '/sandbox'];

    /**
     * @param  list<string> $urls already canonicalised
     * @return array{repository: ?string, website: ?string, demo: ?string, unclassified: list<string>}
     */
    public function classify(array $urls): array
    {
        $repository = null;
        $demo = null;
        $candidates = [];

        foreach ($urls as $url) {
            $host = $this->host($url);

            if ($host === null) {
                continue;
            }

            if ($repository === null && $this->isRepository($host, $url)) {
                $repository = $url;

                continue;
            }

            if ($demo === null && $this->isDemo($host, $url)) {
                $demo = $url;

                continue;
            }

            $candidates[] = $url;
        }

        // The website is whatever remains that is not the repository or the
        // demo. If nothing remains, the field stays null rather than being
        // filled with the repository URL a second time -- a duplicated link
        // tells the reader nothing and looks like a bug.
        $website = $candidates === [] ? null : $candidates[0];

        return [
            'repository' => $repository,
            'website' => $website,
            'demo' => $demo,
            'unclassified' => array_slice($candidates, 1),
        ];
    }

    public function isRepositoryUrl(string $url): bool
    {
        $host = $this->host($url);

        return $host !== null && $this->isRepository($host, $url);
    }

    private function isRepository(string $host, string $url): bool
    {
        if (! in_array($host, self::REPOSITORY_HOSTS, true)) {
            return false;
        }

        // A host-level or org-level link is not a project.
        $segments = array_values(array_filter(explode('/', (string) parse_url($url, PHP_URL_PATH))));

        return count($segments) >= 2;
    }

    private function isDemo(string $host, string $url): bool
    {
        foreach (self::DEMO_HOSTS as $demoHost) {
            if ($host === $demoHost || str_ends_with($host, '.' . $demoHost)) {
                return true;
            }
        }

        $path = mb_strtolower((string) parse_url($url, PHP_URL_PATH));

        foreach (self::DEMO_PATH_HINTS as $hint) {
            if (str_starts_with($path, $hint)) {
                return true;
            }
        }

        return str_starts_with($host, 'demo.');
    }

    private function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = mb_strtolower($host);

        return preg_replace('/^www\./', '', $host) ?? $host;
    }
}
