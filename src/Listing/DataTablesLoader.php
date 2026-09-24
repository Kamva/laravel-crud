<?php

namespace Kamva\Crud\Listing;

use Closure;
use Illuminate\Http\Request;
use Kamva\Crud\Columns\ColumnSet;

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
        $operator   = $rows->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $model      = $rows->getModel();

        return $rows->where(function ($q) use ($text, $operator, $model) {
            foreach ($this->columns as $col) {
                // Columns with no database column of their own (Closure or
                // relation values) can't be searched in the query.
                $colName = $col->guessColNameInDB($model);
                if (!empty($colName)) {
                    $q->orWhere($colName, $operator, "%" . $text . "%");
                }
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

        // A column with no database column of its own (Closure or relation
        // value) orders by the primary key: a stable order on every driver.
        $colName = $this->columns->at($index)->guessColNameInDB($rows->getModel())
            ?? $rows->getModel()->getKeyName();

        // $dir is untrusted too: Builder::orderBy() throws on anything but
        // asc/desc, so fall back to the default direction instead of a 500.
        $dir = is_string($dir) && strtolower($dir) === 'asc' ? 'asc' : 'desc';

        if (!empty($colName)) {
            return $rows->orderBy($colName, $dir);
        }

        return $rows;
    }
}
