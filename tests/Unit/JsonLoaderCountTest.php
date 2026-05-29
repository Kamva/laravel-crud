<?php

namespace Kamva\Crud\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kamva\Crud\CRUDController;
use Kamva\Crud\Form;
use Kamva\Crud\Tests\TestCase;

/**
 * The DataTables JSON loader must report record counts via SQL COUNT(*),
 * not by hydrating every matching row. These tests pin both the correctness
 * of the counts and that the count queries are aggregate (no full hydration).
 */
class JsonLoaderCountTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('loader_widgets', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->timestamps();
        });

        foreach (['apple', 'apricot', 'banana'] as $name) {
            LoaderWidget::create(['name' => $name]);
        }
    }

    public function test_counts_are_correct_with_and_without_search(): void
    {
        $response = $this->loaderRequest(['search' => ['value' => 'ap']]);

        $this->assertSame(3, $response['recordsTotal'], 'total ignores the search term');
        $this->assertSame(2, $response['recordsFiltered'], 'apple + apricot match "ap"');
    }

    public function test_count_uses_aggregate_query_not_full_hydration(): void
    {
        DB::enableQueryLog();
        $this->loaderRequest([]);

        $countQueries = array_filter(DB::getQueryLog(), function ($q) {
            return stripos($q['query'], 'count(*)') !== false;
        });

        $this->assertNotEmpty($countQueries, 'record counts must be computed with COUNT(*)');
    }

    private function loaderRequest(array $extra): array
    {
        $form = $this->app->make(Form::class);
        $controller = new class($form) extends CRUDController {
            public function setup(): void
            {
                $this->setModel(LoaderWidget::class);
                $this->addColumn('Name', 'name');
            }
        };
        $controller->init();

        $params = array_merge([
            'start'  => 0,
            'length' => 10,
            'draw'   => 1,
            'order'  => [['column' => 1, 'dir' => 'asc']],
        ], $extra);

        // wantsJson() (Accept: application/json), non-API path so isApi() is false.
        $request = Request::create('/widgets', 'GET', $params, [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $this->app->instance('request', $request);

        return $controller->index($request);
    }
}

class LoaderWidget extends Model
{
    protected $table = 'loader_widgets';
    protected $guarded = [];
}
