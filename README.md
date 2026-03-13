# RSL Group Auth

Central Laravel auth portal for Cognito-backed sign-in, registration, account confirmation, forgot password, and password reset.

Downstream apps such as WordPress, Laravel, and .NET sites are intended to redirect users here instead of sending them to Cognito Hosted UI. Users complete the auth flow on this app, and on successful sign-in the portal can return them to the originating app with a short-lived signed `sso_token`.

## What this project is for

- Provide a single branded authentication property for multiple sites
- Keep users on this app for auth forms instead of exposing Cognito Hosted UI
- Preserve origin context such as `consumer`, `redirect_to`, and `origin`
- Return authenticated users to the originating app when needed
- Support direct visits where users sign in and remain on the portal status page

## Current flow

1. A downstream app redirects the user to this portal, optionally with `consumer` and `redirect_to`.
2. The portal renders its own auth form.
3. Laravel calls Cognito User Pool APIs directly.
4. On successful sign-in:
   - if `redirect_to` is present and allowed for the consumer, the user is returned there with `sso_token`
   - otherwise the user stays on the portal and sees signed-in status

## Requirements

- PHP `^8.2` (the local environment here is running PHP `8.4.11`)
- Composer `2.x`
- Node.js `20.19+`
- npm `10+`
- SQLite for local development, or another Laravel-supported database if you change the config
- An AWS Cognito User Pool app client configured for direct auth

## Cognito requirements

This app does not use Cognito Hosted UI for the main auth flow.

Your Cognito app client needs to support direct authentication, specifically:

- `USER_PASSWORD_AUTH` or equivalent direct username/password flow
- sign-up and confirmation APIs
- forgot-password and confirm-forgot-password APIs

If the Cognito app client has a secret, this app computes `SECRET_HASH` server-side.

## Environment

Copy the environment template if needed:

```bash
cp .env.example .env
```

If you already have a preserved config file, you can copy that instead:

```bash
cp .env.config .env
```

Important env values:

```env
APP_NAME="RSL Group Auth"
APP_URL=http://localhost:8000

COGNITO_REGION=
COGNITO_USER_POOL_ID=
COGNITO_CLIENT_ID=
COGNITO_CLIENT_SECRET=
COGNITO_AUTH_FLOW=USER_PASSWORD_AUTH

SSO_SITE_WORDPRESS_BASE_URL=
SSO_SITE_WORDPRESS_ALLOWED_RETURN_HOSTS=
```

For brokered redirects to work, the return host must be allowed for the consumer. Example:

```env
SSO_SITE_WORDPRESS_BASE_URL=https://backstage.example.com
SSO_SITE_WORDPRESS_ALLOWED_RETURN_HOSTS=backstage.example.com
```

## Install

Install PHP dependencies:

```bash
composer install
```

Install frontend dependencies:

```bash
npm install
```

Run database setup:

```bash
php artisan migrate
```

## Run locally

Start the full local dev stack:

```bash
composer run dev
```

That starts:

- Laravel app server
- queue listener
- Laravel log tail
- Vite dev server

Then open:

```text
http://localhost:8000
```

If you prefer separate processes:

```bash
php artisan serve
npm run dev
```

## Useful routes

- `/` status page
- `/login` sign in
- `/register` create account
- `/register/confirm` confirm sign-up code
- `/forgot-password` request reset code
- `/reset-password` complete password reset
- `/logout` sign out locally and from Cognito where possible

## Testing

Run the Laravel test suite:

```bash
php artisan test
```

Build frontend assets:

```bash
npm run build
```

## Notes

- This app currently issues a signed `sso_token` on successful brokered sign-in, but consuming apps still need to verify and consume that token.
- Return URL validation is strict by design. If a redirect host is not configured in the consumer allow-list, the portal will refuse to return the user there.
