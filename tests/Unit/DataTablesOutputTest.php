<?php

namespace Kamva\Crud\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Kamva\Crud\CRUDController;
use Kamva\Crud\Form;
use Kamva\Crud\Tests\TestCase;

/**
 * Pins the full DataTables JSON payload (row counter, column values in
 * order, trailing actions cell, counts, draw) so the loader can be moved out
 * of CRUDController without changing what the list view receives.
 */
class DataTablesOutputTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('dt_widgets', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->timestamps();
        });

        foreach (['cherry', 'apple', 'banana', 'apricot'] as $name) {
            DtWidget::create(['name' => $name]);
        }
    }

    public function test_payload_with_row_counter_search_order_and_paging(): void
    {
        $response = $this->loaderRequest(true, [
            'start'  => 1,
            'length' => 1,
            'draw'   => '3',
            'search' => ['value' => 'ap'],
            'order'  => [['column' => 1, 'dir' => 'asc']],
        ]);

        $this->assertSame([
            'data'            => [[2, 'apricot', 'APRICOT', 'actions:apricot']],
            'recordsTotal'    => 4,
            'recordsFiltered' => 2,
            'draw'            => '3',
        ], $response);
    }

    public function test_payload_without_row_counter(): void
    {
        $response = $this->loaderRequest(false, [
            'start'  => 0,
            'length' => 2,
            'draw'   => '1',
            'order'  => [['column' => 0, 'dir' => 'desc']],
        ]);

        $this->assertSame([
            'data'            => [
                ['cherry', 'CHERRY', 'actions:cherry'],
                ['banana', 'BANANA', 'actions:banana'],
            ],
            'recordsTotal'    => 4,
            'recordsFiltered' => 4,
            'draw'            => '1',
        ], $response);
    }

    public function test_set_order_by_overrides_requested_order(): void
    {
        $response = $this->loaderRequest(true, [
            'start'  => 0,
            'length' => 10,
            'order'  => [['column' => 1, 'dir' => 'asc']],
        ], fn (CRUDController $c) => $c->setOrderBy('id', 'desc'));

        $this->assertSame(
            ['apricot', 'banana', 'apple', 'cherry'],
            array_column($response['data'], 1)
        );
    }

    private function loaderRequest(bool $rowCounter, array $params, ?\Closure $tweak = null): array
    {
        $controller = new class($this->app->make(Form::class)) extends CRUDController {
            public bool $counter = true;
            public ?\Closure $tweak = null;

            public function setup(): void
            {
                $this->setModel(DtWidget::class);
                $this->addColumn('Name', 'name');
                $this->addColumn('Shout', fn ($row) => strtoupper($row->name));

                if (! $this->counter) {
                    $this->disableRowCounter();
                }
                if ($this->tweak) {
                    ($this->tweak)($this);
                }
            }

            // Subclass overrides of this public method must keep being
            // honoured by the loader.
            public function getActionFieldForRow($row, $avoidGroup = false)
            {
                return 'actions:' . $row->name;
            }
        };
        $controller->counter = $rowCounter;
        $controller->tweak = $tweak;
        $controller->init();

        $request = Request::create('/dt-widgets', 'GET', $params, [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $this->app->instance('request', $request);

        return $controller->index($request);
    }
}

class DtWidget extends Model
{
    protected $table = 'dt_widgets';
    protected $guarded = [];
}
