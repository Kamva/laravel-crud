<?php

use Illuminate\Support\Facades\Route;
use Kamva\Crud\ProcessController;

Route::post('kc-process/observe', [ProcessController::class,'observe'])->name('kamva-crud.process');
