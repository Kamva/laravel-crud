<?php

namespace Kamva\Crud\Actions\Internal;

use Kamva\Crud\Containers\ActionContainer;

class BaseAction
{
    public $method;
    public $caption;
    public $accessControlMethod;
    public $render;
    public $parameters;
    public $options;

    public function getAction()
    {
        // ActionContainer's setters are strictly typed (string/array). The
        // public properties here default to null, so a subclass that omits
        // any of them would otherwise trigger a TypeError. Coalesce to the
        // container's own defaults instead of crashing.
        $ac = new ActionContainer();
        $ac->setCaption($this->caption ?? '');
        $ac->setMethod($this->method ?? 'GET');
        $ac->setAccessControlMethod($this->accessControlMethod);
        $ac->setRender($this->render ?? '');
        $ac->setParameters($this->parameters ?? []);
        $ac->setOptions($this->options ?? []);

        return $ac;
    }
}
