<?php

use Kamva\Crud\Containers\FieldContainer;

if (!function_exists('makeField')) {
    function makeField($type, $caption, $name, $value = null)
    {
        return (new FieldContainer($type, $caption, $name, $value))->field();
    }
}
