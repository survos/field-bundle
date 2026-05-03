# RouteMeta — Design Doc

> Add `RouteMeta` as a sibling to `EntityMeta` in `survos/field-bundle`.
> Method-level attribute on controller actions. Discovered by a compiler pass
> that walks the Symfony router. Powers: sitemap generation, AI/dev introspection
> ("what does GET /abc do?"), nav/breadcrumb building, OpenAPI projection,
> and `<meta description>` tags.

## Goals

- One source of truth per route, co-located with the controller action.
- Composes with `#[Route]`, `#[Template]`, `#[IsGranted]` — does not replace them.
- Links routes to entities (when applicable) so the registry can answer
  "what's the canonical show page for Tenant?" and "which entities are missing
  a dashboard?".
- Zero runtime cost: registry is built at compile time and cached in the container.
- Works for application-level pages that have no entity (`entity: null`).

## Non-goals

- Not a replacement for translations. `label` is a translation key; `description`
  is developer-facing English prose.
- Not a router. `#[Route]` still owns URL/method/name.
- Not a permissions system. `#[IsGranted]` still owns access control.
  `audience` on `RouteMeta` is descriptive metadata for nav/sitemap, not enforcement.

---

## The attribute

**File:** `src/Attribute/RouteMeta.php`

```php
<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Attribute;

/**
 * Method-level metadata for controller actions.
 *
 * Discovered at compile time by RouteMetaPass, which walks the Symfony
 * RouterInterface route collection and reflects each route's controller.
 *
 * Example:
 *   #[Route('/tenant/{slug}', name: 'tenant_show')]
 *   #[RouteMeta(
 *       entity: Tenant::class,
 *       purpose: Purpose::Show,
 *       description: 'Public overview of a tenant: name, photo, public collections.',
 *       audience: Audience::Public,
 *       sitemap: true,
 *       changefreq: 'weekly',
 *   )]
 *   public function show(Tenant $tenant): Response { ... }
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class RouteMeta
{
    public function __construct(
        /** Developer-facing prose. Required. Used for AI introspection, OpenAPI, dashboards. */
        public readonly string $description,

        /** FQCN of the primary entity this route operates on, or null for app-level pages. */
        public readonly ?string $entity = null,

        /** What this route does relative to the entity. Use Purpose enum or free string. */
        public readonly string $purpose = Purpose::Custom,

        /** Translation key for menu/breadcrumb display. Defaults to route name humanized. */
        public readonly ?string $label = null,

        /** Who is this page for? Drives nav grouping and sitemap inclusion defaults. */
        public readonly string $audience = Audience::Authenticated,

        /** Include in sitemap.xml. Defaults true for public, false otherwise. */
        public readonly ?bool $sitemap = null,

        /** sitemap.xml <changefreq>: always|hourly|daily|weekly|monthly|yearly|never */
        public readonly ?string $changefreq = null,

        /** sitemap.xml <priority>: 0.0 to 1.0 */
        public readonly ?float $priority = null,

        /** Free-form tags for filtering ('admin', 'api', 'export', 'beta'). */
        public readonly array $tags = [],

        /** Route name(s) to consider as parent for breadcrumb construction. */
        public readonly array $parents = [],
    ) {}
}
```

## Supporting enums (as final classes with consts — Symfony-idiomatic, attribute-friendly)

**File:** `src/Attribute/Purpose.php`

```php
<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Attribute;

/**
 * What a route does relative to its entity. Free-form, but these are the
 * canonical values the registry understands for graph queries.
 */
final class Purpose
{
    public const Index     = 'index';      // list/browse
    public const Show      = 'show';       // public detail page
    public const Dashboard = 'dashboard';  // admin overview for one entity + related
    public const New       = 'new';        // create form
    public const Edit      = 'edit';       // update form
    public const Delete    = 'delete';     // delete action
    public const Export    = 'export';     // download/export
    public const Api       = 'api';        // JSON/API endpoint
    public const Custom    = 'custom';     // anything else
}
```

**File:** `src/Attribute/Audience.php`

