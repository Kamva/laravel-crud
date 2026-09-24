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
     * Eager-load the relations read by dotted columns (`'category.title'`)
     * for a page of rows, where that gives the same values as lazy loading.
     * See {@see RelationPreloader}.
     *
     * @param \Illuminate\Database\Eloquent\Collection|mixed $rows
     */
    public function preloadRelations($rows): void
    {
        $names = [];
        foreach ($this->columns as $col) {
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

            $names[$segments[0]] = $segments[0];
        }

        if ($names) {
            RelationPreloader::preload($rows, array_values($names));
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
        return array_map(fn (ColumnContainer $col) => $col->getValue($row, $raw), $this->columns);
    }

    /**
     * Column values for one record keyed by column title. Columns sharing a
     * title collapse to the last one, so use {@see values()} when the output
     * must line up with {@see headers()}.
     */
    public function keyedValues($row, bool $raw = false): array
    {
        $out = [];
        foreach ($this->columns as $col) {
            $out[$col->getName()] = $col->getValue($row, $raw);
        }

        return $out;
    }
}
