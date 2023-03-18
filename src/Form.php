<?php

namespace Kamva\Crud;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rules\Unique;
use Kamva\Crud\Containers\FieldContainer;
use Kamva\Crud\Exceptions\FieldValidationException;
use Kamva\Crud\Extensions\ExtensionManager;
use Kamva\Crud\Fields\Internal\FieldContract;

class Form
{
    private $fields = [];
    private $readOnly = false;
    private $action;
    private $method;
    private $fieldData;

    /**
     * @param $type
     * @param $caption
     * @param $name
     * @param null $value
     * @param array $options
     * @return FieldContract
     */
    public function addField($type, $caption, $name, $value = null, $options = [])
    {
        $this->fields[] = new FieldContainer($type, $caption, $name, $value, $options);
        return (end($this->fields))->field();
    }

    public function isReadOnly()
    {
        return $this->readOnly;
    }
    public function render($data = null)
    {
        $out = '';

        foreach ($this->fields as $field) {
            $field->field()->setFieldData($this->fieldData);

            $out .= $this->wrapWithDiv($field->getName(), $field->render($data, $this->readOnly));
        }

        return $out;
    }

    public function setFieldData($data)
    {
        $this->fieldData = $data;
    }

    public function wrapWithDiv($name, $render)
    {
        return "<k-crud id='". $name ."' style='width:100%'>" . $render . "</k-crud>";
    }

    public function setRenderAsReadOnly($readOnly = true)
    {
        $this->readOnly = $readOnly;
    }

    public function setAction($action, $method = 'POST')
    {
        $this->action = $action;
        $this->method = $method;

        return $this;
    }

    public function getAction()
    {
        return $this->action;
    }

    public function getFields()
    {
        return $this->fields;
    }

    public function getMethod()
    {
        return $this->method;
    }

    public function saveToModel(Request $request, &$model, $saveIt = false)
    {
        KamvaCrud::set('model', $model);

        $fields = collect($this->fields)->filter(function ($field) {
            return !$field->field()->shouldSkipSaving();
        })->toArray();

        $inputs = $request->all();

        foreach ($fields as $field) {
            $field = $field->field();
            $input = $inputs[$field->getName()] ?? null;

            try {
                $context = KamvaCrud::RunExtension(ExtensionManager::STORE_TYPE, $field->store($field->getStoreCallback()($input), $model->{$field->getName()}));
            } catch (FieldValidationException $e) {
                $e->setFieldName($field->getName());
                throw $e;
            }

            if (!is_null($context) || $field->isMultiple()) {
                $model->{$field->getName()} = $context;
            }
        }

        if ($saveIt) {
            $model->save();
            $this->runSkippedMethods($request, $model);
        }
    }

    private function runSkippedMethods(Request $request, $model): void
    {
        $fields = collect($this->fields)->filter(function ($field) {
            return $field->field()->shouldSkipSaving();
        })->toArray();

        foreach ($fields as $field) {
            $field = $field->field();
            $input = $request->get($field->getName());
            try {
                $field->getStoreCallback()($input, $model);
            } catch (FieldValidationException $e) {
                $e->setFieldName($field->getName());
                throw $e;
            }
        }
    }


    public function validate(Request $request, $model = null)
    {
        return $request->validate(...$this->createValidationArray($model));
    }

    private function createValidationArray($model)
    {
        $rules      = [];
        $attributes = [];

        foreach ($this->fields as $field) {
            $field          = $field->field();
            $fieldRules     = $field->getValidation();

            if (count($fieldRules)) {
                if (!empty($model)) {
                    foreach ($fieldRules as $i => $fieldRule) {
                        if ($fieldRule instanceof Unique) {
                            $fieldRules[$i] = $fieldRule->ignore($model->{$field->getName()}, $field->getName());
                        }
                    }
                }

                if ($field->isMultiple()) {
                    $rules      [$field->getName()   . ".*"]     = $fieldRules;
                    $attributes [$field->getName()   . ".*"]     = $field->getCaption();
                } else {
                    $rules      [$field->getName()         ]     = $fieldRules;
                    $attributes [$field->getName()         ]     = $field->getCaption();
                }
            }
        }
        return [$rules,[],$attributes];
    }

    public function scripts()
    {
        $out = '';

        foreach ($this->fields as $field) {
            foreach ($field->field()->getObservers() as $observe) {
                $out .= view('kamva-crud::observe', [
                    'observe'   => $observe,
                    'field'     => $field,
                    'c'         => Crypt::encryptString($observe['field'] . "|" . $field->getName() . "|" . (get_class(KamvaCrud::get('class')) ?? "") . "|" . KamvaCrud::get('model'))
                ])->render();
            }
        }

        return $out;
    }
}
