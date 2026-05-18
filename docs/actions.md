# Actions

Two flavours: **row actions** (per-row buttons in the list) and **top
actions** (page-header buttons, v2+).

## Row actions

Register with `$this->addAction($actionClass, $routeName, $accessControlMethod = null)`.

```php
use App\KamvaCrud\Actions\ShowAction;
use App\KamvaCrud\Actions\EditAction;
use App\KamvaCrud\Actions\DestroyAction;
use App\KamvaCrud\Actions\CustomAction;

$this->addAction(ShowAction::class, 'crud.lead.show');
$this->addAction(EditAction::class, 'crud.lead.edit');
$this->addAction(DestroyAction::class, 'crud.lead.destroy');

// Hide an action conditionally
$this->addAction(EditAction::class, 'crud.admin.edit', function ($data) {
    return ! $data->isGod();
});
```

### Custom row actions

`CustomAction` accepts a caption, HTTP method, and icon class:

```php
$this->addAction(
    new CustomAction('Send invite', 'POST', 'feather icon-mail'),
    'crud.user.send-invite'
);
```

The constructor signature varies — see your app-side `App\KamvaCrud\Actions\CustomAction`
for the exact arguments, which typically include access-control closures.

### Built-in row actions

- `ShowAction` — link to the show page
- `EditAction` — link to the edit form
- `DestroyAction` — POST/DELETE with confirmation
- `AccessAction` — manage permissions for an admin (project-specific)
- `ChangeStatusAction` — change a status attribute
- `CustomAction` — anything else

## Top actions (v2+)

For buttons that live in the page header rather than per-row, use
`addTopAction()`:

```php
$this->addTopAction('Switch to kanban', 'crud.lead.index', [
    'params' => ['view' => 'kanban'],
    'icon'   => 'feather icon-columns',
    'class'  => 'btn-sm btn-outline-secondary',
]);

$this->addTopAction('Reports', 'crud.lead.reports', [
    'icon' => 'feather icon-bar-chart',
]);

$this->addTopAction('Export', 'crud.lead.export', [
    'accessControlMethod' => fn () => auth()->user()->can('export-leads'),
]);
```

Options:

| Key                   | Type            | Default                 | Notes                                       |
|-----------------------|-----------------|-------------------------|---------------------------------------------|
| `params`              | array           | `[]`                    | Route params                                |
| `icon`                | string          | `''`                    | CSS class for an `<i>` element              |
| `class`               | string          | `btn-sm btn-secondary`  | CSS class for the `<a>`                     |
| `accessControlMethod` | Closure|null    | `null`                  | `fn(): bool` — false hides the action       |

The list view template receives the resolved top actions as `$topActions`,
an array of associative arrays with `caption`, `route`, `params`, `icon`,
`class`, and a `url` (pre-built via `route()`).

Render them in your published list view:

```blade
<div class="header-buttons mb-3">
    @foreach ($topActions as $a)
        <a href="{{ $a['url'] }}" class="btn {{ $a['class'] }}">
            @if ($a['icon']) <i class="{{ $a['icon'] }}"></i> @endif
            {{ $a['caption'] }}
        </a>
    @endforeach
</div>
```

## AJAX / JSON actions

There's no dedicated `JsonAction` class — define a custom route + handler
that returns `response()->json(...)` and wire a custom JS click handler to
fire the request. The kanban view's drag-drop is the worked example;
see [kanban.md](kanban.md).

For a generic "click button, POST to URL, refresh row" pattern, register
a `CustomAction` with `method='POST'` and add an app-side JS handler that
intercepts the form submission.
