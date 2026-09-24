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

    /** The request flushRequestState() last ran for. */
    private ?\WeakReference $request = null;

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

    public function hasColumnType($name): bool
    {
        return isset($this->columnHelpers[$name]);
    }

    public function setDefaultACLMethod(\Closure $callable)
    {
        $this->set('default_acl_method', $callable);
    }

    /**
     * How a row action's own access closure combines with the default ACL
     * method (setDefaultACLMethod()):
     *  - 'replace' (default): the action's closure is used instead of the
     *    default method, as in every release so far.
     *  - 'and': both must allow the action. The closure narrows the
     *    permission check (e.g. "only unlocked rows") instead of replacing it.
     */
    public function setActionAclMode(string $mode): void
    {
        if (!in_array($mode, ['replace', 'and'], true)) {
            throw new \InvalidArgumentException("Action ACL mode must be 'replace' or 'and', got '{$mode}'.");
        }

        $this->set('action_acl_mode', $mode);
    }

    public function getActionAclMode(): string
    {
        return $this->get('action_acl_mode') ?? 'replace';
    }

    /**
     * Set the dark mode preference for package views.
     * 'auto'  — follows the OS/browser prefers-color-scheme media query (default).
     * 'dark'  — always dark, regardless of system setting.
     * 'light' — always light, regardless of system setting.
     */
    public function setDarkMode(string $mode = 'auto'): void
    {
        $this->set('dark_mode', $mode);
    }

    public function getDarkMode(): string
    {
        return $this->get('dark_mode') ?? 'auto';
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

    /**
     * Forget what belongs to one request: the active controller ('class'),
     * the record being edited ('model') and the per-controller option
     * caches of source-backed fields. Settings made at boot (default ACL
     * method, dark mode, column types…) are kept. Called by
     * CRUDController::init() with the current request, so a long-lived
     * worker doesn't serve one request's state to the next; given a request
     * already flushed for, it does nothing.
     */
    public function flushRequestState(?object $request = null): void
    {
        // Once per request: a second controller initialised while handling
        // the same request must not drop the record the first one is editing.
        if ($request !== null) {
            if ($this->request?->get() === $request) {
                return;
            }

            $this->request = \WeakReference::create($request);
        }

        foreach (array_keys($this->data) as $key) {
            if ($key === 'class' || $key === 'model' || str_starts_with($key, 'source_cache_')) {
                unset($this->data[$key]);
            }
        }
    }

    public function set($key, $value)
    {
        $this->data[$key] = $value;

        return $this->data[$key];
    }

    public function isApi()
    {
        // Match the `api` segment exactly or an `api/...` prefix — not any
        // path that merely starts with the letters "api" (e.g. /apiary,
        // /api-docs), which the previous 'api*' glob misclassified as API
        // requests and served JSON for.
        return request()->is('api', 'api/*') || request()->routeIs('kamva-crud.process');
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
