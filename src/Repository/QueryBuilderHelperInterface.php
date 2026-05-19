<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Repository;

/**
 * Repository contract for field-count queries used by facets, state-machine dashboards, and api-grid.
 *
 * Implement on any ServiceEntityRepository whose entity needs facet counts or marking summaries.
 */
interface QueryBuilderHelperInterface
{
    /** @return array<string, int> keyed by field value */
    public function getCounts(string $field): array;

    /** @return array<string, int> keyed by field value, optionally filtered */
    public function findBygetCountsByField(string $field = 'marking', array $filters = []): array;
}
