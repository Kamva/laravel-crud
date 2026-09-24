<?php

namespace Kamva\Crud\Tests\Performance;

use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Request-level benchmarks for the package's hot paths. Run with
 * `composer bench` (see BenchmarkCase for options).
 */
class CrudBenchmarkTest extends BenchmarkCase
{
    /** List view JSON: one DataTables draw of 100 rows, ordered by a column. */
    public function test_datatables_page(): void
    {
        $out = $this->bench('datatables_page', function () {
            $request = $this->dataTablesRequest([
                'start' => 200, 'length' => 100, 'draw' => '4',
                'order' => [['column' => 1, 'dir' => 'asc']],
            ]);

            return $this->freshController($request)->index($request);
        }, fn ($out) => json_encode($out));

        $this->assertCount(100, $out['data']);
        $this->assertSame(static::ROWS, $out['recordsTotal']);
    }

    /** List view JSON: global search + the search filter, 25 rows. */
    public function test_datatables_search(): void
    {
        $out = $this->bench('datatables_search', function () {
            $request = $this->dataTablesRequest([
                'start' => 0, 'length' => 25, 'draw' => '2',
                'search' => ['value' => 'Product 1'],
                'order'  => [['column' => 2, 'dir' => 'desc']],
                'q'      => 'product',
            ]);

            return $this->freshController($request)->index($request);
        }, fn ($out) => json_encode($out));

        $this->assertCount(25, $out['data']);
    }

    /** API index: one page of 100 records with relation and field entities. */
    public function test_api_index(): void
    {
        $out = $this->bench('api_index', function () {
            $request = Request::create('/api/products', 'GET', ['page' => 3]);

            return $this->freshController($request)->index($request);
        }, fn ($out) => $out->getContent());

        $this->assertCount(100, $out->getData(true)['data']);
    }

    /** Excel export of every row (the xlsx writer is stubbed out). */
    public function test_export(): void
    {
        $captured = null;
        Excel::swap(new class($captured) {
            private $captured;
            public function __construct(&$captured) { $this->captured = &$captured; }
            public function download($export, $name) { $this->captured = $export->array(); return 'downloaded'; }
        });

        $this->bench('export', function () use (&$captured) {
            $request = Request::create('/bench/products', 'GET', ['export' => 1]);
            $this->freshController($request)->index($request);

            return $captured;
        }, fn ($out) => json_encode($out));

        $this->assertCount(static::ROWS + 1, $captured);
    }

    /** Edit form: controller edit(), rendering every field and the observer scripts. */
    public function test_edit_form(): void
    {
        $out = $this->bench('edit_form', function () {
            $request    = Request::create('/bench/products/42/edit', 'GET');
            $controller = $this->freshController($request);
            $view       = $controller->edit(42);
            $form       = $controller->getForm();

            return $view->render() . $form->render($view->getData()['data']) . $form->scripts();
        }, fn ($out) => $this->decryptObserverPayloads($out));

        $this->assertStringContainsString("<k-crud id='category_id'", $out);
    }

    /**
     * The observer payload is encrypted with a random IV, so replace each one
     * with its plaintext to keep the fingerprint stable.
     */
    private function decryptObserverPayloads(string $html): string
    {
        return preg_replace_callback('/\{c: "([^"]+)"/', function ($m) {
            return '{c: "' . \Illuminate\Support\Facades\Crypt::decryptString(html_entity_decode($m[1])) . '"';
        }, $html);
    }

    private function dataTablesRequest(array $params): Request
    {
        return Request::create('/bench/products', 'GET', $params, [], [], [
            'HTTP_ACCEPT'           => 'application/json, text/javascript, */*; q=0.01',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
    }
}
