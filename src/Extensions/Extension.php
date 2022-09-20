<?php

namespace Kamva\Crud\Extensions;

class Extension
{
    private $callable;
    private $type;

    public function __construct(\Closure $callable, $type)
    {
        $this->type         = $type;
        $this->callable     = $callable;
    }

    /**
     * @return \Closure
     */
    public function getCallable(): \Closure
    {
        return $this->callable;
    }

    /**
     * @return mixed
     */
    public function getType()
    {
        return $this->type;
    }

}
