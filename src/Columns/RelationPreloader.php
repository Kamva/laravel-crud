<?php

namespace Kamva\Crud\Columns;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Throwable;

/**
 * Fetches the relation a dotted column such as `'category.title'` reads for a
 * whole page of rows in one query, instead of one lazy query per row.
 *
 * The contract is that every row ends up exactly as lazy loading would have
 * left it. So load() only fetches raw records (no models hydrated, no events
 * fired); {@see ColumnSet} attaches each row's record just before evaluating
 * the column that reads it, which is when lazy loading would have loaded it,
 * hydrating a model for that row the way a lazy load does (`retrieved`
 * events included). Rows left out simply load lazily, as before.
 *
 * A relation is preloaded only when:
 *  - it is exactly Laravel's BelongsTo, HasOne or MorphOne (subclasses from
 *    packages may match differently), reached through the relation method
 *    rather than an attribute, cast or accessor of the same name;
 *  - its query has a shape that eager loading reproduces: no limit/offset,
 *    joins, grouping, unions, top-level `orWhere` or raw where clauses, no
 *    nested eager loads, `afterQuery()` callbacks or inverse (`chaperone()`);
 *  - its definition does not depend on the row: the relation query built on a
 *    blank model (what eager loading uses) is identical to the one built on
 *    every row. `->where('currency', $this->currency)` fails this check;
 *  - the related keys are ones PHP and the database compare the same way
 *    (integers, or lowercase ASCII strings matched against string keys) and
 *    none is duplicated. Otherwise collations or type coercion could make the
 *    database pick a different, or an ambiguous, match.
 *
 * Per row, a record is used only when the keys are exactly equal and the
 * row's key hasn't changed by the time its column is evaluated. A row
 * without a record loads lazily unless the absence is certain, and so does
 * a row without a record on a relation with `withDefault()` (the default can
 * be built from the row).
 *
 * @internal
 */
final class RelationPreloader
{
    /** Rows per query, well under database bind-parameter limits. */
    private const CHUNK = 1000;

    private const PLAIN_STRING_KEY = '/^[a-z0-9_-]+$/D';

