<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Ce que l'on retient du client HTTP pour la traçabilité (IP, navigateur).
 * Évite que les services dépendent de l'objet Request.
 */
final readonly class ClientContext
{
    public function __construct(
        public ?string $ip = null,
        public ?string $userAgent = null,
    ) {}

    public static function from(Request $request): self
    {
        return new self($request->ip(), $request->userAgent());
    }
}
