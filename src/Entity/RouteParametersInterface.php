<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Entity;

/**
 * Canonical home for the route-parameters contract.
 * Pair with #[RouteIdentity] and RouteIdentityTrait — no other dependency needed.
 */
interface RouteParametersInterface
{
    public function getUniqueIdentifiers(): array;

    public function getRp(?array $addlParams = []): array;

    public static function getClassnamePrefix(string|null $class = null): string;
}
