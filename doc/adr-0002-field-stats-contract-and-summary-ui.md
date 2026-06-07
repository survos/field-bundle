# ADR 0002 — Shared `FieldStat`/`TableSummary` contract + cross-source summary UI

- **Status:** Proposed
- **Date:** 2026-06-07
- **Scope:** cross-bundle. Contract in **field-bundle**; providers in **jsonl-bundle** (`.jsonl.db`) and **folio-bundle** (`.folio`); UI in a **new neutral ui/display bundle**.
- **Numbering:** follows the cross-bundle design thread that began with jsonl-bundle **ADR 0001** (SQLite sidecar + SQL profiler). This is field-bundle's first ADR; the `0002` keeps the thread legible.
- **Decisions taken (owner):** contract → field-bundle; UI → new ui/display bundle; `FieldStat` shape → **core + extension slot**. Goal: **one browser/summary over either `.jsonl.db` or `.folio`.**

## Context

- ADR 0001 makes jsonl profiling emit per-field stats into a `field_stats` table in `<file>.db`.
- folio already stores the same kind of stats in `SchemaProperty.stats` (commented *"Profiler stats from jsonl-bundle"*), renders a schema/summary view (`templates/folio/schema.html.twig`), and rolls up dataset numbers via SQL (`FolioSummaryService`).
- Both `.jsonl.db` and `.folio` are **SQLite files of JSON rows**. We want **one** set of display tools — a summary view and a faceted data browser — that works over either.
- field-bundle already owns the **declared** field-metadata layer: `#[Field]`, `Model/FieldDescriptor` (assembled by `Service/FieldReader`), `Enum/Widget`. `FieldStat` is the **observed** counterpart of `FieldDescriptor`.
- Without a typed contract, the stats shape is an untyped JSON blob on both sides and **will drift** — especially because ADR 0001 deliberately reshapes the profile.

## Decision

Define a typed, source-agnostic contract in **field-bundle**, project both sources onto it via a provider interface, and build one UI in a neutral bundle.

### 1. Contract (field-bundle, `Survos\FieldBundle\Model`)

`FieldStat` — the **observed** sibling of `FieldDescriptor` (declared). **Core + extension slot:**

```
FieldStat {
  // core (shared by every source)
  string  path            // dotted, array indices collapsed: tags[], dim.w
  array   jsonTypes        // json_type histogram {"text":1900,"null":100}
  int     present, nonNull
  float   nullFraction
  int     distinct         // exact count, never a value list
  mixed   min, max
  ?int    lenMin, lenMax; ?float lenAvg
  array   topValues        // bounded [{value,count}] — feeds facet bars
  bool    isArray          // + element stats when true
  // extension
  array   extra            // source-specific: jsonl => {urlLike,imageLike,
                           //   naturalLanguageLike,localeGuess}; folio => {declaringClass,dtoType}
}

TableSummary {
  ?int    rowCount
  FieldStat[] fields
  ?string generatedAt
  array   source           // {path, kind: 'jsonl'|'folio', table}
  array   extra            // folio => {cores, coreCounts, dtoCounts}
}

interface SummaryProviderInterface {
  summarize(string $sqliteFile, ?string $table = null): TableSummary
}
```

**Declared ↔ observed loop.** `FieldStat` (observed) complements `FieldDescriptor` (declared). Provide a `FieldStat → FieldDescriptor` *suggestion* mapping — `facet` from low `distinct`, `enum` from `topValues`, `maxLength` from `lenMax`, `isUrl` from `extra.urlLike`, `Widget` from type. This formalizes what `code:entity --field` already does ad hoc. The mapping is object→object — a natural fit for **Symfony ObjectMapper** (`#[Map]`), keeping the projection declarative.

### 2. Providers (each in its own bundle; depend on the field-bundle contract, never on each other)

- **jsonl-bundle** `JsonlSummaryProvider`: reads `field_stats` (+ `idx`/`attrs` for facet counts, `_rows`/`meta` for rowcount) from `<file>.db`.
- **folio-bundle** `FolioSummaryProvider`: reads `SchemaProperty(.stats)`/`item`; the existing `FolioSummary` (cores/dtoCounts) becomes `TableSummary.extra`. Co-evolve `SchemaProperty.stats` to hydrate `FieldStat`.

