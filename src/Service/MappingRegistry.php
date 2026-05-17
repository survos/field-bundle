<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Service;

/**
 * Holds the compiled alias map built by DtoMappingCompilerPass.
 *
 * Structure: [FQCN => [sourceAlias => propertyName]]
 *
 * Regex patterns are stored under a '__regex__' key as
 * [[pattern, propertyName], ...] and evaluated at runtime only
 * when no exact alias match is found.
 */
final class MappingRegistry
{
    /** @param array<class-string, array<string, string>> $map */
    public function __construct(
        private readonly array $map = [],
    ) {}

    /**
     * Resolve a source key to a DTO property name for the given class.
     * Returns null when no alias matches.
     */
    public function resolve(string $class, string $sourceKey): ?string
    {
        $classMap = $this->map[$class] ?? [];

        // Exact alias match
        if (isset($classMap[$sourceKey])) {
            return $classMap[$sourceKey];
        }

        // Regex patterns (compiled at build time, evaluated here)
        foreach ($classMap['__regex__'] ?? [] as [$pattern, $prop]) {
            if (preg_match($pattern, $sourceKey)) {
                return $prop;
            }
        }

        return null;
    }

    /** @return array<string, string> */
    public function aliasesFor(string $class): array
    {
        $map = $this->map[$class] ?? [];
        unset($map['__regex__']);
        return $map;
    }

    public function has(string $class): bool
    {
        return isset($this->map[$class]);
    }
}
