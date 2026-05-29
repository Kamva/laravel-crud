<?php

namespace Kamva\Crud;

use Illuminate\Support\ServiceProvider;

class KamvaCRUDServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Singleton on purpose: the Service also holds GLOBAL registries that
        // consumers populate once from a service provider — addColumnType(),
        // addExtension(), setDefaultACLMethod(). Making it request-scoped would
        // flush those between Octane/Swoole request lifecycles and silently
        // disable custom column types and store extensions after the first
        // request. Per-request cache isolation is handled at the cache-key
        // level instead (see BaseField::getOptionsFromSource()).
        $this->app->singleton('kamva-crud', function () {
            return new Service();
        });
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
