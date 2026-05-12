# survos/field-bundle

Universal field/property metadata for Symfony — declare once, consume everywhere.

## The problem

Property metadata is scattered across attributes with overlapping concerns:

| Attribute | Owner | Covers |
|---|---|---|
| `#[ApiProperty]` | api-platform | OpenAPI description, example |
| `#[With]` | symfony/ai | JSON Schema constraints for LLMs |
| `#[ORM\Column]` | doctrine | Storage type |
| `#[ApiFilter]` | api-platform | Server-side filter declaration |

None of them answer: *how should this property be displayed and filtered in a grid, search panel, or UX-search widget?*

## The solution

`#[Field]` declares display and search behavior once, orthogonally to the other attributes:

```php
use Survos\FieldBundle\Attribute\Field;
use Survos\FieldBundle\Enum\Widget;

class Tenant
{
    #[Field(searchable: true, sortable: true, order: 10)]
    public string $name = '';

    #[Field(filterable: true, widget: Widget::Select, facet: true, order: 20)]
    public string $status = '';

    #[Field(sortable: true, format: 'date', order: 30)]
    public \DateTimeImmutable $createdAt;
}
```

## Attribute lanes — no overlap

```php
#[With(description: 'Execution status', enum: ['pending', 'done', 'failed'])]  // LLM schema
#[ApiProperty('Current execution status')]                                       // OpenAPI
#[Field(filterable: true, widget: Widget::Select, facet: true)]                 // display/search
public AiTaskStatus $status;
```

---

## Installation

```bash
composer require survos/field-bundle
```

---

## Attributes

The bundle provides five PHP attributes covering properties, entities, and controllers.

### `#[Field]` — property / method level

```php
use Survos\FieldBundle\Attribute\Field;
use Survos\FieldBundle\Enum\Widget;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
class Field
{
    public function __construct(
        ?string $transKey   = null,   // translation key override (looked up in 'fields' domain)
        bool    $searchable = false,  // include in full-text search
        bool    $sortable   = false,  // allow ordering
        bool    $filterable = false,  // expose a filter control
        ?Widget $widget     = null,   // filter UI widget; inferred from PHP type when null
        bool    $facet      = false,  // include in facet panel (sidebar, searchList, refinements)
        bool    $visible    = true,   // show by default (false = hidden but toggleable)
        int     $order      = 100,    // column display position (lower = further left)
        ?string $width      = null,   // CSS width hint, e.g. '8rem'
        ?string $format     = null,   // display format: 'date', 'datetime', 'currency', etc.
    ) {}
}
```

**Widget inference** — when `widget` is null, `FieldDescriptor::resolvedWidget()` infers from PHP type:

| PHP type | Inferred widget |
|---|---|
| `bool` | `Widget::Boolean` |
| `int`, `float` | `Widget::Range` |
| `\DateTimeInterface` | `Widget::Date` |
| backed enum | `Widget::Select` |
| `string` | `Widget::Text` |

Widget is only inferred when `filterable: true`. Non-filterable fields return `null`.

**Browsability** — `Widget::Select` and `Widget::Boolean` are "browsable" (render as selectable lists in ColumnControl / SearchBuilder / facet panels). `Widget::Range`, `Widget::Date`, and `Widget::Text` are filterable but not browsable — they render as input controls.

### `#[EntityMeta]` — class level

Class-level metadata for admin UI, dashboard cards, and menu auto-registration.

```php
use Survos\FieldBundle\Attribute\EntityMeta;

#[EntityMeta(
    icon: 'mdi:building',
    group: 'Content',
    order: 10,
    label: 'Tenant',
    description: 'A workspace that can own projects and members.',
    adminBrowsable: true,
)]
class Tenant { ... }
```

Parameters:

| Parameter | Type | Default | Description |
|---|---|---|---|
| `icon` | string | null | UX icon name, e.g. `'mdi:building'`, `'tabler:user'` |
| `iconClass` | string | null | CSS class for the icon, e.g. `'text-primary'` |
| `order` | int | 100 | Position within the group (lower = first) |
| `group` | string | `'General'` | Section/submenu header in admin nav |
| `label` | string | null | Human-readable label; defaults to short class name |
| `description` | string | null | One-line description for dashboard cards |
| `adminBrowsable` | bool | true | Include in admin navbar and dashboard |

Discovered at compile time by `EntityMetaPass`, which scans all Doctrine entity directories.

