<?php

namespace Kamva\Crud\Fields\Internal;

use Illuminate\Contracts\Support\Renderable;

interface FieldContract
{
    public const MULTIPLE_SELECT = "multiple";

    public function render($data = null): Renderable;
    public function store($value, $oldValue);
    public function value($data, $raw = false): ?string;
    public function destroy($model): bool;


    public function setName($name): FieldContract;
    public function setCaption($caption): FieldContract;
    public function setValue($value): FieldContract;
    public function setOptions(array $options): FieldContract;
    public function setConfig($config, $value = null): FieldContract;
    public function setSource($model, $key = null, $value = null): FieldContract;

    /**
     * @param $storeCallback
     * @return FieldContract
     *
     * SaveAs will pass the received value to the callable
     * but when skip is enabled also you will have the saved model
     */
    public function saveAs($storeCallback): FieldContract;
    public function setValidation(array $validationArray): FieldContract;
    public function observe($field, $callback): FieldContract;
    public function setMultiple(): FieldContract;
    public function skip(): FieldContract;
}
