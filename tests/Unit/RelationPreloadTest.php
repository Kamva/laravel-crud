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
use Maatwebsite\Excel\Facades\Excel;

/**
 * Dotted relation columns (`'owner.name'`) are eager-loaded for a page of
 * rows, but only where that yields exactly the values lazy loading did.
 */
class RelationPreloadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('rp_owners', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('currency')->nullable();
            $table->string('code')->collation('NOCASE')->nullable();
            $table->timestamps();
        });
        Schema::create('rp_items', function ($table) {
            $table->increments('id');
            $table->string('title');
            $table->unsignedInteger('owner_id')->nullable();
            $table->string('currency')->nullable();
            $table->string('kind')->nullable();
            $table->string('label')->nullable();
            $table->string('owner_code')->nullable();
            $table->string('owner_ref')->nullable();
            $table->timestamps();
        });

        Schema::create('rp_notes', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('item_id');
            $table->string('body');
            $table->timestamps();
        });

        foreach ([['Ann', 'EUR', 'ann'], ['Bob', 'USD', 'bob'], ['Cid', null, 'cid']] as [$name, $currency, $code]) {
            RpOwner::create(['name' => $name, 'currency' => $currency, 'code' => $code]);
        }
        foreach (range(1, 12) as $i) {
            RpItem::create([
                'title'    => "Item {$i}",
                'owner_id' => $i === 12 ? null : ($i % 3) + 1,
                'currency' => ['EUR', 'USD', null][$i % 3],
                'kind'     => $i % 2 ? 'owner' : 'other',
                'label'    => "label-{$i}",
                // Mixed case: matches the lowercase codes only case-insensitively.
                'owner_code' => [null, 'ann', 'BOB', 'Cid'][$i % 4],
                // Zero-padded: matches the integer ids only after type coercion.
                'owner_ref'  => $i % 2 ? '00' . (($i % 3) + 1) : (string) (($i % 3) + 1),
            ]);
        }
    }

    public function test_relation_column_is_eager_loaded_with_identical_values(): void
    {
        [$data, $queries] = $this->draw(fn (CRUDController $c) => $c->addColumn('Owner', 'owner.name'));

        $this->assertSame($this->lazyValues(fn ($item) => $item->owner?->name), array_column($data, 1));
        // rows + counts + one eager query, independent of the number of rows.
        $this->assertLessThanOrEqual(4, $queries);
    }

    public function test_null_foreign_key_and_with_default_match_lazy_loading(): void
    {
        [$data] = $this->draw(fn (CRUDController $c) => $c->addColumn('Owner', 'ownerOrDefault.name'));

        $this->assertSame($this->lazyValues(fn ($item) => $item->ownerOrDefault->name), array_column($data, 1));
        $this->assertContains('Nobody', array_column($data, 1));
    }

    public function test_defaults_built_from_the_row_match_lazy_loading(): void
    {
        foreach (['ownerWithRowDefault', 'ownerWithThisClosureDefault', 'ownerWithParentClosureDefault'] as $relation) {
            [$data] = $this->draw(fn (CRUDController $c) => $c->addColumn('Owner', "{$relation}.name"));

            $expected = $this->lazyValues(fn ($item) => $item->$relation->name);
            $this->assertSame($expected, array_column($data, 1), $relation);
            $this->assertContains('fallback for Item 12', $expected, $relation);
        }
    }

    public function test_row_dependent_relation_is_not_eager_loaded(): void
    {
        // Eager loading builds this relation from a blank model (currency
        // null), which would return no owners at all.
        [$data, $queries] = $this->draw(fn (CRUDController $c) => $c->addColumn('Owner', 'sameCurrencyOwner.name'));

        $expected = $this->lazyValues(fn ($item) => $item->sameCurrencyOwner?->name);
        $this->assertSame($expected, array_column($data, 1));
        $this->assertNotEmpty(array_filter($expected));
        $this->assertGreaterThan(10, $queries, 'must keep loading per row');
    }

    public function test_relation_chosen_by_row_value_is_not_eager_loaded(): void
    {
        [$data] = $this->draw(fn (CRUDController $c) => $c->addColumn('Parent', 'parentByKind.name'));

        $this->assertSame($this->lazyValues(fn ($item) => $item->parentByKind?->name), array_column($data, 1));
    }

    public function test_attribute_with_the_relation_name_is_left_alone(): void
    {
        // `label` is both a column and a method. `$row->label` reads the
        // attribute, so the method must never be called to preload it.
        KamvaCrud::addColumnType('upper', fn ($data, $col) => strtoupper($data->$col));
        RpItem::$labelCalls = 0;

        [$data] = $this->draw(fn (CRUDController $c) => $c->addColumn('Label', 'label.upper'));

        $this->assertSame(0, RpItem::$labelCalls);
        $this->assertSame($this->lazyValues(fn ($item) => strtoupper($item->label)), array_column($data, 1));
    }

    public function test_to_many_relations_are_not_preloaded(): void
    {
        KamvaCrud::addColumnType('total', fn ($data, $col) => 'n' . $data->$col->count());

        [$data, $queries] = $this->draw(fn (CRUDController $c) => $c->addColumn('Siblings', 'siblings.total'));

        $this->assertSame($this->lazyValues(fn ($item) => 'n' . $item->siblings->count()), array_column($data, 1));
        $this->assertGreaterThan(10, $queries, 'hasMany keeps loading per row');
    }

    public function test_has_one_is_eager_loaded_when_each_row_has_one_match(): void
    {
        foreach (range(1, 8) as $id) {
            RpNote::create(['item_id' => $id, 'body' => "note {$id}"]);
        }

        [$data, $queries] = $this->draw(fn (CRUDController $c) => $c->addColumn('Note', 'note.body'));

        $this->assertSame($this->lazyValues(fn ($item) => $item->note?->body), array_column($data, 1));
        $this->assertLessThanOrEqual(4, $queries);
    }

    public function test_has_one_with_several_matches_keeps_lazy_loading(): void
    {
        foreach (range(1, 8) as $id) {
            RpNote::create(['item_id' => $id, 'body' => "note {$id}"]);
        }
        RpNote::create(['item_id' => 3, 'body' => 'second note for 3']);

        [$data, $queries] = $this->draw(fn (CRUDController $c) => $c->addColumn('Note', 'note.body'));

        $this->assertSame($this->lazyValues(fn ($item) => $item->note?->body), array_column($data, 1));
        $this->assertGreaterThan(10, $queries, 'ambiguous data must not be eager-loaded');
    }

    public function test_each_row_gets_its_own_related_instance(): void
    {
        // Rows 1, 4, 7 and 10 share owner 2. A renderer that changes the
        // related model must only affect its own row, as with lazy loading.
        [$data] = $this->draw(fn (CRUDController $c) => $c->addColumn('Owner', function ($row) {
            return $row->owner ? ($row->owner->name .= '!') : null;
        }));
        [$eager] = $this->draw(function (CRUDController $c) {
            $c->addColumn('Preload', 'owner.name');
            $c->addColumn('Owner', function ($row) {
                return $row->owner ? ($row->owner->name .= '!') : null;
            });
        });

        $this->assertSame(array_column($data, 1), array_column($eager, 2));
        $this->assertNotContains('Bob!!', array_column($eager, 2));
    }

    public function test_custom_column_types_are_not_preloaded(): void
    {
        KamvaCrud::addColumnType('shout', fn ($data, $col) => strtoupper((string) $data->$col?->name));

        [$data, $queries] = $this->draw(fn (CRUDController $c) => $c->addColumn('Owner', 'owner.shout'));

        $this->assertSame($this->lazyValues(fn ($item) => strtoupper((string) $item->owner?->name) ?: null), array_column($data, 1));
        $this->assertGreaterThan(10, $queries, 'the column type decides what it loads');
    }

    public function test_keys_the_database_compares_differently_match_lazy_loading(): void
    {
        foreach (['ownerByCode', 'ownerByRef'] as $relation) {
            [$data] = $this->draw(fn (CRUDController $c) => $c->addColumn('Owner', "{$relation}.name"));

            $expected = $this->lazyValues(fn ($item) => $item->$relation?->name);
            $this->assertSame($expected, array_column($data, 1), $relation);
            $this->assertGreaterThan(6, count(array_filter($expected)), "{$relation}: lazy loading finds these");
        }
    }

    public function test_uppercase_related_keys_are_not_preloaded(): void
    {
        RpOwner::where('name', 'Cid')->update(['code' => 'CID']);

        [$data, $queries] = $this->draw(fn (CRUDController $c) => $c->addColumn('Owner', 'ownerByCode.name'));

        $this->assertSame($this->lazyValues(fn ($item) => $item->ownerByCode?->name), array_column($data, 1));
        $this->assertGreaterThan(8, $queries);
    }

    public function test_relation_is_attached_when_its_column_is_evaluated(): void
    {
        $probe = fn ($row) => ($row->relationLoaded('owner') ? 'loaded' : 'not loaded')
            . '|' . (array_key_exists('owner', $row->toArray()) ? 'in array' : 'not in array');

        [$data] = $this->draw(function (CRUDController $c) use ($probe) {
            $c->addColumn('Before', $probe);
            $c->addColumn('Owner', 'owner.name');
            $c->addColumn('After', $probe);
        });

        $withOwner = array_filter($data, fn ($row) => $row[2] !== null);
        $this->assertSame(['not loaded|not in array'], array_values(array_unique(array_column($data, 1))));
        $this->assertSame(['loaded|in array'], array_values(array_unique(array_column($withOwner, 3))));
    }

    public function test_retrieved_events_fire_once_per_row_like_lazy_loading(): void
    {
        $count = 0;
        RpOwner::retrieved(function () use (&$count) {
            $count++;
        });

        $this->lazyValues(fn ($item) => $item->owner?->name);
        $lazy  = $count;
        $count = 0;

        $this->draw(fn (CRUDController $c) => $c->addColumn('Owner', 'owner.name'));

        $this->assertSame($lazy, $count);
    }

    public function test_rows_get_independent_instances_hydrated_from_the_database(): void
    {
        [$data] = $this->draw(function (CRUDController $c) {
            $c->addColumn('Owner', 'owner.name');
            $c->addColumn('Same', function ($row) {
                static $seen = [];
                $id = $row->owner ? spl_object_id($row->owner) : null;
                $dup = $id !== null && isset($seen[$id]);
                $seen[$id] = true;

                return $dup ? 'shared' : 'own';
            });
        });

        $this->assertNotContains('shared', array_column($data, 2));
    }

    public function test_relations_with_nested_eager_loads_or_an_inverse_stay_lazy(): void
    {
        foreach (range(1, 12) as $id) {
            RpNote::create(['item_id' => $id, 'body' => "note {$id}"]);
        }

        foreach (['noteWithInverse', 'noteWithNestedEagerLoad'] as $relation) {
            [$data, $queries] = $this->draw(fn (CRUDController $c) => $c->addColumn('Note', "{$relation}.body"));

            $this->assertSame($this->lazyValues(fn ($item) => $item->$relation?->body), array_column($data, 1), $relation);
            $this->assertGreaterThan(10, $queries, $relation);
        }
    }

    public function test_large_pages_are_loaded_in_chunks(): void
    {
        foreach (range(13, 2600) as $i) {
            RpItem::create(['title' => "Item {$i}", 'owner_code' => ['ann', 'bob', 'cid'][$i % 3]]);
        }

        [$data, $queries] = $this->draw(fn (CRUDController $c) => $c->addColumn('Owner', 'ownerByCode.name'), 3000);

        $this->assertCount(2600, $data);
        $this->assertSame($this->lazyValues(fn ($item) => $item->ownerByCode?->name), array_column($data, 1));
        // rows + 2 counts + 3 chunks, plus lazy loads for the 9 mixed-case rows.
        $this->assertLessThanOrEqual(3 + 3 + 9, $queries);
    }

    public function test_api_index_preloads_and_matches_lazy_values(): void
    {
        $controller = $this->controller(fn (CRUDController $c) => $c->addApiEntity('owner', 'owner.name'));
        $request    = Request::create('/api/items', 'GET');
        $this->app->instance('request', $request);

        $queries = $this->countQueries(function () use ($controller, $request, &$payload) {
            $payload = $controller->index($request)->getData(true)['data'];
        });

        $expected = RpItem::query()->orderBy('created_at', 'desc')->get()->map(fn ($i) => $i->owner?->name)->all();
        $this->assertSame($expected, array_column($payload, 'owner'));
        $this->assertLessThanOrEqual(3, $queries);
    }

    public function test_export_preloads_and_keeps_the_page_window(): void
    {
        if (! function_exists('jdate')) {
            eval('function jdate() { return new \DateTime(); }');
        }
        $captured = null;
        Excel::swap(new class($captured) {
            private $captured;
            public function __construct(&$captured) { $this->captured = &$captured; }
            public function download($export, $name) { $this->captured = $export->array(); return 'ok'; }
        });

        $controller = $this->controller(fn (CRUDController $c) => $c->addColumn('Owner', 'owner.name'));

        foreach ([['export' => 1], ['export' => 1, 'page' => 'abc'], ['export' => 1, 'page' => 2]] as $params) {
            $request = Request::create('/items', 'GET', $params);
            $this->app->instance('request', $request);
            $queries = $this->countQueries(fn () => $controller->index($request));

            $expected = ($params['page'] ?? 1) === 2
                ? []
                : RpItem::query()->orderBy('created_at')->get()->map(fn ($i) => [$i->id, $i->owner?->name])->all();

            $this->assertSame(array_merge([['Id', 'Owner']], $expected), $captured);
            $this->assertLessThanOrEqual(2, $queries);
        }
    }

    private function draw(\Closure $columns, int $length = 50): array
    {
        $controller = $this->controller($columns);
        $request    = Request::create('/items', 'GET', [
            'start' => 0, 'length' => $length, 'draw' => '1',
            'order' => [['column' => 0, 'dir' => 'asc']],
        ], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->app->instance('request', $request);

        $queries = $this->countQueries(function () use ($controller, $request, &$response) {
            $response = $controller->index($request);
        });

        return [$response['data'], $queries];
    }

    private function controller(\Closure $columns): CRUDController
    {
        $controller = new class($this->app->make(Form::class)) extends CRUDController {
            public ?\Closure $columns = null;

            public function setup(): void
            {
                $this->setModel(RpItem::class);
                $this->disableRowCounter();
                $this->addColumn('Id', 'id');
                ($this->columns)($this);
            }
        };
        $controller->columns = $columns;
        $controller->init();

        return $controller;
    }

    /** Values computed with plain lazy loading, in the list's order (id asc). */
    private function lazyValues(\Closure $value): array
    {
        return RpItem::query()->orderBy('id')->get()->map($value)->all();
    }

    private function countQueries(\Closure $run): int
    {
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });
        $run();

        return $count;
    }
}

