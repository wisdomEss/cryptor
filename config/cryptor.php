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

    /*
    |--------------------------------------------------------------------------
    | Horodatage RFC 3161 (optionnel, désactivé par défaut)
    |--------------------------------------------------------------------------
    |
    | Sans `url`, CRYPTOR utilise un horodatage interne (heure du serveur) : il prouve
    | quand l'application a vu la signature, pas davantage. Avec une autorité
    | d'horodatage (TSA), l'heure est attestée par un tiers de confiance.
    |
    | `ca_file` : fichier PEM des certificats de la TSA à reconnaître (racine et
    | intermédiaires). Sans lui, le jeton est vérifié mais la TSA reste « non reconnue ».
    |
    | Exemple (FreeTSA, gratuit) : CRYPTOR_TSA_URL=https://freetsa.org/tsr
    |                               CRYPTOR_TSA_CA_FILE=/chemin/vers/freetsa-cacert.pem
    |
    */
    'tsa' => [
        'url' => env('CRYPTOR_TSA_URL'),
        'ca_file' => env('CRYPTOR_TSA_CA_FILE'),
        'timeout' => (int) env('CRYPTOR_TSA_TIMEOUT', 10),
    ],

];
