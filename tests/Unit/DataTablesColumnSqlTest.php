<?php

namespace Kamva\Crud\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kamva\Crud\CRUDController;
use Kamva\Crud\Form;
use Kamva\Crud\KamvaCrud;
use Kamva\Crud\Tests\TestCase;

/**
 * The DataTables search and ordering must only reference real columns.
 * Closure and relation columns have none: querying a guessed name ("_id",
 * or the relation's name) is an unknown-column error on Postgres and MySQL,
 * while SQLite silently reads a double-quoted unknown name as a string, so
 * the SQL is checked here too.
 */
class DataTablesColumnSqlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('dcs_owners', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('dcs_widgets', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->unsignedInteger('owner_id');
            $table->timestamps();
        });

        $ann = DcsOwner::create(['name' => 'Ann']);
        $bob = DcsOwner::create(['name' => 'Bob']);

        foreach ([['cherry', $ann], ['Apple', $bob], ['banana', $ann], ['apricot', $bob]] as [$name, $owner]) {
            DcsWidget::create(['name' => $name, 'owner_id' => $owner->id]);
        }
    }

    public function test_search_skips_closure_and_relation_columns(): void
    {
        DB::enableQueryLog();

        $response = $this->loaderRequest([
            'search' => ['value' => 'ap'],
            'order'  => [['column' => 2, 'dir' => 'asc']],
        ]);

        $this->assertSame(['Apple', 'apricot'], array_column($response['data'], 0));
        $this->assertSame(2, $response['recordsFiltered']);

        foreach (DB::getQueryLog() as $query) {
            $this->assertStringNotContainsString('_id"::', $query['query']);
            $this->assertDoesNotMatchRegularExpression('/["`]_id["`]/', $query['query']);
            $this->assertDoesNotMatchRegularExpression('/["`]owner["`]/', $query['query']);
        }
    }

    public function test_search_is_case_insensitive(): void
    {
        $response = $this->loaderRequest([
            'search' => ['value' => 'AP'],
            'order'  => [['column' => 2, 'dir' => 'asc']],
        ]);

        $this->assertSame(['Apple', 'apricot'], array_column($response['data'], 0));
    }

    public function test_ordering_by_a_closure_column_orders_by_primary_key(): void
    {
        $response = $this->loaderRequest(['order' => [['column' => 2, 'dir' => 'desc']]]);

        $this->assertSame(['apricot', 'banana', 'Apple', 'cherry'], array_column($response['data'], 0));
    }

    public function test_ordering_by_a_relation_column_orders_by_primary_key(): void
    {
        $response = $this->loaderRequest(['order' => [['column' => 1, 'dir' => 'asc']]]);

        $this->assertSame(['cherry', 'Apple', 'banana', 'apricot'], array_column($response['data'], 0));
    }

    public function test_dotted_column_handled_by_a_column_type_still_uses_its_column(): void
    {
        KamvaCrud::addColumnType('dcs_upper', fn ($row, $col) => strtoupper($row->$col));

        $response = $this->loaderRequest([
            'search' => ['value' => 'an'],
            'order'  => [['column' => 3, 'dir' => 'asc']],
        ]);

        $this->assertSame(['BANANA'], array_column($response['data'], 3));
    }

    private function loaderRequest(array $params): array
    {
        $controller = new class($this->app->make(Form::class)) extends CRUDController {
            public function setup(): void
            {
                $this->setModel(DcsWidget::class);
                $this->disableRowCounter();
                $this->addColumn('Name', 'name');
                $this->addColumn('Owner', 'owner.name');
                $this->addColumn('Shout', fn ($row) => strtoupper($row->name));
                $this->addColumn('Upper', 'name.dcs_upper');
            }

            public function getActionFieldForRow($row, $avoidGroup = false)
            {
                return '';
            }
        };
        $controller->init();

        $request = Request::create('/dcs-widgets', 'GET', $params + ['start' => 0, 'length' => 10], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $this->app->instance('request', $request);

        return $controller->index($request);
    }
}

class DcsOwner extends Model
{
    protected $table = 'dcs_owners';
    protected $guarded = [];
}

class DcsWidget extends Model
{
    protected $table = 'dcs_widgets';
    protected $guarded = [];

    public function owner()
    {
        return $this->belongsTo(DcsOwner::class, 'owner_id');
    }
}
