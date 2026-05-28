<?php

namespace Kamva\Crud\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Kamva\Crud\CRUDController;
use Kamva\Crud\Form;
use Kamva\Crud\Tests\TestCase;

/**
 * The API list size must come from config, not env() at runtime. Under
 * `php artisan config:cache`, env() returns null, which previously made the
 * paginator run paginate(0) and divide by zero. These tests pin the config
 * source and the non-positive fallback.
 */
class PaginateSizeConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('paginate_widgets', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->timestamps();
        });

        foreach (range(1, 5) as $i) {
            PaginateWidget::create(['name' => "w{$i}"]);
        }
    }

    public function test_default_config_value_is_used(): void
    {
        $this->assertSame(15, (int) config('kamva-crud.paginate_size'));
    }

    public function test_api_list_uses_configured_page_size(): void
    {
        config(['kamva-crud.paginate_size' => 2]);

        $response = $this->apiIndex();

        $this->assertSame(2, $response['per_page']);
        $this->assertCount(2, $response['data']);
        $this->assertSame(5, $response['total']);
    }

    public function test_non_positive_config_falls_back_instead_of_dividing_by_zero(): void
    {
        config(['kamva-crud.paginate_size' => 0]);

        // Must not throw a DivisionByZeroError.
        $response = $this->apiIndex();

        $this->assertSame(15, $response['per_page']);
        $this->assertSame(5, $response['total']);
    }

    private function apiIndex(): array
    {
        $form = $this->app->make(Form::class);
        $controller = new class($form) extends CRUDController {
            public function setup(): void
            {
                $this->setModel(PaginateWidget::class);
                $this->addApiEntity('name');
            }
        };
        $controller->init();

        // isApi() keys off the request path beginning with "api".
        $request = Request::create('/api/widgets', 'GET');
        $this->app->instance('request', $request);

        $json = $controller->index($request);

        return json_decode($json->getContent(), true);
    }
}

class PaginateWidget extends Model
{
    protected $table = 'paginate_widgets';
    protected $guarded = [];
}