    /**
     * @param \Illuminate\Database\Eloquent\Collection|mixed $rows
     * @return array{related: Model, connection: ?string, rowKey: string, rows: array<int, array{0: Model, 1: mixed, 2: ?array}>}|null
     *         Per row (by spl_object_id): the row, the key it was matched on,
     *         and the related record's raw attributes (null: no record).
     *         Null when nothing is preloaded.
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
        $keyNames = $relation ? self::keyNames($relation) : null;
        if ($keyNames === null || ! self::hasEagerSafeShape($relation)) {
            return null;
        }
        [$rowKeyName, $relatedKeyName] = $keyNames;

        // Rows whose keys could only be matched lazily: don't query at all.
        $usable = false;
        foreach ($models as $model) {
            $key = $model->getAttribute($rowKeyName);
            if (is_int($key) || (is_string($key) && preg_match(self::PLAIN_STRING_KEY, $key))) {
                $usable = true;
                break;
            }
        }
        if (! $usable) {
            return null;
        }

        $related    = $relation->getRelated();
        $connection = $related->getConnectionName();
        $dictionary = [];
        $stringKeys = null;

        foreach (array_chunk($models, self::CHUNK) as $chunk) {
            $query = self::build($blank, $name);
            $query->addEagerConstraints($chunk);
            $connection = $query->getQuery()->getQuery()->getConnection()->getName();

            // Raw records: no model is hydrated and no event fires until a
            // record is actually attached to a row.
            foreach ($query->getQuery()->toBase()->get() as $record) {
                $attributes = (array) $record;
                $probe      = $related->newInstance([], true)->setRawAttributes($attributes, true);
                $key        = $probe->getAttribute($relatedKeyName);
                $isString   = is_string($key);

                if ((! is_int($key) && ! ($isString && preg_match(self::PLAIN_STRING_KEY, $key)))
                    || ($stringKeys !== null && $stringKeys !== $isString)) {
                    return null;
                }

                if (array_key_exists($key, $dictionary)) {
                    // The same record again (from another chunk) is fine; a
                    // second record with this key makes the match ambiguous.
                    if ($probe->getKey() === null || $probe->getKey() !== $dictionary[$key][1]) {
                        return null;
                    }
                    continue;
                }

                $stringKeys       = $isString;
                $dictionary[$key] = [$attributes, $probe->getKey()];
            }
        }

        $hasDefault = self::hasDefault($relation);
        $map        = [];
        foreach ($models as $model) {
            $key = $model->getAttribute($rowKeyName);

            if ((is_string($key) || (is_int($key) && $stringKeys !== true)) && isset($dictionary[$key])) {
                $map[spl_object_id($model)] = [$model, $key, $dictionary[$key][0]];
                continue;
            }

            // No related record. Certain when the row has no key, when the
            // query found nothing at all, or when integer keys were compared
            // (the query returned every record the database considers equal
            // to the row's key). Otherwise, e.g. 'US' against 'us' under a
            // case-insensitive collation, leave the row to lazy loading.
            $certain = $key === null || ! $dictionary || (is_int($key) && $stringKeys === false);

            if ($certain && ! $hasDefault) {
                $map[spl_object_id($model)] = [$model, $key, null];
            }
        }

        if (! $map) {
            return null;
        }

        return ['related' => $related, 'connection' => $connection, 'rowKey' => $rowKeyName, 'rows' => $map];
    }

    /**
     * The model to attach for one row: hydrated from the record the way a
     * lazy load hydrates it (fires `retrieved`), or null for no record.
     */
    public static function hydrate(Model $related, ?string $connection, ?array $attributes): ?Model
    {
        return $attributes === null ? null : $related->newFromBuilder($attributes, $connection);
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
     * null when it is not one of the relation classes this class preloads.
     */
    private static function build(Model $model, string $name): ?Relation
    {
        try {
            $relation = Relation::noConstraints(fn () => $model->$name());
        } catch (Throwable $e) {
            return null;
        }

        return is_object($relation) && in_array(get_class($relation), [BelongsTo::class, HasOne::class, MorphOne::class], true)
            ? $relation
            : null;
    }

    /**
     * [row key attribute, related key attribute], or null if the relation
     * doesn't expose them (older Laravel versions).
     */
    private static function keyNames(Relation $relation): ?array
    {
        $names = $relation instanceof BelongsTo
            ? ['getForeignKeyName', 'getOwnerKeyName']
            : ['getLocalKeyName', 'getForeignKeyName'];

        foreach ($names as $method) {
            if (! method_exists($relation, $method)) {
                return null;
            }
        }

        return [$relation->{$names[0]}(), $relation->{$names[1]}()];
    }

    /**
     * A string identifying the relation's definition, or null when it is not
     * a relation this class preloads.
     */
    private static function signature(Model $model, string $name): ?string
    {
        $relation = self::build($model, $name);
        $keys     = $relation ? self::keyNames($relation) : null;
        if ($keys === null) {
            return null;
        }

        $related = $relation->getRelated();

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
     * Whether the relation's query gives each row the same record with an
     * eager `where key in (…)` as with a lazy `where key = ?`, and whether its
     * records can be handed to rows independently.
     */
    private static function hasEagerSafeShape(Relation $relation): bool
    {
        $eloquent = $relation->getQuery();
        $query    = $eloquent->getQuery();

        if ($eloquent->getEagerLoads()
            || self::hasModelAfterQueryCallbacks($eloquent)
            || (method_exists($relation, 'getInverseRelationship') && $relation->getInverseRelationship() !== null)
            || $query->limit !== null
            || $query->offset !== null
            || ! empty($query->joins)
            || ! empty($query->groups)
            || ! empty($query->havings)
            || ! empty($query->unions)
            || (property_exists($query, 'groupLimit') && $query->groupLimit !== null)) {
            return false;
        }

        foreach ($query->wheres as $where) {
            if (strtolower($where['boolean'] ?? 'and') !== 'and' || strtolower($where['type'] ?? '') === 'raw') {
                return false;
            }
        }

        return true;
    }

    /**
     * Records are fetched with toBase(), which skips Eloquent-level
     * afterQuery() callbacks (they run on the model collection).
     */
    private static function hasModelAfterQueryCallbacks($eloquent): bool
    {
        if (! property_exists($eloquent, 'afterQueryCallbacks')) {
            return false;
        }

        $property = new \ReflectionProperty($eloquent, 'afterQueryCallbacks');
        $property->setAccessible(true);

        return (bool) $property->getValue($eloquent);
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
