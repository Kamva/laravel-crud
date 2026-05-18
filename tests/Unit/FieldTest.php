<?php

namespace Kamva\Crud\Tests\Unit;

use Illuminate\Contracts\Support\Renderable;
use Kamva\Crud\Fields\Internal\BaseField;
use Kamva\Crud\Fields\Internal\FieldContract;
use Kamva\Crud\Tests\TestCase;

class FieldTest extends TestCase
{
    public function test_field_is_not_readonly_by_default(): void
    {
        $f = $this->makeField();
        $this->assertFalse($f->isReadOnly());
    }

    public function test_readonly_flag_round_trips(): void
    {
        $f = $this->makeField();
        $ret = $f->readOnly();

        $this->assertSame($f, $ret, 'readOnly() returns $this for chaining');
        $this->assertTrue($f->isReadOnly());
    }

    public function test_should_show_returns_true_when_no_predicate(): void
    {
        $f = $this->makeField();
        $this->assertTrue($f->shouldShow());
        $this->assertTrue($f->shouldShow(new \stdClass));
    }

    public function test_should_show_honours_predicate(): void
    {
        $f = $this->makeField()->showWhen(fn ($m) => isset($m->visible) && $m->visible);

        $shouldShow = (object) ['visible' => true];
        $shouldHide = (object) ['visible' => false];

        $this->assertTrue($f->shouldShow($shouldShow));
        $this->assertFalse($f->shouldShow($shouldHide));
        $this->assertFalse($f->shouldShow(null));
    }

    public function test_should_show_predicate_can_return_truthy_non_bool(): void
    {
        $f = $this->makeField()->showWhen(fn ($m) => 'truthy-string');
        $this->assertTrue($f->shouldShow(null));
    }

    public function test_readonly_and_show_when_compose(): void
    {
        // Field marked both readOnly (no writes) and showWhen (hide when
        // condition false). Combination is legitimate — e.g. a field
        // that should be visible only sometimes and never editable.
        $f = $this->makeField()
            ->readOnly()
            ->showWhen(fn ($m) => true);

        $this->assertTrue($f->isReadOnly());
        $this->assertTrue($f->shouldShow());
    }

    private function makeField(): BaseField
    {
        return new class extends BaseField implements FieldContract {
            public function render($data = null): Renderable
            {
                return new class implements Renderable {
                    public function render() { return ''; }
                };
            }
            public function store($value, $oldValue) { return $value; }
        };
    }
}
