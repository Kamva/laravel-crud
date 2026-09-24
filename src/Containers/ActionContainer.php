<?php

namespace Kamva\Crud\Containers;

use Kamva\Crud\KamvaCrud;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Str;
use Throwable;

class ActionContainer
{
    private string      $route;
    private string      $caption;
    private string      $render;
    private string      $method;
    private ?\Closure   $accessControlMethod;
    private array       $options    = [];
    private array       $parameters = [];
    private ?bool       $renderIsView = null;

    /** @var array{0:string,1:array}|false|null URL with placeholders, false when disabled. */
    private $urlTemplate = null;

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
        $this->route       = $route;
        $this->urlTemplate = null;
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
        $this->render       = $render;
        $this->renderIsView = null;
    }

    public function getRender($data)
    {
        // Cached (reset by setRender()): this runs for every action on every
        // row, and a miss (the usual case, an icon HTML string) is not cached
        // by the view finder, so each call probed the filesystem.
        $this->renderIsView ??= view()->exists($this->render);

        return $this->renderIsView ? view($this->render, compact('data')) : $this->render;
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
        $rowKeys    = [];
        foreach ($parameters as $key => $parameter) {
            if (Str::startsWith($parameter, '$')) {
                unset($parameters[$key]);

                $parameters[$key] = $data->{str_replace("$", "", $parameter)};
                $rowKeys[]        = $key;
            }
        }

        return $this->urlFromTemplate($parameters, $rowKeys) ?? route($this->route, $parameters);
    }

    /**
     * Fast path for url(): it runs for every action on every list row, and
     * route() is comparatively expensive. The URL is generated once with
     * placeholder values, then each row's values are substituted into it.
     *
     * Only used when the result is guaranteed to equal route()'s:
     *  - every row value is an int or a string of letters, digits, `-` and
     *    `_` (e.g. ids, UUIDs, slugs), which route() inserts verbatim (no
     *    encoding, no model binding);
     *  - the URL generator is Laravel's own, without formatHostUsing() /
     *    formatPathUsing() callbacks that could depend on the values;
     *  - each placeholder appears exactly once in the generated URL, and the
     *    template reproduces route() for the first real row.
     * Anything else returns null and url() calls route() as before.
     */
    private function urlFromTemplate(array $parameters, array $rowKeys): ?string
    {
        if ($this->urlTemplate === false) {
            return null;
        }

        $values = [];
        foreach ($rowKeys as $key) {
            $value = $parameters[$key];
            if (! is_int($value) && ! (is_string($value) && preg_match('/^[A-Za-z0-9_-]+$/D', $value))) {
                return null;
            }
            $values[$key] = (string) $value;
        }

        if ($this->urlTemplate === null) {
            $this->urlTemplate = $this->buildUrlTemplate($parameters, $rowKeys) ?? false;

            if ($this->urlTemplate === false) {
                return null;
            }
        }

        [$template, $placeholders] = $this->urlTemplate;

        $replace = [];
        foreach ($placeholders as $key => $placeholder) {
            $replace[$placeholder] = $values[$key];
        }

        return strtr($template, $replace);
    }

    /**
     * @return array{0:string,1:array}|null
     */
    private function buildUrlTemplate(array $parameters, array $rowKeys): ?array
    {
        $url = app('url');

        if (get_class($url) !== UrlGenerator::class) {
            return null;
        }

        try {
            foreach (['formatHostUsing', 'formatPathUsing'] as $property) {
                $reflection = new \ReflectionProperty(UrlGenerator::class, $property);
                $reflection->setAccessible(true);
                if ($reflection->getValue($url) !== null) {
                    return null;
                }
            }

            $placeholders = [];
            $probe        = $parameters;
            foreach ($rowKeys as $i => $key) {
                $placeholders[$key] = 'kcrudurl' . bin2hex(random_bytes(8)) . 'p' . $i;
                $probe[$key]        = $placeholders[$key];
            }

            $template = route($this->route, $probe);
            $expected = route($this->route, $parameters);
        } catch (Throwable $e) {
            return null;
        }

        foreach ($placeholders as $placeholder) {
            if (substr_count($template, $placeholder) !== 1) {
                return null;
            }
        }

        $replace = [];
        foreach ($placeholders as $key => $placeholder) {
            $replace[$placeholder] = (string) $parameters[$key];
        }

        return strtr($template, $replace) === $expected ? [$template, $placeholders] : null;
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
        $this->parameters  = $parameters;
        $this->urlTemplate = null;
    }

    public function hasAccess($data)
    {
        $default = KamvaCrud::get('default_acl_method');

        if (empty($this->getAccessControlMethod())) {
            if (empty($default)) {
                return true;
            }

            return $default($this->route, auth()->user(), $data);
        }

        // 'and' mode: the action's closure narrows the default permission
        // check instead of replacing it (see Service::setActionAclMode()).
        if (!empty($default) && KamvaCrud::getActionAclMode() === 'and' && !$default($this->route, auth()->user(), $data)) {
            return false;
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