```php
<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Attribute;

final class Audience
{
    public const Public        = 'public';        // anyone, indexable
    public const Authenticated = 'authenticated'; // logged-in users
    public const Admin         = 'admin';         // admin only
    public const Api           = 'api';           // machine consumers
    public const Internal      = 'internal';      // dev tools, not for users
}
```

---

## The registry

**File:** `src/Service/MetaRegistry.php`

The single service every consumer talks to. Exposes both entity and route metadata,
plus the graph that joins them.

```php
<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Service;

use Survos\FieldBundle\Attribute\EntityMeta;
use Survos\FieldBundle\Attribute\RouteMeta;

final class MetaRegistry
{
    /**
     * @param array<class-string, EntityMeta> $entities
     * @param array<string, RouteMetaEntry>   $routes      keyed by route name
     */
    public function __construct(
        private readonly array $entities,
        private readonly array $routes,
    ) {}

    /** @return array<class-string, EntityMeta> */
    public function entities(): array { return $this->entities; }

    /** @return array<string, RouteMetaEntry> */
    public function routes(): array { return $this->routes; }

    public function entity(string $fqcn): ?EntityMeta
    {
        return $this->entities[$fqcn] ?? null;
    }

    public function route(string $name): ?RouteMetaEntry
    {
        return $this->routes[$name] ?? null;
    }

    /**
     * All routes for a given entity, optionally filtered by purpose.
     *
     * @return array<string, RouteMetaEntry>
     */
    public function routesFor(string $entityFqcn, ?string $purpose = null): array
    {
        return array_filter(
            $this->routes,
            fn (RouteMetaEntry $r) =>
                $r->meta->entity === $entityFqcn
                && ($purpose === null || $r->meta->purpose === $purpose),
        );
    }

    /** Convenience: canonical show route for an entity, or null. */
    public function showRouteFor(string $entityFqcn): ?RouteMetaEntry
    {
        return $this->routesFor($entityFqcn, \Survos\FieldBundle\Attribute\Purpose::Show)[0] ?? null;
    }

    /**
     * Diagnostic: entities that are missing routes of the given purposes.
     *
     * @param  list<string> $required
     * @return array<class-string, list<string>>  entity => list of missing purposes
     */
    public function missingPurposes(array $required): array
    {
        $gaps = [];
        foreach ($this->entities as $fqcn => $_meta) {
            $present = array_map(fn ($r) => $r->meta->purpose, $this->routesFor($fqcn));
            $missing = array_values(array_diff($required, $present));
            if ($missing !== []) {
                $gaps[$fqcn] = $missing;
            }
        }
        return $gaps;
    }
}
```

**File:** `src/Service/RouteMetaEntry.php`

A flattened DTO — the attribute plus the routing info that came from `#[Route]`.
This is what the registry stores so consumers don't need to re-walk the router.

```php
<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Service;

use Survos\FieldBundle\Attribute\RouteMeta;

final class RouteMetaEntry
{
    public function __construct(
        public readonly string   $name,        // route name
        public readonly string   $path,        // /tenant/{slug}
        public readonly array    $methods,     // ['GET'] etc
        public readonly string   $controller,  // 'App\Controller\TenantController::show'
        public readonly RouteMeta $meta,
    ) {}
}
```

---

## The compiler pass

**File:** `src/DependencyInjection/Compiler/RouteMetaPass.php`

