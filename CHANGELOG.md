# Changelog

All notable changes to `kamva/laravel-crud` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **List search and sorting work on Postgres and MySQL.** Searching the list
  table, or sorting it by a Closure column, queried a column named `_id`
  (a MongoDB leftover), and relation columns such as `'owner.name'` queried a
  column named after the relation. Both are errors on Postgres and MySQL.
  Search now skips columns with no database column of their own, and sorting
  by one orders by the model's primary key (`_id` on MongoDB, as before).
- **List search ignores case on Postgres**, as it already did on MySQL and
  SQLite (`ILIKE` instead of `LIKE`).
- **`addSearchField()` works on non-text columns on Postgres.** It used
  `LOWER(column)`, which Postgres only defines for text; the column is now
  cast to text first. Other databases get the same SQL as before.

### Internal

- CI: a GitHub Actions workflow runs the tests on SQLite and Postgres 16.

## [2.1.0] - 2026-09-24

No breaking changes and nothing to change in your app. Shipped in #27. See
[docs/performance.md](docs/performance.md) for details and measurements.

### Performance

- **Relation columns are eager-loaded.** Dotted columns such as
  `'category.title'` used to run one query per row in the list JSON, the API
  index and the export (5,000 queries for a 5,000-row export). The relation
  is now fetched for the whole page in one query (per 1,000 rows), and each
  row ends up exactly as lazy loading would have left it: the relation is
  attached when its column is evaluated, and every row gets its own
  instance, hydrated like a lazy load (`retrieved` events included). It is
  only used where the result is guaranteed identical: Laravel's own to-one
  relations whose definition doesn't depend on the row, whose query eager
  loading reproduces (no limit, joins, `orWhere`, raw wheres, nested eager
  loads, `afterQuery()` or `chaperone()`), with keys PHP and the database
  compare the same way (no case-insensitive or type-coerced matches), and not
  for custom column types. Anything else, including rows without a match on
  a relation with `withDefault()` and rows whose key an earlier column
  changed, keeps loading lazily.
- **Row-action URLs** are built from a per-action template for plain values
  (ids, UUIDs, simple slugs) instead of calling `route()` for every action on
  every row. Other values, custom URL generators and URL formatting callbacks still
  use `route()`.
- **Action `render` lookups** (`view()->exists()`) run once per request
  instead of once per row, which previously probed the filesystem each time
  for icon HTML strings.
- **`'field.field'` columns** find their form field through an index.
- **Export** no longer runs an unused `COUNT(*)` query.

Benchmark medians (5,000 rows, SQLite, opcache): list page 32.4 → 15.8 ms,
API index 13.4 → 8.2 ms, export 557 → 315 ms. Queries per list page 103 → 4,
per export 5,002 → 6.

### Added

- `KamvaCrud::hasColumnType($name)`.
- Benchmark suite (`composer bench`, `tests/Performance`). It fingerprints
  each scenario's output and can fail a run whose output differs from a
  baseline.

## [2.0.0] - 2026-09-23

Major version because PHP 8.1 is now required (see **Breaking changes**).
Otherwise public method signatures and the markup and JSON the list view
receives are unchanged, except for the fixes below. Changes shipped in #24
(code) and #25 (documentation).

### ⚠️ Breaking changes

- **PHP 8.1 is now the minimum.** `composer.json` previously allowed PHP 7.4,
  but the Kanban and Timeline classes (`readonly` promoted properties) and
  `Columns\Renderers` (`catch` without a variable) already failed to parse on
  anything older, so the constraint now says what the code needs. Apps on
  PHP 7.4/8.0 that never loaded those classes will no longer be able to
  install this version.

### Added

- `Form::getField($name)` returns a field's `FieldContainer` by name, or
  `null`.
- The row-actions cell is now rendered by the publishable
  `kamva-crud::actions` view instead of HTML strings inside
  `CRUDController::getActionFieldForRow()`. The default view produces exactly
  the same markup. Apps that already published the views fall back to the
  package copy until they publish again.

### Fixed

- **Excel export header row.** When no `addExportEntity()` was registered, the
  first row of the `.xlsx` export was empty instead of holding the list column
  titles. The header row now always matches the exported columns.
- **Excel export with duplicate column titles.** Two columns with the same
  title collapsed into one value per row, which shifted every later value
  under the wrong header. Each column now keeps its own cell.
- **`addFieldFilter()` threw a `TypeError`** on every call (it passed a
  `FieldContainer` where a `FieldContract` was expected). It now attaches a
  copy of the named form field, renamed to the filter input so the widget
  submits the value `applyFilters()` reads.
- **Invalid DataTables sort direction.** An `order[0][dir]` other than
  `asc`/`desc` made `orderBy()` throw (HTTP 500). It now falls back to `desc`.

### Documentation

- README: the list view example now loads rows the way the controller serves
  them (server-side DataTables JSON); the old example looped over variables
  the controller never passed. Added the list JSON format, the API record
  shape, the export's header and row rules, the full list of view variables,
  and an "Upgrading & security" section.
- README: PHP requirement corrected to 8.1.
- `docs/filters.md`: how `addFieldFilter()` builds its widget.
- `docs/actions.md`: customising the row-actions markup.

