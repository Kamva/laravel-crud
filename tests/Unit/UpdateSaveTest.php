<?php

namespace Kamva\Crud\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Kamva\Crud\CRUDController;
use Kamva\Crud\Form;
use Kamva\Crud\Tests\Stubs\StubTextField;
use Kamva\Crud\Tests\TestCase;

/**
 * update() must persist the model exactly once. Previously saveToModel(true)
 * saved it and then update() called $model->save() again, re-firing the
 * saving/saved model events on every update.
 */
class UpdateSaveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('update_widgets', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('side')->nullable();
            $table->timestamps();
        });

        UpdateWidget::$savedCount = 0;
        UpdateWidget::saved(function () {
            UpdateWidget::$savedCount++;
        });
    }

    public function test_update_fires_saved_event_once(): void
    {
        $widget = UpdateWidget::create(['name' => 'before']);
        UpdateWidget::$savedCount = 0;

        $controller = $this->controller();
        $this->registerIndexRoute($controller);
        $controller->init();

        $controller->update($widget->id, Request::create('/', 'PUT', ['name' => 'after']));

        $this->assertSame('after', $widget->fresh()->name);
        $this->assertSame(1, UpdateWidget::$savedCount, 'update must persist the model exactly once');
    }

    public function test_skip_callback_attribute_changes_are_persisted_on_update(): void
    {
        $widget = UpdateWidget::create(['name' => 'before']);

        $controller = $this->controller(withSkip: true);
        $this->registerIndexRoute($controller);
        $controller->init();

        $controller->update($widget->id, Request::create('/', 'PUT', ['name' => 'after', 'side' => 'x']));

        $this->assertSame('computed', $widget->fresh()->side, 'skip-callback attribute write must persist');
    }

    private function controller(bool $withSkip = false): CRUDController
    {
        $form = $this->app->make(Form::class);

        return new class($form, $withSkip) extends CRUDController {
            private bool $withSkip;
            public function __construct(Form $form, bool $withSkip)
            {
                parent::__construct($form);
                $this->withSkip = $withSkip;
            }
            public function setup(): void
            {
                $this->setModel(UpdateWidget::class);
                $this->addField(StubTextField::class, 'Name', 'name');
                if ($this->withSkip) {
                    // A skip field that sets an attribute on the model without
                    // saving — relies on saveToModel flushing it.
                    $this->addField(StubTextField::class, 'Side', 'side')
                        ->skip()
                        ->saveAs(function ($value, $model) {
                            $model->side = 'computed';
                        });
                }
            }
        };
    }

    private function registerIndexRoute(CRUDController $controller): void
    {
        // handleSuccessResponse redirects to the controller's index route.
        Route::get('update-widgets', [get_class($controller), 'index'])->name('update-widgets.index');
    }
}

class UpdateWidget extends Model
{
    public static int $savedCount = 0;
    protected $table = 'update_widgets';
    protected $guarded = [];
}
