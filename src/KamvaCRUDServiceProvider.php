<?php

namespace Kamva\Crud;

use Illuminate\Support\ServiceProvider;

class KamvaCRUDServiceProvider extends ServiceProvider
{

    public function register()
    {
        $this->app->singleton('kamva-crud', function () {
            return new Service();
        });
    }

    public function boot()
    {
        include __DIR__ . "/helpers.php";

        $this->loadRoutesFrom       (__DIR__ . '/routes.php');
        $this->loadViewsFrom        (__DIR__ . '/views',          'kamva-crud');
        $this->publishes([
            __DIR__ . '/views'      => resource_path('views/vendor/kamva-crud')
        ]);
    }
}
