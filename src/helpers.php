<?php

use Kamva\Crud\Containers\FieldContainer;

function makeField($type, $caption, $name, $value = null){
    return (new FieldContainer($type, $caption, $name, $value))->field();
}
