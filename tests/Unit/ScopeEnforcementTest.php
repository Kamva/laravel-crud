<?php

namespace Kamva\Crud\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Kamva\Crud\CRUDController;
use Kamva\Crud\Form;
use Kamva\Crud\Tests\Stubs\StubTextField;
use Kamva\Crud\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Regression tests for the setQuery() scope being enforced on the mutating
 * actions. Historically update()/destroy() resolved the model WITHOUT the
 * scope, so a user could mutate a record outside their scope by guessing its
 * ID (IDOR / broken access control). These tests pin the fix.
 */
class ScopeEnforcementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('scope_widgets', function ($table) {
            $table->increments('id');
            $table->string('owner')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    public function test_update_cannot_reach_a_record_outside_the_query_scope(): void
    {
        $other = ScopeWidget::create(['owner' => 'bob', 'name' => 'original']);

        $controller = $this->scopedController();
        $controller->init();

        $request = Request::create('/', 'PUT', ['name' => 'hacked']);

        try {
            $controller->update($other->id, $request);
            $this->fail('Expected a 404 for an out-of-scope record.');
        } catch (NotFoundHttpException $e) {
            // expected
        }

        $this->assertSame('original', $other->fresh()->name, 'out-of-scope record must not be modified');
    }

    public function test_destroy_cannot_reach_a_record_outside_the_query_scope(): void
    {
        $other = ScopeWidget::create(['owner' => 'bob', 'name' => 'keep']);

        $controller = $this->scopedController();
        $controller->init();

        try {
            $controller->destroy($other->id);
            $this->fail('Expected a 404 for an out-of-scope record.');
        } catch (NotFoundHttpException $e) {
            // expected
        }

        $this->assertNotNull($other->fresh(), 'out-of-scope record must not be deleted');
    }

    private function scopedController(): CRUDController
    {
        $form = $this->app->make(Form::class);

        return new class($form) extends CRUDController {
            public function setup(): void
            {
                $this->setModel(ScopeWidget::class);
                $this->setQuery(fn ($q) => $q->where('owner', 'alice'));
                $this->addField(StubTextField::class, 'Name', 'name');
            }
        };
    }
}

class ScopeWidget extends Model
{
    protected $table = 'scope_widgets';
    protected $guarded = [];
}
