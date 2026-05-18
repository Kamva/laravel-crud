# Kanban view (v2+)

Drop-in kanban variant for any model with a status-like attribute. Cards
are bucketed by an attribute value, dragged between columns to trigger
a transition.

## Enable

In `setup()`:

```php
use Kamva\Crud\Kanban\KanbanConfig;

$this->enableKanban(new KanbanConfig(
    attribute: 'stage',
    columns: [
        'new'         => ['label' => 'New',         'color' => 'secondary', 'accepts_drop' => true],
        'contacted'   => ['label' => 'Contacted',   'color' => 'info',      'accepts_drop' => true],
        'qualified'   => ['label' => 'Qualified',   'color' => 'primary'],
        'won'         => ['label' => 'Won',         'color' => 'success'],
        'lost'        => ['label' => 'Lost',        'color' => 'danger'],
    ],
    cardRenderer: fn ($lead) => [
        'title'    => $lead->name,
        'subtitle' => $lead->email,
        'value'    => $lead->value_estimate ? '€' . number_format($lead->value_estimate, 0) : null,
        'href'     => route('crud.lead.show', $lead),
    ],
    transitionRoute: 'crud.lead.transition',
));

// Also add a top action so users can switch between list and kanban
$this->addTopAction('Kanban view', 'crud.lead.index', [
    'params' => ['view' => 'kanban'],
    'icon'   => 'feather icon-columns',
]);
```

## KanbanConfig parameters

| Param              | Type     | Required | Notes                                                     |
|--------------------|----------|----------|-----------------------------------------------------------|
| `attribute`        | string   | yes      | Model attribute used to bucket cards (e.g. `'stage'`).    |
| `columns`          | array    | yes      | Key = attribute value, value = `{label, color, accepts_drop}`. Order preserved. |
| `cardRenderer`     | Closure  | yes      | `fn($model) => array` returning card data.                |
| `transitionRoute`  | string   | yes      | Named route to POST to on drag-drop. Should accept the model id + a column target. |
| `transitionParam`  | string   | no       | Form field name for the target column. Default: `'to_stage'`. |
| `emptyMessage`     | string   | no       | Shown when no cards visible. Default: 'No items.'         |

## Card data shape

Each `cardRenderer` invocation returns an array. Recommended keys:

| Key        | Meaning                                                |
|------------|--------------------------------------------------------|
| `id`       | Card id (auto-filled from `$model->getKey()` if absent) |
| `title`    | Main card text                                          |
| `subtitle` | Secondary line                                          |
| `body`     | Optional body text                                      |
| `value`    | Numeric value summed into the column total              |
| `href`     | URL — card becomes clickable                            |

Extra keys are ignored. Your published kanban view can render anything it
wants from this map.

## Backend transition handler

Register a route to handle drag-drop POSTs:

```php
// routes/web.php
Route::post('lead/{lead}/transition', [LeadController::class, 'transition'])
    ->name('crud.lead.transition');
```

Controller method (write your own — the package doesn't auto-generate it
because the transition logic is domain-specific):

```php
public function transition(Request $request, Lead $lead)
{
    $data = $request->validate([
        'to_stage' => ['required', 'string', Rule::in(['new', 'contacted', ...])],
    ]);

    // Whatever your domain rules dictate — typically a service:
    $applied = app(LeadStageService::class)->advance($lead, $data['to_stage']);

    return response()->json([
        'applied' => $applied,
        'stage'   => $lead->fresh()->stage,
    ]);
}
```

## Frontend: SortableJS wiring

The package's stock kanban view exposes two globals so apps can wire any
sortable library:

```js
window.KAMVA_KANBAN_TRANSITION_URL   // URL template with __ID__ placeholder
window.KAMVA_KANBAN_TRANSITION_PARAM // form field name (default 'to_stage')
```

Example with SortableJS:

```js
import Sortable from 'sortablejs';

document.querySelectorAll('.kamva-kanban-col-body').forEach((col) => {
    if (col.dataset.acceptsDrop === '0') return;

    new Sortable(col, {
        group: 'kamva-kanban',
        animation: 150,
        draggable: '.kamva-kanban-card',
        onAdd: async (evt) => {
            const card = evt.item;
            const id   = card.dataset.id;
            const to   = evt.to.dataset.stage;
            const url  = window.KAMVA_KANBAN_TRANSITION_URL.replace('__ID__', id);

            const resp = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ [window.KAMVA_KANBAN_TRANSITION_PARAM]: to }),
            });

            const data = await resp.json();
            if (!resp.ok || !data.applied) {
                // Revert the drag
                evt.from.appendChild(card);
                alert('Transition rejected.');
            }
        },
    });
});
```

Apps are responsible for including SortableJS (`npm install sortablejs` or
CDN) — the package doesn't bundle JS.

## Activating the kanban variant

The kanban renders when:

1. `enableKanban()` was called in `setup()`, AND
2. The request has `?view=kanban`

So one resource gets both list and kanban variants at the same URL:

- `/lead` — list (default)
- `/lead?view=kanban` — kanban

Use `addTopAction()` to switch between them in the UI.

## Backwards compatibility

The kanban is **opt-in**. Until you call `enableKanban()`, the kanban
view is never rendered and nothing about the list/show/edit flow changes.

## Customising the kanban view

Publish the view to override styling / layout:

```bash
php artisan vendor:publish --provider="Kamva\\Crud\\KamvaCRUDServiceProvider"
```

Then edit `resources/views/vendor/kamva-crud/kanban.blade.php`.
