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
        $ac = new ActionContainer();
        $ac->setCaption($this->caption);
        $ac->setMethod($this->method);
        $ac->setAccessControlMethod($this->accessControlMethod);
        $ac->setRender($this->render);
        $ac->setParameters($this->parameters);
        $ac->setOptions($this->options);

        return $ac;
    }
}