```php
<?php

declare(strict_types=1);

namespace Survos\FieldBundle\DependencyInjection\Compiler;

use Survos\FieldBundle\Attribute\RouteMeta;
use Survos\FieldBundle\Service\MetaRegistry;
use Survos\FieldBundle\Service\RouteMetaEntry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Routing\RouterInterface;

final class RouteMetaPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Router isn't fully built at compile time, but route loaders are.
        // Use the cached route collection via the router service definition,
        // OR walk controller classes directly via the same mechanism EntityMetaPass uses.
        //
        // Recommended: walk controller directories the same way EntityMetaPass walks
        // entity directories. This avoids depending on the router at compile time.
        //
        // Pseudocode — actual scanning logic mirrors EntityMetaPass:

        $entries = []; // route_name => RouteMetaEntry

        foreach ($this->findControllerClasses($container) as $fqcn) {
            $reflection = new \ReflectionClass($fqcn);
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $routeAttrs = $method->getAttributes(\Symfony\Component\Routing\Attribute\Route::class);
                $metaAttrs  = $method->getAttributes(RouteMeta::class);
                if ($routeAttrs === [] || $metaAttrs === []) {
                    continue;
                }

                $route = $routeAttrs[0]->newInstance();
                $meta  = $metaAttrs[0]->newInstance();
                $name  = $route->getName() ?? throw new \LogicException(
                    sprintf('Route on %s::%s needs a name to use RouteMeta.', $fqcn, $method->getName())
                );

                $entries[$name] = new RouteMetaEntry(
                    name:       $name,
                    path:       $route->getPath(),
                    methods:    $route->getMethods() ?: ['GET'],
                    controller: $fqcn . '::' . $method->getName(),
                    meta:       $meta,
                );
            }
        }

        // Wire into the registry. EntityMetaPass already populates $entities;
        // we merge into the same registry definition.
        $registryDef = $container->getDefinition(MetaRegistry::class);
        $registryDef->replaceArgument(1, $entries);
    }

    /** @return iterable<class-string> */
    private function findControllerClasses(ContainerBuilder $container): iterable
    {
        // Mirror EntityMetaPass: scan src/Controller and bundle Controller dirs.
        // Implementation detail — copy the directory-walking helper from EntityMetaPass.
        return [];
    }
}
```

> **Implementation note:** the directory walking and FQCN resolution should be
> extracted into a shared helper so `EntityMetaPass` and `RouteMetaPass` use the
> same scanning code with different target dirs (`Entity/` vs `Controller/`) and
> different attribute classes.

---

## Bundle wiring

**File:** `src/SurvosFieldBundle.php` (additions)

```php
public function build(ContainerBuilder $container): void
{
    parent::build($container);
    $container->addCompilerPass(new EntityMetaPass());
    $container->addCompilerPass(new RouteMetaPass());
}
```

**File:** `config/services.php` (additions)

```php
$services->set(MetaRegistry::class)
    ->args([
        [], // entities — populated by EntityMetaPass
        [], // routes   — populated by RouteMetaPass
    ])
    ->public(); // exposed for console commands
```

---

## Console commands (ship with the bundle)

### `bin/console meta:list`

Prints a table of all routes with their entity, purpose, audience, and description.

### `bin/console meta:export [--format=json|yaml] [--output=meta.json]`

Dumps the full registry as structured data. **This is the killer feature for AI workflows** —
paste the JSON into a Claude conversation and ask design questions.

Output shape:

```json
{
  "entities": {
    "App\\Entity\\Tenant": {
      "icon": "mdi:building",
      "group": "Content",
      "label": "Tenant",
      "description": "A museum or institution using ScanStation."
    }
  },
  "routes": {
    "tenant_show": {
      "path": "/tenant/{slug}",
      "methods": ["GET"],
      "controller": "App\\Controller\\TenantController::show",
      "entity": "App\\Entity\\Tenant",
      "purpose": "show",
      "description": "Public overview of a tenant: name, photo, public collections.",
      "audience": "public",
      "sitemap": true
    }
  },
  "graph": {
    "App\\Entity\\Tenant": {
      "show": "tenant_show",
      "dashboard": "tenant_dashboard",
      "edit": "tenant_edit"
    }
  },
  "gaps": {
    "App\\Entity\\Image": ["index", "show"]
  }
}
```

### `bin/console meta:gaps [--require=index,show,edit]`

Reports entities missing canonical purposes. CI-friendly: nonzero exit if gaps found.

---

## Sitemap consumer (separate bundle, depends on field-bundle)

`survos/sitemap-bundle` becomes trivial:

