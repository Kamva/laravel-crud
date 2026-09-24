<?php

namespace Kamva\Crud;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/**
 * The export mirrors what the list shows, so text stays text. PhpSpreadsheet's
 * default binder turns any numeric-looking string into a number (dropping the
 * `+` of '+13208600048', and more than 15 digits, Excel's precision) and any
 * string starting with `=` into a formula.
 *
 * Only strings that are plain decimal numbers Excel holds exactly (such as
 * DECIMAL columns, which drivers return as strings) are still written as
 * numbers, so sums over them keep working.
 */
class CRUDExport implements FromArray, WithCustomValueBinder
{
    /** Excel keeps 15 significant digits. */
    private const EXCEL_DIGITS = 15;

    private $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function array(): array
    {
        return $this->data;
    }

    public function bindValue(Cell $cell, $value)
    {
        if ((is_string($value) && !$this->isPlainNumber($value)) || (is_int($value) && !$this->fitsExcel((string) $value))) {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

            return true;
        }

        return (new DefaultValueBinder())->bindValue($cell, $value);
    }

    /** '42', '-3.50', '0.5'; not '+1', '007', '1e5', ' 1' or '1,000'. */
    private function isPlainNumber(string $value): bool
    {
        return preg_match('/^-?(0|[1-9]\d*)(\.\d+)?$/D', $value) === 1 && $this->fitsExcel($value);
    }

    private function fitsExcel(string $number): bool
    {
        return strlen(ltrim(str_replace(['-', '.'], '', $number), '0')) <= self::EXCEL_DIGITS;
    }
}
