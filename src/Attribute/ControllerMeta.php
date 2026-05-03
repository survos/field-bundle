<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Attribute;

use Survos\FieldBundle\Enum\Audience;

/**
 * Class-level metadata for a controller — the fields that apply to every
 * action in the class, factored out of repeated #[RouteMeta] declarations.
 *
 * Pairs with #[RouteMeta] (method-level). RouteMetaPass merges class-level
 * ControllerMeta defaults UNDER each method-level RouteMeta — the method
 * always wins for any field it explicitly sets; ControllerMeta fills the gaps.
 *
 * Example:
 *
 *     #[Route('/{tenantId}/acc/{accCode}')]
 *     #[ControllerMeta(
 *         entity: Acc::class,
 *         relatedEntities: [Tenant::class],
 *         audience: Audience::Authenticated,
 *     )]
 *     final class AccController
 *     {
 *         public function __construct(
 *             private Tenant $tenant,
 *             private Acc $acc,
 *         ) {}
 *
 *         #[Route('/show', name: 'acc_show')]
 *         #[RouteMeta(description: 'Accession detail page', purpose: Purpose::Show)]
 *         public function show(): Response { /* uses $this->acc *\/ }
 *
 *         #[Route('/edit', name: 'acc_edit')]
 *         #[RouteMeta(description: 'Edit accession', purpose: Purpose::Edit, audience: Audience::Admin)]
 *         public function edit(): Response { /* override audience here *\/ }
 *     }
 *
 * Fields here are deliberately the *class-friendly* subset of RouteMeta:
 *
 *   - description, purpose, label, parents — method-only (intrinsically per-action)
 *   - everything else — shareable
 *
 * Constructor defaults match RouteMeta where they overlap so users see the
 * same field semantics regardless of where they declare them. The pass uses
 * raw attribute arguments (not instantiated values) for merging, so an
 * unspecified field at class level does not override a method-level explicit.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ControllerMeta
{
    /**
     * @param list<class-string>          $relatedEntities
     * @param array<string, class-string> $params
     * @param list<string>                $tags
     */
    public function __construct(
        public readonly ?string   $entity          = null,
        public readonly array     $relatedEntities = [],
        public readonly array     $params          = [],
        public readonly ?Audience $audience        = null,
        public readonly array     $tags            = [],
        public readonly ?bool     $sitemap         = null,
        public readonly ?string   $changefreq      = null,
        public readonly ?float    $priority        = null,
    ) {}
}
