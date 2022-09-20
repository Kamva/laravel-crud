<?php

namespace Kamva\Crud\Extensions;

class ExtensionManager
{
    public const STORE_TYPE = "store";

    private $extensions = [];

    public function addExtension(\Closure $callable, $type)
    {
        $this->extensions[] = new Extension($callable, $type);
    }

    public function getExtensions($type)
    {
        return collect($this->extensions)->filter(function (Extension $extension) use ($type) {
            return $extension->getType() === $type;
        })->toArray();
    }
}
