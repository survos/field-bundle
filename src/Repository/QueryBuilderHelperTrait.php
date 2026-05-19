<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Repository;

/**
 * Doctrine ORM implementation of QueryBuilderHelperInterface.
 *
 * Mix into any ServiceEntityRepository whose entity has #[Field(facet: true)]
 * properties — the facet and marking-count infrastructure calls getCounts()
 * automatically on those fields.
 */
trait QueryBuilderHelperTrait
{
    public function getCounts(string $field): array
    {
        $results = $this->createQueryBuilder('s')
            ->select(["s.$field", 'count(s) as count'])
            ->groupBy('s.' . $field)
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($results as $r) {
            $key = $r[$field] ?? '';
            if (is_array($key) || $key === null) continue;
            if (is_bool($key)) $key = $key ? '1' : '0';
            $counts[$key] = (int) $r['count'];
        }

        return $counts;
    }

    public function findBygetCountsByField(string $field = 'marking', array $filters = []): array
    {
        $qb = $this->createQueryBuilder('e')
            ->select(sprintf('COUNT(e) as c, e.%s as field', $field));

        foreach ($filters as $relation => $value) {
            if ($value) {
                $qb->join('e.' . $relation, $relation)
                   ->andWhere("e.$relation = :$relation")
                   ->setParameter($relation, $value);
            }
        }

        $counts = [];
        foreach ($qb->groupBy('e.' . $field)->getQuery()->getResult() as $row) {
            $counts[$row['field']] = (int) $row['c'];
        }

        return $counts;
    }
}
