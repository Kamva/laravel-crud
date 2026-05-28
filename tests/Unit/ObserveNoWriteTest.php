<?php

namespace Kamva\Crud\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Kamva\Crud\CRUDController;
use Kamva\Crud\Form;
use Kamva\Crud\Tests\TestCase;

/**
 * checkModel($id, $assign, $createIfMissing=false) — used by the observe
 * endpoint — must never create a record. Previously a singleType controller
 * would create+save a blank row on a missing model, reachable from the
 * (session/CSRF-less) observe POST.
 */
class ObserveNoWriteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('single_settings', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    public function test_check_model_does_not_create_when_create_if_missing_is_false(): void
    {
        $controller = $this->singleTypeController();
        $controller->init();

        $result = $controller->checkModel(999, true, false);

        $this->assertNull($result);
        $this->assertSame(0, SingleSetting::count(), 'observe path must not create rows');
    }

    public function test_check_model_still_creates_for_single_type_by_default(): void
    {
        $controller = $this->singleTypeController();
        $controller->init();

        $result = $controller->checkModel(null);

        $this->assertNotNull($result);
        $this->assertSame(1, SingleSetting::count());
    }

    private function singleTypeController(): CRUDController
    {
        $form = $this->app->make(Form::class);

        return new class($form) extends CRUDController {
            public function setup(): void
            {
                $this->setModel(SingleSetting::class);
                $this->setSingleType(true);
            }
        };
    }
}

class SingleSetting extends Model
{
    protected $table = 'single_settings';
    protected $guarded = [];
}
