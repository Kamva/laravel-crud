<?php

namespace Kamva\Crud\Containers;

use Kamva\Crud\KamvaCrud;

class ColumnContainer
{
    private $name;
    public $value;

    public function __construct($name, $value)
    {
        $this->name     = $name;
        $this->value    = $value;
    }

    public function getName()
    {
        return $this->name;
    }

    public function guessColNameInDB()
    {
        if (!is_string($this->value)) {
            return "_id";
        }

        return explode(".", $this->value)[0] ?? null;
    }

    public function getValue($data, $raw = false)
    {
        if (empty($data)) {
            return null;
        }

        $callable = $this->value;

        if ($callable instanceof \Closure) {
            return $callable($data, $raw);
        }

        $value          = explode(".", $callable);
        $action         = $value[1] ?? null;
        $parameters     = array_slice($value, 2);

        if (!empty($action)) {
            $extension      = KamvaCrud::callColumnType($action, $data, $value[0], $parameters ?? null, $raw);

            if (!empty($extension)) {
                return $extension;
            }

            if (method_exists($this, $action)) {
                return $this->{$action}($data, $value[0], $parameters ?? null, $raw);
            }

            if (method_exists($data, $value[0])) {
                foreach ($value ?? [] as $parameter) {
                    $data = $data->$parameter;
                }

                return $data;
            }
        }

        if (!is_object($data)) {
            return $data;
        }

        return $data->$callable;
    }

    public function field($data, $fieldName, $parameters, $raw)
    {
        $field = collect(KamvaCrud::get('class')->getForm()->getFields())->filter(function ($field) use ($fieldName) {
            return $field->getName() == $fieldName;
        })->first();

        if (empty($field)) {
            return null;
        }


        $value = $field->field()->getValue($data, $raw);

        return (count($field->field()->getOptions())) ? $field->field()->getOption($value, true) : $value;
    }
}
