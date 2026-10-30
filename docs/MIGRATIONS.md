# Legacy migration adapter contract (disabled)

`LEGACY_MIGRATION_ENABLED=false` keeps migrations disabled. Administrator management writes are now enabled by user request (`COGNITO_MANAGEMENT_WRITES_ENABLED=true`); this alone does not enable migrations. WordPress requires its HTTPS verification endpoint and server API token. Cloud uses the two API calls described below.

## Cloud login fallback and token cache

Login checks Cognito first. For a missing Cognito account, enabled WordPress verification runs first; Cloud runs only when WordPress returns no match (401/404), or WordPress is disabled. A WordPress match stops the search. Provider outages and malformed responses stop migration rather than triggering fallback.

Configure Cloud service credentials in the server environment, never in frontend code or committed files:

```dotenv
LEGACY_MIGRATION_ENABLED=true
MIGRATION_CLOUD_ENABLED=true
SSO_CLOUD_API_URL=https://your-cloud-api
SSO_CLOUD_API_USERNAME=your-service-account
SSO_CLOUD_API_PASSWORD="your-service-password"
```

The portal calls `GET /generate-token` with `userName` and `password` from the service account. The response must contain a nonempty `Token` and a future absolute `ExpireTime`, for example `2026-11-01T11:50:31+00:00`. The returned `URL` is ignored; requests always use the configured base URL. Credentials in this GET query must be redacted from infrastructure request logs.

The bearer token is encrypted in Laravel's cache until `ExpireTime`. It is shared across users and requests, scoped to the base URL and service credentials. A cache lock and a second cache check prevent concurrent requests from generating duplicate tokens. Use a persistent shared cache store for multiple application instances; the `array` store is suitable only for tests. A failed authentication request does not regenerate an unexpired token.

With that token, the portal sends JSON `{"identifier":"<entered username or email>","password":"<entered password>"}` to `POST /backstage/ssoauthenticate`. A 200 response containing `{"authenticated":false,"user":null}`, or HTTP 404, means no match. Other HTTP errors stop migration. Successful responses require `authenticated: true` and the `user` fields in the verified identity contract below. The supplied Cloud user example matches that contract, including string ID, email-style username and `email_verified: true`. Email-style usernames receive a username built from given and family names (for example `aaron.osullivan`); the returned email remains the verified sign-in alias. Names, including apostrophes, are preserved. Missing identity fields or email-verification evidence block provisioning.

## WordPress password-recovery fallback

`LEGACY_PASSWORD_RECOVERY_ENABLED=false` keeps recovery lookup disabled independently of password-based login migration. When enabled together with `MIGRATION_WORDPRESS_ENABLED=true`, a Cognito `UserNotFoundException` from the portal's forgot-password form calls the WordPress lookup endpoint before returning the normal Cognito error. The portal signs `POST /wp-json/cognito-migrate/v1/lookup` with `X-Cognito-Migrate-Timestamp` and the HMAC-SHA256 signature required by WP-Cognito, over the exact JSON body `{"username":"<entered identifier>"}`.

A successful lookup creates a role-free Cognito account with a generated server-only password, then invokes Cognito's normal forgot-password operation. The reset code therefore goes only to the WordPress account's returned email and Cognito remains responsible for the final password reset. No legacy password, WordPress role, or WordPress session is transferred.

For WordPress login verification, the portal posts JSON with `identifier` (username or email, entered by the user) and `password`, authenticated with `Authorization: Bearer <provider token>`. The endpoint must validate the actual password through the legacy platform's supported authentication API; a user-exists response is insufficient. It must support both identifier types. Use constant-time password verification, generic failure responses and rate limits. Never log the password or authorization header.

- Return 401/404 for invalid credentials/no such account.
- Return 200 only for verified credentials, with the following JSON.
- Return 429/5xx for throttling/outages. The portal stops instead of treating these as absent accounts.

```json
{
  "authenticated": true,
  "user": {
    "id": "stable-source-user-id",
    "username": "legacy-username",
    "email": "person@example.com",
    "email_verified": true,
    "given_name": "Example",
    "family_name": "Person"
  }
}
```

WordPress takes priority over Cloud for login migration. Unverified email, missing names, mismatched identifiers and directory outages block migration. Existing Cognito identities always retain their own authentication flow, and no legacy password can reset an existing pool account. The exact submitted password is used for the new pool account and must satisfy its current password policy.

Only the listed identity fields are imported; no roles or admin flags are copied. Email-style legacy usernames receive a sanitized lowercase `givenname.familyname` Cognito username; email sign-in continues to use the verified alias. If a name is already taken, the portal checks candidates with numeric suffixes (`_2`, `_3`, and so on, up to 100 attempts). It also retries a Cognito `UsernameExistsException` if another signup takes a candidate during creation. Existing email aliases still block migration; password-setting failures after creation require operator review and never retry creation. The same naming applies to password-recovery provisioning. Normal legacy usernames are retained. Provisioning uses a shared-cache lock by email, checks username/email collisions again, suppresses welcome messages, and never enables `ForceAliasCreation`. AdminCreateUser and AdminSetUserPassword cannot form one AWS transaction: a partial failure requires operator review, with no automatic overwrite, adoption or deletion. Native registration or another application can still race between AWS checks; validate concurrent alias behavior in staging before rollout.

To activate, obtain endpoints from MusicTeacher WordPress and RSL Cloud owners, confirm authoritative email verification semantics, decide how overlapping identities should be linked, approve SDK writes, and test with specifically designated migration accounts. The existing Cognito migration Lambda stays untouched. This adapter intercepts absent identities before direct Cognito password authentication when enabled; accounts already in the pool use Cognito normally. Not-yet-migrated password recovery still needs a separate workflow decision.
