# RSL Group authentication portal

Laravel 12 portal for the existing AWS Cognito staging pool. It provides split sign-in/registration, email confirmation, username/email password recovery, supported MFA and temporary-password challenges, Google federation, a persistent portal session, an application dashboard and administrator user management.

The portal now implements the authorization-code handoff expected by the local Guestlist and Backstage repositories. It returns genuine, validated Cognito ID/access tokens for their existing **shared Cognito client**. The former custom `sso_token` handoff has been removed. No changes have been made to those repositories or to Cognito pool/client configuration.

See [the implementation plan and outstanding decisions](docs/IMPLEMENTATION_PLAN.md) and [the migration adapter contract](docs/MIGRATIONS.md).

## Local setup

Requires PHP 8.2+, Composer 2, Node 20.19+ and SQLite (or another Laravel database). Composer resolves dependencies against PHP 8.2. The official AWS PHP SDK supplies IAM-signed directory operations.

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

WordPress and RSL Cloud migrations use independent environment-configured credential-verification adapters. All migration settings are disabled by default. No live adapter endpoint has yet been supplied. The implementation refuses ambiguous identities, unverified email, existing-account replacement, imported roles and provider failures. See the [contract and reconciliation constraints](docs/MIGRATIONS.md) before enabling anything. Existing Cognito migration triggers remain unchanged.

## Verification and operations

```sh
php artisan test
vendor/bin/pint --test
npm run build
composer audit
php artisan schedule:work # development; production runs Laravel's scheduler every minute
```

Tests fake external calls and cover code exchange/replay/expiry, exact redirect matching, client credentials, PKCE, state, session refresh, Cognito token claims, MFA, admin authorization/write controls and migration conflicts. The scheduler removes expired encrypted authorization codes hourly. Protect `APP_KEY`, database backups and audit logs; session encryption is always enabled.

Outstanding launch items: additional platform contracts and staging URLs, HTTPS deployment, live social callback/provider setup, approved management mutation tests, real legacy verification APIs and collision policy, partial-migration reconciliation, not-yet-migrated password recovery, additional MFA enrollment/selection flows, and final privacy/terms content.
