<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Autorité de certification interne
    |--------------------------------------------------------------------------
    |
    | L'AC est créée automatiquement au premier usage (ou avec `php artisan
    | cryptor:ca-init`) : aucune configuration n'est nécessaire pour démarrer.
    | Sa clé privée est chiffrée avec APP_KEY, comme les clés des utilisateurs.
    |
    */
    'ca' => [
        'name' => env('CRYPTOR_CA_NAME', 'CRYPTOR Internal CA'),
        'organization' => env('CRYPTOR_ORGANIZATION', 'CRYPTOR'),
        // Code pays ISO 3166-1 sur 2 lettres (optionnel), ex. FR.
        'country' => env('CRYPTOR_COUNTRY'),
        'validity_days' => 3650,
        'directory' => 'pki', // sur le disque `local` (privé)
    ],

    /*
    |--------------------------------------------------------------------------
    | Certificats des utilisateurs
    |--------------------------------------------------------------------------
    */
    'certificate' => [
        'default_days' => 365,
        'max_days' => 3650,
    ],

];
