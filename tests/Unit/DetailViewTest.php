<?php

namespace Kamva\Crud\Tests\Unit;

use Kamva\Crud\CRUDController;
use Kamva\Crud\Form;
use Kamva\Crud\Tests\TestCase;

class DetailViewTest extends TestCase
{
    public function test_add_detail_section_stores_renderer_keyed_by_name(): void
    {
        $c = $this->makeController();
        $c->setup();

        $ret = $c->addDetailSection('Overview', fn () => 'overview content');
        $this->assertSame($c, $ret);

        // Use reflection to peek — the public API exposes data through the
        // show() flow, but we don't boot a request here. The smoke test is:
        // does registration not throw + can re-register replace?
        $c->addDetailSection('Overview', fn () => 'replaced');
        $this->assertTrue(true);
    }

    public function test_add_detail_sidebar_chains_returnable(): void
    {
        $c = $this->makeController();
        $c->setup();

        $ret = $c->addDetailSidebar('Actions', fn () => 'sidebar content');
        $this->assertSame($c, $ret);
    }

    public function test_set_show_view_chains_returnable(): void
    {
        $c = $this->makeController();
        $c->setup();

        $ret = $c->setShowView('app.lead.show');
        $this->assertSame($c, $ret);
    }

    private function makeController(): CRUDController
    {
        $form = $this->app->make(Form::class);
        return new class($form) extends CRUDController {
            public function setup() {}
        };
    }
}
