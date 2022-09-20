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

    public function render($data = null)
    {
        return $this->field->render($data);
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
