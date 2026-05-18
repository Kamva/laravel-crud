# Timeline (v2+)

Merge multiple sources of "what happened to this record" into one
chronological feed. Typical sources: stage transitions, audit-log
changes, comments/notes.

## Register sources

In `setup()`:

```php
$this->addTimelineSource('transitions', function ($lead) {
    return $lead->transitions->map(fn ($t) => [
        'at'    => $t->created_at,
        'type'  => 'stage',
        'title' => "{$t->from_stage} → {$t->to_stage}",
        'actor' => $t->triggered_by,
    ]);
});

$this->addTimelineSource('audits', function ($lead) {
    return $lead->audits()->latest()->limit(50)->get()->map(fn ($a) => [
        'at'    => $a->created_at,
        'type'  => 'edit',
        'title' => 'Edited',
        'body'  => implode(', ', array_keys($a->new_values ?? [])),
        'actor' => optional($a->user)->name,
    ]);
});

$this->addTimelineSource('notes', function ($lead) {
    return Note::where('lead_id', $lead->id)->latest()->get()->map(fn ($n) => [
        'at'    => $n->created_at,
        'type'  => 'note',
        'title' => 'Note',
        'body'  => $n->body,
        'actor' => $n->user_name,
    ]);
});
```

Each source's producer closure must return an iterable of:

- `TimelineEvent` instances (see `Kamva\Crud\Timeline\TimelineEvent`), or
- Array-compatible structures with these keys:

| Key     | Type                                          | Required | Default |
|---------|-----------------------------------------------|----------|---------|
| `at`    | `DateTimeInterface` or parsable date string   | yes      | —       |
| `title` | string                                        | no       | `''`    |
| `body`  | string                                        | no       | `''`    |
| `icon`  | string (CSS class)                            | no       | `''`    |
| `actor` | string (who did it)                           | no       | `''`    |
| `type`  | string (categorisation)                       | no       | `''`    |

Malformed entries (non-arrays, missing `at`) are silently skipped.

## Reading the timeline

The detail view (when opted in — see [detail-view.md](detail-view.md))
receives the merged, time-sorted timeline as `$timeline`:

```blade
@foreach ($timeline as $event)
    <li>
        <strong>{{ $event->title }}</strong>
        <small>{{ $event->at->format('Y-m-d H:i') }}</small>
        @if ($event->actor)
            by {{ $event->actor }}
        @endif
        @if ($event->body)
            <div class="text-muted">{{ $event->body }}</div>
        @endif
    </li>
@endforeach
```

To consume the timeline outside the show page (e.g. in a custom report):

```php
$timeline = $this->getTimeline();  // ?Timeline
if ($timeline) {
    $events = $timeline->for($model);  // Collection<TimelineEvent>
}
```

## Single source from outside the controller

You can also instantiate `Timeline` standalone (e.g. in a service):

```php
use Kamva\Crud\Timeline\Timeline;

$timeline = new Timeline();
$timeline->addSource('transitions', fn ($model) => /* ... */);
$timeline->addSource('audits',      fn ($model) => /* ... */);

$events = $timeline->for($lead);
```

## Sorting

Events are sorted by `at` **descending** (most recent first). The sort
uses `DateTimeInterface::getTimestamp()`, so all timezones normalise
correctly.

## Backwards compatibility

Timelines are opt-in: until you call `addTimelineSource()`, the
controller's `show()` doesn't include any timeline data and the detail
view's timeline panel is empty / hidden.
