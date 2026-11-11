<?php

namespace App\Services\Signing;

use Illuminate\Http\Request;

/** Options d'une signature demandées par l'utilisateur. */
final readonly class SigningRequest
{
    public function __construct(
        public string $format = 'cms',
        public ?string $reason = null,
        public ?string $location = null,
        public ?string $contact = null,
        public bool $includeVisual = false,
        public int $expiresInDays = 0,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            format: (string) $request->input('signature_format', 'cms'),
            reason: $request->input('signing_reason'),
            location: $request->input('signing_location'),
            contact: $request->input('contact_info'),
            includeVisual: $request->boolean('include_visual'),
            expiresInDays: (int) $request->input('expires_in_days', 0),
        );
    }
}
