# CLAUDE.md

Guide pour Claude Code sur le projet **CRYPTOR** : application web de PKI légère pour générer des paires de clés, signer des documents et vérifier des signatures (vérification interne et publique).

## Stack

- **Backend** : Laravel 12, PHP 8.4, Fortify (auth + 2FA), Inertia v2
- **Frontend** : Vue 3 + TypeScript, Tailwind v4, composants shadcn-vue (`resources/js/components/ui/`, reka-ui), vee-validate + zod
- **Routes côté front** : deux systèmes cohabitent
  - **Wayfinder** (`@/routes`, `@/actions`, généré par le plugin Vite, ignoré par git) : utilisé par les pages auth/settings du starter kit
  - **Ziggy** (`route('...')`) : utilisé par les pages métier (keys, signatures, verifications, dashboard)
- **BD** : MySQL en local et en prod (le dashboard utilise `DATE_FORMAT`, propre à MySQL). Les tests tournent sur SQLite `:memory:` (`phpunit.xml`).
- Crypto : extension PHP `openssl` (RSA 2048/4096, ECDSA prime256v1, certificats X.509 auto-signés)

## Commandes

```bash
composer dev            # serve + queue + pail + vite (concurrently)
composer test           # pint --test puis php artisan test
php artisan test --filter=NomDuTest
vendor/bin/pint         # format PHP
npm run lint            # eslint --fix
npm run format          # prettier sur resources/
npx vue-tsc --noEmit    # typecheck
npm run build
```

## Architecture métier

Les contrôleurs (`app/Http/Controllers/<Domaine>/`) sont **minces** : ils valident la requête, autorisent (Policies), appellent un service et répondent. Toute la logique métier est dans `app/Services/` (pas de FormRequest pour le métier ; validation inline dans le contrôleur) :

| Service | Rôle |
|---|---|
| `Keys/KeyManager` | cycle de vie d'une clé : création (+ certificat), modification, défaut, activation, révocation, suppression, et les règles qui les encadrent |
| `Keys/KeyGenerator` | génération RSA 2048/4096 et ECDSA P-256 |
| `Keys/KeyBackupService` | export/import PKCS#12 (.p12) |
| `Keys/KeyFingerprint` | **seule** définition de l'empreinte (SHA-256 du DER SPKI) : `of()` complète, `short()` = son préfixe de 8 octets pour les listes |
| `Keys/SshKeyExporter` | export `authorized_keys` (RSA et ECDSA P-256) |
| `Signing/DocumentSigner` | signer : stockage, empreintes, CMS, horodatage, puis enregistrement |
| `Signing/SignatureManager` | valider / révoquer / supprimer une signature |
| `Verification/SignatureVerifier` | vérification (interne, externe, publique) |
| `Crypto/CertificateAuthority`, `CmsService`, `TimestampAuthority` | primitives PKI |