```php
final class SitemapGenerator
{
    public function __construct(
        private readonly MetaRegistry $registry,
        private readonly UrlGeneratorInterface $urls,
    ) {}

    public function generate(): string
    {
        $xml = new \SimpleXMLElement('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"/>');
        foreach ($this->registry->routes() as $entry) {
            $include = $entry->meta->sitemap
                ?? ($entry->meta->audience === Audience::Public);
            if (!$include) continue;
            // For parametric routes, resolve via repository — out of scope here.
            $u = $xml->addChild('url');
            $u->addChild('loc', $this->urls->generate($entry->name, [], UrlGeneratorInterface::ABSOLUTE_URL));
            if ($entry->meta->changefreq) $u->addChild('changefreq', $entry->meta->changefreq);
            if ($entry->meta->priority !== null) $u->addChild('priority', (string) $entry->meta->priority);
        }
        return $xml->asXML();
    }
}
```

---

## Worked example — Tenant

```php
final class TenantController extends AbstractController
{
    #[Route('/tenants', name: 'tenant_index', methods: ['GET'])]
    #[RouteMeta(
        description: 'Public list of all tenants. Searchable, filterable by group.',
        entity: Tenant::class,
        purpose: Purpose::List,
        audience: Audience::Public,
        sitemap: true,
        changefreq: 'daily',
        priority: 0.7,
    )]
    public function index(): Response { ... }

    #[Route('/tenant/{slug}', name: 'tenant_show', methods: ['GET'])]
    #[RouteMeta(
        description: 'Public-facing tenant overview: name, logo, bio, public collections.',
        entity: Tenant::class,
        purpose: Purpose::Show,
        audience: Audience::Public,
        sitemap: true,
        changefreq: 'weekly',
    )]
    public function show(Tenant $tenant): Response { ... }

    #[Route('/admin/tenant/{slug}', name: 'tenant_dashboard', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    #[RouteMeta(
        description: 'Admin dashboard for a tenant. Shows members, images, items, recent activity.',
        entity: Tenant::class,
        purpose: Purpose::Dashboard,
        audience: Audience::Admin,
        parents: ['tenant_show'],
    )]
    public function dashboard(Tenant $tenant): Response { ... }
}
```

---

## Open questions for you

1. **Where does scanning happen?** EntityMetaPass scans entity dirs. Do you want
   `RouteMetaPass` to scan `src/Controller` + each bundle's `Controller/` dir,
   or hook into the existing router cache? Scanning is more decoupled; router-hook
   is more accurate (catches routes added by `RouteLoader`s).

2. **Should `purpose` be an enum-backed type?** PHP 8.1+ enums are nicer but
   some attribute-discovery tools choke on them. The `final class` + `const`
   pattern shown matches what API Platform and EasyAdmin do.

3. **Multiple entities per route?** A "compare two tenants" page operates on
   `Tenant::class` twice. Do you want `entity` to accept `string|array`? Recommend
   keeping it singular for now — multi-entity pages can use `tags` or a custom
   `secondaryEntities` field added later without BC break.

4. **Translation strategy for `label`?** Default behavior: if `label` is null,
   the registry computes a key like `route.tenant_show.label` and consumers run
   it through the translator. If `label` is set, treat it as the key directly.

5. **Should `RouteMeta` be repeatable?** Marked it so above (`IS_REPEATABLE`) on
   the assumption that one method might serve multiple named routes. If that's
   not a real case in survos apps, drop the flag.

---

## Implementation checklist (for Claude Code)

- [ ] `src/Attribute/RouteMeta.php` — the attribute class
- [ ] `src/Attribute/Purpose.php` — purpose constants
- [ ] `src/Attribute/Audience.php` — audience constants
- [ ] `src/Service/RouteMetaEntry.php` — DTO
- [ ] `src/Service/MetaRegistry.php` — extend or replace existing entity registry
- [ ] `src/DependencyInjection/Compiler/RouteMetaPass.php` — controller scanning
- [ ] Refactor: extract directory-walking helper shared with `EntityMetaPass`
- [ ] `src/Command/MetaListCommand.php` — `meta:list`
- [ ] `src/Command/MetaExportCommand.php` — `meta:export`
- [ ] `src/Command/MetaGapsCommand.php` — `meta:gaps`
- [ ] `tests/` — fixture controllers with attributes; assert registry contents
- [ ] `README.md` section explaining `RouteMeta` alongside `EntityMeta`
- [ ] CHANGELOG entry: new attribute, new commands, no BC break to `EntityMeta`
