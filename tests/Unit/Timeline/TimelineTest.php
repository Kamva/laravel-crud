<?php

namespace Kamva\Crud\Tests\Unit\Timeline;

use Kamva\Crud\Tests\TestCase;
use Kamva\Crud\Timeline\Timeline;
use Kamva\Crud\Timeline\TimelineEvent;

class TimelineTest extends TestCase
{
    public function test_empty_timeline_returns_empty_collection(): void
    {
        $t = new Timeline();
        $this->assertCount(0, $t->for((object) []));
    }

    public function test_merges_multiple_sources_and_sorts_descending(): void
    {
        $t = new Timeline();

        $t->addSource('older', fn () => [
            ['at' => '2025-01-01', 'title' => 'old A'],
            ['at' => '2025-01-05', 'title' => 'old B'],
        ]);
        $t->addSource('newer', fn () => [
            ['at' => '2025-02-15', 'title' => 'new'],
        ]);

        $events = $t->for(null);

        $this->assertCount(3, $events);
        $this->assertSame('new',   $events[0]->title);
        $this->assertSame('old B', $events[1]->title);
        $this->assertSame('old A', $events[2]->title);
    }

    public function test_accepts_timeline_event_instances_or_arrays(): void
    {
        $t = new Timeline();
        $t->addSource('mix', fn () => [
            new TimelineEvent(new \DateTimeImmutable('2025-03-01'), title: 'as-instance'),
            ['at' => '2025-03-02', 'title' => 'as-array'],
        ]);

        $events = $t->for(null);
        $this->assertSame('as-array',    $events[0]->title);
        $this->assertSame('as-instance', $events[1]->title);
    }

    public function test_skips_malformed_entries(): void
    {
        $t = new Timeline();
        $t->addSource('mixed', fn () => [
            'not-an-array-or-event',
            ['at' => '2025-01-01', 'title' => 'good'],
            42,
            null,
        ]);

        $events = $t->for(null);
        $this->assertCount(1, $events);
        $this->assertSame('good', $events[0]->title);
    }

    public function test_array_entry_with_bad_at_string_is_skipped_not_thrown(): void
    {
        // Codex P1: malformed `at` strings must not break the whole timeline.
        // Without the try/catch in for(), `new DateTimeImmutable('not a date')`
        // would throw and fail the entire detail-page request.
        $t = new Timeline();
        $t->addSource('mixed', fn () => [
            ['at' => 'definitely not a date', 'title' => 'bad'],
            ['at' => '2025-01-02', 'title' => 'good'],
        ]);

        $events = $t->for(null);
        $this->assertCount(1, $events);
        $this->assertSame('good', $events[0]->title);
    }

    public function test_throwing_producer_does_not_break_other_sources(): void
    {
        $t = new Timeline();
        $t->addSource('exploding', fn () => throw new \RuntimeException('boom'));
        $t->addSource('healthy',   fn () => [['at' => '2025-01-01', 'title' => 'fine']]);

        $events = $t->for(null);
        $this->assertCount(1, $events);
        $this->assertSame('fine', $events[0]->title);
    }

    public function test_remove_source_by_name(): void
    {
        $t = new Timeline();
        $t->addSource('a', fn () => [['at' => '2025-01-01', 'title' => 'A']]);
        $t->addSource('b', fn () => [['at' => '2025-01-02', 'title' => 'B']]);
        $t->removeSource('a');

        $this->assertFalse($t->hasSource('a'));
        $this->assertTrue($t->hasSource('b'));
        $this->assertCount(1, $t->for(null));
    }

    public function test_re_registering_same_name_replaces_source(): void
    {
        $t = new Timeline();
        $t->addSource('s', fn () => [['at' => '2025-01-01', 'title' => 'V1']]);
        $t->addSource('s', fn () => [['at' => '2025-01-01', 'title' => 'V2']]);

        $this->assertSame(['s'], $t->getSourceNames());
        $this->assertSame('V2', $t->for(null)[0]->title);
    }

    public function test_producer_receives_model(): void
    {
        $t = new Timeline();
        $captured = null;
        $t->addSource('introspect', function ($m) use (&$captured) {
            $captured = $m;
            return [];
        });

        $model = (object) ['id' => 99];
        $t->for($model);

        $this->assertSame($model, $captured);
    }

    public function test_timeline_event_normalisation_handles_carbon(): void
    {
        $at = \Carbon\Carbon::create(2025, 6, 1, 12);
        $e  = TimelineEvent::fromArray(['at' => $at, 'title' => 'x']);
        $this->assertSame($at->getTimestamp(), $e->at->getTimestamp());
    }
}
