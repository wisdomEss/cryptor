<?php

use App\Http\Controllers\Keys\KeyController;
use App\Http\Controllers\PublicSignatures\PublicSignaturesController;
use App\Http\Controllers\Signatures\SignatureController;
use App\Http\Controllers\Verifications\VerificationController;
use App\Http\Controllers\Dashboard\DashboardController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

/*
|--------------------------------------------------------------------------
| Page d'accueil
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');


/*
|--------------------------------------------------------------------------
| Routes Publiques
|--------------------------------------------------------------------------
*/

// Page publique de vérification
Route::get('/verifications/public', [PublicSignaturesController::class, 'index'])
    ->name('verifications.public')
    ->middleware('throttle:30,1');


/*
|--------------------------------------------------------------------------
| Dashboard (protégé)
|--------------------------------------------------------------------------
*/

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');


/*
|--------------------------------------------------------------------------
| Routes protégées (auth)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth'])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Keys
    |--------------------------------------------------------------------------
    */

    Route::resource('keys', KeyController::class);

    Route::get('/keys/revoked', [KeyController::class, 'revoked'])
        ->name('keys.revoked');

    Route::post('/keys/{key}/revoke', [KeyController::class, 'revoke'])
        ->name('keys.revoke');

    Route::post('/keys/{key}/activate', [KeyController::class, 'activate'])
        ->name('keys.activate');

    Route::post('/keys/{key}/deactivate', [KeyController::class, 'deactivate'])
        ->name('keys.deactivate');

    Route::post('/keys/{key}/set-default', [KeyController::class, 'setDefault'])
        ->name('keys.set-default');

    Route::get('/keys/{key}/download', [KeyController::class, 'download'])
        ->name('keys.download');

    Route::post('/keys/{key}/download-private', [KeyController::class, 'downloadPrivate'])
        ->name('keys.download-private');

    Route::get('/keys/{key}/export/pem', [KeyController::class, 'exportPem'])
        ->name('keys.export.pem');

    Route::get('/keys/{key}/export/ssh', [KeyController::class, 'exportSsh'])
        ->name('keys.export.ssh');


    /*
    |--------------------------------------------------------------------------
    | Signatures
    |--------------------------------------------------------------------------
    */

    Route::resource('signatures', SignatureController::class);

    Route::get('/signatures/pending', [SignatureController::class, 'pending'])
        ->name('signatures.pending');

    Route::get('/signatures/revoked', [SignatureController::class, 'revoked'])
        ->name('signatures.revoked');

    Route::get('/signatures/expired', [SignatureController::class, 'expired'])
        ->name('signatures.expired');

    Route::post('/signatures/{signature}/revoke', [SignatureController::class, 'revoke'])
        ->name('signatures.revoke');

    // ✅ correction : plus de conflit avec resource update
    Route::patch('/signatures/{signature}/validate', 
        [SignatureController::class, 'validateSignature']
    )->name('signatures.validate');


    /*
    |--------------------------------------------------------------------------
    | Verifications (protégées)
    |--------------------------------------------------------------------------
    */

    Route::resource('verifications', VerificationController::class)
        ->only(['index', 'create', 'store', 'show']);
});


require __DIR__.'/settings.php';