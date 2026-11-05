# RSL Group authentication portal

Laravel 12 portal for the existing AWS Cognito staging pool. It provides split sign-in/registration, email confirmation, username/email password recovery, supported MFA and temporary-password challenges, Google federation, a persistent portal session, an application dashboard and administrator user management.

The portal now implements the authorization-code handoff expected by the local Guestlist and Backstage repositories. It returns genuine, validated Cognito ID/access tokens for their existing **shared Cognito client**. The former custom `sso_token` handoff has been removed. No changes have been made to those repositories or to Cognito pool/client configuration.

See [the implementation plan and outstanding decisions](docs/IMPLEMENTATION_PLAN.md) and [the migration adapter contract](docs/MIGRATIONS.md).

## Local setup

Requires PHP 8.2+, Composer 2, Node 22 (22.12.0 or newer) and SQLite (or another Laravel database). Composer resolves dependencies against PHP 8.2. The official AWS PHP SDK supplies IAM-signed directory operations.

```sh
composer install
npm ci
cp .env.example .env # only for a fresh installation; preserve an existing .env
php artisan key:generate # only for a fresh installation
php artisan migrate
npm run build
php artisan serve --host=localhost --port=8000
```

Set `COGNITO_REGION`, `COGNITO_USER_POOL_ID`, `COGNITO_CLIENT_ID`, `COGNITO_CLIENT_SECRET`, `COGNITO_DOMAIN` and AWS credentials in `.env`. IAM role credentials are also supported. The Cognito domain can be a hostname or HTTPS URL. Public-client Cognito calls use the client credentials; directory operations use IAM credentials.

The existing staging configuration already supports direct password and refresh-token auth. `http://localhost:8000/callback` is an already registered callback suitable for local Google federation; `COGNITO_REDIRECT_URI` now defaults to `${APP_URL}/callback`. `/auth/callback` remains a route alias. Only Google is enabled by default. Microsoft, Facebook and Apple icons/redirect adapters are present but disabled until their Cognito provider configurations exist.

Run `composer run dev` for the full Laravel/Vite stack, or `php artisan serve` after building assets.

## Docker

The single `compose.yaml` configures local development: application code is mounted from your working directory, PHP caching is disabled, and the queue listener reloads code between jobs. Save PHP, route, configuration or Blade template changes and refresh the browser; no image rebuild is needed. Run `npm run dev` on the host for live CSS/JavaScript updates, or `npm run build` to refresh the assets in `public/build`. Run `npm ci` first on a fresh checkout. The local setup uses separate volumes for container PHP dependencies and Laravel bootstrap caches, while retaining the existing persistent storage and database volumes.

After changing `.env`, run `docker compose up -d --force-recreate` so all services receive the updated settings. If Composer dependencies change, run `docker compose exec app composer install --no-dev --optimize` and restart the background services. Rebuild only for Dockerfile, system dependency or image configuration changes. The Compose setup is for local development; the Dockerfile retains a separate `production` image target for deployment.

Docker Compose uses the existing `.env` for the web app, queue worker and scheduler. Keep the same `APP_KEY`, Cognito, AWS, database, session and migration settings. The `.env` file and local credentials are excluded from the image; frontend assets and production PHP dependencies are built into it. Composer is not required on the host. For local development, Node is used on the host to build or serve frontend assets; the production image includes its own built assets.

```sh
docker compose build
# Run once on initial setup, and when deploying new database migrations:
docker compose run --rm app php artisan migrate --force
docker compose up -d
```

Open `http://localhost:8000` (or the hostname configured in `APP_URL`). Set `DOCKER_PORT` in `.env` to change the published port, and update `APP_URL` and any explicitly configured callback URLs to match. Cognito callbacks must remain registered with Cognito. For a fresh installation only, copy `.env.example` to `.env` and generate a key with `docker compose run --rm app php artisan key:generate --show`, then save that value as `APP_KEY` in `.env` before migrating. Existing installations must retain their key.

For a local MySQL database, set `DB_HOST=database` and `COMPOSE_PROFILES=local-db` in `.env`. Keep your existing `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` (a non-root username and a non-empty password are required). Start and initialize it before the application:

```sh
docker compose up -d --wait database
docker compose run --rm app php artisan migrate --force
docker compose up -d
```

