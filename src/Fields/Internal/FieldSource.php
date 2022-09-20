<?php

namespace Kamva\Crud\Fields\Internal;

use Illuminate\Database\Eloquent\Builder;

class FieldSource
{
    private $model;
    private $key;
    private $value;
    private $field;

    public function __construct($model, $key, $value, $field = null)
    {
        $this->model    = $model;
        $this->key      = $key;
        $this->value    = $value;
        $this->field    = $field;
    }

    /**
     * @return mixed
     */
    public function getModel()
    {
        return $this->model;
    }

    public function getBuilder(): Builder
    {
        $model = $this->getModel();

        if ($model instanceof Builder) {
            $model = $this->getModel();
        }

        if ($model instanceof \Closure) {
            $model = $model($this->field);
        }

        if (is_string($model)) {
            $model = app($model)->newQuery();
        }

        return $model;
    }

    public function toArray(): array
    {
        return $this->getBuilder()->get()->pluck($this->getValue(), $this->getKey())->toArray();
    }

    /**
     * @param mixed $model
     */
    public function setModel($model): void
    {
        $this->model = $model;
    }

    /**
     * @return mixed
     */
    public function getKey()
    {
        return $this->key;
    }

    /**
     * @param mixed $key
     */
    public function setKey($key): void
    {
        $this->key = $key;
    }

    /**
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * @param mixed $value
     */
    public function setValue($value): void
    {
        $this->value = $value;
    }

    /**
     * @return mixed|null
     */
    public function getField()
    {
        return $this->field;
    }

    /**
     * @param mixed|null $field
     */
    public function setField($field): void
    {
        $this->field = $field;
    }
}
