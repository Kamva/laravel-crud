<?php

use Illuminate\Support\Facades\Route;
use Kamva\Crud\ProcessController;

// Apply the `web` middleware group so this endpoint gets session + CSRF
// protection. Without it, package routes loaded via loadRoutesFrom() run
// with NO middleware (no CSRF, no session), leaving the observe endpoint
// open to cross-site POSTs. The shipped observe view sends the CSRF token.
Route::middleware('web')->group(function () {
    Route::post('kc-process/observe', [ProcessController::class, 'observe'])->name('kamva-crud.process');
});
