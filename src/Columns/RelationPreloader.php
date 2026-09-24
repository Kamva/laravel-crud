<?php

namespace Kamva\Crud\Columns;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Throwable;

/**
 * Loads the relation a dotted column such as `'category.title'` reads for a
 * whole page of rows in one query, instead of one lazy query per row.
 *
 * The contract is that every row ends up exactly as lazy loading would have
 * left it. So load() only fetches: it returns a map of row => related model,
 * and {@see ColumnSet} attaches each entry just before evaluating the column
 * that reads it, which is when lazy loading would have loaded it. Rows left
 * out of the map simply load lazily, as before.
 *
 * A relation is preloaded only when:
 *  - it is reached through the relation, not shadowed by an attribute, cast
 *    or accessor of the same name;
 *  - it is a to-one relation (BelongsTo, HasOne, MorphOne), without nested
 *    eager loads or an inverse (`chaperone()`) whose state would be shared;
 *  - its definition does not depend on the row: the relation query built on a
 *    blank model (what eager loading uses) is identical to the one built on
 *    every row. `->where('currency', $this->currency)` fails this check;
 *  - the related keys are ones PHP and the database compare the same way
 *    (integers, or lowercase ASCII strings matched against string keys) and
 *    none is duplicated. Otherwise collations or type coercion could make the
 *    database pick a different, or an ambiguous, match.
 *
 * Per row, a match is used only when the keys are exactly equal. A row
 * without one loads lazily unless "no record" is certain (the database
 * might still match it, e.g. case-insensitively), and so does a row without
 * a record on a relation with `withDefault()` (the default can be built from
 * the row). Each row gets its own model instance,
 * hydrated like a lazy load, so `retrieved` events fire once per row too.
 *
 * @internal
 */
final class RelationPreloader
{
    /** Rows per eager query, well under database bind-parameter limits. */
    private const CHUNK = 1000;

    /**
     * @param \Illuminate\Database\Eloquent\Collection|mixed $rows
     * @return array<int, array{0: Model, 1: Model|null}>|null
     *         spl_object_id(row) => [row, related]; null when nothing is preloaded.
     */
    public static function load($rows, string $name): ?array
    {
        if (! $rows instanceof EloquentCollection || $rows->isEmpty()) {
            return null;
        }

        $models = array_values($rows->filter(
            fn ($row) => $row instanceof Model && ! $row->relationLoaded($name)
        )->all());

        if (! $models || ! self::canEagerLoad($rows, $name)) {
            return null;
        }

        $blank    = $models[0]->newInstance();
        $relation = self::build($blank, $name);
        if ($relation === null || self::hasSharedState($relation)) {
            return null;
        }

        [$rowKeyName, $relatedKeyName] = $relation instanceof BelongsTo
            ? [$relation->getForeignKeyName(), $relation->getOwnerKeyName()]
            : [$relation->getLocalKeyName(), $relation->getForeignKeyName()];

        $dictionary = [];
        $stringKeys = null;
        foreach (array_chunk($models, self::CHUNK) as $chunk) {
            $query = self::build($blank, $name);
            $query->addEagerConstraints($chunk);

            foreach ($query->getEager() as $related) {
                $key      = $related->getAttribute($relatedKeyName);
                $isString = is_string($key);

                if ((! is_int($key) && ! ($isString && preg_match('/^[a-z0-9_-]+$/D', $key)))
                    || ($stringKeys !== null && $stringKeys !== $isString)) {
                    return null;
                }

                if (array_key_exists($key, $dictionary)) {
                    // The same record again (from another chunk) is fine; a
                    // second record with this key makes the match ambiguous.
                    $known = $dictionary[$key];
                    if ($related->getKey() === null || $related->getKey() !== $known->getKey()) {
                        return null;
                    }
                    continue;
                }

                $stringKeys       = $isString;
                $dictionary[$key] = $related;
            }
        }

        $hasDefault = self::hasDefault($relation);
        $map        = [];
        foreach ($models as $model) {
            $key = $model->getAttribute($rowKeyName);

            if ((is_string($key) || (is_int($key) && $stringKeys !== true)) && isset($dictionary[$key])) {
                $map[spl_object_id($model)] = [$model, $dictionary[$key]];
                continue;
            }

            // No related record. Certain when the row has no key, when the
            // query found nothing at all, or when integer keys were compared
            // (the query returned every record the database considers equal
            // to the row's key). Otherwise, e.g. 'US' against 'us' under a
            // case-insensitive collation, leave the row to lazy loading.
            $certain = $key === null || ! $dictionary || (is_int($key) && $stringKeys === false);

            if ($certain && ! $hasDefault) {
                $map[spl_object_id($model)] = [$model, null];
            }
        }

        return $map ?: null;
    }

