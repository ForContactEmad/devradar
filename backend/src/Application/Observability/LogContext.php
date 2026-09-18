<?php

declare(strict_types=1);

namespace DevRadar\Application\Observability;

/**
 * Fields stamped onto every log line for the life of one unit of work.
 *
 * THE GAP THIS FILLS. DevRadar already emits fifty-four distinct structured
 * events across collection, processing, classification, extraction,
 * enrichment and scoring. Every one of them is useful on its own and none of
 * them can be joined to another: there is no way to ask "what happened to the
 * batch that started at 04:00" because each stage logs in isolation.
 *
 * A correlation id shared by every line from one pipeline run turns fifty-four
 * unrelated events into a trace. That is the difference between logs you can
 * search and logs you can follow.
 *
 * MUTABLE AND INJECTED, not static. A static holder is convenient and then
 * leaks between queue jobs in a long-lived worker, so one run's id ends up
 * stamped on the next run's failures. This is a singleton in the container
 * that the job resets on entry.
 */
final class LogContext
{
    /** @var array<string, string|int|null> */
    private array $fields = [];

    /**
     * Begin a new unit of work, discarding anything from the previous one.
     *
     * The reset is the point. A queue worker handles thousands of jobs in one
     * process, and a context that accumulated would attribute every later
     * failure to the first job's correlation id.
     */
    public function begin(string $correlationId, ?string $stage = null): void
    {
        $this->fields = array_filter(
            ['correlation_id' => $correlationId, 'stage' => $stage],
            fn ($v) => $v !== null,
        );
    }

    public function set(string $key, string|int|null $value): void
    {
        if ($value === null) {
            unset($this->fields[$key]);

            return;
        }

        $this->fields[$key] = $value;
    }

    public function correlationId(): ?string
    {
        $id = $this->fields['correlation_id'] ?? null;

        return is_string($id) ? $id : null;
    }

    /** @return array<string, string|int> */
    public function fields(): array
    {
        /** @var array<string, string|int> */
        return $this->fields;
    }

    public function clear(): void
    {
        $this->fields = [];
    }

    /**
     * A correlation id.
     *
     * Prefixed so it is obvious in a log what kind of identifier it is, and
     * short enough to read aloud over a call. Not a UUID: 128 bits of entropy
     * buys nothing here, and a 12-character token is far easier to grep for
     * and to quote in a bug report.
     */
    public static function newId(string $prefix = 'run'): string
    {
        return $prefix . '_' . bin2hex(random_bytes(6));
    }
}
