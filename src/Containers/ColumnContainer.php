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

    /**
     * The database column this list column reads, for search and ordering,
     * or null when it doesn't read one: a Closure column, or a dotted column
     * that {@see getValue()} resolves through a relation on $model
     * (`'category.title'`). Without $model, dotted columns aren't checked.
     */
    public function guessColNameInDB($model = null)
    {
        if (!is_string($this->value)) {
            return null;
        }

        $segments   = explode(".", $this->value);
        $action     = $segments[1] ?? null;

        // Same resolution order as getValue(): a column type or a column
        // method handles the value first; otherwise a method on the model
        // means the first segment is a relation, not a column.
        if (
            !empty($action) && is_object($model)
            && !KamvaCrud::hasColumnType($action) && !method_exists($this, $action)
            && method_exists($model, $segments[0])
        ) {
            return null;
        }

        return $segments[0];
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
                    $data = $data !== null ? $data->$parameter : null;
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
        $field = KamvaCrud::get('class')->getForm()->getField($fieldName);

        if (empty($field)) {
            return null;
        }


        $value = $field->field()->getValue($data, $raw);

        return (count($field->field()->getOptions())) ? $field->field()->getOption($value, true) : $value;
    }
}
