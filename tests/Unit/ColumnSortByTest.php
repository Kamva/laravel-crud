<?php

namespace Kamva\Crud\Tests\Unit;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Kamva\Crud\CRUDController;
use Kamva\Crud\Form;
use Kamva\Crud\Tests\TestCase;

class ColumnSortByTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('sb_plans', function ($table) {
            $table->increments('id');
            $table->integer('credit');
        });
        Schema::create('sb_subs', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->unsignedInteger('plan_id');
            $table->integer('expires')->default(0);
            $table->timestamps();
        });

        $credits = [1 => 30, 2 => 10, 3 => 20];
        foreach ($credits as $id => $credit) {
            SbPlan::create(['id' => $id, 'credit' => $credit]);
        }

        // name, plan, expires
        foreach ([['a', 1, 2], ['b', 2, 2], ['c', 3, 1], ['d', 2, 1]] as $i => [$name, $plan, $expires]) {
            SbSub::create(['name' => $name, 'plan_id' => $plan, 'expires' => $expires, 'created_at' => now()->addMinutes($i)]);
        }
    }

    public function test_sort_by_string_expression_orders_a_closure_column(): void
    {
        $credit = '(select credit from sb_plans where sb_plans.id = sb_subs.plan_id)';

        $asc = $this->names(['order' => [['column' => 1, 'dir' => 'asc']]], fn ($c) => $c->addColumn('Credit', fn ($r) => $r->plan_id)->sortBy($credit));
        $desc = $this->names(['order' => [['column' => 1, 'dir' => 'desc']]], fn ($c) => $c->addColumn('Credit', fn ($r) => $r->plan_id)->sortBy($credit));

        $this->assertSame(['b', 'd', 'c', 'a'], $asc);
        $this->assertSame(['a', 'c', 'b', 'd'], $desc);
    }

    public function test_sort_by_plain_name_is_quoted_as_a_column(): void
    {
        $names = $this->names(['order' => [['column' => 1, 'dir' => 'desc']]], fn ($c) => $c->addColumn('Shout', fn ($r) => strtoupper($r->name))->sortBy('name'));

        $this->assertSame(['d', 'c', 'b', 'a'], $names);
    }

    public function test_sort_by_closure_receives_query_and_direction(): void
    {
        $seen = [];
        $names = $this->names(['order' => [['column' => 1, 'dir' => 'asc']]], function ($c) use (&$seen) {
            $c->addColumn('Plan', fn ($r) => $r->plan_id)->sortBy(function (Builder $q, string $dir) use (&$seen) {
                $seen = [get_class($q), $dir];
                $q->orderBy('plan_id', $dir)->orderBy('name', 'desc');
            });
        });

        $this->assertSame([Builder::class, 'asc'], $seen);
        $this->assertSame(['a', 'd', 'b', 'c'], $names);
    }

    public function test_invalid_direction_reaches_sort_by_as_desc(): void
    {
        $seen = null;
        $this->names(['order' => [['column' => 1, 'dir' => 'asc; drop table x']]], function ($c) use (&$seen) {
            $c->addColumn('Plan', fn ($r) => $r->plan_id)->sortBy(function (Builder $q, string $dir) use (&$seen) {
                $seen = $dir;
                $q->orderBy('plan_id', $dir);
            });
        });

        $this->assertSame('desc', $seen);
    }

    public function test_primary_key_is_the_final_tie_breaker_in_both_directions(): void
    {
        $queries = [];
        \DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });

        foreach (['asc', 'desc'] as $dir) {
            $this->names(['by_name' => 1, 'order' => [['column' => 1, 'dir' => $dir]]], function ($c) {
                $c->addHiddenFilter('by_name', fn ($request, $q) => $q->orderBy('name', 'desc'));
                $c->addColumn('Expires', 'expires');
            });
        }

        $this->assertCount(2, array_filter($queries, fn ($sql) => preg_match('/order by .*"expires" (asc|desc), "name" desc, "sb_subs"\."id" asc/i', $sql) && str_contains($sql, 'limit')));
    }

    public function test_user_order_comes_before_a_filters_order(): void
    {
        $names = $this->names(['by_expires' => 1, 'order' => [['column' => 1, 'dir' => 'desc']]], function ($c) {
            $c->addHiddenFilter('by_expires', fn ($request, $q) => $q->orderBy('expires', 'asc'));
            $c->addColumn('Name', 'name');
        });

        $this->assertSame(['d', 'c', 'b', 'a'], $names);
    }

    public function test_union_orders_of_a_filter_are_kept_as_tie_breaker(): void
    {
        $names = $this->names(['by_union' => 1, 'order' => [['column' => 1, 'dir' => 'asc']]], function ($c) {
            $c->addHiddenFilter('by_union', fn ($request, $q) => $q->union(SbSub::query()->where('id', 0)->toBase())->orderBy('expires', 'asc'));
            $c->addColumn('Plan', fn ($r) => $r->plan_id)->sortBy('plan_id');
        });

        $this->assertSame(['a', 'd', 'b', 'c'], $names);
    }

    public function test_filter_order_is_kept_as_tie_breaker_with_its_bindings(): void
    {
        $names = $this->names(['by_expires' => 1, 'order' => [['column' => 1, 'dir' => 'asc']]], function ($c) {
            $c->addHiddenFilter('by_expires', fn ($request, $q) => $q->orderByRaw('expires * ? desc, name asc', [1]));
            $c->addColumn('Plan', fn ($r) => $r->plan_id)->sortBy('plan_id');
        });

        $this->assertSame(['a', 'b', 'd', 'c'], $names);
    }

    public function test_fallback_keeps_filter_order_and_defaults_to_newest_first(): void
    {
        $filtered = $this->names(['by_expires' => 1, 'order' => [['column' => 99, 'dir' => 'asc']]], function ($c) {
            $c->addHiddenFilter('by_expires', fn ($request, $q) => $q->orderBy('expires', 'asc')->orderBy('name', 'asc'));
            $c->addColumn('Name', 'name');
        });
        $plain = $this->names(['order' => [['column' => 99, 'dir' => 'asc']]], fn ($c) => $c->addColumn('Name', 'name'));

        $this->assertSame(['c', 'd', 'a', 'b'], $filtered);
        $this->assertSame(['d', 'c', 'b', 'a'], $plain);
    }

    public function test_unsortable_columns_exclude_sort_by_columns(): void
    {
        $controller = $this->controller(function ($c) {
            $c->addColumn('Name', 'name');
            $c->addColumn('Plain closure', fn ($r) => 1);
            $c->addColumn('Sortable closure', fn ($r) => 1)->sortBy('plan_id');
        });

        $request = Request::create('/sb', 'GET');
        $this->app->instance('request', $request);

        $this->assertSame([2], $controller->index($request)->getData()['unsortableColumns']);
    }

    public function test_fixed_order_still_wins_over_sort_by(): void
    {
        $names = $this->names(['order' => [['column' => 1, 'dir' => 'asc']]], function ($c) {
            $c->setOrderBy('name', 'desc');
            $c->addColumn('Plan', fn ($r) => $r->plan_id)->sortBy('plan_id');
        });

        $this->assertSame(['d', 'c', 'b', 'a'], $names);
    }

    private function names(array $params, \Closure $columns): array
    {
        $controller = $this->controller($columns);

        $request = Request::create('/sb', 'GET', $params + ['start' => 0, 'length' => 10], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $this->app->instance('request', $request);

        $rows = $controller->index($request)['data'];

        return array_map(fn ($row) => SbSub::find($row[0])->name, $rows);
    }

    private function controller(\Closure $columns): CRUDController
    {
        $controller = new class($this->app->make(Form::class)) extends CRUDController {
            public ?\Closure $columns = null;

            public function setup(): void
            {
                $this->setModel(SbSub::class);
                $this->disableRowCounter();
                $this->addColumn('Id', 'id');
                ($this->columns)($this);
            }

            public function getActionFieldForRow($row, $avoidGroup = false)
            {
                return '';
            }
        };
        $controller->columns = $columns;
        $controller->init();

        return $controller;
    }
}

class SbPlan extends Model
{
    protected $table = 'sb_plans';
    public $timestamps = false;
    protected $guarded = [];
}

class SbSub extends Model
{
    protected $table = 'sb_subs';
    protected $guarded = [];
}
