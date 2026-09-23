<?php

namespace Kamva\Crud\Columns;

use Kamva\Crud\Containers\ColumnContainer;

/**
 * An ordered list of {@see ColumnContainer}s and the one place that turns a
 * record into a row of column values. The list view (DataTables), the API
 * and the Excel export all serialize through here, so a header row and its
 * data rows are always built from the same columns in the same order.
 */
final class ColumnSet
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

    public function count(): int
    {
        return count($this->columns);
    }

    public function at(int $index): ?ColumnContainer
    {
        return $this->columns[$index] ?? null;
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
