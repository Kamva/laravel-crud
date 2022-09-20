<?php

namespace Kamva\Crud;

use Closure;
use Kamva\Crud\Extensions\Extension;
use Kamva\Crud\Extensions\ExtensionManager;

class Service
{
    private $columnHelpers      = [];
    private $data               = [];
    private $extensionManager;

    public function __construct()
    {
        $this->extensionManager = new ExtensionManager();
    }

    public function addExtension($type, Closure $callable)
    {
        $this->extensionManager->addExtension($callable, $type);
    }

    public function addColumnType($name, Closure $callback)
    {
        $this->columnHelpers[$name] = $callback;
    }

    public function setDefaultACLMethod(\Closure $callable)
    {
        $this->set('default_acl_method', $callable);
    }

    public function callColumnType($name, ...$parameters)
    {
        $callback = $this->columnHelpers[$name] ?? null;

        return empty($callback) ? null : call_user_func_array($callback, $parameters);
    }

    /**
     * @param $key
     * @return mixed
     */
    public function get($key)
    {
        return $this->data[$key] ?? null;
    }

    public function set($key, $value)
    {
        $this->data[$key] = $value;

        return $this->data[$key];
    }

    public function isApi()
    {
        return request()->is('api*') || request()->routeIs('kamva-crud.process');
    }

    public function apiResponse($data, $code = 200)
    {
        return response()->json($data, $code, ['Content-Type' => 'application/json;charset=UTF-8', 'Charset' => 'utf-8'], JSON_UNESCAPED_UNICODE);
    }

    public function RunExtension($type, $context)
    {
        /** @var Extension $extension */
        foreach ($this->extensionManager->getExtensions($type) as $extension) {
            $context = $extension->getCallable()($context);
        }

        return $context;
    }
}
