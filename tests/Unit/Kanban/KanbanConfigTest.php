<?php

namespace Kamva\Crud\Tests\Unit\Kanban;

use Kamva\Crud\Kanban\KanbanConfig;
use Kamva\Crud\Tests\TestCase;

class KanbanConfigTest extends TestCase
{
    public function test_bucket_groups_models_by_attribute_into_defined_columns(): void
    {
        $config = $this->makeConfig();
        $models = [
            (object) ['id' => 1, 'stage' => 'new',       'name' => 'A'],
            (object) ['id' => 2, 'stage' => 'new',       'name' => 'B'],
            (object) ['id' => 3, 'stage' => 'contacted', 'name' => 'C'],
        ];

        $buckets = $config->bucket($models);

        $this->assertSame(2, $buckets['new']['count']);
        $this->assertSame(1, $buckets['contacted']['count']);
        $this->assertSame(0, $buckets['qualified']['count']);
    }

    public function test_bucket_drops_models_whose_value_is_outside_defined_columns(): void
    {
        $config = $this->makeConfig();
        $models = [
            (object) ['id' => 1, 'stage' => 'new',       'name' => 'A'],
            (object) ['id' => 2, 'stage' => 'unknown',   'name' => 'B'],
            (object) ['id' => 3, 'stage' => null,        'name' => 'C'],
        ];

        $buckets = $config->bucket($models);

        $this->assertSame(1, array_sum(array_column($buckets, 'count')));
    }

    public function test_bucket_sums_card_value_for_value_sum(): void
    {
        $config = $this->makeConfig(fn ($m) => ['title' => $m->name, 'value' => $m->price ?? 0]);

        $models = [
            (object) ['id' => 1, 'stage' => 'new', 'name' => 'A', 'price' => 100],
            (object) ['id' => 2, 'stage' => 'new', 'name' => 'B', 'price' => 250],
            (object) ['id' => 3, 'stage' => 'new', 'name' => 'C'],  // no price
        ];

        $buckets = $config->bucket($models);
        $this->assertSame(350.0, $buckets['new']['value_sum']);
    }

    public function test_bucket_resolves_backed_enum_attribute_values(): void
    {
        $config = $this->makeConfig();

        $stage = StubStage::New;
        $model = new \stdClass();
        $model->id = 1;
        $model->stage = $stage;
        $model->name = 'EnumLead';

        $buckets = $config->bucket([$model]);
        $this->assertSame(1, $buckets['new']['count']);
    }

    public function test_card_renderer_must_return_array_else_silently_skipped(): void
    {
        $config = new KanbanConfig(
            attribute: 'stage',
            columns: ['new' => ['label' => 'New']],
            cardRenderer: fn ($m) => null, // bad — won't be a card
            transitionRoute: 'fake.route',
        );

        $buckets = $config->bucket([
            (object) ['id' => 1, 'stage' => 'new'],
        ]);

        $this->assertSame(0, $buckets['new']['count']);
    }

    public function test_card_id_auto_filled_from_model_getKey_when_missing(): void
    {
        $model = new class {
            public string $stage = 'new';
            public function getKey() { return 'uuid-123'; }
        };

        $config = $this->makeConfig(fn ($m) => ['title' => 'X']);
        $buckets = $config->bucket([$model]);

        $this->assertSame('uuid-123', $buckets['new']['cards'][0]['id']);
    }

    private function makeConfig(?\Closure $renderer = null): KanbanConfig
    {
        $renderer ??= fn ($m) => ['title' => $m->name ?? '', 'id' => $m->id ?? null];
        return new KanbanConfig(
            attribute: 'stage',
            columns: [
                'new'       => ['label' => 'New'],
                'contacted' => ['label' => 'Contacted'],
                'qualified' => ['label' => 'Qualified'],
            ],
            cardRenderer: $renderer,
            transitionRoute: 'crud.lead.transition',
        );
    }
}

enum StubStage: string
{
    case New = 'new';
    case Contacted = 'contacted';
}
