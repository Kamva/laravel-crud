# kamva/laravel-crud

A declarative CRUD framework for Laravel admin panels. Define your models'
fields, columns, filters, and actions in a `setup()` method on a controller
and the package builds list, create, edit, show, and (now) kanban + detail
views for you.

## Status

Maintained but minimal-API; this repo holds the framework primitives. The
actual list/create/show/kanban templates are stubs in the package — apps
publish them via `php artisan vendor:publish --tag=kamva-crud-views` and
customise to fit their visual design.

## Install

```bash
composer require kamva/laravel-crud
```

The package ships with a `KamvaCRUDServiceProvider` registered via Laravel's
package discovery. To publish the view stubs:

```bash
php artisan vendor:publish --provider="Kamva\\Crud\\KamvaCRUDServiceProvider"
```

Note: `CRUDController` extends `App\Http\Controllers\Controller`, which is
the conventional base controller from Laravel application scaffolding.
Standard Laravel apps already have this class; the package assumes its
presence.

## Quick start

```php
use App\Models\Lead;
use App\KamvaCrud\Fields\Text;
use App\KamvaCrud\Fields\Select;
use App\KamvaCrud\Actions\ShowAction;
use App\KamvaCrud\Actions\EditAction;
use App\KamvaCrud\Actions\DestroyAction;
use Kamva\Crud\CRUDController;

class LeadController extends CRUDController
{
    public function setup()
    {
        $this->setTitle('Leads');
        $this->setModel(Lead::class);

        // Columns shown in the list view
        $this->addColumn('Name', 'name');
        $this->addColumn('Email', 'email');
        $this->addColumn('Status', fn ($lead) => $lead->status);

        // Filters shown above the list (each gets its own form widget)
        $this->addFilter('q', function ($req, $q) {
            $q->where('name', 'like', '%' . $req->get('q') . '%');
        }, makeField(Text::class, 'Search', 'q', request('q')));

        // Per-row actions
        $this->addAction(ShowAction::class, 'crud.lead.show');
        $this->addAction(EditAction::class, 'crud.lead.edit');
        $this->addAction(DestroyAction::class, 'crud.lead.destroy');

        // Form fields (used on create/edit forms)
        $this->addField(Text::class, 'Name', 'name')->setValidation(['required']);
        $this->addField(Text::class, 'Email', 'email')->setValidation(['email']);
        $this->addField(Select::class, 'Status', 'status')->setOptions([
            'open' => 'Open',
            'closed' => 'Closed',
        ]);
    }
}
```

Routes are registered the standard Laravel way:

```php
Route::resource('lead', LeadController::class);
```

## Features

| Feature              | Docs                                                  | New in v2 |
|----------------------|-------------------------------------------------------|-----------|
| Fields               | [docs/fields.md](docs/fields.md)                      |           |
| Columns + Renderers  | [docs/columns.md](docs/columns.md)                    | ✓ Renderers |
| Filters              | [docs/filters.md](docs/filters.md)                    | ✓ Hidden, Search |
| Actions              | [docs/actions.md](docs/actions.md)                    | ✓ Top actions |
| Detail view          | [docs/detail-view.md](docs/detail-view.md)            | ✓ |
| Timeline             | [docs/timeline.md](docs/timeline.md)                  | ✓ |
| Kanban view          | [docs/kanban.md](docs/kanban.md)                      | ✓ |
| Stats                | [docs/stats.md](docs/stats.md)                        | ✓ |
| Filtering on list    | See filters doc                                       |           |
| Import / export      | (existing — undocumented here yet)                    |           |

## What's new in v2 (this release)

The v2 release is **fully backwards compatible**. All additions are
additive; no existing methods change behaviour.

- `addHiddenFilter(input, callback)` — register a filter that applies when
  its query param is present but renders no UI widget.
- `addSearchField(columns[], input, field?)` — multi-column LIKE search
  with `%`/`_` escaping. Replaces hand-rolled `whereRaw('LOWER... LIKE...')`
  boilerplate.
- `addTopAction(caption, route, options)` — page-header buttons (not
  per-row). Useful for "Switch to kanban", "Export all", etc.
- `addDetailSection(name, renderer)` / `addDetailSidebar(name, renderer)`
  / `setShowView(view)` — opt-in into a section-and-sidebar detail page
  instead of the auto-rendered read-only edit form.
- `addTimelineSource(name, producer)` — merge multiple event sources
  (audit logs, status transitions, notes) into a sorted activity feed.
- `enableKanban(KanbanConfig)` — drop-in kanban view variant gated on
  `?view=kanban` in the request.
- `addStat(label, compute, options)` — register a summary stat for the
  list/kanban header strip.
- `Kamva\Crud\Columns\Renderers` — static factories for badge / link /
  boolean / date / truncate column types.
- `Field::readOnly()` / `Field::showWhen()` — service-managed and
  conditionally visible fields.
- `FilterContainer::render()` is null-safe (returns `''` when no field
  attached) and exposes `hasField()` / `getField()` so views can skip
  hidden filters cleanly.

## Tests

```bash
composer test
```

The suite uses orchestra/testbench and runs against PHP 8.1+. Tests are
located in `tests/`.

## Contributing

PRs welcome. Please include tests for any new public API. Backwards
compatibility is mandatory — this package is used by multiple internal
projects and a breaking change would create coordination overhead. If you
need to change existing behaviour, add a new method instead and deprecate
the old one with `@deprecated`.

## License

MIT.
