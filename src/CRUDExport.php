<?php

namespace Kamva\Crud;

use Maatwebsite\Excel\Concerns\FromArray;

class CRUDExport implements FromArray
{
    public function __construct($data)
    {
        $this->data = $data;
    }

    public function array(): array
    {
        return $this->data;
    }
}
