# survos/field-bundle

Universal field/property metadata for Symfony — declare once, consume everywhere.

## The problem

Property metadata is currently scattered across several attributes with overlapping concerns:

| Attribute | Owner | Covers |
|---|---|---|
| `#[ApiProperty]` | api-platform | OpenAPI description, example |
| `#[With]` | symfony/ai | JSON Schema constraints for LLMs |
| `#[ORM\Column]` | doctrine | Storage type |
| `#[ApiFilter]` | api-platform | Server-side filter declaration |
| `#[Facet]` | survos/meili-bundle | Meilisearch facet UI config |

None of them answer: *how should this property be displayed and filtered in a grid, search panel, or UX-search widget?*

## The solution

```php
use Survos\FieldBundle\Attribute\Field;
use Survos\FieldBundle\Enum\Widget;

class Tenant
{
    #[Field(label: 'Name', searchable: true, sortable: true, order: 10)]
    public string $name = '';

    #[Field(label: 'Status', filterable: true, widget: Widget::Select, facet: true, order: 20)]
    public string $status = '';

    #[Field(label: 'Created', sortable: true, format: 'date', order: 30)]
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

## Consumers

| Bundle | What it does with `#[Field]` |
|---|---|
| `survos/grid-bundle` | DataTables column config (searchable, sortable, width) |
| `survos/api-grid-bundle` | ColumnControl content types (search, searchList, range) |
| `survos/meili-bundle` | Meilisearch searchable/filterable/sortable/facet settings |
| `survos/inspection-bundle` | Unified `FieldDescriptor` DTO for Twig templates |

## Widget mapping

| `Widget` | ColumnControl | Meilisearch | UX-Search |
|---|---|---|---|
| `Text` | `search` | searchable | SearchBox |
| `Select` | `searchList` | RefinementList | RefinementList |
| `Range` | `< >` controls | RangeSlider | RangeSlider |
| `Date` | date range | NumericMenu | DateRangePicker |
| `Boolean` | `searchList` | Toggle | ToggleRefinement |

Widget is optional — `FieldDescriptor::resolvedWidget()` infers it from the PHP type when omitted.

## Zero required dependencies

The `Field` attribute and `Widget` enum have no external dependencies — just PHP 8.4.

`FieldReader` progressively enhances its output when optional packages are present:
- `symfony/ai-platform` → reads `#[With]` (description, example, enum, min/max)
- `api-platform/core` → reads `#[ApiProperty]` (description, example)
- `doctrine/orm` → infers types from `#[ORM\Column]`

## Installation

```bash
composer require survos/field-bundle
```
