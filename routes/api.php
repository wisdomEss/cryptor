<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PublicSignatures\PublicSignaturesController;

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
    ->name('api.public.verify');

// Vous pouvez ajouter d'autres routes API ici