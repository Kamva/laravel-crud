<?php

namespace Kamva\Crud\Kanban;

use Closure;

/**
 * Configuration for a kanban view registered on a CRUD controller via
 * {@see \Kamva\Crud\CRUDController::enableKanban()}.
 *
 * Designed as a plain value object — the controller stores one of these
 * and the index() handler consults it when rendering the kanban variant.
 */
final class KanbanConfig
{
    /**
     * @param string  $attribute        Model attribute name used to bucket cards
     *                                  into columns (e.g. 'status', 'stage').
     * @param array<string, array{label:string, color?:string, accepts_drop?:bool}>
     *                $columns          Column definitions, keyed by the attribute
     *                                  value. Order is preserved.
     * @param Closure $cardRenderer     fn($model): array — returns the data the
     *                                  view needs to render a card. Recommended
     *                                  keys: title, subtitle, body, value, href.
     * @param string  $transitionRoute  Named route to POST to when a card is
     *                                  dragged. Must accept the model's key
     *                                  (e.g. {lead}) and a `to` query/form arg.
     * @param string  $transitionParam  Form/query field name for the target
     *                                  column. Default: 'to_stage'.
     * @param string|null $emptyMessage Optional message shown when the kanban
     *                                  is empty. Default: derived by view.
     */
    public function __construct(
        public readonly string $attribute,
        public readonly array $columns,
        public readonly Closure $cardRenderer,
        public readonly string $transitionRoute,
        public readonly string $transitionParam = 'to_stage',
        public readonly ?string $emptyMessage = null,
    ) {}

    /**
     * Bucket a collection of models into columns. Returns an array keyed by
     * the column key (attribute value), with structure:
     *
     *     [
     *         'new' => [
     *             'definition' => ['label' => 'New', 'color' => 'gray', ...],
     *             'cards'      => array<int, array> // one per model
     *             'count'      => int,
     *             'value_sum'  => float (sum of card['value'] when present)
     *         ],
     *         ...
     *     ]
     *
     * Models whose attribute value isn't in the columns map are silently
     * dropped (typically terminal states the kanban doesn't render).
     *
     * @param iterable $models
     * @return array
     */
    public function bucket(iterable $models): array
    {
        $out = [];
        foreach ($this->columns as $key => $def) {
            $out[$key] = [
                'key'        => $key,
                'definition' => $def,
                'cards'      => [],
                'count'      => 0,
                'value_sum'  => 0.0,
            ];
        }

        foreach ($models as $model) {
            $value = $this->resolveAttribute($model);
            if ($value === null || !isset($out[$value])) {
                continue;
            }
            $card = ($this->cardRenderer)($model);
            if (!is_array($card)) {
                continue;
            }
            // Always carry id + href fallback so views can build links.
            if (!isset($card['id']) && method_exists($model, 'getKey')) {
                $card['id'] = $model->getKey();
            }
            $out[$value]['cards'][] = $card;
            $out[$value]['count']++;
            if (isset($card['value']) && is_numeric($card['value'])) {
                $out[$value]['value_sum'] += (float) $card['value'];
            }
        }

        return $out;
    }

    /**
     * Look up the attribute value on the model. Supports backed enums
     * (returns `->value`) and plain scalars.
     */
    private function resolveAttribute($model): ?string
    {
        $raw = is_object($model) ? ($model->{$this->attribute} ?? null) : null;
        if ($raw === null) {
            return null;
        }
        if ($raw instanceof \BackedEnum) {
            return (string) $raw->value;
        }
        return (string) $raw;
    }
}
