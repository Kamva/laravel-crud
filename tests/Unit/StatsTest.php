<?php

namespace Kamva\Crud\Tests\Unit;

use Kamva\Crud\CRUDController;
use Kamva\Crud\Form;
use Kamva\Crud\Tests\TestCase;

class StatsTest extends TestCase
{
    public function test_add_stat_registers_label_compute_and_defaults(): void
    {
        $c = $this->makeController();
        $c->setup();
        $c->addStat('Open leads', fn () => 42);

        $stats = $c->getStats();
        $this->assertCount(1, $stats);
        $this->assertSame('Open leads', $stats[0]['label']);
        $this->assertSame(42, $stats[0]['value']);
        $this->assertSame('primary', $stats[0]['color']);
        $this->assertSame('', $stats[0]['icon']);
        $this->assertNull($stats[0]['link']);
    }

    public function test_stats_compute_is_called_lazily_via_get_stats(): void
    {
        $calls = 0;
        $c = $this->makeController();
        $c->setup();
        $c->addStat('Counter', function () use (&$calls) { $calls++; return $calls; });

        $this->assertSame(0, $calls, 'compute not called on addStat()');
        $c->getStats();
        $this->assertSame(1, $calls);
        $c->getStats();
        $this->assertSame(2, $calls, 'each getStats() call re-evaluates');
    }

    public function test_stats_options_propagate(): void
    {
        $c = $this->makeController();
        $c->setup();
        $c->addStat('Revenue', fn () => 1000, [
            'icon'  => 'feather icon-trending-up',
            'color' => 'success',
            'link'  => fn () => '/leads?stage=paying',
        ]);

        $stats = $c->getStats();
        $this->assertSame('feather icon-trending-up', $stats[0]['icon']);
        $this->assertSame('success', $stats[0]['color']);
        $this->assertSame('/leads?stage=paying', $stats[0]['link']);
    }

    public function test_add_stat_chains_returnable(): void
    {
        $c = $this->makeController();
        $c->setup();

        $ret = $c->addStat('A', fn () => 1);
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
