<?php

namespace Kamva\Crud\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Kamva\Crud\CRUDController;
use Kamva\Crud\Form;
use Kamva\Crud\Tests\TestCase;

/**
 * disableRowCounter() / hideCreateButton() toggles. Verifies the JSON loader
 * drops the counter cell and keeps column ordering aligned, and that the
 * setters are chainable and default to "on".
 */
class RowCounterAndCreateButtonTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('counter_widgets', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->timestamps();
        });

        CounterWidget::create(['name' => 'alpha']);
        CounterWidget::create(['name' => 'beta']);
    }

    public function test_counter_present_by_default_prepends_index_cell(): void
    {
        $response = $this->loaderRequest(false, ['column' => 1, 'dir' => 'asc']);

        // First cell is the 1-based counter, then the name column.
        $this->assertSame(1, $response['data'][0][0]);
        $this->assertSame('alpha', $response['data'][0][1]);
    }

    public function test_disable_row_counter_drops_the_index_cell(): void
    {
        // With the counter off, DataTables sorts the name column at index 0.
        $response = $this->loaderRequest(true, ['column' => 0, 'dir' => 'desc']);

        // First cell is now the name column (no counter), sorted desc.
        $this->assertSame('beta', $response['data'][0][0]);
        $this->assertSame('alpha', $response['data'][1][0]);
    }

    public function test_setters_are_chainable(): void
    {
        $controller = $this->controller(false);
        $this->assertSame($controller, $controller->disableRowCounter());
        $this->assertSame($controller, $controller->hideCreateButton());
    }

    private function controller(bool $disableCounter): CRUDController
    {
        $form = $this->app->make(Form::class);
        return new class($form, $disableCounter) extends CRUDController {
            private bool $disableCounter;
            public function __construct(Form $form, bool $disableCounter)
            {
                parent::__construct($form);
                $this->disableCounter = $disableCounter;
            }
            public function setup(): void
            {
                $this->setModel(CounterWidget::class);
                $this->addColumn('Name', 'name');
                if ($this->disableCounter) {
                    $this->disableRowCounter();
                }
            }
        };
    }

    private function loaderRequest(bool $disableCounter, array $order): array
    {
        $controller = $this->controller($disableCounter);
        $controller->init();

        $request = Request::create('/widgets', 'GET', [
            'start'  => 0,
            'length' => 10,
            'draw'   => 1,
            'order'  => [$order],
        ], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->app->instance('request', $request);

        return $controller->index($request);
    }
}

class CounterWidget extends Model
{
    protected $table = 'counter_widgets';
    protected $guarded = [];
}