class RpNote extends Model
{
    protected $table = 'rp_notes';
    protected $guarded = [];

    public function item()
    {
        return $this->belongsTo(RpItem::class, 'item_id');
    }
}

class RpNoteWithItem extends Model
{
    protected $table = 'rp_notes';
    protected $guarded = [];
    protected $with = ['item'];

    public function item()
    {
        return $this->belongsTo(RpItem::class, 'item_id');
    }
}

class RpOwner extends Model
{
    protected $table = 'rp_owners';
    protected $guarded = [];
}

class RpItem extends Model
{
    public static int $labelCalls = 0;

    protected $table = 'rp_items';
    protected $guarded = [];

    public function owner()
    {
        return $this->belongsTo(RpOwner::class, 'owner_id');
    }

    public function ownerOrDefault()
    {
        return $this->belongsTo(RpOwner::class, 'owner_id')->withDefault(['name' => 'Nobody']);
    }

    public function ownerWithRowDefault()
    {
        return $this->belongsTo(RpOwner::class, 'owner_id')->withDefault(['name' => 'fallback for ' . $this->title]);
    }

    public function ownerWithThisClosureDefault()
    {
        return $this->belongsTo(RpOwner::class, 'owner_id')->withDefault(function ($owner) {
            $owner->name = 'fallback for ' . $this->title;
        });
    }

