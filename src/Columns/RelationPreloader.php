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
 * Eager-loads the relations that dotted columns such as `'category.title'`
 * read, so a page of rows costs one query per relation instead of one per
 * row.
 *
 * Eager loading must never change a value compared with lazy loading, so a
 * relation is only preloaded when all of these hold:
 *  - it is reached through the relation, not shadowed by an attribute, cast
 *    or accessor of the same name;
 *  - it is a to-one relation (BelongsTo, HasOne, MorphOne), the only kind a
 *    dotted column can read a value from;
 *  - its definition does not depend on the row: the relation query built on
 *    a blank model (which is what eager loading uses) is identical to the one
 *    built on every row. A definition such as
 *    `->where('currency', $this->currency)` fails this check and keeps
 *    loading lazily, exactly as before;
 *  - every row has at most one matching related record. With duplicates,
 *    lazy loading takes the first match while eager matching can take
 *    another, so ambiguous data keeps loading lazily too.
 *
 * Rows without a match are left to lazy loading when the relation has a
 * `withDefault()`: the default can be built from the row itself, which
 * eager loading (building from a blank model) would not reproduce.
 *
 * Each row gets its own copy of the related model, as lazy loading gives.
 * The one observable difference: `retrieved` events on the related model
 * fire once per distinct record instead of once per row.
 *
 * @internal
 */
final class RelationPreloader
{
    /**
     * @param iterable<string> $relations Relation names (first segment of dotted columns).
     */
    public static function preload($rows, iterable $relations): void
    {
        if (! $rows instanceof EloquentCollection || $rows->isEmpty()) {
            return;
        }

        foreach ($relations as $name) {
            $missing = $rows->filter(fn ($row) => $row instanceof Model && ! $row->relationLoaded($name));

            if ($missing->isNotEmpty() && self::canEagerLoad($rows, $name)) {
                self::eagerLoad(array_values($missing->all()), $name);
            }
        }
    }

    /**
     * What Eloquent's eager loading does (Builder::eagerLoadRelation()), plus
     * the one-match-per-row check and the per-row copies.
     *
     * @param Model[] $models
     */
    private static function eagerLoad(array $models, string $name): void
    {
        try {
            $relation = Relation::noConstraints(fn () => $models[0]->newInstance()->$name());
            $relation->addEagerConstraints($models);
            $results = $relation->getEager();
        } catch (Throwable $e) {
            // Leave it to lazy loading, which reports any error as before.
            return;
        }

        $keyName = $relation instanceof BelongsTo ? $relation->getOwnerKeyName() : $relation->getForeignKeyName();
        $seen    = [];
        foreach ($results as $result) {
            $key = $result->getAttribute($keyName);
            if ((! is_int($key) && ! is_string($key)) || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
        }

        $relation->match($relation->initRelation($models, $name), $results, $name);

        $matched = [];
        foreach ($results as $result) {
            $matched[spl_object_id($result)] = true;
        }
        $hasDefault = self::hasDefault($relation);

        $given = [];
        foreach ($models as $model) {
            $related = $model->getRelation($name);
            if (! $related instanceof Model || ! isset($matched[spl_object_id($related)])) {
                if ($hasDefault) {
                    $model->unsetRelation($name);
                }
                continue;
            }
            if (isset($given[spl_object_id($related)])) {
                $model->setRelation($name, clone $related);
            } else {
                $given[spl_object_id($related)] = true;
            }
        }
    }

    private static function hasDefault(Relation $relation): bool
    {
        try {
            $property = new \ReflectionProperty($relation, 'withDefault');
            $property->setAccessible(true);

            return $property->getValue($relation) !== null && $property->getValue($relation) !== false;
        } catch (Throwable $e) {
            return true;
        }
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
     * A string identifying the relation's definition without its per-row key
     * constraints, or null when it is not a relation this class preloads.
     */
    private static function signature(Model $model, string $name): ?string
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
}
