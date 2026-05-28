<?php

namespace Kamva\Crud\Tests\Unit;

use Kamva\Crud\Actions\Internal\BaseAction;
use Kamva\Crud\Containers\ActionContainer;
use Kamva\Crud\Tests\TestCase;

/**
 * A BaseAction subclass that leaves some properties unset must still produce
 * an ActionContainer without a TypeError (the container's setters are typed
 * string/array; the base properties default to null).
 */
class BaseActionTest extends TestCase
{
    public function test_get_action_does_not_crash_when_properties_are_unset(): void
    {
        $action = new class extends BaseAction {
            public $caption = 'Only caption';
            // method, render, parameters, options intentionally left null
        };

        $container = $action->getAction();

        $this->assertInstanceOf(ActionContainer::class, $container);
        $this->assertSame('Only caption', $container->getCaption());
        $this->assertSame('GET', $container->getMethod());
        $this->assertSame([], $container->getParameters());
    }
}
