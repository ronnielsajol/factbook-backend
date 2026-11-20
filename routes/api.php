<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DpwhController;
use App\Http\Controllers\AccessController;
Route::any('/dpwh/{endpoint}', DpwhController::class);
Route::post('/factbook/access', AccessController::class);

