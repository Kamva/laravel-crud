<?php

namespace Kamva\Crud\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kamva\Crud\CRUDController;
use Kamva\Crud\Form;
use Kamva\Crud\KamvaCrud;
use Kamva\Crud\Tests\Stubs\StubTextField;
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

    public function test_fixed_order_makes_every_column_unsortable(): void
    {
        $controller = new class($this->app->make(Form::class)) extends CRUDController {
            public function setup(): void
            {
                $this->setModel(DcsWidget::class);
                $this->setOrderBy('id', 'desc');
                $this->addColumn('Name', 'name');
                $this->addColumn('Shout', fn ($row) => strtoupper($row->name));
            }
        };
        $controller->init();

        $request = Request::create('/dcs-widgets', 'GET');
        $this->app->instance('request', $request);

        $this->assertSame([1, 2], $controller->index($request)->getData()['unsortableColumns']);
    }

    public function test_column_names_match_case_as_the_driver_does(): void
    {
        DB::enableQueryLog();

        // `Name` is resolved by the model (getNameAttribute), so it is checked
        // against the table: SQLite and MySQL match `name` whatever the case,
        // Postgres only matches the exact quoted name.
        $response = $this->loaderRequest([
            'search' => ['value' => 'ap'],
            'order'  => [['column' => 0, 'dir' => 'asc']],
        ], fn (CRUDController $c) => $c->addColumn('Name', 'Name'));

        $queried = collect(DB::getQueryLog())->contains(fn ($q) => str_contains($q['query'], '"Name"'));

        if (DB::getDriverName() === 'pgsql') {
            $this->assertFalse($queried);
            $this->assertSame([], $response['data']);
        } else {
            $this->assertTrue($queried);
            $this->assertCount(2, $response['data']);
        }
    }

    public function test_a_failed_schema_listing_is_tried_again(): void
    {
        config(['database.connections.dcs_later' => ['driver' => 'sqlite', 'database' => '/nonexistent/dir/db.sqlite', 'prefix' => '']]);
        $model = (new DcsWidget())->setConnection('dcs_later');
        $columns = $this->app->make(\Kamva\Crud\Listing\TableColumns::class);

        $this->assertNull($columns->has($model, 'name'));

        config(['database.connections.dcs_later.database' => ':memory:']);
        DB::purge('dcs_later');
        Schema::connection('dcs_later')->create('dcs_widgets', fn ($table) => $table->string('name'));

        $this->assertTrue($columns->has($model, 'name'));
        $this->assertFalse($columns->has($model, 'label'));
    }

    public function test_skipped_field_column_without_a_table_column_is_not_queried(): void
    {
        DB::enableQueryLog();

        $response = $this->loaderRequest([
            'search' => ['value' => 'ap'],
            'order'  => [['column' => 1, 'dir' => 'desc']],
        ], function (CRUDController $c) {
            // Saved by its own callback: no `nickname` column.
            $c->addField(StubTextField::class, 'Nickname', 'nickname')->skip();
            $c->addColumn('Name', 'name');
            $c->addColumn('Nickname', 'nickname.field');
        });

        $this->assertSame(['apricot', 'Apple'], array_column($response['data'], 0));

        foreach (DB::getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/["`]nickname["`]/', $query['query']);
        }
    }

    public function test_skipped_field_over_a_table_column_is_still_queried(): void
    {
        DB::enableQueryLog();

        $response = $this->loaderRequest([
            'search' => ['value' => '2'],
            'order'  => [['column' => 1, 'dir' => 'desc']],
        ], function (CRUDController $c) {
            $c->addField(StubTextField::class, 'Owner', 'owner_id')->skip();
            $c->addColumn('Owner', 'owner_id.field');
            $c->addColumn('Name', 'name');
        });

        $this->assertSame(['apricot', 'Apple'], array_column($response['data'], 1));
        $this->assertTrue(collect(DB::getQueryLog())->contains(fn ($q) => str_contains($q['query'], '"owner_id"')));
    }

    public function test_field_columns_that_arent_skipped_dont_list_the_schema(): void
    {
        DB::enableQueryLog();

        $this->loaderRequest([
            'search' => ['value' => '2'],
            'order'  => [['column' => 0, 'dir' => 'asc']],
        ], function (CRUDController $c) {
            $c->addField(StubTextField::class, 'Owner', 'owner_id');
            $c->addColumn('Owner', 'owner_id.field');
        });

        $this->assertSame([], array_values(array_filter(
            array_column(DB::getQueryLog(), 'query'),
            fn ($sql) => !preg_match('/from ["`]dcs_widgets["`]/', $sql)
        )));
    }

    public function test_search_term_wildcards_match_literally(): void
    {
        foreach (['snake_case', 'snakeXcase', 'fifty%', 'fiftyX', 'back\\slash', 'backslash', 'bang!', 'bang'] as $name) {
            DcsWidget::create(['name' => $name, 'owner_id' => 1]);
        }

        foreach (['_' => 'snake_case', '%' => 'fifty%', '\\' => 'back\\slash', '!' => 'bang!', 'e_c' => 'snake_case'] as $term => $match) {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $response = $this->loaderRequest(['search' => ['value' => $term]], function (CRUDController $c) {
                $c->addColumn('Name', 'name');
            });

            $this->assertSame([$match], array_column($response['data'], 0));
            $this->assertContains('%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%', array_merge(...array_column(DB::getQueryLog(), 'bindings')));
            $this->assertMatchesRegularExpression("/[\"`]name[\"`](::text)? i?like \\? escape '!'/", implode("\n", array_column(DB::getQueryLog(), 'query')));
        }
    }

    public function test_accessors_have_no_stored_field_where_columns_cant_be_listed(): void
    {
        // What TableColumns reports on MongoDB: columns unknown.
        $this->app->instance(\Kamva\Crud\Listing\TableColumns::class, new class {
            public function has($model, $column): ?bool
            {
                return null;
            }
        });

        $columns = function (CRUDController $c) {
            $c->setModel(DcsSchemalessWidget::class);
            $c->addColumn('Name', 'name');          // plain attribute: kept
            $c->addColumn('Label', 'label');        // accessor: no stored field
            $c->addColumn('Owner', 'owner.name');   // relation
            $c->addColumn('Owner id', 'owner_id');  // stored, but has an accessor
        };

        DB::enableQueryLog();
        $response = $this->loaderRequest([
            'search' => ['value' => 'ap'],
            'order'  => [['column' => 1, 'dir' => 'desc']],
        ], $columns);

        // Searched by name only; sorting by the accessor orders by the key.
        $this->assertSame(['apricot', 'Apple'], array_column($response['data'], 0));
        foreach (DB::getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/["`](label|owner_id)["`] like/i', $query['query']);
        }

        $controller = new class($this->app->make(Form::class)) extends CRUDController {
            public ?\Closure $columns = null;

            public function setup(): void
            {
                $this->disableRowCounter();
                ($this->columns)($this);
            }
        };
        $controller->columns = $columns;
        $controller->init();

        $request = Request::create('/dcs-widgets', 'GET');
        $this->app->instance('request', $request);

        // The trade-off: a stored field that also has an accessor of the same
        // name (owner_id) can't be told apart there, so it isn't queried.
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

    public function getNameAttribute($value)
    {
        return $value;
    }

    public function getLabelAttribute()
    {
        return "#{$this->id} {$this->name}";
    }
}

class DcsSchemalessWidget extends Model
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

    public function getOwnerIdAttribute($value)
    {
        return $value;
    }
}
