<?php

namespace App\Services\Keys;

/**
 * Empreinte d'une clé publique : SHA-256 de sa structure DER (SubjectPublicKeyInfo),
 * identique à `openssl pkey -pubin -outform DER | sha256sum`. Une seule définition pour
 * toute l'application (liste des clés, signatures, vérifications).
 */
final class KeyFingerprint
{
    /** Empreinte complète, `AB:CD:...` (32 octets). */
    public static function of(string $publicKeyPem): string
    {
        return implode(':', str_split(strtoupper(self::hex($publicKeyPem)), 2));
    }

    /** Début de l'empreinte (8 octets), pour les listes. C'est un préfixe de of(). */
    public static function short(string $publicKeyPem): string
    {
        return implode(':', str_split(strtoupper(substr(self::hex($publicKeyPem), 0, 16)), 2));
    }

    private static function hex(string $publicKeyPem): string
    {
        $body = preg_replace('/-----(BEGIN|END) [A-Z ]+-----|\s+/', '', $publicKeyPem);
        $der = base64_decode((string) $body, true);

        // PEM illisible : on empreint le texte tel quel plutôt que de planter l'affichage.
        return hash('sha256', $der === false || $der === '' ? $publicKeyPem : $der);
    }
}
