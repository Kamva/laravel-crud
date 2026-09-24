<?php

namespace Kamva\Crud\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Kamva\Crud\CRUDController;
use Kamva\Crud\Form;
use Kamva\Crud\Tests\TestCase;

/**
 * addSearchField() builds a raw LIKE clause. Column identifiers must be
 * wrapped through the query grammar (quoted/escaped) rather than string-
 * interpolated, while the search value stays parameter-bound.
 */
class SearchFieldSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('search_widgets', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
        });

        SearchWidget::create(['name' => 'Apple', 'email' => 'a@x.test']);
        SearchWidget::create(['name' => 'banana', 'email' => 'b@x.test']);
    }

    public function test_search_is_case_insensitive_and_matches_expected_rows(): void
    {
        $results = $this->applySearch(['name', 'email'], 'apple')->get();

        $this->assertCount(1, $results);
        $this->assertSame('Apple', $results->first()->name);
    }

    public function test_column_identifier_is_wrapped_in_generated_sql(): void
    {
        $sql = $this->applySearch(['name'], 'x')->toSql();

        // Identifier wrapped (sqlite/MySQL: "name" / `name`; Postgres adds
        // a ::text cast), not bare.
        $this->assertMatchesRegularExpression('/lower\(["`]name["`](::text)?\)/i', $sql);
        $this->assertStringNotContainsString('LOWER(name)', $sql);
    }

    public function test_non_text_columns_can_be_searched(): void
    {
        // Postgres has no LOWER(integer); the column must be cast first.
        $results = $this->applySearch(['name', 'id'], '2')->get();

        $this->assertCount(1, $results);
        $this->assertSame('banana', $results->first()->name);
    }

    public function test_value_stays_bound_not_interpolated(): void
    {
        // A term containing a quote must not break the query — proves it is
        // bound, not concatenated.
        $results = $this->applySearch(['name'], "o'brien")->get();
        $this->assertCount(0, $results);
    }

    private function applySearch(array $columns, string $term)
    {
        $form = $this->app->make(Form::class);
        $controller = new class($form) extends CRUDController {
            public function setup(): void {}
        };

        $filter = $controller->addSearchField($columns, 'q');

        $rows = SearchWidget::query();
        $request = Request::create('/', 'GET', ['q' => $term]);
        $filter->apply($request, $rows);

        return $rows;
    }
}

class SearchWidget extends Model
{
    protected $table = 'search_widgets';
    public $timestamps = false;
    protected $guarded = [];
}
