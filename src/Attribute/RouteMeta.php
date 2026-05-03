<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Attribute;

use Survos\FieldBundle\Enum\Audience;
use Survos\FieldBundle\Enum\Purpose;

/**
 * Method-level metadata for controller actions — a sibling of #[EntityMeta].
 *
 * Discovered at compile time by RouteMetaPass via atlas-bundle's
 * ControllerAtlasBuilder. Powers sitemap generation, AI/dev introspection
 * ("what does GET /tenants do?"), nav/breadcrumb building, OpenAPI projection,
 * and HTML <meta description> tags.
 *
 * Composes with #[Route], #[Template], and #[IsGranted] — does not replace them.
 *
 * Example:
 *
 *     #[Route('/tenant/{slug}', name: 'tenant_show')]
 *     #[RouteMeta(
 *         description: 'Public overview of a tenant: name, photo, public collections.',
 *         entity: Tenant::class,
 *         purpose: Purpose::Show,
 *         audience: Audience::Public,
 *         sitemap: true,
 *         changefreq: 'weekly',
 *     )]
 *     public function show(Tenant $tenant): Response { ... }
 *
 * The companion #[Route] must carry an explicit name. Routes without a name
 * are skipped by atlas-bundle and therefore never reach the RouteMetaRegistry.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class RouteMeta
{
    /**
     * @param list<class-string>          $relatedEntities Additional entity classes the route depends on
     *                                                     (cross-cutting routes like `/tenant/{x}/user/{y}`).
     *                                                     Use $entity for the primary; $relatedEntities for the rest.
     * @param array<string, class-string> $params          Optional route-variable → entity-class map.
     *                                                     Only needed when the variable name doesn't match an
     *                                                     entity by `{shortName}…` convention (e.g. `/compare/{a}/{b}`
     *                                                     where both are Tenant).
     * @param list<string>                $tags            Free-form labels ('admin', 'api', 'export', 'beta', …).
     * @param list<string>                $parents         Route names to consider as parents for breadcrumbs.
     */
    public function __construct(
        /** Developer-facing English prose. Required. Used for AI introspection, OpenAPI, dashboards. */
        public readonly string $description,

        /** FQCN of the primary entity this route operates on, or null for app-level pages. */
        public readonly ?string $entity = null,

        public readonly array $relatedEntities = [],

        public readonly array $params = [],

        /** What this route does relative to the entity. */
        public readonly Purpose $purpose = Purpose::Custom,

        /** Translation key for menu/breadcrumb display. Null = derive from route name. */
        public readonly ?string $label = null,

        /** Who is this route for? Descriptive only — does not enforce access. */
        public readonly Audience $audience = Audience::Authenticated,

        /** Include in sitemap.xml. Null = "default for this audience" (public => true). */
        public readonly ?bool $sitemap = null,

        /** sitemap.xml <changefreq>: always|hourly|daily|weekly|monthly|yearly|never */
        public readonly ?string $changefreq = null,

        /** sitemap.xml <priority>: 0.0 to 1.0 */
        public readonly ?float $priority = null,

        public readonly array $tags = [],

        public readonly array $parents = [],
    ) {}
}
