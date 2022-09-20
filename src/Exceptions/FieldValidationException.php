<?php

namespace Kamva\Crud\Exceptions;

use Exception;

class FieldValidationException extends Exception{

    private $field = null;
    public function __construct($message, $field = null)
    {
        $this->field = $field;
        parent::__construct($message);
    }

    public function getFieldName()
    {
        return $this->field;
    }

    public function setFieldName($field)
    {
        $this->field = $field;
    }
}
