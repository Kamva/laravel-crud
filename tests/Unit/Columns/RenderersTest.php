<?php

namespace Kamva\Crud\Tests\Unit\Columns;

use Kamva\Crud\Columns\Renderers as R;
use Kamva\Crud\Tests\TestCase;

class RenderersTest extends TestCase
{
    public function test_badge_from_attribute_uses_default_secondary_color(): void
    {
        $r = R::badge('status');
        $row = (object) ['status' => 'open'];
        $this->assertSame('<span class="badge badge-secondary">open</span>', $r($row));
    }

    public function test_badge_from_closure_label_and_dynamic_color(): void
    {
        $r = R::badge(
            fn ($row) => strtoupper($row->status),
            ['colorBy' => fn ($row) => $row->status === 'open' ? 'success' : 'danger']
        );
        $this->assertSame('<span class="badge badge-success">OPEN</span>', $r((object) ['status' => 'open']));
        $this->assertSame('<span class="badge badge-danger">CLOSED</span>', $r((object) ['status' => 'closed']));
    }

    public function test_badge_escapes_label_text(): void
    {
        $r = R::badge('status');
        $row = (object) ['status' => '<script>alert(1)</script>'];
        $rendered = $r($row);
        $this->assertStringNotContainsString('<script>', $rendered);
        $this->assertStringContainsString('&lt;script&gt;', $rendered);
    }

    public function test_badge_empty_falls_back_to_dash(): void
    {
        $r = R::badge('status');
        $this->assertSame('—', $r((object) ['status' => null]));
        $this->assertSame('—', $r((object) ['status' => '']));
    }

    public function test_link_renders_anchor_with_label_and_href(): void
    {
        $r = R::link('name', fn ($row) => '/users/' . $row->id);
        $row = (object) ['id' => 42, 'name' => 'Alice'];

        $rendered = $r($row);
        $this->assertSame('<a href="/users/42">Alice</a>', $rendered);
    }

    public function test_link_with_class_target_and_title(): void
    {
        $r = R::link('name', fn () => '/x', ['class' => 'text-primary', 'target' => 'blank', 'title' => 'Open']);
        $rendered = $r((object) ['name' => 'X']);

        $this->assertStringContainsString('class="text-primary"', $rendered);
        $this->assertStringContainsString('target="_blank"', $rendered);
        $this->assertStringContainsString('rel="noopener"', $rendered);
        $this->assertStringContainsString('title="Open"', $rendered);
    }

    public function test_link_escapes_href_and_label(): void
    {
        $r = R::link('name', fn () => '"><script>');
        $rendered = $r((object) ['name' => '<b>X</b>']);

        $this->assertStringNotContainsString('<script>', $rendered);
        $this->assertStringContainsString('&lt;b&gt;X&lt;/b&gt;', $rendered);
    }

    public function test_boolean_uses_check_and_dash_by_default(): void
    {
        $r = R::boolean('active');
        $this->assertSame('✓', $r((object) ['active' => true]));
        $this->assertSame('—', $r((object) ['active' => false]));
        $this->assertSame('—', $r((object) ['active' => null]));
    }

    public function test_boolean_accepts_predicate_closure(): void
    {
        $r = R::boolean(fn ($row) => $row->count > 0);
        $this->assertSame('✓', $r((object) ['count' => 5]));
        $this->assertSame('—', $r((object) ['count' => 0]));
    }

    public function test_truncate_cuts_long_text_with_ellipsis(): void
    {
        $r = R::truncate('bio', 10);
        $row = (object) ['bio' => 'This is a very long bio'];
        // mb_strimwidth counts the ellipsis toward the limit.
        $this->assertSame('This is a…', $r($row));
    }

    public function test_truncate_returns_dash_for_empty(): void
    {
        $r = R::truncate('bio', 10);
        $this->assertSame('—', $r((object) ['bio' => '']));
        $this->assertSame('—', $r((object) ['bio' => null]));
    }

    public function test_date_formats_carbon_and_strings(): void
    {
        $r = R::date('when', 'Y-m-d');
        $this->assertSame('2026-05-18', $r((object) ['when' => \Carbon\Carbon::create(2026, 5, 18, 12, 0, 0)]));
        $this->assertSame('2026-05-18', $r((object) ['when' => '2026-05-18T12:00:00Z']));
        $this->assertSame('—', $r((object) ['when' => null]));
    }
}