This creates a separate, initially empty MySQL 8.4 database in the `mysql-data` volume. It does not import an existing database. The credentials initialize MySQL on its first startup; changing them later requires updating the MySQL account too. MySQL is available only to containers, with no database port published to the host.

To use an existing database instead, remove `COMPOSE_PROFILES=local-db` and configure `DB_HOST` for that server. A database, Redis or mail server on the host must use `host.docker.internal` instead of `127.0.0.1` on Docker Desktop; Linux hosts need an equivalent reachable address. Remote server hostnames stay the same. Ensure the database accepts connections from the Docker host. MySQL/MariaDB, PostgreSQL, SQLite and Redis PHP drivers are included. Migrations always remain an explicit command.

The `app-storage` volume preserves logs, uploads, file sessions/cache and the default SQLite database across container replacements. An existing host SQLite database is not imported automatically. If you explicitly set an SQLite `DB_DATABASE`, use an absolute container path inside `/var/www/html/storage` for persistence and provide the existing database there. `docker compose down` preserves the volume; `docker compose down -v` deletes it. The application's existing environment variables control session/cache drivers as usual.

```sh
docker compose ps
docker compose logs -f app queue scheduler
# Apply .env changes to all services:
docker compose up -d --force-recreate
# Rebuild after Dockerfile or system dependency changes:
docker compose up -d --build
docker compose down
```

For an HTTPS deployment, put the web service behind your HTTPS proxy and use the existing production settings (`APP_ENV=production`, `APP_DEBUG=false`, the HTTPS `APP_URL`, and `SESSION_SECURE_COOKIE=true`). The scheduler runs the existing hourly authorization-code cleanup; the queue worker processes the configured queue connection.

## Application compatibility

| Existing application request | Portal behavior |
| --- | --- |
| `GET /login?response_type=code&client_id=…&redirect_uri=…` | Guestlist sign-in entry |
| `GET /oauth2/authorize?...&state=…` | Backstage sign-in entry; opaque state preserved |
| `POST /oauth2/token` with authorization code | Client-authenticated exchange for Cognito ID/access tokens |
| `GET /logout?client_id=…&logout_uri=…` | Clear portal session and return to an exact registered logout URL |
| Direct `/` or `/login` | Dashboard when signed in; split form otherwise |

Callbacks and logout URLs use **exact URL allowlists**, configured separately for each application. Authorization codes expire after 60 seconds, are stored as hashes with encrypted payloads, and are atomically consumed once. Token authentication supports `client_secret_post` and HTTP Basic. S256 PKCE is supported and required when no client secret is configured. Invalid requests never redirect to an untrusted callback. Separate browser tabs carry separate validated request IDs.

Supported prompts are `login`, `none` and Backstage's `forgot_password`. The broker accepts the audited identity scopes `openid email profile phone`; these select an identity flow, **not new access-token permissions**. Direct Cognito API access tokens retain Cognito API scopes. The portal does not mint a new Cognito issuer, audience or OAuth scope claim.

This is not a general OIDC server: implicit/client-credentials/refresh-token grants, resource scopes, OAuth userInfo, discovery and arbitrary downstream nonce/max_age requests are not implemented. Refresh tokens remain encrypted in the portal's server-side session; the audited apps get new access through the authorization flow. Other platforms and different Cognito clients need an explicit compatibility assessment. Cross-client refresh tokens cannot be substituted.

## Staging switch, after end-to-end approval

1. Deploy to an HTTPS portal hostname reachable from both staging servers. Use shared session, code storage and locking/cache infrastructure, a stable `APP_KEY`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, host-only cookies, and HTTPS proxy configuration appropriate for the host. Use database or Redis sessions; do not use the cookie session driver for tokens.
2. Keep `COGNITO_DOMAIN` on this portal pointed at AWS for federation. A deployed social callback needs an approved Cognito callback registration; no pool configuration has been changed.
3. Verify Backstage's actual ACF client ID/secret match the portal/shared Guestlist client. Its local repository cannot prove database option values.
4. Change only Guestlist's `AWS_COGNITO_DOMAIN` and Backstage ACF's `cognito_domain` to the **portal hostname**, without scheme or path. Preserve their client ID/secret and callback settings. Both applications force HTTPS.
5. Test native sign-in, registration/confirmation, recovery, social sign-in, returning sessions, expired sessions, app-specific state and logout. Roll back by restoring the original AWS domain settings.

