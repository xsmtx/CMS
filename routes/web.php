<?php

declare(strict_types=1);

use App\Http\Controllers\StorefrontController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public storefront
|--------------------------------------------------------------------------
|
| The admin and client areas are registered separately, in routes/admin.php
| and routes/client.php, from bootstrap/app.php.
|
*/

Route::get('/', StorefrontController::class)->name('storefront.home');
