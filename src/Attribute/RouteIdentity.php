<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Attribute;

/**
 * Class-level metadata declaring how an entity identifies itself in URLs.
 *
 * Replaces the legacy `UNIQUE_PARAMETERS` const + `RouteParametersTrait`
 * pattern from survos/core-bundle. Pair with `RouteIdentityTrait` (also in
 * field-bundle) to keep the existing `RouteParametersInterface::getRp()`
 * contract that menus, link helpers, and other consumers rely on.
 *
 * Single source of truth — one declaration per entity. The parent chain is
 * walked automatically; callers no longer need to remember to merge in
 * `$tenant->getRp()` when generating a child entity's URL.
 *
 * Example:
 *
 *     #[RouteIdentity(field: 'code')]
 *     class Tenant implements RouteParametersInterface
 *     {
 *         use RouteIdentityTrait;
 *         #[ORM\Column] public string $code;
 *     }
 *
 *     #[RouteIdentity(field: 'code', parents: ['tenant'])]
 *     class Project implements RouteParametersInterface
 *     {
 *         use RouteIdentityTrait;
 *         #[ORM\ManyToOne] public Tenant $tenant;
 *         #[ORM\Column]    public string $code;
 *     }
 *
 *     // $project->getRp() now returns ['tenantId' => 'acme', 'projectId' => 'photo-archive']
 *     // — chain walked automatically, no manual merge.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class RouteIdentity
{
    /**
     * @param string         $field   Property name OR getter to read for this entity's value.
     *                                 'code' tries `$entity->code`, then `$entity->getCode()`.
     * @param list<string>   $parents Property names of associations to walk for parent params.
     *                                 Each target SHOULD also carry `#[RouteIdentity]`; if a target
     *                                 only has the legacy trait it will be detected by interface
     *                                 and `getRp()` will be called instead.
     * @param string|null    $key     Override the URL parameter key for this entity. Defaults to
     *                                 `{lcfirst(shortName)}Id` (e.g. Tenant => 'tenantId') for
     *                                 backward compatibility with existing UNIQUE_PARAMETERS.
     */
    public function __construct(
        public readonly string  $field,
        public readonly array   $parents = [],
        public readonly ?string $key = null,
    ) {}
}