    public function ownerWithParentClosureDefault()
    {
        return $this->belongsTo(RpOwner::class, 'owner_id')->withDefault(function ($owner, $item) {
            $owner->name = 'fallback for ' . $item->title;
        });
    }

    public function sameCurrencyOwner()
    {
        return $this->belongsTo(RpOwner::class, 'owner_id')->where('currency', $this->currency);
    }

    public function parentByKind()
    {
        return $this->kind === 'owner'
            ? $this->belongsTo(RpOwner::class, 'owner_id')
            : $this->belongsTo(RpItem::class, 'owner_id');
    }

    public function label()
    {
        static::$labelCalls++;

        return $this->belongsTo(RpOwner::class, 'owner_id');
    }

    public function note()
    {
        return $this->hasOne(RpNote::class, 'item_id');
    }

    public function ownerByCode()
    {
        return $this->belongsTo(RpOwner::class, 'owner_code', 'code');
    }

    public function ownerByRef()
    {
        return $this->belongsTo(RpOwner::class, 'owner_ref');
    }

    public function noteWithInverse()
    {
        return $this->hasOne(RpNote::class, 'item_id')->chaperone('item');
    }

    public function noteWithNestedEagerLoad()
    {
        return $this->hasOne(RpNoteWithItem::class, 'item_id');
    }

    public function siblings()
    {
        return $this->hasMany(RpItem::class, 'owner_id', 'owner_id');
    }
}
