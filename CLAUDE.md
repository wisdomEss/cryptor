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

Les contrôleurs sont groupés par domaine dans `app/Http/Controllers/<Domaine>/` ; la logique de clés/signatures est dans les contrôleurs, mais **la vérification est dans `app/Services/Verification/SignatureVerifier.php`** (pas de FormRequest pour le métier).

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
- `Signature` (`signatures`) : `signature_value` est un **JSON** (`{signature: base64, certificate, signedAttributes...}`) et non une signature brute
- `SignatureVerification` (`signatures_verifications`) : journal de chaque vérification, `verification_token` pour la vérification publique

Flux :
1. **Signature** (`SignatureController@store`) : upload sur le disque `local` (`storage/app/private/signatures/{user}/documents/Y/m/d`), hash SHA-256, `openssl_sign` sur le contenu brut, création de `FileSigned` + `Signature` + d'une vérification initiale, le tout dans une transaction.
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

- La gestion des clés et signatures (création, signature) reste dans les contrôleurs ; seuls la vérification (service) et l'autorisation (Policies) ont été extraits.
- `keys/Edit.vue` et `keys/Revoke.vue` étaient des pages vides (« Page en construction ») ; elles ont été implémentées mais restent minimales.
- Pas de vraie bibliothèque PAdES/CAdES : les « signatures PDF/CMS » sont une structure JSON maison, pas un format standard vérifiable par des outils tiers.

### Dépendances / sécurité (2026-11-11)

- `composer audit` et `npm audit` sont à 0 vulnérabilité ; `.github/dependabot.yml` surveille composer, npm et GitHub Actions chaque lundi (mises à jour minor/patch groupées).
- `zod` et `@vee-validate/zod` ont été supprimés (jamais utilisés) ; seul `vee-validate` reste.
- `package.json` contient un override `@typescript-eslint/typescript-estree.minimatch ^11.0.1` (correctif DoS) : à retirer quand `typescript-eslint` publiera une version corrigée.
- Laravel reste en 12.x ; les changements de version majeure (Laravel 13, PHPUnit 13, Inertia 3, vue-tsc, etc.) sont à traiter à part.

### Chantier « PKI standard » (démarré le 2026-11-11)

Contrainte directrice : **tout le monde doit pouvoir cloner et utiliser le projet** (`composer setup` + `composer dev`, SQLite par défaut, aucun service ni secret externe obligatoire).

- [x] Phase 1 — installation reproductible (README, `composer setup` idempotent, paquet npm `node` retiré), en-têtes de sécurité (`SecurityHeaders`, CSP avec nonce Vite/Ziggy), SAST (`.github/workflows/security.yml` : CodeQL + Semgrep).
- [x] Phase 2 — service `SignatureVerifier` unique (fin de la duplication, −570 lignes dans les contrôleurs) + 4 Policies ; correctifs au passage : fuite des « signatures récentes » d'autres utilisateurs sur `verifications/create`, et erreur 500 sur une signature externe sans `file_hash` (`expected_hash` rendu nullable). 78 tests.
- [ ] Phase 3 — vraies signatures CMS détachées via `openssl_cms_sign/verify` (déjà dispo dans PHP, aucune dépendance) ; compatibilité avec l'ancien JSON conservée en lecture seule.
- [ ] Phase 4 — AC interne auto-initialisée (`cryptor:ca-init`, générée au premier usage), certificats signés par l'AC, validation de chaîne, liste de révocation publique.
- [ ] Phase 5 — horodatage RFC 3161 optionnel (`TSA_URL` dans `.env`, désactivé par défaut) ; PAdES réel hors périmètre sans bibliothèque PDF dédiée (retirer l'exemple `byteRange` factice).
- [ ] Phase 6 — pages `keys/Edit` et `keys/Revoke` complètes ; export sauvegarde d'une clé privée chiffrée par passphrase (PKCS#8) pour ne plus dépendre uniquement d'`APP_KEY`.
- [ ] Phase 7 — couverture de tests mesurée en CI (pcov) avec seuil minimal.