**Twig globals** — every `#[EntityMeta]` entity is exposed as a Twig global keyed by `APP_ENTITY_{SHORTNAME}` (upper-snake of short class name). Use this to avoid class strings in templates:

```twig
{# Instead of constant('App\\Entity\\Song') #}
<twig:api_grid :class="APP_ENTITY_SONG" ... />

{# Iterate all registered entities #}
{% for descriptor in ENTITY_META.all %}
    {{ descriptor.label }}: {{ descriptor.class }}
{% endfor %}
```

### `#[RouteIdentity]` — class level

Declares how an entity identifies itself in URLs. This is fundamental to Survos navigation:
entities generate their own route parameters with `getRp()`, controllers resolve typed entity
arguments from those same parameters, and templates link with `path('route_name', entity.rp)`.

This replaces the legacy `UNIQUE_PARAMETERS` const pattern from `survos/core-bundle` and avoids
repeating `#[MapEntity]` mappings on every controller method.

```php
use Survos\FieldBundle\Attribute\RouteIdentity;

// Simple: single field
#[RouteIdentity(field: 'code')]
class Tenant implements RouteParametersInterface
{
    use RouteIdentityTrait;
    #[ORM\Column] public string $code;
}

// Nested: child entity walks the parent chain automatically
#[RouteIdentity(field: 'code', parents: ['tenant'], key: 'projectCode')]
class Project implements RouteParametersInterface
{
    use RouteIdentityTrait;
    #[ORM\ManyToOne] public Tenant $tenant;
    #[ORM\Column]    public string $code;
}

// $project->getRp() → ['tenantId' => 'acme', 'projectCode' => 'photo-archive']
// No manual merge — the parent chain resolves automatically.
```

Parameters:

| Parameter | Type | Description |
|---|---|---|
| `field` | string | Property name or getter to read (e.g. `'code'` → `$entity->code` or `$entity->getCode()`) |
| `parents` | string[] | Property names of associations to walk for parent route params |
| `key` | string | Override the URL parameter key (defaults to `{lcfirst(ShortName)}Id`) |

**`RouteIdentityTrait`** implements `getRp()`, `getUniqueIdentifiers()`, and `erp()` for the entity. Pair with `implements RouteParametersInterface` from `survos/core-bundle`.

#### Navigation Contract

For every navigable Doctrine entity, use this pattern:

```php
use Survos\CoreBundle\Entity\RouteParametersInterface;
use Survos\FieldBundle\Attribute\RouteIdentity;
use Survos\FieldBundle\Entity\RouteIdentityTrait;

#[RouteIdentity(field: 'id')]
class Image implements RouteParametersInterface
{
    use RouteIdentityTrait;

    #[ORM\Id]
    #[ORM\Column(length: 26)]
    public string $id;
}
```

Then name the route parameter after the generated key. The default key is
`{lcfirst(shortClassName)}Id`, so `Image` becomes `imageId`, `Item` becomes `itemId`,
and `GalleryImage` becomes `galleryImageId`.

```php
#[Route('/image/{imageId}')]
final class ImageController extends AbstractController
{
    #[Route('/show', name: 'image_show')]
    public function show(Image $image): array
    {
        return ['image' => $image];
    }
}
```

Templates should not rebuild route parameters manually:

```twig
<a href="{{ path('image_show', image.rp) }}">{{ image }}</a>
```

For a custom route key, declare it on the entity and use that key in the route:

```php
#[RouteIdentity(field: 'code', key: 'intakeCode', parents: ['tenant'])]
class Intake implements RouteParametersInterface
{
    use RouteIdentityTrait;
}

#[Route('/{tenantId}/i/{intakeCode}')]
final class IntakeController extends AbstractController
{
    #[Route('/show', name: 'intake_show')]
    public function show(Intake $intake): array
    {
        return ['intake' => $intake];
    }
}
```

The entity is the single source of truth for route identity:

- `entity.rp` generates URL parameters.
- `RouteIdentityValueResolver` resolves controller arguments.
- Menus, labels, redirects, and templates all use the same contract.
- If the route parameter name does not match the identity key, typed entity resolution will not run.

Migration from old pattern:

