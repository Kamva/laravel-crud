<?php

namespace Kamva\Crud\Tests\Unit;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Kamva\Crud\Containers\FilterContainer;
use Kamva\Crud\Fields\Internal\BaseField;
use Kamva\Crud\Fields\Internal\FieldContract;
use Kamva\Crud\Tests\TestCase;

class FilterContainerTest extends TestCase
{
    public function test_render_returns_empty_string_when_no_field_attached(): void
    {
        $filter = new FilterContainer('overdue', function () {});

        $this->assertSame('', $filter->render());
    }

    public function test_has_field_reports_false_when_no_field_attached(): void
    {
        $filter = new FilterContainer('overdue', function () {});

        $this->assertFalse($filter->hasField());
        $this->assertNull($filter->getField());
    }

    public function test_render_delegates_to_field_when_attached(): void
    {
        $field = $this->makeStubField('rendered-by-field');
        $filter = new FilterContainer('q', function () {}, $field);

        $this->assertTrue($filter->hasField());
        $this->assertSame($field, $filter->getField());
        $rendered = $filter->render();
        $this->assertInstanceOf(Renderable::class, $rendered);
        $this->assertSame('rendered-by-field', $rendered->render());
    }

    public function test_apply_runs_callback_with_request_and_query(): void
    {
        $called = false;
        $filter = new FilterContainer(
            'q',
            function (Request $req, $rows) use (&$called) {
                $called = true;
                $this->assertSame('hello', $req->get('q'));
                $this->assertNotNull($rows);
            }
        );

        // Eloquent Builder requires a model; use a model anonymous-class shim.
        $model = new class extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'sqlite_master';
            public $timestamps = false;
        };
        $rows = $model->newQuery();
        $filter->apply(Request::create('/', 'GET', ['q' => 'hello']), $rows);

        $this->assertTrue($called);
    }

    private function makeStubField(string $rendered): FieldContract
    {
        return new class($rendered) extends BaseField implements FieldContract {
            public function __construct(private string $rendered) {}
            public function render($data = null): Renderable
            {
                $marker = $this->rendered;
                return new class($marker) implements Renderable {
                    public function __construct(private string $marker) {}
                    public function render() { return $this->marker; }
                };
            }
            public function store($value, $oldValue) { return $value; }
        };
    }
}
