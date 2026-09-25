<?php

namespace Kamva\Crud\Listing;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Kamva\Crud\Columns\ColumnSet;
use Kamva\Crud\Containers\ColumnContainer;
use Kamva\Crud\KamvaCrud;

/**
 * Answers the server-side DataTables request the list view makes
 * (`index()` with `Accept: application/json`): applies the global search,
 * ordering and paging to the query and returns the `data` / `recordsTotal` /
 * `recordsFiltered` / `draw` payload.
 *
 * Each data row is `[counter?, ...column values, actions cell]`.
 */
final class DataTablesLoader
{
    private ColumnSet $columns;
    private bool $rowCounter;
    private Closure $actionsCell;
    private $fixedOrderCol;
    private $fixedOrderDir;

    /**
     * @param ColumnSet $columns     The list columns, in display order.
     * @param bool      $rowCounter  Whether a leading row-number column is rendered.
     * @param Closure   $actionsCell fn($row, bool $avoidGroup): string — renders the trailing actions cell.
     * @param mixed     $fixedOrderCol Column from setOrderBy(); when set it overrides the requested order.
     * @param mixed     $fixedOrderDir Direction from setOrderBy().
     */
    public function __construct(ColumnSet $columns, bool $rowCounter, Closure $actionsCell, $fixedOrderCol = null, $fixedOrderDir = null)
    {
        $this->columns       = $columns;
        $this->rowCounter    = $rowCounter;
        $this->actionsCell   = $actionsCell;
        $this->fixedOrderCol = $fixedOrderCol;
        $this->fixedOrderDir = $fixedOrderDir;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $rows The filtered, scoped query.
     */
    public function respond(Request $request, $rows): array
    {
        $start          = (int) $request->input('start');
        $length         = (int) $request->input('length');
        $search         = $request->input('search')['value'] ?? null;

        $order          = $request->input('order')[0]['column'] ?? null;
        $orderDir       = $request->input('order')[0]['dir'] ?? 'desc';

        $initialRows    = clone $rows;

        $rows           = $this->search($rows, $search);

        $rows           = $this->order($rows, $order, $orderDir);

        $filteredRows   = clone $rows;

        $rows           = $rows->skip($start)->take($length)->get();

        return [
            "data"              => $this->records($start, $rows),
            // Count via getCountForPagination() rather than ->get() + count():
            // the latter hydrates every matching row into Eloquent models just
            // to count them, on every DataTables draw — a memory/latency
            // problem and a DoS vector on large tables. getCountForPagination()
            // is the same routine the paginator uses, so it preserves
            // distinct()/groupBy() semantics (wrapping in a subquery) where a
            // bare count() would not.
            "recordsTotal"      => $initialRows->toBase()->getCountForPagination(),
            "recordsFiltered"   => $filteredRows->toBase()->getCountForPagination(),
            "draw"              => $request->input('draw'),
        ];
    }

    private function records($start, $rows): array
    {
        $out            = [];
        $i              = $start + 1;
        $avoidGroup     = $rows->count() < 5;

        $this->columns->preloadRelations($rows);

        foreach ($rows as $row) {
            $value      = [];

            if ($this->rowCounter) {
                $value[] = $i++;
            }

            foreach ($this->columns->values($row) as $cell) {
                $value[] = $cell;
            }

            $value[]    = ($this->actionsCell)($row, $avoidGroup);
            $out[]      = $value;
        }

        return $out;
    }

    private function search($rows, $text)
    {
        if (empty($text)) {
            return $rows;
        }

        // Postgres' LIKE is case-sensitive; ILIKE matches the way LIKE does
        // under MySQL's default collations and SQLite.
        $pgsql      = $rows->getConnection()->getDriverName() === 'pgsql';
        $operator   = $pgsql ? 'ilike' : 'like';
        $model      = $rows->getModel();

        // Columns with no database column of their own (Closure, relation
        // or accessor values) can't be searched in the query.
        $colNames   = [];
        foreach ($this->columns as $col) {
            $colName = $this->dbColumn($col, $model);
            if (!empty($colName)) {
                $colNames[] = $colName;
            }
        }

        // Nothing searchable: match no rows rather than ignore the term.
        if (empty($colNames)) {
            return $rows->whereKey([]);
        }

        // Escape wildcards so `_` and `%` match literally. `!` needs no
        // escaping inside a string literal, unlike a backslash (MySQL's
        // NO_BACKSLASH_ESCAPES), and SQLite has no default escape character,
        // so it is given explicitly on every driver. Postgres casts to text,
        // as Laravel's grammar does for a LIKE, so non-text columns match.
        $like       = "%" . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text) . "%";
        $cast       = $pgsql ? '::text' : '';

        return $rows->where(function ($q) use ($like, $operator, $colNames, $cast) {
            foreach ($colNames as $colName) {
                $q->orWhereRaw($q->getGrammar()->wrap($colName) . "{$cast} {$operator} ? escape '!'", [$like]);
            }
        });
    }