```php
// Before (core-bundle)
class Owner implements RouteParametersInterface
{
    use RouteParametersTrait;
    public const array UNIQUE_PARAMETERS = ['ownerId' => 'code'];
}

// After (field-bundle)
#[RouteIdentity(field: 'code')]
class Owner implements RouteParametersInterface
{
    use RouteIdentityTrait;
}
```

### `#[RouteMeta]` — method level

Metadata for individual controller actions. Powers sitemap generation, AI introspection, breadcrumbs, nav, and OpenAPI projection.

```php
use Survos\FieldBundle\Attribute\RouteMeta;
use Survos\FieldBundle\Enum\Audience;
use Survos\FieldBundle\Enum\Purpose;

#[Route('/tenant/{tenantId}', name: 'tenant_show')]
#[RouteMeta(
    description: 'Public overview page for a tenant.',
    entity: Tenant::class,
    purpose: Purpose::Show,
    audience: Audience::Public,
    sitemap: true,
    changefreq: 'weekly',
)]
public function show(Tenant $tenant): array { ... }
```

Key parameters:

| Parameter | Type | Description |
|---|---|---|
| `description` | string | **Required.** Dev-facing English prose. Used for AI, OpenAPI, dashboards. |
| `entity` | class-string | Primary entity this route operates on |
| `purpose` | Purpose | What the route does (`List`, `Show`, `New`, `Edit`, `Delete`, `Export`, `Custom`) |
| `audience` | Audience | Who it's for (`Public`, `Authenticated`, `Admin`, `Api`, `Internal`) |
| `sitemap` | bool | Include in sitemap.xml (defaults to true for Public routes) |
| `changefreq` | string | sitemap `<changefreq>`: `always`\|`daily`\|`weekly`\|`monthly`\|… |
| `priority` | float | sitemap `<priority>`: 0.0–1.0 |
| `tags` | string[] | Free-form labels: `['admin', 'export', 'beta', …]` |
| `parents` | string[] | Route names for breadcrumb parents |

### `#[ControllerMeta]` — class level

Class-level defaults for `#[RouteMeta]`. Avoids repeating `entity:`, `audience:`, and `tags:` on every action.

```php
use Survos\FieldBundle\Attribute\ControllerMeta;

#[Route('/tenant/{tenantId}')]
#[ControllerMeta(entity: Tenant::class, audience: Audience::Authenticated)]
final class TenantController extends AbstractController
{
    #[Route('', name: 'tenant_show')]
    #[RouteMeta(description: 'Tenant detail page', purpose: Purpose::Show, audience: Audience::Public)]
    public function show(Tenant $tenant): array { ... }

    // Inherits entity: Tenant::class, audience: Audience::Authenticated from ControllerMeta
    #[Route('/edit', name: 'tenant_edit')]
    #[RouteMeta(description: 'Edit tenant settings', purpose: Purpose::Edit)]
    public function edit(Tenant $tenant, Request $request): array|RedirectResponse { ... }
}
```

`RouteMetaPass` merges class-level `ControllerMeta` defaults under each method's `#[RouteMeta]`. The method always wins for any field it sets explicitly; `ControllerMeta` fills the gaps.

---

## `FieldReader` — reading descriptors at runtime

`FieldReader` is the main service for consuming `#[Field]` metadata programmatically. Inject it anywhere:

```php
use Survos\FieldBundle\Service\FieldReader;
use Survos\FieldBundle\Model\FieldDescriptor;

class MyService
{
    public function __construct(private readonly FieldReader $fieldReader) {}

    public function buildSearchConfig(string $class): array
    {
        $descriptors = $this->fieldReader->getDescriptors($class);

        return [
            'searchable' => array_map(fn (FieldDescriptor $d) => $d->name,
                array_filter($descriptors, fn ($d) => $d->searchable)),
            'sortable'   => array_map(fn (FieldDescriptor $d) => $d->name,
                array_filter($descriptors, fn ($d) => $d->sortable)),
        ];
    }

    // Get a single property descriptor
    public function getLabel(string $class, string $property): string
    {
        $d = $this->fieldReader->getDescriptor($class, $property);
        return $d?->getFallbackLabel() ?? $property;
    }
}
```

### `FieldDescriptor` properties

