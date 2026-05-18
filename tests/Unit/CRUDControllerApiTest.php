<?php

namespace Kamva\Crud\Tests\Unit;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Kamva\Crud\CRUDController;
use Kamva\Crud\Form;
use Kamva\Crud\Tests\TestCase;

/**
 * Lightweight introspection tests for the additive controller API. We don't
 * boot a full Laravel request lifecycle here — we exercise the controller
 * methods directly and assert the registered shape.
 */
class CRUDControllerApiTest extends TestCase
{
    public function test_add_top_action_records_entry_with_defaults(): void
    {
        $c = $this->makeController();
        $c->setup();

        $entry = $c->addTopAction('Switch to Kanban', 'home');

        $this->assertSame('Switch to Kanban', $entry['caption']);
        $this->assertSame('home', $entry['route']);
        $this->assertSame([], $entry['params']);
        $this->assertSame('', $entry['icon']);
        $this->assertSame('btn-sm btn-secondary', $entry['class']);
    }

    public function test_get_top_actions_filters_via_access_control(): void
    {
        $this->app['router']->get('/page-a', fn () => '')->name('a');
        $this->app['router']->get('/page-b', fn () => '')->name('b');

        $c = $this->makeController();
        $c->setup();
        $c->addTopAction('Visible', 'a');
        $c->addTopAction('Hidden', 'b', ['accessControlMethod' => fn () => false]);

        $actions = $c->getTopActions();
        $this->assertCount(1, $actions);
        $this->assertSame('Visible', $actions[0]['caption']);
        $this->assertStringContainsString('/page-a', $actions[0]['url']);
    }

    public function test_add_hidden_filter_registers_field_less_filter(): void
    {
        $c = $this->makeController();
        $c->setup();

        $filter = $c->addHiddenFilter('mine', fn ($req, $q) => $q->where('owner_id', 'me'));

        $this->assertFalse($filter->hasField());
        $this->assertSame('mine', $filter->getInputName());
    }

    public function test_add_search_field_returns_a_filter_container_and_uses_named_input(): void
    {
        $c = $this->makeController();
        $c->setup();

        $filter = $c->addSearchField(['name', 'email'], 'q');

        $this->assertSame('q', $filter->getInputName());
    }

    private function makeController(): CRUDController
    {
        $form = $this->app->make(Form::class);
        return new class($form) extends CRUDController {
            public function setup() { /* no-op for these tests */ }
        };
    }
}
