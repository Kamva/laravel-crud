<?php

namespace Kamva\Crud\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kamva\Crud\KamvaCrud;
use Kamva\Crud\Tests\Stubs\StubTextField;
use Kamva\Crud\Tests\TestCase;

/**
 * The per-field option-source cache must be scoped by the active controller
 * so two controllers that both expose a field with the same name do not
 * share each other's option list.
 */
class SourceCacheScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['opt_a', 'opt_b', 'opt_empty'] as $table) {
            Schema::create($table, function ($t) {
                $t->increments('id');
                $t->string('title')->nullable();
            });
        }

        OptA::create(['title' => 'Alpha']);
        OptB::create(['title' => 'Bravo']);
    }

    public function test_same_field_name_on_different_controllers_does_not_collide(): void
    {
        $fieldA = new StubTextField();
        $fieldA->setName('status');
        $fieldA->setSource(OptA::class, 'id', 'title');

        $fieldB = new StubTextField();
        $fieldB->setName('status');
        $fieldB->setSource(OptB::class, 'id', 'title');

        KamvaCrud::set('class', new \stdClass());
        $this->assertSame([1 => 'Alpha'], $fieldA->getOptions());

        // A *different* controller context — must not reuse A's cached list.
        KamvaCrud::set('class', new class {});
        $this->assertSame([1 => 'Bravo'], $fieldB->getOptions(), 'controller B must not see controller A cached options');
    }

    public function test_empty_option_list_is_cached_and_not_requeried(): void
    {
        $field = new StubTextField();
        $field->setName('empty_field');
        $field->setSource(OptEmpty::class, 'id', 'title');

        KamvaCrud::set('class', new \stdClass());

        DB::enableQueryLog();
        $this->assertSame([], $field->getOptions());
        $afterFirst = count(DB::getQueryLog());

        $this->assertSame([], $field->getOptions());
        $afterSecond = count(DB::getQueryLog());

        $this->assertSame($afterFirst, $afterSecond, 'empty result should be cached, not re-queried');
    }
}

class OptA extends Model
{
    protected $table = 'opt_a';
    public $timestamps = false;
    protected $guarded = [];
}

class OptB extends Model
{
    protected $table = 'opt_b';
    public $timestamps = false;
    protected $guarded = [];
}

class OptEmpty extends Model
{
    protected $table = 'opt_empty';
    public $timestamps = false;
    protected $guarded = [];
}
