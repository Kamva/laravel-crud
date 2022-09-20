<?php

namespace Kamva\Crud;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Kamva\Crud\Containers\ImportProfileContainer;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithStartRow;

class CRUDImport implements ToCollection, WithChunkReading, WithStartRow
{
    private $container;
    private $model;

    public function __construct(ImportProfileContainer $container, Model $model)
    {
        $this->container = $container;
        $this->model = $model;
    }

    /**
     * @param Collection $collection
     */
    public function collection(Collection $collection)
    {
        foreach ($collection as $item) {
            if (count($this->container->getFields()) > 0) {
                $model = $this->model;
                foreach ($this->container->getFields() as $i => $field) {
                    $model->{$field->getName()} = $field->getValue($item[$i] ?? null, true);
                }
                $model->save();
            }

            $this->container->eachCallback($item, $model ?? null);
        }
    }

    public function chunkSize(): int
    {
        return 50;
    }

    public function startRow(): int
    {
        return 2;
    }
}
