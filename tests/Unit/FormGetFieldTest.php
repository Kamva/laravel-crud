<?php

namespace Kamva\Crud\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Kamva\Crud\Containers\FieldContainer;
use Kamva\Crud\CRUDController;
use Kamva\Crud\Exceptions\KamvaCrudException;
use Kamva\Crud\Form;
use Kamva\Crud\Tests\Stubs\StubTextField;
use Kamva\Crud\Tests\TestCase;

class FormGetFieldTest extends TestCase
{
    public function test_get_field_finds_container_by_name(): void
    {
        $form = new Form();
        $form->addField(StubTextField::class, 'Name', 'name');
        $form->addField(StubTextField::class, 'Email', 'email');

        $field = $form->getField('email');

        $this->assertInstanceOf(FieldContainer::class, $field);
        $this->assertSame('Email', $field->field()->getCaption());
        $this->assertNull($form->getField('missing'));
    }

    public function test_add_field_filter_attaches_the_named_form_field(): void
    {
        Schema::create('gf_widgets', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->timestamps();
        });
        GfWidget::create(['name' => 'apple']);
        GfWidget::create(['name' => 'banana']);

        $controller = new class($this->app->make(Form::class)) extends CRUDController {
            public $filter;

            public function setup(): void
            {
                $this->setModel(GfWidget::class);
                $this->addColumn('Name', 'name');
                $this->addField(StubTextField::class, 'Name', 'name');
                $this->filter = $this->addFieldFilter('name', function ($request, $rows) {
                    $rows->where('name', $request->get('name'));
                }, 'name');
            }
        };
        $controller->init();

        $this->assertTrue($controller->filter->hasField());
        $this->assertSame('name', $controller->filter->getField()->getName());

        $request = Request::create('/gf', 'GET', ['name' => 'banana', 'start' => 0, 'length' => 10], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $this->app->instance('request', $request);

        $this->assertSame(1, $controller->index($request)['recordsFiltered']);
    }

    public function test_add_field_filter_rejects_unknown_field(): void
    {
        $controller = new class($this->app->make(Form::class)) extends CRUDController {
            public function setup(): void
            {
                $this->setModel(GfWidget::class);
                $this->addFieldFilter('x', fn () => null, 'nope');
            }
        };

        $this->expectException(KamvaCrudException::class);
        $controller->init();
    }
}

class GfWidget extends Model
{
    protected $table = 'gf_widgets';
    protected $guarded = [];
}
