# Actions

Two flavours: **row actions** (per-row buttons in the list) and **top
actions** (page-header buttons).

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

### Access control

Two checks can decide whether a row shows an action:

- the **default ACL method**, set once for the app with
  `KamvaCrud::setDefaultACLMethod(fn ($route, $user, $row) => bool)`, usually a
  permission check on the route;
- the action's **own closure** (`addAction(..., $accessControlMethod)`,
  `fn ($row) => bool`), usually a row condition.

By default an action's own closure **replaces** the default method: an action
with a closure skips the permission check. To have the closure **narrow** the
permission check instead, so both must allow the action, switch the mode once,
next to `setDefaultACLMethod()`:

```php
KamvaCrud::setDefaultACLMethod(fn ($route, $user, $row) => $user->can($route));
KamvaCrud::setActionAclMode('and');   // default: 'replace'

// Shown only to users who may use posts.edit, and only for unlocked rows.
$this->addAction(EditAction::class, 'posts.edit', fn ($row) => ! $row->locked);
```

Either way this only decides which buttons are shown. The route itself must
still be protected (middleware, policies).

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

### Customising the row-actions markup

The actions cell is rendered by the `kamva-crud::actions` view. The first three
permitted actions are passed as `$inlineActions` and the rest as
`$groupedActions` (shown in a dropdown); `$row` is the record. To change the
markup, publish the package views and edit
`resources/views/vendor/kamva-crud/actions.blade.php`. Values are output
unescaped, and whitespace between elements shows up as gaps between the
inline buttons.

## Top actions

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
