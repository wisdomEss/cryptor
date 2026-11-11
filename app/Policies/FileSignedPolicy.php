<?php

namespace App\Policies;

use App\Models\FileSigned;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Un document est accessible à la personne qui l'a envoyé ou qui l'a signé.
 */
class FileSignedPolicy
{
    public function view(User $user, FileSigned $file): Response
    {
        return $this->involved($user, $file);
    }

    public function download(User $user, FileSigned $file): Response
    {
        return $this->involved($user, $file);
    }

    private function involved(User $user, FileSigned $file): Response
    {
        $allowed = $user->id === $file->uploader_id
            || $file->signatures()->where('signer_id', $user->id)->exists();

        return $allowed ? Response::allow() : Response::denyAsNotFound();
    }
}
