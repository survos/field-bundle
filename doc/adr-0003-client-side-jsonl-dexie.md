# ADR 0003 — Client-side JSONL via Dexie / IndexedDB

- **Status:** Idea / future — captured so it isn't lost. No implementation planned yet.
- **Date:** 2026-06-07
- **Scope:** cross-bundle, client-side. Reuses the `pk + indexes` contract from jsonl-bundle ADR 0001 and the `FieldStat` contract from field-bundle ADR 0002.
- **Continues:** ADR 0001 (SQLite sidecar = server-side index), ADR 0002 (shared summary/browser UI = server-side rendering).

## Context

[Dexie.js](https://dexie.org/) is a small, ergonomic wrapper over the browser's
**IndexedDB**, with elegant async access. Its store definition is almost exactly
our `jsonl:index` surface:

```js
db.version(1).stores({ products: 'id, category, brand' });
//                                 ^pk  ^---- indexes ----^
```

That is `--pk id --facet category,brand` in another runtime. The symmetry is the
point:

| | Server (ADR 0001/0002) | Client (this ADR) |
|---|---|---|
| Store | SQLite `<file>.db` sidecar | IndexedDB via Dexie |
| Primary key | `idx.pk` | Dexie `'id'` |
| Indexes / facets | `idx.attrs` + expression indexes | Dexie secondary indexes |
| Source of truth | the `.jsonl[.gz]` archive | the same `.jsonl[.gz]`, fetched |
| Access | `JsonlReader::get()` / facet SQL | `db.products.get()` / `.where()` |

Both are **derived indexes over the same canonical JSONL** — the file stays the
archive; each runtime builds its own fast read structure.

## Idea

A client-side loader that streams a `.jsonl[.gz]` into a Dexie store, using the
**same index definition** the server already knows.

1. **Schema from metadata, not by hand.** The profiler's `FieldStat` (ADR 0002) —
   specifically the facet/pk fields and cardinality — *generates* the Dexie
   `stores()` string. One declaration (`--pk` / `--facet`, or `#[Field(facet)]`)
   drives both the SQLite sidecar and the Dexie store. No drift between server and
   client index definitions.
2. **Stream + bulk-put.** Fetch the `.jsonl[.gz]` (gunzip in-browser if needed),
   parse line-by-line, `bulkPut` in batches into the Dexie table. Idempotent on
   `pk` (last-wins, matching the server's Bitcask semantics).
3. **Freshness.** Reuse the sidecar `meta` facts (`jsonlSize`/`jsonlMtime`, ADR 0001)
   as a version token; re-load only when the source changed — the client analog of
   the server's tail-scan rebuild.

## First use case: authority lists

Controlled vocabularies / authority lists (subjects, places, agents, term sets)
are the ideal first target:

- small and read-heavy — cheap to ship, big win from indexed local lookup;
- offline-friendly — typeahead / validation without a server round-trip;
- naturally `pk + a few facets` (id + label + type), exactly the contract;
- already produced as JSONL in our pipelines (e.g. term-set extraction).

So: publish an authority list as `<name>.jsonl` + its FieldStat-derived schema, load
it into Dexie once, and the browser gets instant `get(id)` / `where('type')` access.

## Why capture it now (and not build it)

- It validates the ADR 0001/0002 contract: if a single `pk + indexes` declaration
  can drive SQLite *and* Dexie, the contract is right.
- It's out of scope for the current server-side phases; recording it keeps the
  symmetry visible so we don't accidentally design something server-only that
  can't cross to the client.

## Open questions (for when this is picked up)

- Where the loader lives: a small JS package (AssetMapper-friendly) vs part of the
  ui bundle's assets.
- Schema generation: a CLI (`jsonl:dexie-schema <file>`) emitting the `stores()`
  string from `field_stats`, vs a build-time export.
- Large lists: IndexedDB quota + chunked load; when to prefer a server query instead.
- gzip in the browser (`DecompressionStream`) vs shipping plain `.jsonl`.

## References

- Dexie.js — https://dexie.org/
- jsonl-bundle ADR 0001 — `bu/jsonl-bundle/doc/adr-0001-sqlite-sidecar.md` (`pk + attrs` index)
- field-bundle ADR 0002 — `adr-0002-field-stats-contract-and-summary-ui.md` (`FieldStat`, server UI)