### 3. UI (new neutral bundle — e.g. `survos/ui-bundle` or `survos/display-bundle`)

Depends on field-bundle (contract) + `tabler-bundle` + `simple-datatables-bundle`; consumed by **apps**, not by folio/jsonl.

- `<twig:DatasetSummary :summary>` — per-field cards: type histogram, distinct, null %, `topValues` as facet bars, min/max, heuristic badges (urlLike/imageLike/translatable). Generalizes folio's `schema.html.twig`.
- `<twig:DataBrowser :db :table>` — faceted, **server-paged** table over a SQLite source. Reads either `.jsonl.db` (`_rows` + `idx`/`attrs`) or `.folio` (`item`): paging via offset/`LIMIT`, facets via `idx.attrs` (jsonl) or expression-indexed `json_extract` (folio). Replaces the old "first X rows" hack with real paging + filtering.

One renderer over the contract; **all source differences are isolated to the providers**.

### 4. Why field-bundle owns the contract

It already owns declared field metadata (`#[Field]`/`FieldDescriptor`); observed stats are the *same vocabulary, measured*. Dependency direction stays honest — jsonl, folio, and the ui bundle already relate to field-bundle, so none gains a new cross-dependency, and no separate contracts package is needed.

## Consequences

**Good**
- One contract de-drifts folio + jsonl + `code:entity` as ADR 0001 reshapes the profile.
- One UI browses/summarizes both `.jsonl.db` and `.folio`.
- `FieldStat ↔ FieldDescriptor` closes the declared/observed loop and formalizes `code:entity --field`.
- The browser gains real paging + facets (offset index + `attrs`), retiring the "first X rows" hack.

**Costs**
- A new ui/display bundle to stand up.
- folio's `SchemaProperty.stats` must co-evolve to the typed shape.
- field-bundle gains a small runtime model (`FieldStat`/`TableSummary`) alongside its descriptors.

**Risks**
- Contract churn while ADR 0001's profile shape settles — keep `FieldStat` minimal-core + `extra` until stable.

### 5. Reference implementation (already exists, app-level)

The `jsonl-demo` app (formerly `sleekdb-demo`) ships a working, app-level version
of this UI in `App\Controller\JsonlController` + `templates/jsonl/{home,show}.html.twig`:
it lists every `var/**.jsonl[.gz]` with rows / distinct keys / facet fields, and a
detail page renders facet counts (from `idx.attrs`, no data scan) + a row sample.
It reads the sidecar through the bundle API (`SidecarDb`, `JsonlReader`,
`JsonlStateService`). When this ADR lands, that controller collapses into
`<twig:DatasetSummary>` + `<twig:DataBrowser>` over the shared contract — it is the
proof-of-concept the providers/components generalize.

### 6. Client-side counterpart (Dexie / IndexedDB)

The same `pk + indexes` contract has a browser-side runtime: see
[ADR 0003 — client-side JSONL via Dexie/IndexedDB](adr-0003-client-side-jsonl-dexie.md).
`FieldStat`/facet metadata can generate a Dexie `stores()` schema, so one index
definition drives both the server sidecar and the client store.

## Open / deferred

- Exact home/name of the UI bundle (`survos/ui-bundle` vs `survos/display-bundle`).
- Optional FTS in the browser (SQLite FTS5) — folio has `FolioFtsIndexer`; generalize later.
- Directory-level catalog (many tables) remains its own future ADR; `TableSummary` is single-table.
- Client-side access via Dexie/IndexedDB — ADR 0003.

## References

- jsonl-bundle ADR 0001 — `bu/jsonl-bundle/doc/adr-0001-sqlite-sidecar.md`
- folio-bundle — `Entity/SchemaProperty`, `Model/FolioSummary` + `Service/FolioSummaryService`, `Service/FolioViewBuilder`, `templates/folio/schema.html.twig`
- field-bundle — `Attribute/Field`, `Model/FieldDescriptor`, `Service/FieldReader`
- Symfony ObjectMapper (8.1 improvements) — candidate for the `FieldStat → FieldDescriptor` projection
