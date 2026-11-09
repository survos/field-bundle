# Symfony 8.2 forms handoff

Date: 2026-11-09

## Decisions and completed changes

The user approved making field-bundle Symfony 8.2-only and prefers native Symfony
attributes wherever they cover the behavior. All direct Symfony constraints in
field-bundle's composer.json, including dev requirements and the Flex hint, now
require ^8.2. PHP remains ^8.5.

The README documents native AsFormType/FormField usage, DTO service discovery,
readonly form results, prerelease installation requirements, and the division of
responsibility. Field's docblock now explicitly distinguishes FormField.

Field remains the search/grid attribute (facets, sorting, display order, etc.). No
runtime form adapter, attribute replacement, or richer FieldReader type extraction
has been implemented. FieldReader still reduces native types to strings;
DtoHydrator still creates an empty object and assigns values through PropertyAccessor.

Serializer, PropertyInfo and PHPDoc parsers are now explicit development dependencies.
PhpDocRoundTripTest no longer silently skips when those dependencies are absent.
It checks actual EntityViewDescriptor reconstruction and mapper key preservation.

## Validation and limitations

- Composer validation passed for field-bundle.
- All 22 field-bundle tests passed, with 87 assertions.
- Tests used the monorepo's existing Symfony 8.1 dependencies, not an 8.2 install.
- PHPUnit reported only the deprecated --do-not-cache-result runner flag; use
  --do-not-record-test-run-history on subsequent runs.
- No monorepo lockfile update, application upgrade, release, or push was performed.
- Before stable 8.2, the consuming root project must explicitly permit prereleases;
  a dependency's stability settings do not opt the root project in.

## Recommended pilot

Use the existing local EasyAdmin demo at /Users/tac/tacman/easyadmin-demo.
Its origin is https://github.com/EasyCorp/easyadmin-demo.git. Its
vendor/survos/ez-bundle is already linked to /Users/tac/sites/mono/bu/ez-bundle.
It contains the recent EZ menu work: recent-page history, locale integration,
category page navigation and a UI Kit action in src/EventListener/EzDemoMenu.php.

Suggested first feature: a Publication settings custom admin action backed by a
readonly DTO with Symfony AsFormType, FormField and Validator attributes. The
existing src/Controller/Admin/PostCrudController.php has publish/unpublish/archive
actions; Post already has status and publishedAt. Define desired publication
semantics explicitly; a future timestamp alone must not imply a scheduler exists.
Render the form within the EZ admin layout and obtain the result from form->getData().

This is a proposal, not implemented work. The demo currently restricts Symfony via
extra.symfony.require = 8.1.* and needs an explicit 8.2 upgrade. It already contains
uncommitted work in Composer files, controllers, bundle config, tests, translations,
and its SQLite database, plus new menu files. Preserve those changes. This session
only inspected the demo.

## EZ integration boundaries

EzField contains form-adjacent label/help/type metadata, but also admin-specific
visibility, ordering, filtering and linking. AbstractEzCrudController reads EzService
metadata and constructs EasyAdmin fields using Doctrine types. Adding FormField
alone will not cause that CRUD pipeline to consume Symfony's attribute-based forms.

Start with the DTO custom action, verify native type guessing, nullable dates,
validation and readonly reconstruction, then identify a concrete reusable EZ adapter
if needed. Keep Symfony responsible for form creation/submission; keep Survos-only
search and admin metadata. No changes to ez-bundle were made in this session.

Continue on main; do not create a branch without the user's confirmation. Read the
target repository's instructions before implementation. Persistent bundle changes
belong in mono, not vendor; retain the existing monolink workflow.

## Sources

- https://symfony.com/blog/new-in-symfony-8-2-forms-that-read-your-types
- https://symfony.com/blog/new-in-symfony-8-2-attribute-based-forms
- https://symfony.com/doc/current/serializer.html#handling-arrays
