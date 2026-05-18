# Filters

Filters narrow the list view's underlying query. Register with one of
three methods, each with progressively more inference:

## addFilter — full control

`$this->addFilter($input, Closure $callback, ?FieldContract $field = null)`

```php
$this->addFilter(
    'date_from',
    fn ($req, $q) => $q->where('created_at', '>=', $req->get('date_from')),
    makeField(PersianDate::class, 'From', 'date_from', request('date_from'))
);
```

- `$input` — the request input name (query-string param or form field)
- `$callback` — `fn(Request $req, Builder $query): void`
- `$field` — optional `FieldContract` that renders the filter widget;
  omit to register a **hidden** filter (applied to the query but no UI)

The callback only runs when the input is present (`!empty($request->get($input))`).

## addHiddenFilter (v2+) — no UI

For filters driven by something other than the filter form (deep links,
dashboard widget URLs, toggles elsewhere in the UI):

```php
$this->addHiddenFilter('overdue', function ($req, $q) {
    $q->whereNotNull('next_contact_at')
      ->where('next_contact_at', '<', now());
});
```

Then a dashboard widget can link to `/leads?overdue=1` and the filter
fires without any filter widget needing to be visible on the page.

`addHiddenFilter` is a convenience wrapper around `addFilter($input, $cb)`
(no field arg). The same effect is achieved by calling `addFilter` with no
field.

## addSearchField (v2+) — multi-column LIKE search

The "search by name OR email OR phone" pattern, packaged:

```php
$this->addSearchField(['name', 'email', 'phone'], 'q');
```

Generates a callback that:

1. Lowercases the input
2. Escapes `%`, `_`, `\` (so user input can't act as wildcards)
3. Builds an OR-joined `LOWER(col) LIKE ?` clause across all columns

Optional third arg adds a search field widget (otherwise hidden):

```php
$this->addSearchField(
    ['name', 'email', 'phone'],
    'q',
    makeField(Text::class, 'Search', 'q', request('q'))
);
```

## addFieldFilter — reuse an already-registered form field

If you already have a field defined for the create/edit form and want to
also use its value as a filter:

```php
$this->addFieldFilter('status', function ($req, $q) {
    $q->where('status', $req->get('status'));
}, fieldName: 'status');
```

The third arg is the **field name** (not the input name) — it looks up
the matching field from the form and uses it as the filter's render
widget.

## Hidden filters in views (v2+)

`FilterContainer` now exposes `hasField(): bool` and `getField(): ?FieldContract`.
The list view template iterates `$filters` to render the filter form — to
skip hidden filters cleanly:

```blade
@foreach ($filters as $filter)
    @if ($filter->hasField())
        <div class="col-md-3">{!! $filter->render() !!}</div>
    @endif
@endforeach
```

The package's `CRUDController::index()` already pre-filters out hidden
filters before passing `$filters` to the view, so app templates don't
strictly need the `hasField()` guard — but it's defensive and harmless.