    private function order($rows, $by, $dir)
    {
        if (!empty($this->fixedOrderCol)) {
            $rows->orderBy($this->fixedOrderCol, $this->fixedOrderDir);

            return $rows;
        }

        // DataTables sends a 0-based column index across all rendered columns.
        // The leading row-counter column occupies index 0 only when it's
        // enabled, so the offset into the columns depends on rowCounter:
        //   counter on  → data columns start at DataTables index 1 (offset 1)
        //   counter off → data columns start at DataTables index 0 (offset 0)
        // $by is untrusted request input — the (int) cast guards non-numeric
        // values, and the bound check covers out-of-range indexes.
        $offset = $this->rowCounter ? 1 : 0;
        $index  = (int) $by - $offset;

        if ($index < 0 || $index >= $this->columns->count()) {
            return $rows->orderBy("created_at", "desc");
        }

        // A column with no database column of its own (Closure, relation or
        // accessor value) orders by the primary key: a stable order on every
        // driver. Qualified, so a query with joins isn't ambiguous.
        $colName = $this->dbColumn($this->columns->at($index), $rows->getModel())
            ?? $rows->getModel()->getQualifiedKeyName();

        // $dir is untrusted too: Builder::orderBy() throws on anything but
        // asc/desc, so fall back to the default direction instead of a 500.
        $dir = is_string($dir) && strtolower($dir) === 'asc' ? 'asc' : 'desc';

        if (!empty($colName)) {
            return $rows->orderBy($colName, $dir);
        }

        return $rows;
    }

    /**
     * DataTables indexes of the data columns that can't be sorted (they have
     * no database column of their own, or setOrderBy() fixes the order), for
     * the list view's
     * `columnDefs: [{orderable: false, targets: …}]`. Sorting by one orders
     * by the primary key, which the header arrow would misrepresent.
     *
     * @return int[]
     */
    public function unsortableColumns(Model $model): array
    {
        $offset = $this->rowCounter ? 1 : 0;
        $out    = [];

        foreach ($this->columns as $index => $col) {
            // setOrderBy() fixes the order: no header changes it.
            if (!empty($this->fixedOrderCol) || empty($this->dbColumn($col, $model))) {
                $out[] = $index + $offset;
            }
        }

        return $out;
    }

    /**
     * The table column $col reads, or null when it reads none. A name the
     * model resolves itself (an accessor, an appended attribute, or a method
     * such as a relation), or the name of a skip()ped form field, is checked
     * against the table's real columns where the schema can be listed;
     * elsewhere (MongoDB) the guess stands. Plain names are used as is,
     * without a schema query.
     */
    private function dbColumn(ColumnContainer $col, Model $model): ?string
    {
        $name = $col->guessColNameInDB($model);

        if (empty($name)) {
            return null;
        }

        if (
            ($this->resolvedByModel($model, $name) || $this->isSkippedField($col, $name))
            && app(TableColumns::class)->has($model, $name) === false
        ) {
            return null;
        }

        return $name;
    }

    /** A `'name.field'` column whose form field is saved by its own callback, not as a column. */
    private function isSkippedField(ColumnContainer $col, string $name): bool
    {
        if (!is_string($col->value) || (explode('.', $col->value)[1] ?? null) !== 'field') {
            return false;
        }

        $controller = KamvaCrud::get('class');
        $field      = is_object($controller) ? $controller->getForm()->getField($name) : null;

        return !empty($field) && $field->field()->shouldSkipSaving();
    }

    private function resolvedByModel(Model $model, string $name): bool
    {
        return $model->hasGetMutator($name)
            || (method_exists($model, 'hasAttributeMutator') && $model->hasAttributeMutator($name))
            || (method_exists($model, 'getAppends') && in_array($name, $model->getAppends(), true))
            || (method_exists($model, $name) && !method_exists(Model::class, $name));
    }
}