### Internal

- Row serialization for the API, the export and the list view goes through
  one `Kamva\Crud\Columns\ColumnSet` class.
- The DataTables search/order/paging/count code moved from `CRUDController`
  into `Kamva\Crud\Listing\DataTablesLoader`. A subclass override of
  `getActionFieldForRow()` is still used for the actions cell.
- The three copies of the field-by-name lookup (`ProcessController`,
  `ColumnContainer`, `addFieldFilter()`) now use `Form::getField()`.

## [1.0.0] - 2026-05-29

First tagged release. Establishes the SemVer baseline for the package. This
release bundles a security/quality hardening pass; the items under **Breaking
changes** alter behaviour relative to the previously untagged `dev-main`, so
review them before upgrading.

### ⚠️ Breaking changes

- **Observe endpoint now requires CSRF (#12).** The `kc-process/observe` route
  is now registered inside the `web` middleware group, so it requires a valid
  CSRF token and a session.
  - The bundled `kamva-crud::observe` view was updated to send `_token`, so
    consumers using the shipped observe client are covered after upgrading.
  - **Consumers with a custom observe client must add the CSRF token (`_token`)
    to the POST body**, otherwise requests fail with HTTP `419`.
  - Pages still served with the **old cached observe JavaScript** will fail
    until reloaded.
  - **API-only apps** that don't configure sessions / the `web` group must
    ensure that group is available (it pulls in `StartSession`).

- **`update()` and `destroy()` now honour `setQuery()` scope (#9).** Both
  actions resolve the target model through the controller's `setQuery()` scope,
  matching `index()`/`show()`/`edit()`. A record outside the scope can no longer
  be updated or deleted by guessing its ID — the action returns `404`. This
  closes an IDOR / broken-access-control issue. Audit any controller that used
  `setQuery()` for list filtering but relied on mutations reaching out-of-scope
  records.

### Added

- `disableRowCounter()` and `hideCreateButton()` on `CRUDController` (#20). Both
  default to enabled (no behaviour change). The list view now receives
  `$rowCounter` and `$createButton` booleans; published list templates should
  render the counter column / create button conditionally on these. The JSON
  loader omits the counter cell and shifts its column-ordering offset
  accordingly when the counter is disabled.
- Publishable config file `config/kamva-crud.php` with a `paginate_size` key
  (#10), publishable via the `kamva-crud-config` tag.

### Fixed

- **Pagination size is read from config, not `env()` at runtime (#10).** Reading
  `env('CRUD_PAGINATE_SIZE')` at runtime returned `null` under
  `php artisan config:cache`, producing `paginate(0)` and a division-by-zero in
  production. The value now comes from `config('kamva-crud.paginate_size', 15)`
  with a non-positive fallback. The `CRUD_PAGINATE_SIZE` env var is still
  honoured.
- **Per-controller option-source cache (#11).** The option-source cache key is
  now scoped by the active controller class, so two controllers exposing a
  field with the same name no longer share one cached option list. Empty option
  lists are now cached instead of re-queried on every call. (The service stays a
  singleton so global registries — `addColumnType()`, `addExtension()`,
  `setDefaultACLMethod()` — survive the worker lifetime under Octane/Swoole.)
- **`observe` endpoint no longer creates records (#12).** The observe path
  resolves the model with a read-only scoped lookup, so a missing model on a
  `singleType` controller no longer triggers a blank-row insert.
- **Column identifiers wrapped in `addSearchField()` (#14).** The raw `LIKE`
  clause now wraps each column through the query grammar (resolved from the
  connection, compatible with Laravel 6+), neutralising identifier injection if
  a non-trusted column name is ever passed. Values remain parameter-bound.
- **No duplicate model events on update (#15).** `update()` no longer issues a
  redundant second `save()`; `saveToModel()` owns persistence and re-saves after
  skipped-field callbacks only when the model is dirty.
- **`isApi()` path match anchored (#16).** Matches the `api` segment or an
  `api/...` prefix instead of any path beginning with the letters `api` (e.g.
  `/apiary`, `/api-docs` are no longer treated as API requests).
- **`BaseAction::getAction()` null-safety (#17).** A custom action subclass that
  omits `$render`/`$method`/`$parameters`/`$options` no longer triggers a
  `TypeError`.
- **DataTables order column index hardened (#18, #20).** The untrusted order
  column index is cast and bound-checked (accounting for the row-counter
  offset), falling back to the default ordering instead of raising an
  undefined-index notice.
- **Imports are transactional per chunk (#19).** Each import chunk runs inside a
  transaction opened on the model's own connection, so a row that fails
  part-way rolls back the chunk instead of leaving it half-imported.

### Performance

- **DataTables record counts use an aggregate query (#13).** `recordsTotal` /
  `recordsFiltered` are computed via `getCountForPagination()` instead of
  hydrating every matching row into models on each draw — preserving
  `distinct()` / `groupBy()` semantics without the memory/latency cost.

[Unreleased]: https://github.com/Kamva/laravel-crud/compare/v2.1.0...HEAD
[2.1.0]: https://github.com/Kamva/laravel-crud/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/Kamva/laravel-crud/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/Kamva/laravel-crud/releases/tag/v1.0.0
