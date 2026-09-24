<?php

namespace Kamva\Crud\Tests\Performance;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kamva\Crud\CRUDController;
use Kamva\Crud\Form;
use Kamva\Crud\KamvaCrud;
use Kamva\Crud\Tests\TestCase;

/**
 * Base class for the performance suite (`composer bench`).
 *
 * Each scenario runs a full request several times against 5,000 seeded rows
 * and records the median/min/p90 wall time, peak memory and query count to
 * build/benchmarks/latest.json. It also records a fingerprint of the
 * scenario's output.
 *
 * Environment:
 *   BENCH_ITERATIONS=N     measured iterations per scenario (default 15)
 *   BENCH_BASELINE=path    compare against a previous results file: prints
 *                          the change per scenario and FAILS when a
 *                          scenario's output fingerprint differs, so an
 *                          optimisation cannot silently change behaviour
 *   BENCH_SAVE=path        also copy the results to this path (e.g. to
 *                          create a baseline)
 */
abstract class BenchmarkCase extends TestCase
{
    protected const ROWS       = 5000;
    protected const CATEGORIES = 25;
    private const RESULTS      = __DIR__ . '/../../build/benchmarks/latest.json';

    private int $queries = 0;

    protected function defineRoutes($router): void
    {
        $router->get('bench/products/{id}', fn () => '')->name('bench.products.show');
        $router->get('bench/products/{id}/edit', fn () => '')->name('bench.products.edit');
        $router->delete('bench/products/{id}', fn () => '')->name('bench.products.destroy');
        $router->post('bench/products/{id}/duplicate', fn () => '')->name('bench.products.duplicate');
        $router->patch('bench/products/{id}/archive', fn () => '')->name('bench.products.archive');

        // Resource-style routes so getMethodRoute() resolves create/store/update.
        $router->get('bench/products', [BenchProductController::class, 'index'])->name('bench.products.index');
        $router->get('bench/products/create', [BenchProductController::class, 'create'])->name('bench.products.create');
        $router->post('bench/products', [BenchProductController::class, 'store'])->name('bench.products.store');
        $router->put('bench/products/{id}', [BenchProductController::class, 'update'])->name('bench.products.update');
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('jdate')) {
            eval('function jdate() { return new \DateTime("2026-01-01"); }');
        }

        // Fixed CSRF token so the actions markup (and its fingerprint) is stable.
        $this->app['session']->setId(str_repeat('a', 40));
        $this->app['session']->put('_token', 'bench-token');
        $this->app['config']->set('kamva-crud.paginate_size', 100);

        Schema::create('bench_categories', function ($table) {
            $table->increments('id');
            $table->string('title');
            $table->timestamps();
        });
        Schema::create('bench_products', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->integer('price');
            $table->string('status');
            $table->unsignedInteger('category_id')->index();
            $table->timestamps();
        });

        $now = '2026-01-01 00:00:00';
        DB::table('bench_categories')->insert(array_map(fn ($i) => [
            'title' => "Category {$i}", 'created_at' => $now, 'updated_at' => $now,
        ], range(1, static::CATEGORIES)));

        $statuses = ['active', 'draft', 'archived'];
        foreach (array_chunk(range(1, static::ROWS), 500) as $chunk) {
            DB::table('bench_products')->insert(array_map(fn ($i) => [
                'name'        => "Product {$i}",
                'price'       => ($i * 37) % 10000,
                'status'      => $statuses[$i % 3],
                'category_id' => ($i % static::CATEGORIES) + 1,
                'created_at'  => date('Y-m-d H:i:s', strtotime($now) + $i * 60),
                'updated_at'  => $now,
            ], $chunk));
        }

        DB::listen(function () {
            $this->queries++;
        });
    }

    /**
     * Simulate one incoming request: fresh package service (it is rebuilt per
     * request under PHP-FPM), fresh controller + setup(), the given request.
     */
    protected function freshController(Request $request): CRUDController
    {
        $this->app->forgetInstance('kamva-crud');
        KamvaCrud::clearResolvedInstance('kamva-crud');
        KamvaCrud::setDefaultACLMethod(fn ($route, $user, $row) => true);

        $request->setLaravelSession($this->app['session']->driver());
        $this->app->instance('request', $request);

        $controller = new BenchProductController($this->app->make(Form::class));
        $controller->init();

        return $controller;
    }

    /**
     * Run $operation (warm-up + measured iterations) and record the result.
     *
     * @param Closure $operation fn(): mixed — performs one request and returns its output
     * @param Closure $fingerprint fn(mixed $output): string — stable text form of the output
     * @return mixed The output of the last iteration.
     */
    protected function bench(string $scenario, Closure $operation, Closure $fingerprint)
    {
        $iterations = max(1, (int) (getenv('BENCH_ITERATIONS') ?: 15));
        $warmup     = 3;
        $times      = [];
        $queries    = [];
        $peak       = 0;
        $output     = null;

        for ($i = 0; $i < $warmup + $iterations; $i++) {
            gc_collect_cycles();
            if (function_exists('memory_reset_peak_usage')) {
                memory_reset_peak_usage();
            }
            $memBefore     = memory_get_usage();
            $this->queries = 0;

            $start  = hrtime(true);
            $output = $operation();
            $ms     = (hrtime(true) - $start) / 1e6;

            if ($i >= $warmup) {
                $times[]   = $ms;
                $queries[] = $this->queries;
                $peak      = max($peak, memory_get_peak_usage() - $memBefore);
            }
        }

        sort($times);
        $result = [
            'median_ms'   => round($times[intdiv(count($times), 2)], 3),
            'min_ms'      => round($times[0], 3),
            'p90_ms'      => round($times[(int) floor(0.9 * (count($times) - 1))], 3),
            'queries'     => max($queries),
            'peak_mem_kb' => (int) round($peak / 1024),
            'iterations'  => $iterations,
            'fingerprint' => md5($fingerprint($output)),
        ];

        $this->record($scenario, $result);

        return $output;
    }

    private function record(string $scenario, array $result): void
    {
        $all = is_file(self::RESULTS) ? json_decode(file_get_contents(self::RESULTS), true) : [];
        $all['php']       = PHP_VERSION;
        $all['laravel']   = $this->app->version();
        $all['scenarios'][$scenario] = $result;
        ksort($all['scenarios']);

        @mkdir(dirname(self::RESULTS), 0777, true);
        file_put_contents(self::RESULTS, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        if ($save = getenv('BENCH_SAVE')) {
            copy(self::RESULTS, $save);
        }

        $line = sprintf(
            "%-22s median %9.2f ms  min %9.2f ms  p90 %9.2f ms  %5d queries  %8d KB",
            $scenario, $result['median_ms'], $result['min_ms'], $result['p90_ms'], $result['queries'], $result['peak_mem_kb']
        );

        $baselineFile = getenv('BENCH_BASELINE');
        $baseline     = $baselineFile && is_file($baselineFile)
            ? (json_decode(file_get_contents($baselineFile), true)['scenarios'][$scenario] ?? null)
            : null;

        if ($baseline) {
            $line .= sprintf(
                "   vs baseline: %+.1f%% time, %+d queries",
                ($result['median_ms'] / $baseline['median_ms'] - 1) * 100,
                $result['queries'] - $baseline['queries']
            );
        }

        fwrite(STDERR, $line . "\n");

        if ($baseline) {
            $this->assertSame(
                $baseline['fingerprint'],
                $result['fingerprint'],
                "Output of scenario [{$scenario}] differs from the baseline: an optimisation changed behaviour."
            );
        }
    }
}
