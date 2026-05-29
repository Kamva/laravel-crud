<?php

namespace Kamva\Crud\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Kamva\Crud\CRUDImport;
use Kamva\Crud\Containers\ImportProfileContainer;
use Kamva\Crud\Tests\TestCase;
use RuntimeException;

/**
 * A chunk that fails part-way through must roll back, not leave rows from
 * earlier in the same chunk persisted.
 */
class ImportTransactionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('import_widgets', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });
    }

    public function test_chunk_rolls_back_when_a_row_fails(): void
    {
        $profile = new ImportProfileContainer('p', 'Profile', function (ImportProfileContainer $p) {
            $p->addField('name');
            // Throw while processing the second row.
            $p->each(function ($row) {
                if (($row[0] ?? null) === 'Bob') {
                    throw new RuntimeException('boom');
                }
            });
        });

        $import = new CRUDImport($profile, new ImportWidget());

        $rows = collect([
            collect(['Alice']),
            collect(['Bob']),
        ]);

        try {
            $import->collection($rows);
            $this->fail('expected the failing row to throw');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(0, ImportWidget::count(), 'the whole chunk must roll back');
    }

    public function test_successful_chunk_persists_all_rows(): void
    {
        $profile = new ImportProfileContainer('p', 'Profile', function (ImportProfileContainer $p) {
            $p->addField('name');
        });

        $import = new CRUDImport($profile, new ImportWidget());
        $import->collection(collect([collect(['Alice']), collect(['Bob'])]));

        $this->assertSame(2, ImportWidget::count());
    }
}

class ImportWidget extends Model
{
    protected $table = 'import_widgets';
    public $timestamps = false;
    protected $guarded = [];
}
