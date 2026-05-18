<?php

namespace Kamva\Crud\Tests\Unit\Kanban;

use Kamva\Crud\Tests\TestCase;

/**
 * Regression for Codex P1 #3: kanban transition URL must preserve named
 * parent route parameters (e.g. {organization}/{lead}) when constructed.
 *
 * The previous implementation used `['__ID__'] + $this->routeParameters`
 * which, under PHP's array-union semantics, kept the left-hand `[0 => '__ID__']`
 * and dropped any conflicting numeric keys from `$routeParameters`. For
 * named parent params (string keys) it worked; for positional params it
 * silently dropped them and `route()` threw at request time.
 *
 * We assert the URL-building behaviour directly against Laravel's
 * `route()` helper so future regressions in either direction surface.
 */
class KanbanTransitionUrlTest extends TestCase
{
    public function test_named_parent_params_are_preserved_in_transition_url(): void
    {
        $this->app['router']
            ->post('/organization/{organization}/lead/{lead}/transition', fn () => '')
            ->name('crud.org-nested.transition');

        // Same merge logic the controller uses
        $routeParameters  = ['organization' => 'org-abc'];
        $transitionParams = array_merge($routeParameters, ['__ID__']);

        $url = route('crud.org-nested.transition', $transitionParams);

        $this->assertStringContainsString('/organization/org-abc/', $url);
        $this->assertStringContainsString('/lead/__ID__/', $url);
    }

    public function test_no_parent_params_still_yields_id_placeholder(): void
    {
        $this->app['router']
            ->post('/lead/{lead}/transition', fn () => '')
            ->name('crud.lead.transition.flat');

        $url = route('crud.lead.transition.flat', array_merge([], ['__ID__']));

        $this->assertStringContainsString('/lead/__ID__/', $url);
    }

    public function test_positional_parent_param_does_not_overwrite_id_placeholder(): void
    {
        // If a project stored route params positionally (less common but valid),
        // array_merge re-indexes them — so the ordering becomes
        // [0 => parent, 1 => __ID__]. Laravel fills route segments left-to-right
        // so the parent and ID both land in the right slots.
        $this->app['router']
            ->post('/org/{organization}/lead/{lead}/transition', fn () => '')
            ->name('crud.lead.transition.pos');

        $routeParameters  = ['org-positional'];   // positional
        $transitionParams = array_merge($routeParameters, ['__ID__']);

        $url = route('crud.lead.transition.pos', $transitionParams);

        $this->assertStringContainsString('/org/org-positional/', $url);
        $this->assertStringContainsString('/lead/__ID__/', $url);
    }
}
