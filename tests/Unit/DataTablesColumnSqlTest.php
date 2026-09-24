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
 * Closure, relation and accessor columns have none: querying a guessed name
 * ("_id", the relation's or the accessor's name) is an unknown-column error
 * on Postgres and MySQL,
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

    public function test_search_with_no_searchable_column_matches_nothing(): void
    {
        $response = $this->loaderRequest(['search' => ['value' => 'ap']], function (CRUDController $c) {
            $c->addColumn('Owner', 'owner.name');
            $c->addColumn('Shout', fn ($row) => strtoupper($row->name));
        });

        $this->assertSame([], $response['data']);
        $this->assertSame(0, $response['recordsFiltered']);
        $this->assertSame(4, $response['recordsTotal']);
    }

    public function test_numeric_columns_are_searchable(): void
    {
        $response = $this->loaderRequest([
            'search' => ['value' => '3'],
            'order'  => [['column' => 1, 'dir' => 'asc']],
        ], function (CRUDController $c) {
            $c->addColumn('Name', 'name');
            $c->addColumn('ID', 'id');
        });

        $this->assertSame(['banana'], array_column($response['data'], 0));
    }

    public function test_primary_key_ordering_is_qualified_for_joined_queries(): void
    {
        Schema::create('dcs_flags', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('widget_id');
        });
        foreach (DcsWidget::pluck('id') as $id) {
            DB::table('dcs_flags')->insert(['widget_id' => $id]);
        }

        $response = $this->loaderRequest(['join' => 1, 'order' => [['column' => 1, 'dir' => 'desc']]], function (CRUDController $c) {
            // setQuery() nests its closure in a where(), which drops joins;
            // a filter gets the query itself.
            $c->addHiddenFilter('join', fn ($request, $q) => $q->join('dcs_flags', 'dcs_flags.widget_id', '=', 'dcs_widgets.id'));
            $c->addColumn('Name', 'name');
            $c->addColumn('Shout', fn ($row) => strtoupper($row->name));
        });

        $this->assertSame(['apricot', 'banana', 'Apple', 'cherry'], array_column($response['data'], 0));
    }

    public function test_accessor_and_column_type_on_relation_are_not_queried(): void
    {
        KamvaCrud::addColumnType('dcs_badge', fn ($row, $col) => "[{$row->$col?->name}]");
        DB::enableQueryLog();

        $response = $this->loaderRequest([
            'search' => ['value' => 'ap'],
            'order'  => [['column' => 1, 'dir' => 'desc']],
        ], function (CRUDController $c) {
            $c->addColumn('Name', 'name');
            $c->addColumn('Label', 'label');              // accessor
            $c->addColumn('Owner', 'owner.dcs_badge');    // column type on a relation
        });

        // Only `name` is searched; the accessor column orders by the key.
        $this->assertSame(['apricot', 'Apple'], array_column($response['data'], 0));
        $this->assertSame(['#4 apricot', '#2 Apple'], array_column($response['data'], 1));
        $this->assertSame(['[Bob]', '[Bob]'], array_column($response['data'], 2));

        foreach (DB::getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/["`](label|owner)["`]/', $query['query']);
        }
    }

    public function test_list_view_gets_the_unsortable_column_indexes(): void
    {
        $controller = new class($this->app->make(Form::class)) extends CRUDController {
            public function setup(): void
            {
                $this->setModel(DcsWidget::class);
                $this->addColumn('Name', 'name');
                $this->addColumn('Owner', 'owner.name');
                $this->addColumn('Label', 'label');
                $this->addColumn('Shout', fn ($row) => strtoupper($row->name));
                $this->addColumn('Created', 'created_at');
            }
        };
        $controller->init();

        $request = Request::create('/dcs-widgets', 'GET');
        $this->app->instance('request', $request);

        // Row counter on: data columns start at DataTables index 1.
        $this->assertSame([2, 3, 4], $controller->index($request)->getData()['unsortableColumns']);

        $controller->disableRowCounter();
        $this->assertSame([1, 2, 3], $controller->index($request)->getData()['unsortableColumns']);
    }

    private function loaderRequest(array $params, ?\Closure $columns = null): array
    {
        $controller = new class($this->app->make(Form::class)) extends CRUDController {
            public ?\Closure $columns = null;

            public function setup(): void
            {
                $this->setModel(DcsWidget::class);
                $this->disableRowCounter();

                if ($this->columns) {
                    ($this->columns)($this);

                    return;
                }

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
        $controller->columns = $columns;
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

    public function getLabelAttribute()
    {
        return "#{$this->id} {$this->name}";
    }
}