Les données envoyées aux pages Inertia sont mises en forme par `app/Http/Resources/*` (`->resolve()`), pas dans les contrôleurs. `App\Support\ClientContext` transporte IP et user-agent vers les services (pas d'objet `Request` dans les services).

Règles de conception à respecter :
- Un service ne lit pas `auth()` : l'utilisateur est un paramètre.
- Une règle métier violée lève `UserFacingException` (message affichable ; `field` si elle concerne un champ de formulaire). Toute autre exception est inattendue : journalisée, jamais montrée.
- `DocumentSigner` : tout ce qui est lent ou externe (signature, TSA) se fait **avant** la transaction SQL ; les fichiers écrits sont supprimés si une étape échoue. `SignatureManager::delete` n'efface les fichiers **qu'après** le commit.
- `FileSigned` est en suppression logique (`SoftDeletes`) : la ligne reste, le fichier disparaît du disque.

| Domaine | Contrôleur | Pages Inertia |
|---|---|---|
| Clés | `Keys/KeyController` | `pages/keys/*` |
| Signatures | `Signatures/SignatureController` | `pages/signatures/*` |
| Vérifications (auth) | `Verifications/VerificationController` | `pages/verifications/*` |
| Vérification publique | `PublicSignatures/PublicSignaturesController` | `pages/publicVerifications/Index.vue` |
| Dashboard | `Dashboard/DashboardController` | `pages/Dashboard.vue` |

Modèles (`app/Models`) :
- `KeyPair` (`key_pairs`) : clé publique/privée PEM, certificat, flags `is_default` / `is_active` / `is_revoked`
- `FileSigned` (`files_signeds`) : document uploadé, hash SHA-256, statut, compteur de signatures
- **PKI** : `CertificateAuthority` (AC interne + chaîne + révocation) et `CmsService` (signature/vérification CMS) dans `app/Services/Crypto/`. Perdre `APP_KEY` rend l'AC et toutes les clés irrécupérables. Le certificat d'une clé et sa validité (`valid_to`) sont alignés (365 jours par défaut).
- `Signature` (`signatures`) : `signature_value` est un **CMS détaché (RFC 5652) encodé en PEM** (`-----BEGIN CMS-----`), produit par `CmsService`. Les signatures créées avant la phase 3 contiennent l'ancien **JSON maison** (`{signature: base64, ...}`) : `SignatureVerifier` les vérifie toujours, en lecture seule
- `SignatureVerification` (`signatures_verifications`) : journal de chaque vérification, `verification_token` pour la vérification publique

Flux :
1. **Signature** (`SignatureController@store`) : upload sur le disque `local` (`storage/app/private/signatures/{user}/documents/Y/m/d`), hash SHA-256, `CmsService::sign` (`openssl_cms_sign`, CMS détaché avec certificat du signataire), fichier `.p7s` (DER) enregistré à côté, création de `FileSigned` + `Signature` + d'une vérification initiale, le tout dans une transaction. Les formats `cms` et `pdf` produisent le même conteneur.
2. **Vérification** : recherche du `FileSigned` par hash ; s'il existe → `openssl_verify` avec la clé publique du `KeyPair` ; sinon → vérification « externe » à partir d'un fichier de signature fourni.
3. **Publique** : `GET /verifications/public` (page) + `POST /api/public/verify` (JSON, par token ou par upload, sans auth).

La logique de vérification vit dans `SignatureVerifier` (partagée par `VerificationController` et `PublicSignaturesController`, qui ne font que la mise en forme : messages EN côté connecté, FR côté public). Toute correction se fait dans le service.

## Conventions

- Code, commentaires et messages de commit en français ; les messages flash/erreurs côté backend sont en anglais, l'UI publique en français.
- Autorisation : **Policies** dans `app/Policies/` (`KeyPair`, `Signature`, `FileSigned`, `SignatureVerification`), appelées via `$this->authorize(...)` ; un refus répond **404** (`denyAsNotFound`) pour ne pas révéler l'existence de la ressource. Les listes restent scopées à l'utilisateur (`where('user_id'|'signer_id', auth()->id())`).
- Les modèles utilisent `$fillable` : **toute colonne écrite via `create()`/`update()` doit y figurer**, sinon elle est ignorée en silence (plusieurs bugs existants viennent de là).
- Les routes custom d'une ressource (`/keys/revoked`, `/signatures/pending`...) doivent être déclarées **avant** `Route::resource(...)`, sinon `show` les capture.

## Déploiement

`.github/workflows/deploy.yml` a deux jobs : `test` (PHP + Node sur `ubuntu-latest` : pint, eslint, vue-tsc, `php artisan test` sur SQLite, puis `npm run build`) et `deploy` (runner **self-hosted**, `needs: test`) qui build, `rsync` vers `/var/www/laravel/cryptor`, lance `migrate --force`, met en cache la config/les routes/les vues et recharge php8.4-fpm + nginx. Un push sur `main` ne déploie donc plus que si la suite de tests et le lint passent. Le proxy de confiance est `192.168.40.10` (`bootstrap/app.php`).

## État au 2026-10-02 (après correctifs)

La suite (pint, eslint, vue-tsc, `php artisan test`, `npm run build`) est entièrement verte (52 tests). Correctifs apportés :

- **Mots de passe en clair** : `User` n'avait pas le cast `'password' => 'hashed'` ; `PasswordController@update` et `ResetUserPassword` écrivaient donc le mot de passe sans le hasher. Ajouté sur le modèle.
- **Clé privée en clair** : `KeyPair::$casts` a maintenant `'private_key' => 'encrypted'`.
- Routes littérales (`keys/revoked`, `signatures/{pending,revoked,expired}`) déplacées avant `Route::resource(...)` (elles étaient capturées par `show`).
- `signatures.validate` pointe sur `update()` (la méthode qui existe réellement) ; `keys.edit` et `signatures.edit` ont maintenant une implémentation ; `SignatureController@destroy` supprime réellement la signature (et le fichier si c'était la dernière signature dessus) ; ajout de la route/contrôleur `files.download`.
- `$fillable` complétés sur `KeyPair` (`is_revoked`, `revocation_reason`, `revoked_at`, `last_used`, `use_count`, `created_by_ip/user_agent`), `Signature` (`revoked_at`, `revocation_reason`, `validated_at`, `signature_file_path`) et `FileSigned` (`metadata`, `upload_ip/user_agent`) ; migrations ajoutées pour les colonnes qui n'existaient pas (`use_count`, `signature_file_path`, `metadata`).
- La vérification externe peut désormais écrire `signature_id = null` et `verification_type = 'external'` (migration qui rend `signature_id` nullable et étend l'enum `verification_type`).
- `VerificationController@show` vérifie maintenant que l'utilisateur est le signataire, l'uploader ou l'auteur de la vérification (IDOR corrigé).
- `POST /api/public/verify` a un throttle (`throttle:20,1`).
- `dd()` retiré de `SignatureController@pending`.
- `verification_details` est passé en tableau PHP (le cast Eloquent `array` s'occupe de l'encodage) au lieu d'être `json_encode`é avant insertion partout où c'était fait en double.
- `storeDetachedSignature` extrait correctement le champ `signature` (base64) de la structure CMS/PAdES JSON avant de le décoder ; l'algorithme de signature inscrit dans les métadonnées (`rsaEncryption` / `ecdsa-with-SHA256`) dépend enfin du type de clé.
- `FileSigned::getFilePathAttribute` pointait vers le mauvais disque (`storage/app/` au lieu de `storage/app/private/` via le disque `local`) : corrigé.
- `DashboardController` ne dépend plus de `DATE_FORMAT` (MySQL uniquement) : regroupement par mois fait en PHP, ce qui fonctionne aussi sur SQLite (tests).
- Factories ajoutées pour `KeyPair`, `FileSigned`, `Signature`, `SignatureVerification` (absentes) et `UserFactory` renseigne désormais `username`.
- Tests ajoutés : `tests/Feature/KeyPairTest.php`, `SignatureTest.php`, `VerificationTest.php`.

### Dette restante / limites connues

- La signature d'un même contenu par le même utilisateur crée un nouveau `FileSigned` à chaque fois (pas de réutilisation) : `findSignedFile` retrouve alors la première ligne portant cette empreinte.
- La « signature visuelle » d'un PDF n'enregistre que des métadonnées de mise en page (`visual_signature_data`) : le PDF n'est pas modifié.
- `keys/Edit.vue` et `keys/Revoke.vue` étaient des pages vides (« Page en construction ») ; elles ont été implémentées mais restent minimales.
- Pas de PAdES : le format `pdf` est un CMS détaché du fichier, **pas** une signature incorporée dans le PDF (il faudrait une bibliothèque PDF dédiée). Le CMS, lui, est standard et vérifiable avec `openssl cms -verify -inform DER -in x.p7s -content doc -noverify -binary`.
- `CmsService::verify` utilise `OPENSSL_CMS_NOVERIFY` (signature + intégrité seulement) ; la confiance du certificat est évaluée à part par `CertificateAuthority::validateChain` (statut `certificate_trust`, **informatif** : il n'invalide pas une vérification, car les clés antérieures à l'AC ont un certificat auto-signé). En interne, le certificat embarqué doit en plus correspondre à la clé enregistrée.

### Dépendances / sécurité (2026-11-11)

- `composer audit` et `npm audit` sont à 0 vulnérabilité ; `.github/dependabot.yml` surveille composer, npm et GitHub Actions chaque lundi (mises à jour minor/patch groupées).
- `zod` et `@vee-validate/zod` ont été supprimés (jamais utilisés) ; seul `vee-validate` reste.
- `package.json` contient un override `@typescript-eslint/typescript-estree.minimatch ^11.0.1` (correctif DoS) : à retirer quand `typescript-eslint` publiera une version corrigée.
- Laravel reste en 12.x ; les changements de version majeure (Laravel 13, PHPUnit 13, Inertia 3, vue-tsc, etc.) sont à traiter à part.

### Chantier « PKI standard » (démarré le 2026-11-11)

Contrainte directrice : **tout le monde doit pouvoir cloner et utiliser le projet** (`composer setup` + `composer dev`, SQLite par défaut, aucun service ni secret externe obligatoire).

- [x] Phase 1 — installation reproductible (README, `composer setup` idempotent, paquet npm `node` retiré), en-têtes de sécurité (`SecurityHeaders`, CSP avec nonce Vite/Ziggy), SAST (`.github/workflows/security.yml` : CodeQL + Semgrep).
- [x] Phase 2 — service `SignatureVerifier` unique (fin de la duplication, −570 lignes dans les contrôleurs) + 4 Policies ; correctifs au passage : fuite des « signatures récentes » d'autres utilisateurs sur `verifications/create`, et erreur 500 sur une signature externe sans `file_hash` (`expected_hash` rendu nullable). 78 tests.
- [x] Phase 3 — vraies signatures CMS détachées (`app/Services/Crypto/CmsService.php`, `openssl_cms_sign/verify`, aucune dépendance) ; anciennes signatures JSON toujours vérifiables ; route `signatures.download` (.p7s DER) ; signatures externes CMS (PEM/DER) acceptées. Vérifié contre la CLI `openssl cms`. 97 tests.
- [x] Phase 4 — AC interne (`app/Services/Crypto/CertificateAuthority.php`, ECDSA P-384, créée seule au premier usage, clé chiffrée par `APP_KEY`, stockée dans `storage/app/private/pki/`), certificats utilisateur signés par l'AC (extensions dans `resources/openssl/pki.cnf`, config dans `config/cryptor.php`), CMS embarquant le certificat de l'AC (`openssl cms -verify -CAfile ca.crt` valide toute la chaîne), statut `certificate_trust` (trusted/self_signed/untrusted/expired/revoked/invalid) dans les vérifications, `GET /pki/ca.crt` et `GET /pki/revocations.json` (liste signée par l'AC, sans motifs), commandes `cryptor:ca-init` et `cryptor:reissue-certificates` (migre les anciens certificats auto-signés, même clé). DN configurable (plus de ville/pays codés en dur). 114 tests.
- [x] Phase 5 — horodatage RFC 3161 **optionnel** (`app/Services/Crypto/TimestampAuthority.php`, `CRYPTOR_TSA_URL` / `CRYPTOR_TSA_CA_FILE`, désactivé par défaut) : requête DER encodée à la main, réponse lue par un décodeur DER strict (source distante non fiable, bornes contrôlées), jeton CMS vérifié par OpenSSL (empreinte, nonce, usage « horodatage », rattachement à la CA configurée). On horodate le `.p7s` DER ; le jeton est stocké en JSON dans `signatures.timestamp_token` (`type: rfc3161`), l'ancien jeton interne porte `type: internal`. Une TSA en panne ne bloque jamais une signature (repli interne + warning). Statut `timestamp` (trusted/untrusted_tsa/invalid/internal/none) dans les vérifications. Tests avec une vraie TSA locale (`tests/Support/FakeTimestampServer`, `openssl ts`, ignorés sans binaire openssl). Faux `byteRange` PAdES supprimé en phase 3 ; PAdES réel reste hors périmètre.
- [x] Phase 6 — sauvegarde chiffrée d'une clé (`POST keys/{key}/export/backup` → PKCS#12 AES-256 protégé par une phrase secrète ≥ 12 car., mot de passe du compte requis, AC incluse) et import (`keys/import`, RSA ≥ 2048 ou ECDSA P-256, doublons/expirés refusés) : seule parade à la perte d'`APP_KEY`. `KeyController@update` durci (certificat et émetteur non modifiables, `valid_to` ≤ fin du certificat, clé révoquée non modifiable). Téléchargements de fichiers côté front via `usePostDownload` (fetch + CSRF) : Inertia ne gère pas les réponses binaires. La route `keys.download-private` (PEM brut) a été supprimée.
- [x] Phase 7 — couverture mesurée en CI (`pcov`, `vendor/bin/phpunit --coverage-clover`, `scripts/coverage-check.php` → résumé dans `$GITHUB_STEP_SUMMARY` + artefact `coverage.xml`). Seuil réglable sans commit via la variable GitHub Actions `COVERAGE_MIN` (0 = rapport seul). **Aucun taux n'a pu être mesuré en local** (pas de pcov/xdebug) : après le premier run, fixer `COVERAGE_MIN` un peu sous le taux réel. 153 tests.

### Robustesse (2026-11-11, après le chantier PKI)

- **Erreurs** : `App\Exceptions\UserFacingException` = seule exception dont le message peut être montré à l'utilisateur (`UserFacingException::safeMessage`). Toute autre exception (SQL, OpenSSL...) est journalisée (`Log::error(..., ['exception' => $e])`) et remplacée par un message générique ; ne jamais concaténer `$e->getMessage()` dans une réponse. Testé dans `ErrorDisclosureTest`.
- **MySQL** : la suite complète (et les 13 migrations) a été validée sur MySQL 8.0 ; la CI a un job `test (MySQL 8)` en plus du job SQLite. Un conteneur jetable suffit en local : `docker run -d -e MYSQL_ROOT_PASSWORD=secret -e MYSQL_DATABASE=cryptor_test -p 127.0.0.1:33061:3306 mysql:8.0` puis `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33061 DB_DATABASE=cryptor_test DB_USERNAME=root DB_PASSWORD=secret vendor/bin/phpunit`.
- Code mort retiré : route `keys.download-private`, `VerificationController::publicVerify` + page `verifications/Public.vue`, `PlaceholderPattern.vue`, `laravel/sail`.

### Refactoring services (2026-11-11)

`KeyController` 630 → 274 lignes, `SignatureController` 805 → 228 ; 190 tests (SQLite et MySQL 8). Corrigé au passage :
- **Export SSH invalide** : le module RSA n'était pas préfixé de `0x00` (mpint SSH), `ssh-keygen` refusait le fichier. Corrigé, étendu à ECDSA P-256, commentaire assaini (plus d'injection de ligne), validé par `ssh-keygen` dans les tests.
- **Quatre calculs d'empreinte différents** (clé, signature, vérification, dashboard) → un seul (`KeyFingerprint`).
- **Appel à l'autorité d'horodatage (jusqu'à 10 s) dans la transaction SQL** → déplacé avant.
- **Fichiers orphelins** (document ou `.p7s` écrit puis transaction annulée) → nettoyés ; suppression de signature : fichiers effacés après le commit.
- Nom de fichier stocké : extension nettoyée (`[A-Za-z0-9]`), plus de point final ; hachage SHA-256/512/MD5 en une seule lecture ; signature visuelle : émetteur réel du certificat au lieu de « Self-signed » ; `edit()` n'envoie plus le PEM du certificat au front.

### Confirmation de l'adresse e-mail (2026-11-12)

- `User` implémente `MustVerifyEmail`. **Avant, il ne l'implémentait pas** : le middleware `verified` ne bloque que les utilisateurs de cette interface, donc il ne faisait rien nulle part (tableau de bord compris). Toute route qui doit exiger une adresse confirmée utilise `verified`.
- L'alias `verified` est remplacé (`bootstrap/app.php`) par `App\Http\Middleware\EnsureVerifiedEmail`, piloté par `config('cryptor.require_verified_email')` (`CRYPTOR_REQUIRE_VERIFIED_EMAIL`, défaut `true`), lu à chaque requête (compatible `route:cache`).
- Groupe principal de `routes/web.php` : `['auth', 'verified']`. Restent accessibles sans confirmation : page de vérification, renvoi du lien, `settings/profile` (corriger son adresse), déconnexion, et tout le public (`/`, `/verifications/public`, `/api/public/verify`, `/pki/*`).
- Changer d'adresse annule la confirmation **et** renvoie le lien (`ProfileController@update`).
- Migration `2026_11_12_100000_mark_existing_users_as_verified` : les comptes existants (créés sans confirmation) sont marqués confirmés, sinon tout le monde serait bloqué au déploiement. Irréversible.
- `php artisan cryptor:verify-user {email?} {--all}` : confirmation manuelle (essai local, dépannage).
- Les tests qui créent des utilisateurs avec `User::factory()` obtiennent des comptes **confirmés** ; utiliser `->unverified()` pour tester l'inverse.

### Principe : la vérification publique ne demande jamais de compte (2026-11-12)

Quiconque reçoit un document doit pouvoir le vérifier **sans se connecter** : `/verifications/public`, `POST /api/public/verify`, `/pki/ca.crt`, `/pki/revocations.json` ne sont jamais derrière `auth` ni `verified` (testé : `test_public_features_stay_public`).
- Lien de partage : `/verifications/public?token=<64 hex>` ; la page lit le token (`initialToken`, validé `^[a-f0-9]{64}$`) et lance la vérification à l'ouverture. Toujours générer ce lien avec `route('verifications.public', { token })` (objet, jamais le token « à plat » : Ziggy en ferait le **nom** du paramètre, `?<token>=`). L'ancienne forme reste acceptée en lecture.
- Un document inconnu ou modifié est un **résultat normal** : HTTP 422, message clair (`unknown_document`), rien dans les logs d'erreur. Seules les erreurs inattendues renvoient 500.
- Rejouer un token ne modifie jamais l'enregistrement d'origine (IP et navigateur du vérificateur initial) : le journal d'audit reste intact.
- Le « QR code » de `verifications/Show.vue` n'était qu'une icône : supprimé. Si un vrai QR code est voulu, ajouter une bibliothèque (`qrcode`) et y encoder le lien de partage public.

### HTTPS derrière un proxy (2026-11-12)

Symptôme en production : page blanche, console pleine de « Mixed Content » et de violations CSP sur des URLs `http://…`. Cause : le TLS est terminé en amont (Cloudflare/proxy), Laravel reçoit du HTTP simple et génère des URLs `http://` (assets Vite, `@routes` Ziggy, `route()`), que le navigateur bloque sur une page https (et que `'self'` de la CSP exclut).
- `config('cryptor.force_https')` (`CRYPTOR_FORCE_HTTPS`, **défaut : vrai si `APP_URL` commence par `https://`**) → `URL::forceScheme('https')` dans `AppServiceProvider`, HSTS même si `isSecure()` est faux, et `upgrade-insecure-requests` dans la CSP. **Le seul réglage à faire sur un serveur : `APP_URL=https://…` puis `config:cache`.**
- `TRUSTED_PROXIES` (défaut `192.168.40.10`, liste séparée par des virgules ou `*`) remplace l'IP codée en dur de `bootstrap/app.php`.
- Sources CSP supplémentaires **filtrées** (`https://hôte` uniquement, pas de `*`, `data:`, `'unsafe-*'`) : `CRYPTOR_CSP_SCRIPT_SRC`, `CRYPTOR_CSP_CONNECT_SRC`. `static.cloudflareinsights.com` (analytique Cloudflare) est bloqué par la CSP tant qu'on ne l'autorise pas ou qu'on ne la désactive pas côté Cloudflare.
- Fichier statique `resources/js/ziggy.js` **supprimé** (il figeait `http://localhost:8000`) ; `app.ts` fait `.use(ZiggyVue)` sans configuration et lit celle de `@routes`.
- Dans les tests, `BladeRouteGenerator::$generated` (statique Ziggy) doit être remis à `false` pour que `@routes` régénère sa configuration complète.
