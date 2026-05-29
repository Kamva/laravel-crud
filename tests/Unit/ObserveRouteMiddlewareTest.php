<?php
namespace Kamva\Crud\Tests\Unit;
use Kamva\Crud\Tests\TestCase;
use Illuminate\Support\Facades\Route;
class ObserveRouteMiddlewareTest extends TestCase {
    public function test_observe_route_is_in_web_middleware_group(): void {
        $route = Route::getRoutes()->getByName('kamva-crud.process');
        $this->assertNotNull($route, 'observe route must be registered');
        $this->assertContains('web', $route->gatherMiddleware());
    }
}
