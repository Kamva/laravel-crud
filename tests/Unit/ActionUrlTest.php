<?php

namespace Kamva\Crud\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Kamva\Crud\Containers\ActionContainer;
use Kamva\Crud\Tests\TestCase;

/**
 * ActionContainer::url() reuses a per-action URL template for plain
 * alphanumeric values. Every URL must still equal what route() returns.
 */
class ActionUrlTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->get('au/{id}', fn () => '')->name('au.show');
        $router->get('au/{org}/items/{id}/{tab?}', fn () => '')->name('au.nested');
        $router->get('au-static', fn () => '')->name('au.static');
    }

    public function test_urls_match_route_for_many_value_shapes(): void
    {
        $cases = [
            ['au.show', ['$id'], [7, 8, 1234567890, '42', 'abc', 'MiXeD9']],
            ['au.show', ['$id'], ['a b', 'ü', 'x/y', 'a-b', 'a_b', '1.5', '#1', '?q', '%20', '']],
            ['au.nested', ['org' => 'acme', 'id' => '$id'], [1, 'slug', 'with space']],
            ['au.nested', ['org' => 'acme', 'id' => '$id', 'tab' => '$tab'], [3, 'x y']],
            ['au.show', ['$id', 'ref' => '$tab'], [5, 'n']],
            ['au.static', ['q' => '$id'], [9, 'z z']],
            ['au.static', [], [1, 2]],
        ];

        foreach ($cases as [$route, $parameters, $values]) {
            $action = $this->action($route, $parameters);

            foreach ($values as $value) {
                $row = new AuRow(['id' => $value, 'tab' => $value]);

                $this->assertSame(
                    $this->expected($route, $parameters, $row),
                    $action->url($row),
                    "route {$route} with value " . var_export($value, true)
                );
            }
        }
    }

    public function test_fast_path_is_used_for_plain_values_only_when_safe(): void
    {
        $action = $this->action('au.nested', ['org' => 'acme', 'id' => '$id']);
        $action->url(new AuRow(['id' => 10]));
        $this->assertIsArray($this->template($action), 'plain ids use the template');

        URL::formatPathUsing(fn ($path) => $path);
        $guarded = $this->action('au.show', ['$id']);
        $guarded->url(new AuRow(['id' => 10]));
        $this->assertFalse($this->template($guarded), 'custom URL formatting disables it');
    }

    public function test_model_values_still_use_route_keys(): void
    {
        $action = $this->action('au.show', ['$owner']);
        $owner  = new AuRow(['id' => 5, 'slug' => 'five']);

        $this->assertSame(route('au.show', [$owner]), $action->url((new AuRow())->setRelation('owner', $owner)));
    }

    public function test_missing_value_still_throws_like_route(): void
    {
        $action = $this->action('au.show', ['$id']);
        $action->url(new AuRow(['id' => 1]));

        $this->expectException(\Illuminate\Routing\Exceptions\UrlGenerationException::class);
        $action->url(new AuRow(['id' => null]));
    }

    public function test_format_path_callbacks_are_respected(): void
    {
        URL::formatPathUsing(fn ($path) => $path . '/v' . strlen($path));
        $action = $this->action('au.show', ['$id']);

        foreach ([1, 22, 333] as $id) {
            $this->assertSame(route('au.show', [$id]), $action->url(new AuRow(['id' => $id])));
        }
    }

    public function test_changing_route_or_parameters_resets_the_template(): void
    {
        $action = $this->action('au.show', ['$id']);
        $action->url(new AuRow(['id' => 1]));

        $action->setRoute('au.nested');
        $action->setParameters(['org' => 'o', 'id' => '$id']);

        $this->assertSame(route('au.nested', ['org' => 'o', 'id' => 2]), $action->url(new AuRow(['id' => 2])));
    }

    public function test_render_view_lookup_follows_set_render(): void
    {
        $action = new ActionContainer();
        $action->setRender('<i class="icon"></i>');

        $this->assertSame('<i class="icon"></i>', $action->getRender(new AuRow()));

        $action->setRender('kamva-crud::observe');
        $this->assertInstanceOf(\Illuminate\Contracts\View\View::class, $action->getRender(new AuRow()));

        $action->setRender('<b>x</b>');
        $this->assertSame('<b>x</b>', $action->getRender(new AuRow()));
    }

    private function template(ActionContainer $action)
    {
        $property = new \ReflectionProperty(ActionContainer::class, 'urlTemplate');
        $property->setAccessible(true);

        return $property->getValue($action);
    }

    private function action(string $route, array $parameters): ActionContainer
    {
        $action = new ActionContainer();
        $action->setRoute($route);
        $action->setParameters($parameters);

        return $action;
    }

    /** What url() did before the fast path, verbatim. */
    private function expected(string $route, array $parameters, $data): string
    {
        foreach ($parameters as $key => $parameter) {
            if (\Illuminate\Support\Str::startsWith($parameter, '$')) {
                unset($parameters[$key]);
                $parameters[$key] = $data->{str_replace('$', '', $parameter)};
            }
        }

        return route($route, $parameters);
    }
}

class AuRow extends Model
{
    protected $guarded = [];

    public function getRouteKey()
    {
        return $this->slug ?? parent::getRouteKey();
    }
}
