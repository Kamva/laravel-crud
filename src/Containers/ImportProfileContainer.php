<?php

namespace Kamva\Crud\Containers;

use Kamva\Crud\CRUDImport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;

class ImportProfileContainer
{
    private $id;
    private $name;
    private $fields = [];
    private $eachCallback;
    private $sampleFile;


    public function __construct($id, $name, $field = null)
    {
        $this->id       = $id;
        $this->name     = $name;

        if ($field instanceof \Closure) {
            $field($this);
        }
    }

    public function getId()
    {
        return $this->id;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getFields()
    {
        return $this->fields;
    }

    public function addField($title, $value = null)
    {
        $this->fields[] = new ColumnContainer($title, $value);
        return end($this->fields);
    }

    public function import(Model $model, UploadedFile $file)
    {
        return Excel::import(new CRUDImport($this, $model), $file);
    }

    public function each($callback)
    {
        $this->eachCallback = $callback;
        return $this;
    }

    public function eachCallback(Collection $collection, ?Model $model)
    {
        if (empty($this->eachCallback)) {
            return null;
        }

        $callback = $this->eachCallback;

        return $callback($collection, $model);
    }

    public function sample($file)
    {
        $this->sampleFile = $file;
        return $this;
    }

    public function getSampleFile()
    {
        return $this->sampleFile;
    }
}
