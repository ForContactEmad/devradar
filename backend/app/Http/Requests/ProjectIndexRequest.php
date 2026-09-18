<?php

declare(strict_types=1);

namespace App\Http\Requests;

use DevRadar\Domain\Query\ProjectQuery;
use DevRadar\Domain\Query\ProjectSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shapes and type-checks request parameters. It does NOT decide what is
 * valid.
 *
 * The rules here are about HTTP: is this an integer, is it a known sort
 * value, is the array actually an array. The rules about what makes a
 * meaningful query -- relevance needing a search term, the window being seven
 * days -- live in ProjectQuery, where every caller gets them.
 *
 * The split matters because the same rule would otherwise have to be repeated
 * for a console command, a feed generator, or any future non-HTTP caller.
 */
final class ProjectIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:' . ProjectQuery::MAX_PER_PAGE],
            'sort' => ['sometimes', 'string', 'in:' . implode(',', ProjectSort::values())],
            'q' => ['sometimes', 'string', 'min:2', 'max:200'],
            'category' => ['sometimes'],
            'category.*' => ['string', 'max:64'],
            'technology' => ['sometimes'],
            'technology.*' => ['string', 'max:64'],
            'type' => ['sometimes', 'string', 'max:32'],
            'has_repository' => ['sometimes', 'boolean'],
            'min_score' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'within_days' => ['sometimes', 'integer', 'min:1', 'max:7'],
            'min_engagement' => ['sometimes', 'integer', 'min:0'],
            'since' => ['sometimes', 'date'],
            'until' => ['sometimes', 'date'],
        ];
    }

    /**
     * Comma-separated values are the conventional way to express a repeated
     * filter in a query string, and clients send both forms.
     */
    protected function prepareForValidation(): void
    {
        foreach (['category', 'technology'] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $this->merge([$key => array_values(array_filter(array_map('trim', explode(',', $value))))]);
            }
        }
    }

    /**
     * Parse a date parameter, or null when absent.
     *
     * NOT named date(). Illuminate\Http\Request already declares a PUBLIC
     * date() helper, and PHP forbids an override from narrowing visibility --
     * so a private date() here is a fatal error the moment the class loads,
     * which took down every request to /projects.
     */
    private function dateParam(string $key): ?\DateTimeImmutable
    {
        $value = $this->input($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            // Already rejected by the `date` rule; this guards the case where
            // validation is bypassed by a direct construction.
            return null;
        }
    }

    /** Turns HTTP into the domain's own vocabulary. */
    public function toQuery(): ProjectQuery
    {
        return new ProjectQuery(
            page: (int) $this->input('page', 1),
            perPage: (int) $this->input('per_page', ProjectQuery::DEFAULT_PER_PAGE),
            sort: ProjectSort::from((string) $this->input('sort', ProjectSort::Score->value)),
            search: $this->input('q'),
            categories: (array) $this->input('category', []),
            technologies: (array) $this->input('technology', []),
            projectType: $this->input('type'),
            hasRepository: $this->has('has_repository') ? $this->boolean('has_repository') : null,
            minScore: $this->has('min_score') ? (float) $this->input('min_score') : null,
            withinDays: $this->has('within_days') ? (int) $this->input('within_days') : null,
            minEngagement: $this->has('min_engagement') ? (int) $this->input('min_engagement') : null,
            since: $this->dateParam('since'),
            until: $this->dateParam('until'),
        );
    }
}
