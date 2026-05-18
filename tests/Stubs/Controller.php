<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

/**
 * Test-only stub for `App\Http\Controllers\Controller`. The package's
 * CRUDController extends this class (a Laravel-app convention). In a real
 * Laravel application this class is created by the framework's app
 * scaffolding; the package's tests must provide their own.
 */
class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;
}
