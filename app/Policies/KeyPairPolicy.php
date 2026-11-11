<?php

namespace App\Policies;

use App\Models\KeyPair;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Une paire de clés n'est accessible que par son propriétaire.
 * Un refus répond 404 (et non 403) pour ne pas révéler l'existence de la ressource.
 */
class KeyPairPolicy
{
    public function view(User $user, KeyPair $key): Response
    {
        return $this->owner($user, $key);
    }

    public function update(User $user, KeyPair $key): Response
    {
        return $this->owner($user, $key);
    }

    public function delete(User $user, KeyPair $key): Response
    {
        return $this->owner($user, $key);
    }

    private function owner(User $user, KeyPair $key): Response
    {
        return $user->id === $key->user_id ? Response::allow() : Response::denyAsNotFound();
    }
}