The local repository contracts and portal are tested; a live switch of staging application settings has **not** been performed.

## Administration and migrations

`/admin/users` requires a current enabled Cognito user with `admin` or `administrator` in one of `COGNITO_ADMIN_ROLE_ATTRIBUTES`. The role is re-read from Cognito on every admin request. Search filters automatically after a short pause in typing and supports email, username, first/last name and subject. Email search matches any part of the address, case-insensitively; other fields retain prefix matching. Partial email search scans bounded Cognito batches and automatically continues past empty batches. Load more appends up to 30 matches; a new filter starts a fresh list. The list uses DataTables 3.0.3, installed through npm and bundled locally by Vite only on the admin list page. Column headings sort loaded accounts; the pool search and Load more remain the source of directory results, with DataTables pagination and its separate local search disabled. The list separates username, email address, email verification, confirmation status and enabled status, with coloured status badges. Each row has a three-dot action menu with confirmation dialogs that return to the filtered list. Selecting an account opens a full-width profile headed by their given and family names (with a username fallback) with all attributes, status, dates and MFA details, plus a back link that preserves the search filters. Actions support confirmation, password reset, temporary/permanent password setting, enable/disable, global sign-out and permanent deletion. Deletion requires typing the exact username, rechecks the account subject before sending the SDK request, and blocks deleting the signed-in administrator’s own account, including through an alias. Forms require CSRF and explicit action confirmation; mutation audit events exclude passwords/tokens.

`COGNITO_MANAGEMENT_WRITES_ENABLED=true` enables the administrator actions and is the current setting/default. The user has authorised editable administration. Set it to `false` only when read-only maintenance is needed; the existing role checks, CSRF protection, action confirmation and audit events still apply. Portal migrations remain separately disabled. Before production, review who can write the existing admin role attributes across every pool app client: the portal relies on those attributes as the requested authority and does not change their Cognito permissions.

WordPress and RSL Cloud migrations use independent environment-configured credential-verification adapters. All migration settings are disabled by default. Missing Cognito users are verified in Cloud first, then MusicTeacher (WordPress) if Cloud has no match. A validated MusicTeacher sign-in journey gives MusicTeacher priority before Cloud; Cognito is always checked first. Cloud service tokens are cached until their reported expiry. Only a verified identity can be migrated; existing-account replacement and imported roles remain blocked. An unavailable provider is skipped so the next provider can verify the account. If none verifies it, the portal explains which services could not be checked and asks the user to try again later. See the [API contracts, Cloud configuration and reconciliation constraints](docs/MIGRATIONS.md) before enabling migration. WordPress uses the signed HMAC endpoint and preserves Lambda-compatible `wp_<ID>` usernames with `preferred_username` aliases. Existing Cognito migration triggers remain unchanged.

## Verification and operations

```sh
php artisan test
vendor/bin/pint --test
npm run build
composer audit
php artisan schedule:work # development; production runs Laravel's scheduler every minute
```

Pushes and merges into `main` run [the Cloudways live workflow](.github/workflows/live-deploy.yml) using the GitHub `live` environment. It builds Vite assets, tests Laravel, syncs files over SSH/rsync, installs production Composer dependencies when needed, runs migrations and clears Laravel caches. See [Cloudways live setup and verification](docs/cloudways-live-cicd.md) for the required secrets and server configuration. Laravel stays in `private_html/app` and the web document root stays at `public_html`. Built assets and their manifest are published to both locations; the existing public `index.php` and `.htaccess` are preserved.

Tests fake external calls and cover code exchange/replay/expiry, exact redirect matching, client credentials, PKCE, state, session refresh, Cognito token claims, MFA, admin authorization/write controls and migration conflicts. The scheduler removes expired encrypted authorization codes hourly. Protect `APP_KEY`, database backups and audit logs; session encryption is always enabled.

Outstanding launch items: additional platform contracts and live URLs, HTTPS deployment, live social callback/provider setup, approved management mutation tests, real legacy verification APIs and collision policy, partial-migration reconciliation, not-yet-migrated password recovery, additional MFA enrollment/selection flows, and final privacy/terms content.
