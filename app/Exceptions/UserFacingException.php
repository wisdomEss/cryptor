<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Erreur dont le message est écrit pour l'utilisateur et peut lui être montré tel quel.
 *
 * Toute autre exception (SQL, OpenSSL, système de fichiers...) est inattendue : son message
 * peut révéler des détails internes. Elle est journalisée et remplacée par un message générique
 * (voir safeMessage()).
 */
class UserFacingException extends RuntimeException
{
    public const GENERIC = 'An unexpected error occurred. Please try again.';

    public const GENERIC_FR = 'Une erreur inattendue est survenue. Veuillez réessayer.';

    /** Message affichable : celui de l'exception si elle est « publique », sinon un message générique. */
    public static function safeMessage(\Throwable $e, string $generic = self::GENERIC): string
    {
        return $e instanceof self ? $e->getMessage() : $generic;
    }
}
