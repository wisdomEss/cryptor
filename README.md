# CRYPTOR

Application web de **PKI légère** : générer des paires de clés (RSA / ECDSA) avec certificat X.509,
signer des documents et vérifier des signatures, en interne ou via une page publique.

Stack : Laravel 12 · PHP 8.4 · Fortify (auth + 2FA) · Inertia 2 · Vue 3 · TypeScript · Tailwind 4.

## Prérequis

| Outil | Version | Remarque |
|---|---|---|
| PHP | 8.2 ou plus (8.4 recommandé) | extensions `openssl`, `mbstring`, `pdo_sqlite`, `fileinfo`, `dom` |
| Composer | 2.x | |
| Node.js | 20.19 ou plus | avec npm |

Aucun service externe n'est nécessaire : la base par défaut est **SQLite** (un simple fichier).

## Installation rapide

```bash
git clone https://github.com/wisdomEss/cryptor.git
cd cryptor
composer setup      # dépendances, .env, clé d'application, base SQLite, migrations, build front
composer dev        # serveur + file d'attente + logs + Vite
```

L'application est alors disponible sur <http://localhost:8000>. Créez un compte depuis la page d'inscription.

`composer setup` est idempotent : il peut être relancé sans risque (il ne remplace pas un `.env` existant).

### Sans `composer dev`

```bash
php artisan serve        # backend
npm run dev              # frontend avec rechargement à chaud
```

## Configuration

Tout se règle dans `.env` (copie de `.env.example`).

- **MySQL / MariaDB** : remplacez `DB_CONNECTION=sqlite` par `mysql` et renseignez `DB_HOST`, `DB_PORT`,
  `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, puis `php artisan migrate`.
- **E-mails** : par défaut `MAIL_MAILER=log` (les e-mails de vérification et de réinitialisation sont écrits
  dans `storage/logs/laravel.log`). Configurez un vrai SMTP pour les envoyer.
- **Autorité de certification** : stockée dans `storage/app/private/pki/`. Sauvegardez ce dossier avec `.env`.
- **Proxy de confiance** : si l'application est derrière un reverse proxy, adaptez `bootstrap/app.php`.

### ⚠️ Sauvegardez `APP_KEY` et vos clés

Les clés privées des utilisateurs sont **chiffrées en base avec `APP_KEY`**. Si cette clé est perdue ou
régénérée (`php artisan key:generate` sur une base existante), toutes les clés privées deviennent
**irrécupérables**. Conservez `APP_KEY` dans un gestionnaire de secrets, hors du dépôt.

Chaque utilisateur peut aussi exporter une **sauvegarde chiffrée (.p12)** de sa clé (page de la clé > menu >
*Export encrypted backup*), protégée par une phrase secrète de 12 caractères minimum, et la restaurer avec
*Keys > Import backup*. C'est la seule copie qui survit à la perte d'`APP_KEY`.

## Commandes utiles

```bash
composer test                 # pint --test puis php artisan test
php artisan test --filter=Nom # un test précis
vendor/bin/pint               # formatage PHP
npm run lint                  # ESLint (corrige automatiquement)
npx vue-tsc --noEmit          # vérification des types
npm run build                 # build de production
```

## Sécurité

- Authentification Fortify, vérification d'e-mail, **2FA (TOTP)**, limitation de débit sur la connexion
  et sur l'API publique.
- Clés privées chiffrées au repos ; export protégé par confirmation du mot de passe.
- En-têtes de sécurité (CSP avec nonce, HSTS, `X-Frame-Options`, etc.) via `SecurityHeaders`.
- Audit automatique des dépendances (Dependabot) et analyse statique du code (CodeQL, Semgrep).

Pour signaler une vulnérabilité, utilisez l'onglet **Security** du dépôt GitHub (signalement privé).

## Infrastructure de clés (PKI)

CRYPTOR embarque sa propre **autorité de certification** (ECDSA P-384). Elle se crée toute seule au premier
usage : rien à configurer. Elle signe le certificat X.509 de chaque clé générée.

| Adresse | Contenu |
|---|---|
| `/pki/ca.crt` | certificat public de l'autorité |
| `/pki/revocations.json` | certificats révoqués (numéros de série), liste signée par l'autorité |

Les signatures sont des **CMS détachés (RFC 5652)** produits par OpenSSL, avec le certificat du signataire et
celui de l'autorité. Un tiers peut donc valider **toute la chaîne** avec les outils standards :

```bash
curl -O https://votre-instance/pki/ca.crt
openssl cms -verify -inform DER -in document.p7s -content document.pdf -CAfile ca.crt -binary
```

Commandes : `php artisan cryptor:ca-init` (affiche l'empreinte de l'autorité) et
`php artisan cryptor:reissue-certificates` (ré-émet sous l'autorité les anciens certificats auto-signés,
avec la même clé ; `--dry-run` pour simuler). Nom, organisation et pays se règlent dans `.env`
(`CRYPTOR_CA_NAME`, `CRYPTOR_ORGANIZATION`, `CRYPTOR_COUNTRY`).

## Limites connues

- Une seule autorité, sans renouvellement automatique (valide 10 ans) ; pas de CRL X.509 binaire : la liste
  de révocation est un JSON signé.
- Le format « PDF » est un CMS détaché du fichier, pas une signature PAdES incorporée au PDF.
- L'horodatage est interne (pas d'autorité RFC 3161). Voir `CLAUDE.md` pour la feuille de route.

## Déploiement

`.github/workflows/deploy.yml` : tests (`ci.yml`) puis déploiement par un runner self-hosted
(rsync, `migrate --force`, mise en cache, rechargement php-fpm/nginx) à chaque push sur `main`.
Ne jamais ajouter de déclencheur `pull_request` à ce workflow.
