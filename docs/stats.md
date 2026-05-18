# Stats (v2+)

Summary statistic cards rendered above the list / kanban view. Use for
quick "X open leads", "Y total revenue" indicators that link to filtered
views.

## Register

```php
$this->addStat('Open', fn () => Lead::where('stage', 'open')->count());

$this->addStat('Avg deal value', fn () => '€' . round(Lead::avg('value_estimate')), [
    'icon'  => 'feather icon-trending-up',
    'color' => 'success',
]);

$this->addStat('Overdue follow-ups', fn () => Lead::whereNotNull('next_contact_at')
    ->where('next_contact_at', '<', now())->count(), [
    'icon'  => 'feather icon-clock',
    'color' => 'warning',
    'link'  => fn () => route('crud.lead.index', ['overdue' => 1]),
]);
```

## Options

| Key     | Type            | Default     | Notes                                          |
|---------|-----------------|-------------|------------------------------------------------|
| `icon`  | string          | `''`        | CSS class for an icon (`feather icon-...`)     |
| `color` | string          | `primary`   | Bootstrap-style: primary, success, warning, …  |
| `link`  | Closure or null | `null`      | `fn(): string` — clickable href                |

## Laziness

The `compute` closure is called by the view only when stats are actually
rendered. Heavy queries don't slow down pages that don't show stats.

`getStats()` re-evaluates on every call — it's the view's responsibility
to call it once per request.

## Rendering in views

The list and kanban templates receive `$stats` as an array of:

```php
[
    [
        'label' => 'Open',
        'value' => 42,
        'icon'  => '',
        'color' => 'primary',
        'link'  => null,
    ],
    // ...
]
```

A typical card render:

```blade
@if (!empty($stats))
    <div class="row mb-3">
        @foreach ($stats as $stat)
            <div class="col-md-3">
                @if ($stat['link'])<a href="{{ $stat['link'] }}" class="text-decoration-none">@endif
                <div class="card border-{{ $stat['color'] }}">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h3 class="mb-0">{{ $stat['value'] }}</h3>
                                <small class="text-muted">{{ $stat['label'] }}</small>
                            </div>
                            @if ($stat['icon'])
                                <i class="{{ $stat['icon'] }} text-{{ $stat['color'] }} font-large-1"></i>
                            @endif
                        </div>
                    </div>
                </div>
                @if ($stat['link'])</a>@endif
            </div>
        @endforeach
    </div>
@endif
```

## When to use stats vs reports

Stats are for **at-a-glance numbers** on the list/kanban header. For
deeper analytics (conversion matrices, time-series charts, multi-dim
breakdowns), build a dedicated report controller — there's no
single-purpose primitive in the package because report shapes vary
wildly between domains.
