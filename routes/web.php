<?php

use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Files\FileController;
use App\Http\Controllers\Keys\KeyController;
use App\Http\Controllers\Pki\PkiController;
use App\Http\Controllers\PublicSignatures\PublicSignaturesController;
use App\Http\Controllers\Signatures\SignatureController;
use App\Http\Controllers\Verifications\VerificationController;
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

// Infrastructure de clés (publique) : certificat de l'AC et liste de révocation signée.
Route::get('/pki/ca.crt', [PkiController::class, 'certificate'])
    ->middleware('throttle:30,1')
    ->name('pki.ca');

Route::get('/pki/revocations.json', [PkiController::class, 'revocations'])
    ->middleware('throttle:30,1')
    ->name('pki.revocations');

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

    // Routes littérales à déclarer avant Route::resource, sinon elles sont
    // capturées par keys.show (GET /keys/{key}).
    Route::get('/keys/revoked', [KeyController::class, 'revoked'])
        ->name('keys.revoked');

    Route::get('/keys/import', [KeyController::class, 'importForm'])
        ->name('keys.import.form');

    Route::post('/keys/import', [KeyController::class, 'import'])
        ->middleware('throttle:10,1')
        ->name('keys.import');

    Route::resource('keys', KeyController::class);

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

    Route::post('/keys/{key}/export/backup', [KeyController::class, 'exportBackup'])
        ->middleware('throttle:5,1')
        ->name('keys.export.backup');

    Route::get('/keys/{key}/export/pem', [KeyController::class, 'exportPem'])
        ->name('keys.export.pem');

    Route::get('/keys/{key}/export/ssh', [KeyController::class, 'exportSsh'])
        ->name('keys.export.ssh');

    /*
    |--------------------------------------------------------------------------
    | Signatures
    |--------------------------------------------------------------------------
    */

    // Idem : ces routes littérales doivent précéder Route::resource.
    Route::get('/signatures/pending', [SignatureController::class, 'pending'])
        ->name('signatures.pending');

    Route::get('/signatures/revoked', [SignatureController::class, 'revoked'])
        ->name('signatures.revoked');

    Route::get('/signatures/expired', [SignatureController::class, 'expired'])
        ->name('signatures.expired');

    Route::resource('signatures', SignatureController::class);

    Route::post('/signatures/{signature}/revoke', [SignatureController::class, 'revoke'])
        ->name('signatures.revoke');

    // La validation réutilise la même logique que la mise à jour (update).
    Route::get('/signatures/{signature}/download', [SignatureController::class, 'download'])
        ->name('signatures.download');

    Route::patch('/signatures/{signature}/validate', [SignatureController::class, 'update'])
        ->name('signatures.validate');

    /*
    |--------------------------------------------------------------------------
    | Fichiers
    |--------------------------------------------------------------------------
    */

    Route::get('/files/{file}/download', [FileController::class, 'download'])
        ->name('files.download');

    /*
    |--------------------------------------------------------------------------
    | Verifications (protégées)
    |--------------------------------------------------------------------------
    */

    Route::resource('verifications', VerificationController::class)
        ->only(['index', 'create', 'store', 'show']);
});

require __DIR__.'/settings.php';
