<?php

namespace Kamva\Crud\Timeline;

use Closure;
use Illuminate\Support\Collection;

/**
 * Activity timeline composed of N pluggable sources. Each source is a
 * closure that, given a model, returns an iterable of events (either
 * {@see TimelineEvent} instances or arrays compatible with
 * {@see TimelineEvent::fromArray()}).
 *
 * Use case: merge stage-transition history + audit-log changes + comments
 * into one chronological feed on a detail page without each controller
 * rolling its own merge-and-sort logic.
 *
 * Example:
 *
 *     $timeline = new Timeline();
 *
 *     $timeline->addSource('transitions', function ($lead) {
 *         return $lead->transitions->map(fn($t) => [
 *             'at'    => $t->created_at,
 *             'type'  => 'stage',
 *             'title' => sprintf('%s → %s', $t->from_stage, $t->to_stage),
 *             'actor' => $t->triggered_by,
 *         ]);
 *     });
 *
 *     $timeline->addSource('audits', function ($lead) {
 *         return $lead->audits()->latest()->limit(50)->get()->map(fn($a) => [
 *             'at'    => $a->created_at,
 *             'type'  => 'edit',
 *             'title' => 'Edited',
 *             'body'  => implode(', ', array_keys($a->new_values ?? [])),
 *         ]);
 *     });
 *
 *     $events = $timeline->for($lead);  // Collection<TimelineEvent>, sorted desc
 */
class Timeline
{
    /** @var array<string, Closure> */
    private array $sources = [];

    /**
     * Register a named source. The closure receives the model and must
     * return an iterable of TimelineEvent or array-compatible entries.
     *
     * Names are unique — re-registering with the same name overwrites.
     */
    public function addSource(string $name, Closure $producer): self
    {
        $this->sources[$name] = $producer;
        return $this;
    }

    /** @return string[] */
    public function getSourceNames(): array
    {
        return array_keys($this->sources);
    }

    public function hasSource(string $name): bool
    {
        return isset($this->sources[$name]);
    }

    public function removeSource(string $name): self
    {
        unset($this->sources[$name]);
        return $this;
    }

    /**
     * Resolve all sources against the given model, normalize entries to
     * {@see TimelineEvent}, and return them sorted by `at` descending.
     *
     * @param mixed $model
     * @return Collection<int, TimelineEvent>
     */
    public function for($model): Collection
    {
        $events = collect();

        foreach ($this->sources as $producer) {
            try {
                $items = $producer($model) ?? [];
            } catch (\Throwable) {
                // A single source throwing must not break all timeline rendering —
                // skip the source entirely.
                continue;
            }
            foreach ($items as $item) {
                if ($item instanceof TimelineEvent) {
                    $events->push($item);
                } elseif (is_array($item)) {
                    try {
                        $events->push(TimelineEvent::fromArray($item));
                    } catch (\Throwable) {
                        // Malformed entry (bad `at` string, etc.) — skip it,
                        // honouring the documented "silently skip malformed
                        // entries" contract.
                        continue;
                    }
                }
                // Other shapes silently skipped
            }
        }

        return $events->sortByDesc(fn (TimelineEvent $e) => $e->at->getTimestamp())->values();
    }
}
