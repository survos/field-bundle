<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Entity;

use Survos\FieldBundle\Service\RouteIdentityResolver;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Drop-in replacement for survos/core-bundle's RouteParametersTrait.
 *
 * Reads the entity's #[RouteIdentity] attribute (also in field-bundle) instead
 * of the legacy `UNIQUE_PARAMETERS` const. Preserves the exact `getRp()` /
 * `getUniqueIdentifiers()` / `erp()` contract that menu helpers, link
 * builders, and other consumers depend on.
 *
 * Migration:
 *
 *     // Before
 *     class Owner implements RouteParametersInterface
 *     {
 *         use RouteParametersTrait;            // from core-bundle
 *         public const array UNIQUE_PARAMETERS = ['ownerId' => 'code'];
 *     }
 *
 *     // After
 *     #[RouteIdentity(field: 'code')]
 *     class Owner implements RouteParametersInterface
 *     {
 *         use RouteIdentityTrait;              // from field-bundle
 *     }
 *
 * Keep `implements RouteParametersInterface` — the interface stays in
 * core-bundle, untouched. Only the trait changes.
 */
trait RouteIdentityTrait
{
    /**
     * @return array<string, mixed>
     */
    public function getUniqueIdentifiers(): array
    {
        return RouteIdentityResolver::paramsFor($this);
    }

    /**
     * @param  array<string, mixed>|null $addlParams
     * @return array<string, mixed>
     */
    #[Groups(['rp', 'transitions', 'searchable'])]
    public function getRp(?array $addlParams = []): array
    {
        return RouteIdentityResolver::paramsFor($this, $addlParams ?? []);
    }

    /**
     * Single-string identity for tools that can't accept compound keys
     * (EasyAdmin, older grids). Replaces the legacy `erp()` hack.
     *
     * Returns chain values joined by '/' — adjust the separator in calls
     * to RouteIdentityResolver::encode() when needed.
     */
    public function erp(): array
    {
        return ['entityId' => RouteIdentityResolver::encode($this)];
    }

    public static function getClassnamePrefix(?string $class = null): string
    {
        $class ??= static::class;
        $short = (new \ReflectionClass($class))->getShortName();
        return strtolower(preg_replace('/(?<!^)([A-Z])/', '_$1', $short) ?? $short);
    }
}
