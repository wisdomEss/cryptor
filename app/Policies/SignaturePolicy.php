<?php

namespace App\Policies;

use App\Models\Signature;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Seul le signataire peut consulter, valider, révoquer ou supprimer une signature.
 */
class SignaturePolicy
{
    public function view(User $user, Signature $signature): Response
    {
        return $this->signer($user, $signature);
    }

    public function update(User $user, Signature $signature): Response
    {
        return $this->signer($user, $signature);
    }

    public function delete(User $user, Signature $signature): Response
    {
        return $this->signer($user, $signature);
    }

    private function signer(User $user, Signature $signature): Response
    {
        return $user->id === $signature->signer_id ? Response::allow() : Response::denyAsNotFound();
    }
}
