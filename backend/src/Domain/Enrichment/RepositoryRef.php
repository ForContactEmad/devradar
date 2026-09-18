<?php

declare(strict_types=1);

namespace DevRadar\Domain\Enrichment;

use InvalidArgumentException;

/**
 * A repository identified by host, owner and name.
 *
 * Parsing lives here rather than in the client so that "is this a GitHub URL
 * we can analyse" is answerable without a network call, a token, or an
 * instantiated client -- which is what lets the pipeline decide NOT to call
 * GitHub at all for most projects.
 */
final readonly class RepositoryRef
{
    private function __construct(
        public string $host,
        public string $owner,
        public string $name,
    ) {}

    /**
     * Parse a URL into a reference, or null when it is not one.
     *
     * Returns null rather than throwing: most project URLs are not
     * repositories, and that is the ordinary case, not an error.
     */
    public static function fromUrl(?string $url): ?self
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $parts = parse_url(trim($url));

        if ($parts === false || ! isset($parts['host'])) {
            return null;
        }

        $host = strtolower(preg_replace('/^www\./', '', $parts['host']) ?? $parts['host']);

        if ($host !== 'github.com') {
            // Only GitHub is analysed. GitLab and the rest parse fine but
            // have no client behind them, and returning a ref we cannot
            // service would queue work that permanently fails.
            return null;
        }

        $segments = array_values(array_filter(explode('/', $parts['path'] ?? ''), fn ($s) => $s !== ''));

        if (count($segments) < 2) {
            // A user or organisation page, not a repository.
            return null;
        }

        [$owner, $name] = $segments;
        $name = preg_replace('/\.git$/', '', $name) ?? $name;

        // GitHub's own naming rules. Anything else is a reserved path we
        // should not spend a request on.
        if (preg_match('/^[A-Za-z0-9._-]+$/', $owner) !== 1 || preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1) {
            return null;
        }

        if (in_array(strtolower($owner), ['orgs', 'topics', 'features', 'explore', 'sponsors', 'settings', 'about'], true)) {
            return null;
        }

        return new self($host, $owner, $name);
    }

    public static function of(string $owner, string $name, string $host = 'github.com'): self
    {
        if (trim($owner) === '' || trim($name) === '') {
            throw new InvalidArgumentException('A repository needs both an owner and a name.');
        }

        return new self($host, $owner, $name);
    }

    public function fullName(): string
    {
        return $this->owner . '/' . $this->name;
    }

    public function url(): string
    {
        return "https://{$this->host}/{$this->owner}/{$this->name}";
    }

    /** Case-insensitive: GitHub treats Owner/Repo and owner/repo as one. */
    public function key(): string
    {
        return strtolower($this->host . '/' . $this->owner . '/' . $this->name);
    }
}
