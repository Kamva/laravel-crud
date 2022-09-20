<?php

namespace Kamva\Crud\Containers;

use Kamva\Crud\Fields\Internal\FieldContract;

class FieldContainer
{

    private $type;
    private $caption;
    private $name;
    private $value;
    private $options;
    private $field;


    public function __construct($type, $caption, $name, $value = null, $options = [])
    {
        $this->type         = $type;
        $this->caption      = $caption;
        $this->name         = $name;
        $this->value        = $value;
        $this->options      = $options;

    }

    public function getName()
    {
        return $this->name;
    }
    public function field(): FieldContract
    {
        if(!empty($this->field)){
            return $this->field;
        }

        /** @var FieldContract $field */
        $field = new $this->type;
        $field->setCaption  ($this->caption);
        $field->setValue    ($this->value);
        $field->setName     ($this->name);
        $field->setOptions  ($this->options);

        $this->field = $field;
        return $field;
    }

    public function render($data,$readOnly = false)
    {
        return $readOnly ? $this->field()->renderAsReadOnly($data) : $this->field()->render($data);
    }
}
