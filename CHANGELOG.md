# Changelog

All notable changes to `kamva/laravel-crud` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[1.0.0]: https://github.com/Kamva/laravel-crud/releases/tag/v1.0.0
