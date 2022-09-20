<?php

namespace Kamva\Crud\Fields\Internal;


use Illuminate\Contracts\Support\Renderable;
use Kamva\Crud\KamvaCrud;

abstract class BaseField implements FieldContract
{
    protected $caption;
    protected $name;
    protected $value            = null;
    protected $storeCallback    = null;
    protected $options          = [];
    protected $config           = [];
    protected $observers        = [];
    protected $validations      = [];
    protected $source           = null;
    protected $field            = null;

    /**
     * @return mixed
     */
    public function getCaption()
    {
        return $this->caption;
    }

    /**
     * @param mixed $caption
     * @return FieldContract
     */
    public function setCaption($caption): FieldContract
    {
        $this->caption = $caption;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getName()
    {
        return $this->name ?? '';
    }

    public function observe($field, $callback): FieldContract
    {
        $this->observers[] = [
            'field'     => $field,
            'callback'  => $callback,
        ];

        return $this;
    }

    public function getObservers()
    {
        return $this->observers;
    }

    /**
     * @param mixed $name
     * @return FieldContract
     */
    public function setName($name): FieldContract
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @param $data
     * @param bool $raw
     * @return null
     */
    public function getValue($data = null, $raw = false)
    {
        $data = $data ?? $this->field;

        if($this->value instanceof \Closure){
            $callable = $this->value;
            return $callable($data);
        }

        if(!empty(old($this->getName()))){
            return old($this->getName());
        }

        $value = $this->value($data, $raw);
        if(!empty($value)){
            return $value;
        }

        if(!is_null($this->value)){
            return $this->value;
        };

        if(!empty($data)){
            return $data->{$this->getName()};
        }

        return null;
    }

    public function value($data, $raw = false) : ?string
    {
        return null;
    }
    /**
     * @param null $value
     * @return FieldContract
     */
    public function setValue($value): FieldContract
    {
        $this->value = $value;
        return $this;
    }

    public function getInitialValue()
    {
        return $this->value;
    }

    /**
     * @return array
     */
    public function getOptions(): array
    {
        return $this->getOptionsFromSource() ?? $this->options ?? [];
    }

    public function getOptionsFromSource()
    {
        $cacheKey = 'source_cache_' . $this->getName();

        if(!empty($this->getSource())){
            $source = KamvaCrud::get($cacheKey);
            return !empty($source) ? $source : KamvaCrud::set($cacheKey, $this->source->toArray());
        }

        return null;
    }
    /**
     * @param $key
     * @param bool $raw
     * @return string
     */
    public function getOption($key, $raw = false)
    {
        if(is_array($key)){
            $value = [];
            foreach ($key as $item) {
                $value[] = $this->getOption((string)$item) ?? '';
            }

            return $raw ? implode(" , ",$value) : $value;
        }
        return $this->getOptions()[(string) $key] ?? '';
    }

    /**
     * @param array $options
     * @return FieldContract
     */
    public function setOptions(array $options): FieldContract
    {

        $this->options += $options;

        return $this;
    }

    public function setConfig($config, $value = null): FieldContract
    {
        if(is_array($config)){
            $this->config += $config;
        }

        if(!empty($value)){
            $this->config += [$config => $value];
        }

        return $this;
    }

    public function getConfig($key = null)
    {
        return empty($key) ? $this->config : ($this->config[$key] ?? null);
    }
    public function setSource($model, $key = null, $value = null): FieldContract
    {
        $this->source = new FieldSource($model,$key ?? 'id',$value ?? 'title');
        return $this;
    }

    public function setFieldData($field)
    {
        $this->field = $field;
    }

    public function getSource()
    {
        if(!empty($this->source)){
            $this->source->setField($this->field);
        }

        return $this->source;
    }

    /**
     * @return null
     */
    public function getStoreCallback()
    {
        return $this->storeCallback ?? (function ($value){return $value;});
    }

    /**
     * @param null $storeCallback
     * @return FieldContract
     */
    public function saveAs($storeCallback): FieldContract
    {
        $this->storeCallback = $storeCallback;
        return $this;
    }

    /**
     * @return array
     */
    public function getValidation(): array
    {
        return array_merge($this->validations ?? [], $this->rules());
    }

    public function rules(): array
    {
        return [];
    }

    public function store($value, $oldValue)
    {
        return $value;
    }

    public function setValidation(array $validationArray): FieldContract
    {
        $this->validations = array_merge($this->validations, $validationArray);

        return $this;
    }

    public function destroy($model): bool
    {
        return true;
    }

    public function renderAsReadOnly($data) :Renderable
    {
        return view('kamva-crud::fields.read_only', [
            'field' => $this,
            'data'  => $data
        ]);
    }

    public function isMultiple()
    {
        return $this->getConfig('type') == self::MULTIPLE_SELECT;
    }

    public function setMultiple() : FieldContract
    {
        $this->setConfig('type',self::MULTIPLE_SELECT);
        return $this;
    }

    public function skip(): FieldContract
    {
        $this->setConfig('skip',true);
        return $this;
    }

    public function shouldSkipSaving() :bool
    {
        return $this->getConfig('skip') === true;
    }
}
