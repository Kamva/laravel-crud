<?php

namespace Kamva\Crud\Tests\Unit;

use Kamva\Crud\KamvaCRUDServiceProvider;
use Kamva\Crud\Tests\TestCase;

class SmokeTest extends TestCase
{
    public function test_service_provider_is_loaded(): void
    {
        $this->assertTrue(
            isset($this->app->getLoadedProviders()[KamvaCRUDServiceProvider::class]),
            'KamvaCRUDServiceProvider should be loaded'
        );
    }

    public function test_makefield_helper_is_available(): void
    {
        $this->assertTrue(function_exists('makeField'));
        $this->assertTrue(function_exists('makefield')); // case-insensitive PHP function lookup
    }

    public function test_application_boots_clean(): void
    {
        // Just touching the kernel verifies the service provider's boot()
        // doesn't throw — guards against future regressions in package boot.
        $this->assertNotNull($this->app->make('kamva-crud'));
    }
}
