<?php

namespace Kamva\Crud\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Kamva\Crud\Form;
use Kamva\Crud\Tests\Stubs\StubTextField;
use Kamva\Crud\Tests\TestCase;

class FormSaveTest extends TestCase
{
    public function test_save_writes_visible_writable_fields(): void
    {
        $form = $this->app->make(Form::class);
        $form->addField(StubTextField::class, 'Name', 'name');
        $form->addField(StubTextField::class, 'Email', 'email');

        $model = $this->makeBareModel();
        $form->saveToModel(
            Request::create('/', 'POST', ['name' => 'Alice', 'email' => 'a@b.c']),
            $model
        );

        $this->assertSame('Alice', $model->name);
        $this->assertSame('a@b.c', $model->email);
    }

    public function test_save_skips_read_only_fields_even_when_submitted(): void
    {
        $form = $this->app->make(Form::class);
        $form->addField(StubTextField::class, 'Name', 'name');
        $form->addField(StubTextField::class, 'Stage', 'stage')->readOnly();

        $model = $this->makeBareModel();
        $model->stage = 'starting';

        $form->saveToModel(
            Request::create('/', 'POST', ['name' => 'X', 'stage' => 'attacker-supplied']),
            $model
        );

        $this->assertSame('X', $model->name);
        $this->assertSame('starting', $model->stage, 'readOnly field must not accept request input');
    }

    public function test_save_skips_fields_hidden_by_show_when(): void
    {
        $form = $this->app->make(Form::class);
        $form->addField(StubTextField::class, 'Name', 'name');
        // Predicate: lost_reason only writable when stage === 'lost'
        $form->addField(StubTextField::class, 'Reason', 'lost_reason')->showWhen(
            fn ($m) => $m && ($m->stage ?? null) === 'lost'
        );

        $model = $this->makeBareModel();
        $model->stage = 'open';
        $model->lost_reason = null;

        $form->saveToModel(
            Request::create('/', 'POST', ['name' => 'Y', 'lost_reason' => 'price']),
            $model
        );

        $this->assertNull($model->lost_reason, 'hidden field must not accept writes — prevents UI-bypass exploits');
    }

    public function test_save_accepts_show_when_field_when_predicate_passes(): void
    {
        $form = $this->app->make(Form::class);
        $form->addField(StubTextField::class, 'Reason', 'lost_reason')->showWhen(
            fn ($m) => $m && ($m->stage ?? null) === 'lost'
        );

        $model = $this->makeBareModel();
        $model->stage = 'lost';

        $form->saveToModel(
            Request::create('/', 'POST', ['lost_reason' => 'competitor']),
            $model
        );

        $this->assertSame('competitor', $model->lost_reason);
    }

    public function test_get_visible_fields_returns_only_predicate_passing(): void
    {
        $form = $this->app->make(Form::class);
        $form->addField(StubTextField::class, 'Always', 'always');
        $form->addField(StubTextField::class, 'Conditional', 'conditional')->showWhen(
            fn ($m) => $m && ($m->show ?? false)
        );

        $hide = (object) ['show' => false];
        $show = (object) ['show' => true];

        $this->assertCount(1, $form->getVisibleFields($hide));
        $this->assertCount(2, $form->getVisibleFields($show));
        // When $model is null and the predicate tests for $model truthiness,
        // it returns false — so only the unconditional field shows.
        $this->assertCount(1, $form->getVisibleFields(null));
    }

    public function test_get_visible_fields_includes_fields_without_predicate(): void
    {
        $form = $this->app->make(Form::class);
        $form->addField(StubTextField::class, 'A', 'a');
        $form->addField(StubTextField::class, 'B', 'b');

        $this->assertCount(2, $form->getVisibleFields(null));
        $this->assertCount(2, $form->getVisibleFields((object) []));
    }

    private function makeBareModel(): Model
    {
        return new class extends Model {
            protected $guarded = [];
            public $timestamps = false;
        };
    }
}
