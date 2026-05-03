# Controller Naming & Organization Convention

## Rule

**Split controllers by whether the action requires a specific entity from the URL, not by CRUD verb or cardinality.**

- **`XxxController`** — operates on a single, identified entity. Route prefix includes the entity ID(s).
- **`XxxListController`** — operates on the aggregate (the set). No entity ID in the route.

Both end in `Controller` for tooling consistency (Symfony conventions, EasyAdmin, IDE filters).

## Naming

```
src/Controller/TenantController.php       → App\Controller\TenantController
src/Controller/TenantListController.php   → App\Controller\TenantListController
```

The singular gets the unmarked name because most domain logic accretes there over time (`reocr`, `publish`, `printLabel`, …). The list controller stays comparatively small.

## Routing

`TenantController` declares the entity ID at the class level. Every action inherits the requirement.

```php
use App\Entity\Tenant;
use Survos\FieldBundle\Attribute\ControllerMeta;
use Survos\FieldBundle\Attribute\RouteMeta;
use Survos\FieldBundle\Enum\Audience;
use Survos\FieldBundle\Enum\Purpose;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/tenant/{tenantId}')]
#[ControllerMeta(entity: Tenant::class, audience: Audience::Authenticated)]
final class TenantController extends AbstractController
{
    public function __construct(
        private readonly TenantService $tenantService,
        // …services only. See "Entity injection" below for why.
    ) {}

    #[Route('', name: 'tenant_show')]
    #[Template('tenant/show.html.twig')]
    #[RouteMeta(description: 'Tenant detail page', purpose: Purpose::Show, audience: Audience::Public)]
    public function show(Tenant $tenant): array
    {
        return ['tenant' => $tenant];
    }

    #[Route('/edit', name: 'tenant_edit')]
    #[Template('tenant/edit.html.twig')]
    #[RouteMeta(description: 'Edit tenant settings', purpose: Purpose::Edit)]
    public function edit(Tenant $tenant, Request $request): array|RedirectResponse
    {
        $form = $this->createForm(TenantType::class, $tenant);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->tenantService->save($tenant);
            return $this->redirectToRoute('tenant_show', $tenant->getRp());
        }

        return ['tenant' => $tenant, 'form' => $form->createView()];
    }

    #[Route('/reocr', name: 'tenant_reocr', methods: ['POST'])]
    #[RouteMeta(description: 'Re-run OCR over all of this tenant\'s images', purpose: Purpose::Custom)]
    public function reocr(Tenant $tenant): RedirectResponse
    {
        $this->tenantService->queueReocr($tenant);
        return $this->redirectToRoute('tenant_show', $tenant->getRp());
    }
}
```

## Action signature & template

Survos's default action shape:

- Controller method takes the URL-resolved entities as **method arguments**, not constructor arguments. `Tenant $tenant`, not `private Tenant $tenant`.
- The action **returns an `array`** of template variables.
- `#[Template('path/to/template.html.twig')]` declares which template renders that array.
- For actions that may redirect, the return type is `array|RedirectResponse`. For pure redirects (e.g. legacy URL stubs), just `RedirectResponse`.

**Why this matters for testing.** A test calls `$controller->show($tenant)` and gets back the array directly — no Twig rendering, no HTML to parse, no template-path coupling. Asserts on data structure, not markup.

```php
public function testShowReturnsTenantStats(): void
{
    $controller = self::getContainer()->get(TenantController::class);
    $result = $controller->show($tenant);

    self::assertSame($tenant, $result['tenant']);
    // refactor the template however you want — this still passes
}
```

The same array is also reusable in non-HTML contexts (a JSON endpoint, a print-friendly variant, an embedded partial in another action) — the action *is* the data builder; rendering is one consumer of many.

Prefer explicit arrays over `#[Template(vars: [...])]`. The `vars:` form picks up named method args automatically; explicit returns are easier to grep, refactor, and reason about. One way to do it.

## Entity injection

Action method arguments — **not** constructor arguments. Two reasons:

1. Method-arg resolution flows through Symfony's `ValueResolverInterface` chain, which our `ParameterResolver` (in `survos/core-bundle`) participates in. It reads the entity's `#[RouteIdentity]` and walks any parent chain automatically. **No `#[MapEntity]` needed.**
2. Constructor entity injection (Symfony 7.1+) is gated specifically by `#[MapEntity]` and `#[CurrentUser]`; arbitrary `ValueResolverInterface` implementations don't fire there. Until our resolver participates in that path, the constructor stays for services only.

When the future fix lands, controllers that prefer constructor injection (one entity resolved once, every action accesses `$this->tenant`) can switch with an attribute swap. The convention is *aspirational* there; method args are what works today.

`TenantListController` operates on the collection. No `{tenantId}` in the prefix.

