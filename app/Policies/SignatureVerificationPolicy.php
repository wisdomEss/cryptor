<?php

namespace App\Policies;

use App\Models\SignatureVerification;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Une vérification est visible par son auteur, par le signataire du document
 * et par la personne qui a envoyé le document.
 */
class SignatureVerificationPolicy
{
    public function view(User $user, SignatureVerification $verification): Response
    {
        if ($user->id === $verification->verifier_id) {
            return Response::allow();
        }

        $signature = $verification->signature;

        if ($signature && ($user->id === $signature->signer_id || $user->id === $signature->file?->uploader_id)) {
            return Response::allow();
        }

        return Response::denyAsNotFound();
    }
}
