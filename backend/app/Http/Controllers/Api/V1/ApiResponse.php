<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use DevRadar\Domain\Query\Paginated;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * The one place the response envelope is defined.
 *
 * CONSISTENCY IS THE POINT. Every success is {data, meta}; every error is
 * {error, meta}. A consumer writes one success path and one error path rather
 * than discovering that one endpoint returns a bare array and another a
 * wrapped object.
 *
 * Every response carries a request id. When someone reports "the feed was
 * empty at 3am", that id is what connects their report to a log line.
 */
final class ApiResponse
{
    public static function item(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => self::meta()], $status);
    }

    /** @param Paginated<mixed> $page */
    public static function collection(mixed $data, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => self::meta($meta)], $status);
    }

    /** @param Paginated<mixed> $page */
    public static function paginated(mixed $data, Paginated $page, string $url, array $params = []): JsonResponse
    {
        // $page is captured too: the closure reads $page->perPage when building
        // each link, and PHP does not inherit the enclosing scope automatically.
        // Omitting it was invisible until a request actually reached here,
        // because the closure only runs when the links are built.
        $link = function (?int $target) use ($url, $params, $page): ?string {
            if ($target === null) {
                return null;
            }

            return $url . '?' . http_build_query([...$params, 'page' => $target, 'per_page' => $page->perPage]);
        };

        return response()->json([
            'data' => $data,
            'meta' => self::meta([
                'page' => $page->page,
                'per_page' => $page->perPage,
                'total' => $page->total,
                'last_page' => $page->lastPage(),
                'has_more' => $page->hasMore(),
            ]),
            'links' => [
                'self' => $link($page->page),
                'first' => $link(1),
                'last' => $link($page->lastPage()),
                'next' => $link($page->hasMore() ? $page->page + 1 : null),
                'prev' => $link($page->page > 1 ? $page->page - 1 : null),
            ],
        ]);
    }

    /** @param array<string, mixed> $details */
    public static function error(string $type, string $message, int $status, array $details = []): JsonResponse
    {
        $body = ['error' => ['type' => $type, 'message' => $message]];

        if ($details !== []) {
            $body['error']['details'] = $details;
        }

        $body['meta'] = self::meta();

        return response()->json($body, $status);
    }

    /** @param array<string, mixed> $extra */
    private static function meta(array $extra = []): array
    {
        return [
            'request_id' => request()->header('X-Request-Id') ?? (string) Str::uuid(),
            'api_version' => 'v1',
            ...$extra,
        ];
    }
}
