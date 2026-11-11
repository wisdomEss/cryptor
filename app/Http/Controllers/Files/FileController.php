<?php

namespace App\Http\Controllers\Files;

use App\Http\Controllers\Controller;
use App\Models\FileSigned;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    /**
     * Télécharge le document original d'un fichier signé.
     *
     * Accès limité à la personne qui l'a uploadé ou qui l'a signé.
     */
    public function download(int $file): StreamedResponse
    {
        $fileSigned = FileSigned::findOrFail($file);
        $this->authorize('download', $fileSigned);

        // file_path est accesseur -> chemin absolu sur le disque 'local'.
        $relativePath = $fileSigned->getRawOriginal('file_path');

        if (! Storage::disk('local')->exists($relativePath)) {
            abort(404, 'File not found on disk.');
        }

        return Storage::disk('local')->download(
            $relativePath,
            $fileSigned->original_file_name
        );
    }
}
