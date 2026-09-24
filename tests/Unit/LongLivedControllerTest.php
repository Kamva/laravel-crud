<?php

namespace Kamva\Crud\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Kamva\Crud\CRUDController;
use Kamva\Crud\Form;
use Kamva\Crud\KamvaCrud;
use Kamva\Crud\Tests\Stubs\StubTextField;
use Kamva\Crud\Tests\TestCase;

/**
 * One app instance can serve several requests: feature tests, and
 * Octane/RoadRunner/Swoole workers. Laravel keeps the route's controller
 * instance, so init() (and setup()) runs on it more than once, and the
 * kamva-crud singleton outlives the request (issue #33).
 */
class LongLivedControllerTest extends TestCase
{
    private const LIST = '/ll-items?draw=1&start=0&length=10';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('ll_categories', function ($table) {
            $table->increments('id');
            $table->string('title');
        });
        Schema::create('ll_items', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->unsignedInteger('category_id');
            $table->timestamps();
        });

        LlCategory::create(['title' => 'Fruit']);
        LlItem::create(['name' => 'apple', 'category_id' => 1]);

        Route::get('ll-items', [LlController::class, 'index'])->name('ll-items.index');
    }

    public function test_repeated_requests_return_the_same_list(): void
    {
        $first = $this->getJson(self::LIST)->assertOk()->json('data');
        $second = $this->getJson(self::LIST)->assertOk()->json('data');

        $this->assertSame([[1, 'apple', 'Fruit', '']], $first);
        $this->assertSame($first, $second);
    }

    public function test_requests_after_the_route_controller_is_flushed(): void
    {
        $first = $this->getJson(self::LIST)->assertOk()->json('data');

        // The route's controller is dropped but the middleware it gathered
        // is kept, so the init() closure bound to the old instance runs
        // for a new controller (the 500 reported in #33).
        foreach (app('router')->getRoutes() as $route) {
            $route->controller = null;
        }
        $this->assertSame($first, $this->getJson(self::LIST)->assertOk()->json('data'));

        // flushController() (what Octane calls) drops both.
        foreach (app('router')->getRoutes() as $route) {
            $route->flushController();
        }
        $this->assertSame($first, $this->getJson(self::LIST)->assertOk()->json('data'));
    }

    public function test_source_options_are_not_cached_across_requests(): void
    {
        $this->getJson(self::LIST)->assertOk();

        LlCategory::create(['title' => 'Nuts']);
        LlItem::create(['name' => 'almond', 'category_id' => 2]);

        $this->assertSame(
            ['Fruit', 'Nuts'],
            array_column($this->getJson(self::LIST)->assertOk()->json('data'), 2)
        );
    }

    public function test_request_state_is_forgotten_but_boot_settings_are_kept(): void
    {
        $acl = fn () => true;
        KamvaCrud::setDefaultACLMethod($acl);
        KamvaCrud::set('model', 5);
        KamvaCrud::set('source_cache_X_status', [1 => 'a']);

        $controller = $this->app->make(LlController::class);
        $controller->init();

        $this->assertNull(KamvaCrud::get('model'));
        $this->assertNull(KamvaCrud::get('source_cache_X_status'));
        $this->assertSame($controller, KamvaCrud::get('class'));
        $this->assertSame($acl, KamvaCrud::get('default_acl_method'));
    }

    public function test_state_set_before_the_first_setup_survives_repeated_init(): void
    {
        $controller = new class($this->app->make(Form::class)) extends CRUDController {
            public function __construct(Form $form)
            {
                parent::__construct($form);
                $this->setTitle('Items');
                $this->addColumn('Id', 'id');
            }

            public function setup(): void
            {
                $this->setModel(LlItem::class);
                $this->addColumn('Name', 'name');
                $this->addField(StubTextField::class, 'Name', 'name');
            }
        };

        $controller->init();
        $controller->init();
        $controller->init();

        [$title, $cols] = \Closure::bind(fn () => [$this->title, $this->cols], $controller, CRUDController::class)();

        $this->assertSame('Items', $title);
        $this->assertSame(['Id', 'Name'], array_map(fn ($col) => $col->getName(), $cols));
        $this->assertCount(1, $controller->getForm()->getFields());
    }
}

class LlController extends CRUDController
{
    public function setup(): void
    {
        $this->setModel(LlItem::class);
        $this->addColumn('Name', 'name');
        $this->addColumn('Category', 'category_id.field');
        $this->addField(StubTextField::class, 'Category', 'category_id')
            ->setSource(LlCategory::class, 'id', 'title');
    }

    public function getActionFieldForRow($row, $avoidGroup = false)
    {
        return '';
    }
}

class LlCategory extends Model
{
    protected $table = 'll_categories';
    public $timestamps = false;
    protected $guarded = [];
}

class LlItem extends Model
{
    protected $table = 'll_items';
    protected $guarded = [];
}
