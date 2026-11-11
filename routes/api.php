<?php

use App\Http\Controllers\PublicSignatures\PublicSignaturesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Routes API publiques (sans authentification)
Route::post('/public/verify', [PublicSignaturesController::class, 'verify'])
    ->middleware('throttle:20,1')
    ->name('api.public.verify');

// Vous pouvez ajouter d'autres routes API ici