| Property | Type | Source |
|---|---|---|
| `name` | string | Property/method name |
| `type` | string | PHP type (e.g. `'string'`, `'int'`, `'App\Enum\Status'`) |
| `transKey` | ?string | `#[Field(transKey:)]` or null |
| `description` | ?string | `#[With]`, `#[ApiProperty]`, or null |
| `example` | mixed | `#[With]`, `#[ApiProperty]`, or null |
| `searchable` | bool | `#[Field]` or `#[ApiFilter(SearchFilter)]` |
| `sortable` | bool | `#[Field]` or `#[ApiFilter(OrderFilter)]` |
| `filterable` | bool | `#[Field]` or `#[ApiFilter]` |
| `widget` | ?Widget | `#[Field(widget:)]` or inferred |
| `facet` | bool | `#[Field(facet:)]` |
| `visible` | bool | `#[Field(visible:)]` |
| `order` | int | `#[Field(order:)]` |
| `width` | ?string | `#[Field(width:)]` |
| `format` | ?string | `#[Field(format:)]` |
| `enum` | scalar[] | Backed enum cases, or `#[With(enum:)]` |
| `minimum` | int\|float | `#[With(minimum:)]` or `#[Range]` constraint |
| `maximum` | int\|float | `#[With(maximum:)]` or `#[Range]` constraint |
| `maxLength` | ?int | `#[Length(max:)]` constraint |
| `pattern` | ?string | `#[Regex(pattern:)]` constraint |
| `required` | bool | `#[NotBlank]` constraint |
| `isUrl` | bool | `#[Url]` constraint |
| `isEmail` | bool | `#[Email]` constraint |

Key methods:

```php
$d->getTranslationKey()     // transKey ?? name
$d->getTranslationDomain()  // 'fields' (always)
$d->getFallbackLabel()      // TitleCase of name, e.g. 'accountType' → 'Account Type'
$d->resolvedWidget()        // widget ?? inferred from type (null when not filterable)
$d->inputType()             // HTML input type: 'email'|'url'|'number'|'datetime-local'|'text'
```

### Progressive enhancement sources

`FieldReader` enriches descriptors when optional packages are present:

| Source | Package | What it adds |
|---|---|---|
| `#[Field]` | _(this bundle)_ | All display/search settings |
| Symfony validation | `symfony/validator` | `required`, `isUrl`, `isEmail`, `minimum`, `maximum`, `maxLength`, `pattern` |
| `#[With]` | `symfony/ai-platform` | `description`, `example`, `enum`, `minimum`, `maximum` |
| `#[ApiProperty]` | `api-platform/core` | `description`, `example` |
| `#[ApiFilter]` on class | `api-platform/core` | `searchable`, `sortable`, `filterable` (fallback when no `#[Field]`) |
| `#[MeiliIndex]` on class | `survos/meili-bundle` | `searchable`, `sortable`, `filterable` (synthesized fallback) |
| PHP reflection | _(always)_ | `type`, backed enum cases |

**Fallback synthesis** — properties with no `#[Field]` but referenced in `#[ApiFilter]` or `#[MeiliIndex]` get a synthesized descriptor so the grid still shows them correctly. Add `#[Field]` to take explicit control.

---

## Widget mapping across consumers

| `Widget` | ColumnControl (api-grid) | Meilisearch (meili-bundle) | UX-Search |
|---|---|---|---|
| `Text` | `search` input | searchable | SearchBox |
| `Select` | `searchList` dropdown | RefinementList | RefinementList |
| `Range` | Min/Max number inputs | RangeSlider | RangeSlider |
| `Date` | _(future)_ | NumericMenu | DateRangePicker |
| `Boolean` | `searchList` dropdown | Toggle | ToggleRefinement |

---

## Zero required dependencies

`#[Field]` and `Widget` have no external dependencies — just PHP 8.4. `FieldReader` enhances output progressively based on what packages are installed.

---

## Consumers

| Bundle | What it uses |
|---|---|
| `survos/api-grid-bundle` | `FieldReader::getDescriptors()` → column sortable/searchable/browsable/width/widget |
| `survos/grid-bundle` | DataTables column config |
| `survos/meili-bundle` | Meilisearch searchable/filterable/sortable/facet index settings |
| `survos/inspection-bundle` | Unified `FieldDescriptor` DTO for Twig templates and admin tooling |

---

## Further reading

- [`docs/CONTROLLERS.md`](docs/CONTROLLERS.md) — Survos controller naming convention: `XxxController` (entity) vs `XxxListController` (collection). Covers `#[RouteMeta]`, `#[ControllerMeta]`, `#[RouteIdentity]`, entity injection, and testing patterns.
