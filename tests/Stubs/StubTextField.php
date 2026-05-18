<?php

namespace Kamva\Crud\Tests\Stubs;

use Illuminate\Contracts\Support\Renderable;
use Kamva\Crud\Fields\Internal\BaseField;
use Kamva\Crud\Fields\Internal\FieldContract;

/**
 * Bare-bones text-input field for tests. Renders nothing useful; the
 * point is to be a concrete class string we can pass to
 * {@see \Kamva\Crud\Form::addField()} so FieldContainer can `new` it.
 */
class StubTextField extends BaseField implements FieldContract
{
    public function render($data = null): Renderable
    {
        return new class implements Renderable {
            public function render() { return ''; }
        };
    }

    public function store($value, $oldValue)
    {
        return $value;
    }
}
