# Columns

Columns control what appears in the list view. Register with
`$this->addColumn($title, $value)`.

The `$value` arg is either:

- A string attribute name: `'name'`, `'created_at'`
- A dotted relation path: `'user.email'`
- A `Closure(model, $raw)` returning the displayed value (HTML allowed)

```php
$this->addColumn('Name', 'name');
$this->addColumn('Created', 'created_at');
$this->addColumn('Account type', fn ($org) => $org->personal ? 'Personal' : 'Business');
$this->addColumn('Status', fn ($lead) => $lead->status->label());
```

## Column renderers (v2+)

For common rendering patterns (badges, links, booleans, dates) use the
`Kamva\Crud\Columns\Renderers` helpers. They return Closures that drop
into `addColumn()` as the `$value` arg:

```php
use Kamva\Crud\Columns\Renderers as R;

// Badge with a fixed color
$this->addColumn('Status', R::badge('status', ['color' => 'success']));

// Badge with a color that depends on the row
$this->addColumn('Priority', R::badge('priority', [
    'colorBy' => fn ($row) => match ($row->priority) {
        'high'   => 'danger',
        'medium' => 'warning',
        default  => 'secondary',
    },
]));

// Clickable badge that doubles as a link
$this->addColumn('Stage', R::link(
    fn ($lead) => $lead->stage->label(),
    fn ($lead) => route('crud.lead.show', $lead),
    ['class' => 'badge badge-light']
));

// Boolean as check / dash
$this->addColumn('Active', R::boolean('is_active'));

// Truncated long text
$this->addColumn('Bio', R::truncate('bio', 80));

// Formatted date (Carbon or string)
$this->addColumn('Created', R::date('created_at', 'Y-m-d H:i'));
```

All renderers escape user-supplied data using Laravel's `e()`. HTML
attributes (class, href, title) are also escaped, so it's safe to pass
user input through them.

## Available renderers

| Helper      | Output                                                           |
|-------------|------------------------------------------------------------------|
| `badge`     | `<span class="badge badge-...">label</span>`                     |
| `link`      | `<a href="..." class="..." target="...">label</a>`               |
| `boolean`   | `✓` / `—` (customise with `['yes' => '...', 'no' => '...']`)     |
| `truncate`  | Plain text shortened to N chars with ellipsis                    |
| `date`      | Formatted date (handles Carbon, DateTimeInterface, ISO strings)  |

All return `Closure(row) => string`, so you can compose them or replace
them with hand-rolled closures any time.

## Custom column rendering

For anything beyond the helpers, write a Closure directly. DataTables in
the list view renders the returned HTML as-is (it doesn't escape) — so
make sure you escape user data yourself with `e()`:

```php
$this->addColumn('Avatar', function ($user) {
    $url = $user->avatar_url ?? '/img/default.png';
    return '<img src="' . e($url) . '" width="32" height="32" class="rounded">';
});
```

## DB column for sorting / searching

The list view supports sorting and searching by clicking column headers.
By default the framework guesses the underlying DB column from the
`$value` string. For Closure columns it falls back to `_id`. If you need
a specific DB column to back a Closure column (so sorting works), the
current API doesn't expose that — file an issue or use a string `$value`.
