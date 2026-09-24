<?php

namespace Kamva\Crud\Tests;

use Illuminate\Support\Facades\DB;
use Kamva\Crud\KamvaCRUDServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Tests run on in-memory SQLite. Set DB_CONNECTION=pgsql (plus DB_HOST,
 * DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD) to run them on Postgres.
 * Every test drops the database's public schema, so the database name must
 * contain "test".
 */
abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.connections.testing.driver') === 'pgsql') {
            // DB_* may be left over from an app's environment: never wipe a
            // database that isn't obviously a throwaway test one.
            $database = config('database.connections.testing.database');
            if (stripos($database, 'test') === false) {
                $this->fail("Refusing to drop the public schema of \"{$database}\": the Postgres test database name must contain \"test\".");
            }

            // Tests create their tables in setUp(); start each one from an
            // empty schema, as the in-memory SQLite database does. Fixtures
            // use SQLite's built-in NOCASE collation, so define it here.
            DB::unprepared('
                DROP SCHEMA public CASCADE;
                CREATE SCHEMA public;
                CREATE COLLATION "NOCASE" (provider = icu, locale = \'und-u-ks-level2\', deterministic = false);
            ');

            // Old application instances aren't always garbage-collected
            // between tests; close the connection so they can't use up
            // the server's connection limit.
            $this->beforeApplicationDestroyed(fn () => DB::disconnect());
        }
    }

    protected function getPackageProviders($app): array
    {
        return [
            KamvaCRUDServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', env('DB_CONNECTION') === 'pgsql' ? [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'testing'),
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
        ] : [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }
}
