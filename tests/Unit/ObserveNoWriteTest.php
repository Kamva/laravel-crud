<?php

namespace Kamva\Crud\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Kamva\Crud\CRUDController;
use Kamva\Crud\Form;
use Kamva\Crud\ProcessController;
use Kamva\Crud\Tests\Stubs\StubTextField;
use Kamva\Crud\Tests\TestCase;

/**
 * The observe endpoint must never create a record. For singleType controllers
 * checkModel() creates+saves a blank row on a missing model, so the observe
 * path resolves the model with a plain scoped find() instead.
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

    public function test_observe_does_not_create_a_row_for_missing_single_type_model(): void
    {
        // payload: observedField | thisField | ControllerClass | id
        $payload = Crypt::encryptString('trigger|target|' . ObserveSingleController::class . '|999');

        $request = Request::create('/kc-process/observe', 'POST', ['c' => $payload, 'v' => 'anything']);

        (new ProcessController())->observe($request);

        $this->assertSame(0, SingleSetting::count(), 'observe must not create a singleType row');
    }
}

class ObserveSingleController extends CRUDController
{
    public function __construct()
    {
        parent::__construct(new Form());
    }

    public function setup(): void
    {
        $this->setModel(SingleSetting::class);
        $this->setSingleType(true);
        $this->addField(StubTextField::class, 'Target', 'target')
            ->observe('trigger', fn ($value, $field) => true);
    }
}

class SingleSetting extends Model
{
    protected $table = 'single_settings';
    protected $guarded = [];
}