    /**
     * The instance to attach to one row: the loaded model for its first row,
     * then a fresh one hydrated from the same database row for the others,
     * as lazy loading gives every row its own.
     *
     * @param array<int, true> $handedOut spl_object_id of loaded models already attached
     */
    public static function instanceFor(?Model $related, array &$handedOut): ?Model
    {
        if ($related === null) {
            return null;
        }

        $id = spl_object_id($related);
        if (isset($handedOut[$id])) {
            $related = $related->newFromBuilder($related->getRawOriginal(), $related->getConnectionName());
        } else {
            $handedOut[$id] = true;
        }

        // A lazy load hydrates a single row, which never turns this on.
        if (property_exists($related, 'preventsLazyLoading')) {
            $related->preventsLazyLoading = false;
        }

        return $related;
    }

    private static function canEagerLoad(EloquentCollection $rows, string $name): bool
    {
        $first = $rows->first();

        if (! $first instanceof Model || self::isShadowed($first, $name)) {
            return false;
        }

        $expected = self::signature($first->newInstance(), $name);
        if ($expected === null) {
            return false;
        }

        foreach ($rows as $row) {
            if (! $row instanceof Model || get_class($row) !== get_class($first)) {
                return false;
            }
            if ($row->relationLoaded($name)) {
                continue;
            }
            if (self::isShadowed($row, $name) || self::signature($row, $name) !== $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether `$model->$name` resolves to something other than the relation
     * (mirrors the checks at the start of Model::getAttribute()).
     */
    private static function isShadowed(Model $model, string $name): bool
    {
        return $name === ''
            || array_key_exists($name, $model->getAttributes())
            || array_key_exists($name, $model->getCasts())
            || $model->hasGetMutator($name)
            || (method_exists($model, 'hasAttributeMutator') && $model->hasAttributeMutator($name))
            || method_exists(Model::class, $name)
            || ! method_exists($model, $name);
    }

    /**
     * The relation built on $model without its per-row key constraints, or
     * null when it is not a relation this class preloads.
     */
    private static function build(Model $model, string $name): ?Relation
    {
        try {
            $relation = Relation::noConstraints(fn () => $model->$name());
        } catch (Throwable $e) {
            return null;
        }

        if (! $relation instanceof Relation
            || $relation instanceof MorphTo
            || ! ($relation instanceof BelongsTo || $relation instanceof HasOne || $relation instanceof MorphOne)) {
            return null;
        }

        return $relation;
    }

    /**
     * A string identifying the relation's definition, or null when it is not
     * a relation this class preloads.
     */
    private static function signature(Model $model, string $name): ?string
    {
        $relation = self::build($model, $name);
        if ($relation === null) {
            return null;
        }

        $related = $relation->getRelated();
        $keys    = $relation instanceof BelongsTo
            ? [$relation->getForeignKeyName(), $relation->getOwnerKeyName()]
            : [$relation->getForeignKeyName(), $relation->getLocalKeyName()];

        if ($relation instanceof MorphOne) {
            $keys[] = $relation->getMorphType();
            $keys[] = $relation->getMorphClass();
        }

        try {
            return serialize([
                get_class($relation),
                get_class($related),
                $related->getConnectionName(),
                $keys,
                $relation->toSql(),
                $relation->getBindings(),
            ]);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Nested eager loads (e.g. the related model's `$with`) and inverse
     * relations would be shared between rows' instances; keep those lazy.
     */
    private static function hasSharedState(Relation $relation): bool
    {
        if ($relation->getQuery()->getEagerLoads()) {
            return true;
        }

        return method_exists($relation, 'getInverseRelationship')
            && $relation->getInverseRelationship() !== null;
    }

    private static function hasDefault(Relation $relation): bool
    {
        try {
            $property = new \ReflectionProperty($relation, 'withDefault');
            $property->setAccessible(true);
            $default = $property->getValue($relation);

            return $default !== null && $default !== false;
        } catch (Throwable $e) {
            return true;
        }
    }
}
