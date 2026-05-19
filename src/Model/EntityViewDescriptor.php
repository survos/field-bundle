<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Model;

/**
 * Compiled configuration for one entity browse view.
 */
final class EntityViewDescriptor
{
    /**
     * @param list<array{name: string}|string> $columns
     */
    public function __construct(
        public readonly string $code,
        public readonly string $label,
        public readonly string $type = 'table',
        public readonly ?string $template = null,
        public readonly array $columns = [],
        public readonly bool $columnControl = true,
        public readonly bool $searchBuilder = false,
    ) {
    }
}
