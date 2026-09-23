<?php

namespace Kamva\Crud\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Kamva\Crud\CRUDController;
use Kamva\Crud\Form;
use Kamva\Crud\Tests\TestCase;

/**
 * Pins the shape of each record in the API index/show JSON: `id` first, then
 * one key per API entity (raw values), an entity named `id` overriding the
 * key, and entities sharing a title collapsing to the last one.
 */
class ApiRecordShapeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('api_widgets', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->timestamps();
        });

        ApiWidget::create(['name' => 'apple']);
    }

    public function test_index_record_shape(): void
    {
        $data = $this->index(function (CRUDController $c) {
            $c->addApiEntity('name');
            $c->addApiEntity('upper', fn ($row, $raw) => [strtoupper($row->name), $raw]);
        });

        $this->assertSame([['id' => 1, 'name' => 'apple', 'upper' => ['APPLE', true]]], $data);
    }

    public function test_entity_named_id_overrides_and_duplicates_collapse(): void
    {
        $data = $this->index(function (CRUDController $c) {
            $c->addApiEntity('label', fn () => 'first');
            $c->addApiEntity('id', fn ($row) => 'W-' . $row->id);
            $c->addApiEntity('label', fn () => 'second');
        });

        $this->assertSame([['id' => 'W-1', 'label' => 'second']], $data);
    }

    private function index(\Closure $entities): array
    {
        $controller = new class($this->app->make(Form::class)) extends CRUDController {
            public ?\Closure $entities = null;

            public function setup(): void
            {
                $this->setModel(ApiWidget::class);
                ($this->entities)($this);
            }
        };
        $controller->entities = $entities;
        $controller->init();

        $request = Request::create('/api/widgets', 'GET');
        $this->app->instance('request', $request);

        return $controller->index($request)->getData(true)['data'];
    }
}

class ApiWidget extends Model
{
    protected $table = 'api_widgets';
    protected $guarded = [];
}
