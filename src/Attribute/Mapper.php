<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Attribute;

/**
 * Marks a class as a dataset import/mapping DTO.
 *
 * Complements #[Map] (property-level) — this operates at the class level,
 * declaring which dataset(s) this DTO handles. The DtoClassResolver uses
 * `when` to pick the right class for a given dataset key.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Mapper
{
    /**
     * @param array<array-key, string> $when   only apply for these dataset codes (empty = all)
     * @param array<array-key, string> $except skip these dataset codes
     */
    public function __construct(
        public readonly array $when = [],
        public readonly array $except = [],
        public readonly int $priority = 10,
    ) {
    }
}
