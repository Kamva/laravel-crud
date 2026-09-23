<?php

namespace Kamva\Crud\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Kamva\Crud\CRUDController;
use Kamva\Crud\Form;
use Kamva\Crud\Tests\TestCase;
use Maatwebsite\Excel\Facades\Excel;

/**
 * `GET ?export=1` builds a header row plus one row per record. The header
 * row and the data rows must be derived from the same column list.
 */
class ExportTest extends TestCase
{
    private ?array $exported = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('jdate')) {
            // Host apps provide jdate(); the export filename only needs format().
            eval('function jdate() { return new \DateTime(); }');
        }

        Schema::create('export_widgets', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->timestamps();
        });

        ExportWidget::create(['name' => 'apple']);
        ExportWidget::create(['name' => 'banana']);

        Excel::swap(new class($this) {
            private $test;
            public function __construct($test) { $this->test = $test; }
            public function download($export, $fileName) { $this->test->capture($export->array()); return 'downloaded'; }
        });
    }

    public function capture(array $data): void
    {
        $this->exported = $data;
    }

    public function test_headers_default_to_list_columns_when_no_export_entities(): void
    {
        $this->export(function (CRUDController $c) {
            $c->addColumn('Name', 'name');
            $c->addColumn('Shout', fn ($row) => strtoupper($row->name));
        });

        $this->assertSame([
            ['Name', 'Shout'],
            ['apple', 'APPLE'],
            ['banana', 'BANANA'],
        ], $this->exported);
    }

    public function test_export_entities_replace_list_columns(): void
    {
        $this->export(function (CRUDController $c) {
            $c->addColumn('Name', 'name');
            $c->addExportEntity('Label', fn ($row) => '#' . $row->name);
        });

        $this->assertSame([
            ['Label'],
            ['#apple'],
            ['#banana'],
        ], $this->exported);
    }

    public function test_duplicate_titles_keep_rows_aligned_with_headers(): void
    {
        $this->export(function (CRUDController $c) {
            $c->addColumn('Name', 'name');
            $c->addColumn('Name', fn ($row) => strlen($row->name));
        });

        $this->assertSame([
            ['Name', 'Name'],
            ['apple', 5],
            ['banana', 6],
        ], $this->exported);
    }

    private function export(\Closure $columns): void
    {
        $controller = new class($this->app->make(Form::class)) extends CRUDController {
            public ?\Closure $columns = null;

            public function setup(): void
            {
                $this->setModel(ExportWidget::class);
                ($this->columns)($this);
            }
        };
        $controller->columns = $columns;
        $controller->init();

        $request = Request::create('/export-widgets', 'GET', ['export' => 1]);
        $this->app->instance('request', $request);

        $this->assertSame('downloaded', $controller->index($request));
    }
}

class ExportWidget extends Model
{
    protected $table = 'export_widgets';
    protected $guarded = [];
}
