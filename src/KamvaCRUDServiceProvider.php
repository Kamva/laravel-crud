<?php

namespace Kamva\Crud;

use Illuminate\Support\ServiceProvider;

class KamvaCRUDServiceProvider extends ServiceProvider
{
    public function register()
    {
        // The Service holds per-request state (the active controller, current
        // model id, and per-field option caches). A plain singleton persists
        // across requests on long-running workers (Laravel Octane / Swoole),
        // leaking one request's state — and option caches — into the next.
        // Bind it request-scoped where the container supports it (Laravel 8.23+);
        // fall back to singleton on older versions.
        $factory = fn () => new Service();

        if (method_exists($this->app, 'scoped')) {
            $this->app->scoped('kamva-crud', $factory);
        } else {
            $this->app->singleton('kamva-crud', $factory);
        }
    }

     public function boot()
    {
        // Only include the helpers.php file if the makeField function doesn't exist
        if (!function_exists('makeField')) {
            include __DIR__ . "/helpers.php";
        }

        $this->loadRoutesFrom(__DIR__ . '/routes.php');
        $this->loadViewsFrom(__DIR__ . '/views', 'kamva-crud');
        $this->publishes([
            __DIR__ . '/views'      => resource_path('views/vendor/kamva-crud')
        ]);
    }
}
