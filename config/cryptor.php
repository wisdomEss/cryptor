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

    /*
    |--------------------------------------------------------------------------
    | Adresse e-mail vérifiée obligatoire
    |--------------------------------------------------------------------------
    |
    | Activé : un compte doit avoir confirmé son adresse (lien reçu par e-mail) pour
    | utiliser clés, signatures et vérifications. Il peut toujours consulter la page de
    | vérification, renvoyer le lien, corriger son adresse et se déconnecter.
    |
    | Exige un vrai serveur d'e-mail (MAIL_*). Pour un essai local avec MAIL_MAILER=log,
    | le lien est écrit dans storage/logs/laravel.log, ou on valide un compte à la main :
    |   php artisan cryptor:verify-user adresse@exemple.org
    |
    | Mettre à false uniquement pour une instance privée sans serveur d'e-mail.
    |
    */
    'require_verified_email' => (bool) env('CRYPTOR_REQUIRE_VERIFIED_EMAIL', true),

    /*
    |--------------------------------------------------------------------------
    | HTTPS derrière un proxy (Cloudflare, nginx, répartiteur de charge)
    |--------------------------------------------------------------------------
    |
    | Quand le TLS est terminé en amont, Laravel reçoit la requête en HTTP simple et génère
    | des URLs `http://` (feuilles de style, scripts, formulaires) : le navigateur les bloque
    | (contenu mixte, CSP). `force_https` impose `https://` à toutes les URLs générées, envoie
    | HSTS et ajoute `upgrade-insecure-requests` à la CSP.
    |
    | Par défaut il est actif si APP_URL commence par https:// : c'est la seule chose à régler
    | (APP_URL=https://votre-domaine). CRYPTOR_FORCE_HTTPS permet de le forcer à la main.
    |
    */
    'force_https' => filter_var(
        env('CRYPTOR_FORCE_HTTPS', str_starts_with((string) env('APP_URL'), 'https://')),
        FILTER_VALIDATE_BOOLEAN
    ),

    /*
    |--------------------------------------------------------------------------
    | Sources supplémentaires de la Content-Security-Policy (production)
    |--------------------------------------------------------------------------
    |
    | Listes séparées par des espaces, vides par défaut. Exemple pour l'analytique Cloudflare
    | injectée automatiquement par le proxy :
    |   CRYPTOR_CSP_SCRIPT_SRC="https://static.cloudflareinsights.com"
    |   CRYPTOR_CSP_CONNECT_SRC="https://cloudflareinsights.com"
    |
    */
    'csp' => [
        'script_src' => env('CRYPTOR_CSP_SCRIPT_SRC', ''),
        'connect_src' => env('CRYPTOR_CSP_CONNECT_SRC', ''),
    ],

];
