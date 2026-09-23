<?php

namespace Kamva\Crud\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Kamva\Crud\CRUDController;
use Kamva\Crud\Form;
use Kamva\Crud\Tests\TestCase;

/**
 * The DataTables order column index is untrusted request input. An
 * out-of-range or non-numeric value must fall back to a default order, not
 * raise an undefined-index error.
 */
class OrderInputTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('order_widgets', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->timestamps();
        });

        OrderWidget::create(['name' => 'a']);
        OrderWidget::create(['name' => 'b']);
    }

    public function test_out_of_range_order_column_falls_back(): void
    {
        $response = $this->loaderRequest(['column' => 999, 'dir' => 'asc']);
        $this->assertCount(2, $response['data']);
    }

    public function test_non_numeric_order_column_falls_back(): void
    {
        $response = $this->loaderRequest(['column' => 'name); DROP TABLE', 'dir' => 'asc']);
        $this->assertCount(2, $response['data']);
    }

    public function test_invalid_order_direction_falls_back_to_desc(): void
    {
        $response = $this->loaderRequest(['column' => 1, 'dir' => 'sideways']);
        $this->assertSame(['b', 'a'], array_column($response['data'], 1));
    }

    public function test_order_direction_is_case_insensitive(): void
    {
        $response = $this->loaderRequest(['column' => 1, 'dir' => 'ASC']);
        $this->assertSame(['a', 'b'], array_column($response['data'], 1));
    }

    private function loaderRequest(array $order): array
    {
        $form = $this->app->make(Form::class);
        $controller = new class($form) extends CRUDController {
            public function setup(): void
            {
                $this->setModel(OrderWidget::class);
                $this->addColumn('Name', 'name');
            }
        };
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

class OrderWidget extends Model
{
    protected $table = 'order_widgets';
    protected $guarded = [];
}
