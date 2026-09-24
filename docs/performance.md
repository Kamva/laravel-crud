# Performance

The list JSON, API index and Excel export run their column and action code
once per row, so per-row costs add up quickly. This page covers what the
package does about that, and how to measure it.

Nothing here needs configuration or code changes in your app.

## What the package optimises

### Relation columns are eager-loaded

A dotted column such as `addColumn('Category', 'category.title')` reads a
relation for every row. With lazy loading that is one query per row: 100
queries for a list page, 5,000 for a 5,000-row export.

The list JSON, API index and export now load those relations for the whole
page in one query per relation. A relation is only preloaded when the result is
guaranteed to be the same as lazy loading:

- it is a to-one relation (`belongsTo`, `hasOne`, `morphOne`), reached through
  the relation method rather than an attribute, cast or accessor with the same
  name;
- its definition does not depend on the row. A relation such as
  `->where('currency', $this->currency)`, or one that picks a different model
  depending on a column, keeps loading lazily;
- every row has at most one matching record. With duplicate matches (e.g. a
  `hasOne` that actually has several rows) lazy loading's "first match" is kept;
- the column is not handled by a custom column type
  (`KamvaCrud::addColumnType()`), which decides for itself what it loads.

Rows that have no related record fall back to lazy loading when the relation
has a `withDefault()`, because the default may be built from the row itself.

Each row still gets its own instance of the related model. The one observable
difference: `retrieved` model events and observers on the related model fire
once per distinct record, instead of once per row.

Relations read inside Closure columns are not detected. If a Closure column
reads `$row->author->name`, eager-load the relation yourself by overriding
`getModel()` in your controller:

```php
public function getModel($assignQuery = true)
{
    return parent::getModel($assignQuery)->with('author');
}
```

### Row-action URLs

Every action on every row builds a URL with `route()`. For plain alphanumeric
parameter values (ids, simple slugs) the URL is now generated once per action
and the row value is substituted into it. Other values, a custom
`UrlGenerator`, or `URL::formatPathUsing()`/`formatHostUsing()` callbacks fall
back to `route()`. The fast path is also checked against a real `route()` call
before it is used.

### Other

- An action's `render` string is looked up with `view()->exists()` once per
  request instead of once per row. (Missed lookups, i.e. the usual icon HTML,
  were not cached by Laravel and probed the filesystem every time.)
- `'field_name.field'` columns find their form field through an index instead
  of scanning the form for every cell.
- The export no longer runs a `COUNT(*)` query it never used.

## Measuring

The repository has a benchmark suite in `tests/Performance`, separate from the
unit tests:

```bash
composer bench
```

It seeds 5,000 rows in SQLite and times full requests for five scenarios: a
list page (100 rows), a list search, the API index, the export, and the edit
form. It prints the median, min and p90 time, the query count and peak memory,
and writes them to `build/benchmarks/latest.json`. It runs with opcache
enabled, as production does.

Each scenario also records a fingerprint of its output. To check that a change
is faster **and** returns exactly the same output, record a baseline on the
base branch first, then compare:

```bash
git checkout main
BENCH_SAVE=build/benchmarks/before.json composer bench

git checkout my-branch
BENCH_BASELINE=build/benchmarks/before.json composer bench
```

The second run prints the change per scenario and fails if any scenario's
output differs from the baseline. `BENCH_ITERATIONS` sets the number of
measured runs (default 15). Timings vary by ±15% between runs on a busy
machine; query counts are exact.

## Results

Measured on the benchmark suite (PHP 8.4, Laravel 11, SQLite in memory,
opcache on, 30 iterations, median), `2.0.0` against these changes. Timings
vary by about ±15% between runs; query counts are exact.

| Scenario | Before | After | Queries before → after |
|---|---|---|---|
| List page, 100 rows | 42.7 ms | 14.4 ms (−66%) | 103 → 4 |
| List search, 25 rows | 13.9 ms | 8.9 ms (−36%) | 28 → 4 |
| API index, 100 rows | 12.8 ms | 8.7 ms (−32%) | 102 → 3 |
| Export, 5,000 rows | 516 ms | 266 ms (−48%) | 5,002 → 2 |
| Edit form | 1.24 ms | 0.65 ms (−48%) | 2 → 2 |

Peak memory for the export dropped from 19 MB to 14 MB.

SQLite in memory makes a query almost free. Against a MySQL or PostgreSQL
server, where each query costs a network round trip, removing the per-row
queries saves much more: at 0.5 ms per query, the 5,000-row export saves about
2.5 seconds.
