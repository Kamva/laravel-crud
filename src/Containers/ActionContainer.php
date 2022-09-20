<?php

namespace Kamva\Crud\Containers;

use Kamva\Crud\KamvaCrud;
use Illuminate\Support\Str;

class ActionContainer
{
    private string      $route;
    private string      $caption;
    private string      $render;
    private string      $method;
    private ?\Closure   $accessControlMethod;
    private array       $options    = [];
    private array       $parameters = [];

    public function __construct(string $caption = '', string $render = '', $acm = null, $parameters = [])
    {
        $this->caption              = $caption;
        $this->render               = $render;
        $this->accessControlMethod  = $acm;
        $this->parameters           = $parameters;
    }

    /**
     * @param string $route
     */
    public function setRoute(string $route): void
    {
        $this->route = $route;
    }

    /**
     * @param string $caption
     */
    public function setCaption(string $caption): void
    {
        $this->caption = $caption;
    }

    /**
     * @param string $render
     */
    public function setRender(string $render): void
    {
        $this->render = $render;
    }

    public function getRender($data)
    {
        return view()->exists($this->render) ? view($this->render, compact('data')) : $this->render;
    }

    /**
     * @return \Closure|null
     */
    public function getAccessControlMethod(): ?\Closure
    {
        return $this->accessControlMethod;
    }

    /**
     * @param \Closure|null $accessControlMethod
     */
    public function setAccessControlMethod(?\Closure $accessControlMethod): void
    {
        $this->accessControlMethod = $accessControlMethod;
    }

    /**
     * @param $key
     * @return string
     */
    public function getOption($key)
    {
        return $this->options[$key] ?? '';
    }

    public function getCaption()
    {
        return $this->caption;
    }

    public function url($data)
    {
        $parameters = $this->getParameters();
        foreach ($parameters as $key => $parameter) {
            if (Str::startsWith($parameter, '$')) {
                unset($parameters[$key]);

                $parameters[$key] = $data->{str_replace("$", "", $parameter)};
            }
        }

        return route($this->route, $parameters);
    }
    /**
     * @param array $options
     */
    public function setOptions(array $options): void
    {
        $this->options = $options;
    }

    /**
     * @return array
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * @param array $parameters
     */
    public function setParameters(array $parameters): void
    {
        $this->parameters = $parameters;
    }

    public function hasAccess($data)
    {
        if (empty($this->getAccessControlMethod())) {
            $default = KamvaCrud::get('default_acl_method');

            if (empty($default)) {
                return true;
            }

            return $default($this->route, auth()->user(), $data);
        }

        return $this->getAccessControlMethod()($data);
    }

    /**
     * @return string
     */
    public function getMethod(): string
    {
        return strtoupper($this->method ?? 'GET');
    }

    public function isMethod($method)
    {
        return strtoupper($this->method ?? 'GET') == strtoupper($method);
    }

    /**
     * @param string $method
     */
    public function setMethod(string $method): void
    {
        $this->method = $method;
    }
}
