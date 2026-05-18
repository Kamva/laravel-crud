# Detail view (v2+)

By default, the `show()` action renders the create/edit form in read-only
mode. For a richer detail page with arbitrary sections, sidebar panels,
and a merged activity timeline, opt in via three methods:

## addDetailSection(name, renderer)

Adds a card to the main column of the show page.

```php
$this->addDetailSection('Overview', function ($lead) {
    return view('app.lead.overview', compact('lead'));
});

$this->addDetailSection('Stats', function ($lead) {
    return '<div>Total revenue: €' . e($lead->revenue) . '</div>';
});
```

The renderer's return value can be:

- A string (HTML — caller is responsible for escaping user data)
- A `Renderable` (Laravel `View`, etc.)

Sections are rendered in registration order. The section's name is used
as the card header by default.

## addDetailSidebar(name, renderer)

Same contract as `addDetailSection`, but rendered in the right-hand
column. Use for action panels (stage change form, follow-up date,
assignment, etc.):

```php
$this->addDetailSidebar('Change stage', function ($lead) {
    return view('app.lead._stage-form', compact('lead'));
});

$this->addDetailSidebar('Follow-up date', function ($lead) {
    return view('app.lead._followup-form', compact('lead'));
});
```

## setShowView(viewName)

Override the entire detail template (instead of using the stock
`kamva-crud::detail` view):

```php
$this->setShowView('app.lead.show');
```

The view receives:

| Variable      | Type                                                     |
|---------------|----------------------------------------------------------|
| `$title`      | string                                                   |
| `$model`      | the bound model instance                                 |
| `$sections`   | `array<string|int, string\|Renderable>`                  |
| `$sidebars`   | `array<string|int, string\|Renderable>`                  |
| `$timeline`   | `Collection<TimelineEvent>` or `null`                    |
| `$editRoute`  | string or null                                           |
| `$deleteRoute`| string or null                                           |

## Combining detail + timeline

Detail and timeline are independent. Use both for the full picture:

```php
public function setup()
{
    $this->setTitle('Lead');
    $this->setModel(Lead::class);

    // Form fields (still used by edit; show now renders the detail view)
    $this->addField(Text::class, 'Name', 'name');

    // Detail sections / sidebars
    $this->addDetailSection('Contact info', fn ($lead) => view('app.lead._contact', compact('lead')));
    $this->addDetailSidebar('Actions', fn ($lead) => view('app.lead._actions', compact('lead')));

    // Timeline sources (see docs/timeline.md)
    $this->addTimelineSource('stage', fn ($lead) => $lead->transitions->map(...));
    $this->addTimelineSource('audits', fn ($lead) => $lead->audits()->latest()->limit(50)->get()->map(...));
}
```

## Backwards compatibility

Detail view is **opt-in**. Until you call `addDetailSection`,
`addDetailSidebar`, `addTimelineSource`, or `setShowView`, the `show()`
action behaves exactly as before — rendering the create form in read-only
mode.

## Publishing + customising the stock view

The package ships a minimal `kamva-crud::detail` view that renders
sections (main column), sidebars (right column), and a basic timeline.
Publish it to customise:

```bash
php artisan vendor:publish --provider="Kamva\\Crud\\KamvaCRUDServiceProvider"
```

Then edit `resources/views/vendor/kamva-crud/detail.blade.php` to match
your app's visual design.
