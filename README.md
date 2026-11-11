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
- **Proxy de confiance** : si l'application est derrière un reverse proxy, adaptez `bootstrap/app.php`.

### ⚠️ Sauvegardez `APP_KEY`

Les clés privées des utilisateurs sont **chiffrées en base avec `APP_KEY`**. Si cette clé est perdue ou
régénérée (`php artisan key:generate` sur une base existante), toutes les clés privées deviennent
**irrécupérables**. Conservez `APP_KEY` dans un gestionnaire de secrets, hors du dépôt.

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

## Limites connues

- Les certificats sont **auto-signés** et les signatures « CMS/PAdES » sont, pour l'instant, une structure
  JSON propre au projet (non vérifiable par des outils tiers). Voir `CLAUDE.md` pour l'état détaillé et
  la feuille de route.

## Déploiement

`.github/workflows/deploy.yml` : tests (`ci.yml`) puis déploiement par un runner self-hosted
(rsync, `migrate --force`, mise en cache, rechargement php-fpm/nginx) à chaque push sur `main`.
Ne jamais ajouter de déclencheur `pull_request` à ce workflow.
