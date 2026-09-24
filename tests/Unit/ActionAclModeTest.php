<?php

namespace Kamva\Crud\Tests\Unit;

use Kamva\Crud\Containers\ActionContainer;
use Kamva\Crud\KamvaCrud;
use Kamva\Crud\Tests\TestCase;

/**
 * A row action's own access closure and the default ACL method
 * (setDefaultACLMethod()). By default the closure replaces the default
 * check; setActionAclMode('and') makes the closure narrow it (issue #31).
 */
class ActionAclModeTest extends TestCase
{
    private array $calls = [];

    public function test_replace_is_the_default_mode(): void
    {
        $this->assertSame('replace', KamvaCrud::getActionAclMode());
    }

    public function test_replace_mode_uses_the_closure_instead_of_the_default(): void
    {
        $this->defaultAllows(false);

        $this->assertTrue($this->action(fn () => true)->hasAccess($this->row()));
        $this->assertSame([], $this->calls, 'the default method is not consulted');
        $this->assertFalse($this->action(null)->hasAccess($this->row()));
    }

    public function test_replace_mode_returns_the_closure_result_as_is(): void
    {
        $this->defaultAllows(true);

        $this->assertSame(0, $this->action(fn () => 0)->hasAccess($this->row()));
    }

    public function test_and_mode_needs_both_to_allow(): void
    {
        KamvaCrud::setActionAclMode('and');

        foreach ([[true, true, true], [true, false, false], [false, true, false], [false, false, false]] as [$default, $own, $expected]) {
            $this->defaultAllows($default);

            $this->assertSame($expected, (bool) $this->action(fn () => $own)->hasAccess($this->row()), "default {$default}, own {$own}");
        }

        $this->assertSame([['ra.edit', 7], ['ra.edit', 7], ['ra.edit', 7], ['ra.edit', 7]], $this->calls);
    }

    public function test_and_mode_without_a_default_method_uses_the_closure(): void
    {
        KamvaCrud::setActionAclMode('and');

        $this->assertTrue($this->action(fn () => true)->hasAccess($this->row()));
        $this->assertFalse($this->action(fn () => false)->hasAccess($this->row()));
        $this->assertTrue($this->action(null)->hasAccess($this->row()));
    }

    public function test_and_mode_without_a_closure_uses_the_default(): void
    {
        KamvaCrud::setActionAclMode('and');
        $this->defaultAllows(false);

        $this->assertFalse($this->action(null)->hasAccess($this->row()));
    }

    public function test_an_unknown_mode_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        KamvaCrud::setActionAclMode('or');
    }

    public function test_the_mode_survives_request_state_flushes(): void
    {
        KamvaCrud::setActionAclMode('and');
        KamvaCrud::flushRequestState();

        $this->assertSame('and', KamvaCrud::getActionAclMode());
    }

    private function defaultAllows(bool $allow): void
    {
        KamvaCrud::setDefaultACLMethod(function ($route, $user, $row) use ($allow) {
            $this->calls[] = [$route, $row->id];

            return $allow;
        });
    }

    private function action(?\Closure $own): ActionContainer
    {
        $action = new ActionContainer('Edit', '', $own);
        $action->setRoute('ra.edit');

        return $action;
    }

    private function row(): object
    {
        return (object) ['id' => 7];
    }
}
