<?php

declare(strict_types=1);

namespace DevRadar\Application\Query;

use DevRadar\Domain\Port\ProjectQueryRepositoryInterface;
use DevRadar\Domain\Query\Paginated;
use DevRadar\Domain\Query\ProjectDetail;
use DevRadar\Domain\Query\ProjectNotFound;
use DevRadar\Domain\Query\ProjectQuery;

/**
 * The read-side use cases for projects.
 *
 * Thin by design: the validation is in ProjectQuery, the SQL is in the
 * repository, and what remains here is the small amount of application
 * behaviour that belongs to neither -- turning "not found" into an exception
 * the delivery layer can map to a status code.
 *
 * It exists so the controller depends on an application service rather than
 * on a repository directly. A controller holding a repository is one refactor
 * away from holding a query builder.
 */
final readonly class ProjectQueryService
{
    public function __construct(private ProjectQueryRepositoryInterface $repository) {}

    /** @return Paginated<\DevRadar\Domain\Query\ProjectSummary> */
    public function list(ProjectQuery $query): Paginated
    {
        return $this->repository->search($query);
    }

    /** @throws ProjectNotFound */
    public function detail(string $slug): ProjectDetail
    {
        $project = $this->repository->findBySlug($slug);

        if ($project === null) {
            throw ProjectNotFound::withSlug($slug);
        }

        return $project;
    }
}
