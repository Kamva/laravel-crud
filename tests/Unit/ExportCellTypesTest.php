<?php

namespace Kamva\Crud\Tests\Unit;

use Kamva\Crud\CRUDExport;
use Kamva\Crud\Tests\TestCase;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\ExcelServiceProvider;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * The exported .xlsx must hold what the list shows: text stays text
 * (phone numbers, long codes, formula-looking input), while plain numbers
 * stay numeric so sums keep working (issue #32).
 */
class ExportCellTypesTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return array_merge(parent::getPackageProviders($app), [ExcelServiceProvider::class]);
    }

    public function test_cell_values_and_types(): void
    {
        $cells = [
            'phone'       => ['+13208600048', '+13208600048', DataType::TYPE_STRING],
            'long code'   => ['123456789012345678', '123456789012345678', DataType::TYPE_STRING],
            'leading 0'   => ['0912345678', '0912345678', DataType::TYPE_STRING],
            'formula'     => ['=HYPERLINK("http://x","y")', '=HYPERLINK("http://x","y")', DataType::TYPE_STRING],
            'error code'  => ['#N/A', '#N/A', DataType::TYPE_STRING],
            'exponent'    => ['1e5', '1e5', DataType::TYPE_STRING],
            'grouped'     => ['1,000 تومان', '1,000 تومان', DataType::TYPE_STRING],
            'decimal'     => ['12.50', 12.5, DataType::TYPE_NUMERIC],
            'negative'    => ['-3', -3, DataType::TYPE_NUMERIC],
            'zero point'  => ['0.5', 0.5, DataType::TYPE_NUMERIC],
            'int'         => [42, 42, DataType::TYPE_NUMERIC],
            'float'       => [1.5, 1.5, DataType::TYPE_NUMERIC],
            'big int'     => [1234567890123456789, '1234567890123456789', DataType::TYPE_STRING],
            'bool'        => [true, true, DataType::TYPE_BOOL],
        ];

        $sheet = $this->export([array_keys($cells), array_column($cells, 0)]);

        foreach (array_values($cells) as $i => [, $value, $type]) {
            $cell  = $sheet->getCell([$i + 1, 2]);
            $label = array_keys($cells)[$i];

            $this->assertSame($value, $cell->getValue(), $label);
            $this->assertSame($type, $cell->getDataType(), $label);
        }
    }

    public function test_empty_cells_stay_empty(): void
    {
        $sheet = $this->export([['a', 'b'], [null, '']]);

        $this->assertNull($sheet->getCell('A2')->getValue());
        $this->assertContains($sheet->getCell('B2')->getValue(), [null, '']);
    }

    private function export(array $rows)
    {
        $file = tempnam(sys_get_temp_dir(), 'kc-export') . '.xlsx';
        file_put_contents($file, Excel::raw(new CRUDExport($rows), ExcelType::XLSX));

        try {
            return IOFactory::load($file)->getActiveSheet();
        } finally {
            @unlink($file);
        }
    }
}
