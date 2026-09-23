<?php

namespace Kamva\Crud\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Kamva\Crud\Actions\Internal\BaseAction;
use Kamva\Crud\CRUDController;
use Kamva\Crud\Form;
use Kamva\Crud\Tests\TestCase;

/**
 * The row-actions cell moved from string concatenation inside
 * CRUDController::getActionFieldForRow() to the publishable
 * `kamva-crud::actions` view. Published list templates and DataTables
 * consumers depend on the exact markup, so the view must reproduce the
 * previous output byte-for-byte. The reference implementation below is a
 * verbatim copy of the old method.
 */
class RowActionsRenderTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->get('ra/{id}', fn () => '')->name('ra.show');
        $router->get('ra/{id}/edit', fn () => '')->name('ra.edit');
        $router->delete('ra/{id}', fn () => '')->name('ra.destroy');
    }

    public function test_no_actions_renders_empty_string(): void
    {
        $this->assertSame('', $this->controller(0)->getActionFieldForRow($this->row()));
    }

    public function test_up_to_three_actions_render_inline(): void
    {
        $this->assertMatchesLegacy($this->controller(3), false);
    }

    public function test_extra_actions_collapse_into_dropdown(): void
    {
        $controller = $this->controller(5);
        $this->assertMatchesLegacy($controller, false);

        $html = $controller->getActionFieldForRow($this->row());
        $this->assertSame(5, substr_count($html, '<form '));
        $this->assertSame(2, substr_count($html, '<li >'));
        $this->assertStringContainsString('action="http://localhost/ra/7"', $html);
        $this->assertStringContainsString('name="_method" value="DELETE"', $html);
        $this->assertStringEndsWith('</ul></div>', $html);
    }

    public function test_avoid_group_renders_all_actions_inline(): void
    {
        $this->assertMatchesLegacy($this->controller(5), true);
    }

    public function test_actions_without_access_are_omitted(): void
    {
        $controller = $this->controller(5, fn ($row) => false);

        $this->assertSame('', $controller->getActionFieldForRow($this->row()));
    }

    private function assertMatchesLegacy(RowActionsController $controller, bool $avoidGroup): void
    {
        $row = $this->row();

        $this->assertSame(
            $this->legacyRender($controller->exposedActions(), $row, $avoidGroup),
            $controller->getActionFieldForRow($row, $avoidGroup)
        );
    }

    private function controller(int $count, ?\Closure $acl = null): RowActionsController
    {
        $controller = new RowActionsController($this->app->make(Form::class));
        $controller->actionCount = $count;
        $controller->acl = $acl;
        $controller->init();

        return $controller;
    }

    private function row(): RowActionsWidget
    {
        $row = new RowActionsWidget();
        $row->id = 7;

        return $row;
    }

    /** Verbatim copy of the pre-refactor CRUDController::getActionFieldForRow(). */
    private function legacyRender(array $allActions, $row, $avoidGroup): string
    {
        $colValue   = '';
        $actions = collect($allActions)->filter(fn ($action) => $action->hasAccess($row));

        $firstActions = $avoidGroup ? $actions : $actions->take(3);
        $firstActions->each(function ($action) use ($row, &$colValue) {
            $colValue .= '<form data-toggle="tooltip" data-placement="top"  class="action-selector '. $action->getOption('class') . ($action->getOption('ask') ? 'ask' : '') .'" title="'. $action->getCaption() .'" style="margin: 0 5px;display: inline-block" method="'. ($action->isMethod('get') ? 'get' : 'post') .'" action="'.$action->url($row).'">';
            $colValue .= $action->isMethod('get') ? '' : method_field($action->getMethod());
            $colValue .= $action->isMethod('get') ? '' : csrf_field();
            $colValue .= $action->getRender($row);
            $colValue .= '</form>';
        });

        $extraActions = $avoidGroup ? collect([]) : $actions->skip(3);
        if ($extraActions->isEmpty()) {
            return $colValue;
        }

        $colValue .= '<div class="btn-group"><a data-toggle="dropdown"><i class="feather icon-more-vertical"></i></a><ul class="dropdown-menu" role="menu">';

        $extraActions->each(function ($action) use ($row, &$colValue) {
            $colValue .= '<li >';
            $colValue .= '<form class="dropdown-item action-selector '. $action->getOption('class') . ($action->getOption('ask') ? 'ask' : '') .'" method="'. ($action->isMethod('get') ? 'get' : 'post') .'" action="'.$action->url($row).'">';
            $colValue .= $action->isMethod('get') ? '' : method_field($action->getMethod());
            $colValue .= $action->isMethod('get') ? '' : csrf_field();
            $colValue .= $action->getRender($row) .'<span style="margin-right: 1rem">'.$action->getCaption().'</span>';
            $colValue .= '</form>';
            $colValue .='</li>';
        });

        $colValue .= '</ul></div>';

        return $colValue;
    }
}

class RowActionsController extends CRUDController
{
    public int $actionCount = 0;
    public ?\Closure $acl = null;

    public function setup(): void
    {
        $this->setModel(RowActionsWidget::class);

        $kinds = [RowActionsShow::class, RowActionsDelete::class, RowActionsEdit::class];
        for ($i = 0; $i < $this->actionCount; $i++) {
            $kind = $kinds[$i % count($kinds)];
            $this->addAction($kind, (new $kind)->route, $this->acl);
        }
    }

    public function exposedActions(): array
    {
        $prop = new \ReflectionProperty(CRUDController::class, 'actions');
        $prop->setAccessible(true);

        return $prop->getValue($this);
    }
}

class RowActionsShow extends BaseAction
{
    public $route      = 'ra.show';
    public $caption    = 'Show';
    public $render     = '<i class="feather icon-eye"></i>';
    public $parameters = ['$id'];
}

class RowActionsDelete extends BaseAction
{
    public $route      = 'ra.destroy';
    public $method     = 'DELETE';
    public $caption    = 'Delete';
    public $render     = '<i class="feather icon-trash"></i>';
    public $parameters = ['$id'];
    public $options    = ['class' => 'text-danger ', 'ask' => true];
}

class RowActionsEdit extends BaseAction
{
    public $route      = 'ra.edit';
    public $caption    = 'Edit';
    public $render     = '<i class="feather icon-edit"></i>';
    public $parameters = ['$id'];
    public $options    = ['class' => 'text-info'];
}

class RowActionsWidget extends Model
{
    protected $table = 'ra_widgets';
    protected $guarded = [];
}
