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

## Completed EasyAdmin pilot

The demo at `/Users/tac/tacman/easyadmin-demo` now has a `demo` branch, upgraded to
Symfony 8.2.0-BETA1, Stimulus 3.7 and Turbo 3.7. Its existing EZ menu integration
was committed as `212c0e4`; EZ itself was committed as `96f30acc7`.

`src/Dto/PublicationSettings.php` uses native AsFormType/FormField and Validator
attributes. A custom Post CRUD action renders it in the existing EZ admin layout.
Symfony infers the nullable immutable date and boolean checkbox, accepts an empty
date as null, and reconstructs a new readonly DTO on submission. The controller
reads `form->getData()` and explicitly applies the three values to the entity.
The enum uses EnumType explicitly for its class and translated choice labels.
No custom form type, data mapper, hydrator, or EZ form adapter was necessary.

Publication status takes effect immediately. A published post requires a date;
future publication dates are rejected. Scheduled status remains metadata and does
not create a scheduler or alter the existing scheduledAt field.

The action uses `{entityId}` with `#[MapEntity(id: 'entityId')]`: mapped route syntax
`{entityId:post.id}` made EasyAdmin's locale URL generation lose the identifier.
The functional test verifies both header locale links and successful persistence.

Tests cover inferred types, null handling, readonly reconstruction, invalid enum and
date input, future dates, required publication dates, CSRF rejection and persistence.
The existing demo tests also run under 8.2 beta. Test writes restore the original
post settings. Local database changes and generated config references are not source
changes for this pilot.

## EZ integration boundaries

This pilot validates a custom DTO action, not automatic CRUD field generation.
EZ still reads EzField metadata and Doctrine types; FieldReader still supplies
search/grid metadata. Adding FormField to an entity does not change that pipeline.
No runtime changes to ez-bundle or field-bundle were needed for the pilot.

Continue on main for mono and demo for this app. Persistent bundle changes belong
in mono, not vendor; retain the existing monolink workflow.

## Sources

- https://symfony.com/blog/new-in-symfony-8-2-forms-that-read-your-types
- https://symfony.com/blog/new-in-symfony-8-2-attribute-based-forms
- https://symfony.com/doc/current/serializer.html#handling-arrays
