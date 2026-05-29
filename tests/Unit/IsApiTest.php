<?php

namespace Kamva\Crud\Tests\Unit;

use Illuminate\Http\Request;
use Kamva\Crud\KamvaCrud;
use Kamva\Crud\Tests\TestCase;

/**
 * isApi() keys off the request path. It must match the `api` segment or an
 * `api/...` prefix — not any path that merely begins with "api".
 */
class IsApiTest extends TestCase
{
    /**
     * @dataProvider paths
     */
    public function test_is_api_matches_only_real_api_paths(string $path, bool $expected): void
    {
        $this->app->instance('request', Request::create($path, 'GET'));

        $this->assertSame($expected, KamvaCrud::isApi(), "path {$path}");
    }

    public static function paths(): array
    {
        return [
            ['/api', true],
            ['/api/widgets', true],
            ['/api/v1/widgets', true],
            ['/apiary', false],
            ['/api-docs', false],
            ['/widgets', false],
            ['/', false],
        ];
    }
}
