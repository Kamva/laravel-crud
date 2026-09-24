<?php

namespace Kamva\Crud\Listing;

use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * The real columns of a model's table, so the list search and sort only
 * reference names the database knows. Postgres and MySQL reject an unknown
 * column (a 500), while SQLite quietly reads it as a string.
 *
 * Listed once per table for the life of the app (a singleton), like
 * Laravel's own schema caches: a worker picks up a new column when it
 * restarts, as it does after a deploy.
 *
 * @internal
 */
final class TableColumns
{
    /** @var array<string, array<string, true>|null> */
    private array $tables = [];

    /**
     * Whether $column is a column of $model's table. Null when that can't
     * be known: MongoDB (schemaless), or a schema that can't be listed.
     */
    public function has(Model $model, string $column): ?bool
    {
        $columns = $this->of($model);

        if ($columns === null) {
            return null;
        }

        return isset($columns[$this->caseInsensitive($model) ? strtolower($column) : $column]);
    }

    /**
     * @return array<string, true>|null Column names (lowercased where the
     *         driver compares them case-insensitively), or null when unknown.
     */
    private function of(Model $model): ?array
    {
        $connection = $model->getConnection();
        $key        = $connection->getName() . '|' . $model->getTable();

        if (array_key_exists($key, $this->tables)) {
            return $this->tables[$key];
        }

        if ($connection->getDriverName() === 'mongodb') {
            return $this->tables[$key] = null;
        }

        try {
            $listing = $connection->getSchemaBuilder()->getColumnListing($model->getTable());
        } catch (Throwable) {
            // Not cached: the next request tries again.
            return null;
        }

        if ($this->caseInsensitive($model)) {
            $listing = array_map('strtolower', $listing);
        }

        // No columns: a table the schema builder can't see (e.g. a view on
        // some drivers). Keep the name-based guess rather than drop them all.
        return $this->tables[$key] = empty($listing) ? null : array_fill_keys($listing, true);
    }

    /** Postgres matches quoted identifiers exactly; MySQL, SQLite and SQL Server don't. */
    private function caseInsensitive(Model $model): bool
    {
        return $model->getConnection()->getDriverName() !== 'pgsql';
    }
}
