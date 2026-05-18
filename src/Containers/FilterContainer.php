<?php

namespace Kamva\Crud\Containers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Kamva\Crud\Fields\Internal\FieldContract;

class FilterContainer
{
    private $input;
    private $callback;
    private $field;

    public function __construct($input, \Closure $callback, ?FieldContract $field = null)
    {
        $this->input        = $input;
        $this->callback     = $callback;
        $this->field        = $field;
    }

    /**
     * Render the filter's form field. Returns an empty string when no field
     * is registered (hidden filter — applies via the callback but renders no
     * UI). Callers should use {@see hasField()} when they want to skip
     * iterating over hidden filters entirely.
     *
     * @param mixed $data
     * @return mixed Renderable or string ('' when no field).
     */
    public function render($data = null)
    {
        if ($this->field === null) {
            return '';
        }
        return $this->field->render($data);
    }

    /**
     * Whether this filter has a UI field. Filters registered without a field
     * are "hidden" — applied to the query when their input is present but
     * never rendered in the form. List/index views should check this before
     * including the filter in their filter row.
     */
    public function hasField(): bool
    {
        return $this->field !== null;
    }

    public function getField(): ?FieldContract
    {
        return $this->field;
    }

    public function getInputName()
    {
        return $this->input;
    }

    public function apply(Request $request, Builder &$rows)
    {
        $callback = $this->callback;
        return $callback($request, $rows);
    }
}