```php
#[Route('/tenants')]
#[ControllerMeta(entity: Tenant::class, audience: Audience::Admin)]
final class TenantListController extends AbstractController
{
    #[Route('', name: 'tenant_list')]
    #[RouteMeta(description: 'Browse all tenants', purpose: Purpose::List, audience: Audience::Public)]
    public function list(): Response { /* ... */ }

    #[Route('/new', name: 'tenant_new')]
    #[RouteMeta(description: 'Onboard a new tenant', purpose: Purpose::New)]
    public function new(): Response { /* ... */ }

    #[Route('/import', name: 'tenant_import')]
    #[RouteMeta(description: 'Import tenants from CSV', purpose: Purpose::Custom, tags: ['import'])]
    public function import(): Response { /* ... */ }

    #[Route('/export', name: 'tenant_export')]
    #[RouteMeta(description: 'Export all tenants', purpose: Purpose::Export, tags: ['export'])]
    public function export(): Response { /* ... */ }

    #[Route('/bulk-delete', name: 'tenant_bulk_delete', methods: ['POST'])]
    #[RouteMeta(description: 'Bulk delete by IDs from request body', purpose: Purpose::Custom)]
    public function bulkDelete(): Response { /* ... */ }
}
```

## Entity resolution

The entity is resolved by survos's `ParameterResolver` (in core-bundle) reading the entity's `#[RouteIdentity]` declaration. No `#[MapEntity]` needed — the resolver matches the route variable (`{tenantId}`) to the entity field via convention.

For child entities, the parent chain resolves automatically through `RouteIdentity(parents: [...])`:

```php
// Acc::class
#[RouteIdentity(field: 'code', parents: ['tenant'], key: 'accCode')]

// AccController
#[Route('/{tenantId}/acc/{accCode}')]
final class AccController extends AbstractController
{
    public function __construct(
        private Tenant $tenant,
        private Acc    $acc,
    ) {}
    // ...
}
```

The resolver looks up Tenant first (by `tenantId` → `code`), then Acc (by `accCode` → `code` AND `tenant` → resolved Tenant instance).

## Why this works

- The class-level route is a contract: **every** action in the class is guaranteed to have the entity available.
- Class-level `#[IsGranted('TENANT_VIEW', subject: 'tenant')]` becomes possible because the subject always exists.
- Entity resolution lives once in the constructor; individual actions don't repeat the parameter.
- `ControllerMeta` declares the cross-cutting metadata (`entity`, default `audience`, default `tags`) once. `RouteMeta` per action only declares what's specific to that action.
- Route names follow `tenant_*` for both controllers, so URL generation is uniform from Twig.

## Action placement rules

| Action | Controller | Reason |
|---|---|---|
| `show`, `edit`, `delete` | `TenantController` | Operates on one identified entity |
| `list`, `new` | `TenantListController` | No source entity; `new` produces one |
| `export`, `import` | `TenantListController` | Operates on the set |
| `bulkDelete`, `bulkPublish` | `TenantListController` | User-selected subset, not a URL-identified entity |
| `duplicate` / `clone` | `TenantController` | Needs a source entity, even though it produces a new one |
| `setDefault`, `archive`, `restore` | `TenantController` | Targets one specific entity |
| Search / autocomplete endpoints | `TenantListController` | Returns subsets of the collection |
| Domain actions (`reocr`, `publish`, `printLabel`) | `TenantController` | Operate on one entity |

## Child collections

When listing entities scoped to a parent (e.g., a tenant's items), decide by user intent:

- **Tab on the parent's detail page** ("looking at the tenant, items view") → action in `TenantController`, e.g. `/tenant/{tenantId}/items`.
- **Substantial enough to be its own surface** ("working with items, filtered by tenant") → separate `ItemListController` accepting an optional tenant filter.

In ScanStationAI specifically, a collection's pages are the second case — users are working with pages, the collection is the filter.

## Bulk operations note

Add a one-line comment at the top of `TenantListController` reminding contributors that **bulk operations live here**, not in `TenantController`, even when they delete or modify entities. The deciding factor is "operates on a set" vs. "operates on one URL-identified entity."

## Migration from `XxxCollectionController`

Rename `TenantCollectionController` → `TenantListController` across the codebase. Route names should already be `tenant_list`, `tenant_new`, etc.; if any are `tenant_collection_*`, rename those too for consistency. Update Twig `path()` and `url()` calls. Update any `IsGranted` attribute references.

## When our resolver isn't available

If you're outside the survos workspace, or you want explicit URL-variable mapping, use Symfony's `#[MapEntity]` instead:

```php
public function __construct(
    #[MapEntity(mapping: ['tenantId' => 'code'])]
    private Tenant $tenant,
) {}
```

Same end result, more verbose at every call site.
