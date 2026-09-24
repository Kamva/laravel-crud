<?php

namespace Kamva\Crud\Columns;

use ArrayIterator;
use IteratorAggregate;
use Kamva\Crud\Containers\ColumnContainer;
use Kamva\Crud\KamvaCrud;
use Traversable;

/**
 * An ordered list of {@see ColumnContainer}s and the one place that turns a
 * record into a row of column values. The list view (DataTables), the API
 * and the Excel export all serialize through here, so a header row and its
 * data rows are always built from the same columns in the same order.
 */
final class ColumnSet implements IteratorAggregate
{
    /** @var ColumnContainer[] */
    private array $columns;

    /** @var array<int, string> column index => relation that column reads */
    private array $relationColumns = [];

    /** @var array<string, array> relation => map from RelationPreloader::load() */
    private array $preloaded = [];

    /** @var array<string, array<int, true>> relation => loaded models already attached */
    private array $handedOut = [];

    /**
     * @param ColumnContainer[] $columns
     */
    public function __construct(array $columns)
    {
        $this->columns = array_values($columns);
    }

    /**
     * @return Traversable<int, ColumnContainer>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->columns);
    }

    public function count(): int
    {
        return count($this->columns);
    }

    public function at(int $index): ?ColumnContainer
    {
        return $this->columns[$index] ?? null;
    }

    /**
     * Load the relations read by dotted columns (`'category.title'`) for a
     * page of rows in one query each. Nothing is attached yet: values() and
     * keyedValues() attach a row's relation right before the column that
     * reads it, when lazy loading would have loaded it, so every row ends up
     * exactly as before. See {@see RelationPreloader}.
     *
     * @param \Illuminate\Database\Eloquent\Collection|mixed $rows
     */
    public function preloadRelations($rows): void
    {
        $this->relationColumns = [];
        $this->preloaded       = [];
        $this->handedOut       = [];

        foreach ($this->columns as $index => $col) {
            if (! is_string($col->value)) {
                continue;
            }

            // Same resolution order as ColumnContainer::getValue(): a custom
            // column type (KamvaCrud::addColumnType()) or a column method
            // (e.g. 'status.field') handles the value before any relation is
            // read, so only the remaining dotted values are preloaded.
            $segments = explode('.', $col->value);
            $action   = $segments[1] ?? null;
            if (empty($action) || KamvaCrud::hasColumnType($action) || method_exists($col, $action)) {
                continue;
            }

            $this->relationColumns[$index] = $segments[0];
        }

        foreach (array_unique($this->relationColumns) as $name) {
            if ($map = RelationPreloader::load($rows, $name)) {
                $this->preloaded[$name] = $map;
                $this->handedOut[$name] = [];
            }
        }
    }

    /**
     * Column titles, in order.
     */
    public function headers(): array
    {
        return array_map(fn (ColumnContainer $col) => $col->getName(), $this->columns);
    }

    /**
     * Column values for one record, positionally aligned with {@see headers()}.
     */
    public function values($row, bool $raw = false): array
    {
        $out = [];
        foreach ($this->columns as $index => $col) {
            $out[] = $this->value($index, $col, $row, $raw);
        }

        return $out;
    }

    /**
     * Column values for one record keyed by column title. Columns sharing a
     * title collapse to the last one, so use {@see values()} when the output
     * must line up with {@see headers()}.
     */
    public function keyedValues($row, bool $raw = false): array
    {
        $out = [];
        foreach ($this->columns as $index => $col) {
            $out[$col->getName()] = $this->value($index, $col, $row, $raw);
        }

        return $out;
    }

    private function value(int $index, ColumnContainer $col, $row, bool $raw)
    {
        if (isset($this->relationColumns[$index])) {
            $this->attachPreloaded($this->relationColumns[$index], $row);
        }

        return $col->getValue($row, $raw);
    }

    private function attachPreloaded(string $name, $row): void
    {
        if (! isset($this->preloaded[$name]) || ! is_object($row)) {
            return;
        }

        $id    = spl_object_id($row);
        $entry = $this->preloaded[$name][$id] ?? null;
        if ($entry === null || $entry[0] !== $row) {
            return;
        }

        unset($this->preloaded[$name][$id]);

        if (! $row->relationLoaded($name)) {
            $row->setRelation($name, RelationPreloader::instanceFor($entry[1], $this->handedOut[$name]));
        }
    }
}
