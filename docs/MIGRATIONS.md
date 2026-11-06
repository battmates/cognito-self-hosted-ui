# Legacy account migration

`LEGACY_MIGRATION_ENABLED=false` keeps migrations disabled. Administrator management writes are now enabled by user request (`COGNITO_MANAGEMENT_WRITES_ENABLED=true`); this alone does not enable migrations. WordPress requires its HTTPS verification endpoint and server API token. Cloud uses the two API calls described below.

## Cloud login fallback and token cache

Cloud verification only runs for valid email identifiers. Username sign-ins silently omit Cloud from the provider order and progress messages, make no Cloud token or verification requests, and can still use MusicTeacher verification. Existing Cognito accounts are checked first for either identifier type.

Login checks Cognito first. For a missing Cognito account, the default verification order is Cloud then MusicTeacher (WordPress). A sign-in journey whose validated callback belongs to the `musicteacher` consumer uses MusicTeacher then Cloud. The priority comes from the broker-validated journey, not an arbitrary form field or referrer. Disabled providers are skipped. A provider match stops the search; a no-match continues to the next enabled provider. Cloud HTTP 401 with `authenticated: false` and `user: null` is a no-match and continues to the next provider. When the same response includes a user object, stop without checking another provider and show “Incorrect credentials.” The progress modal reports that as rejected rather than unavailable. WordPress no-match responses are HTTP 401/404, or HTTP 200 with `ok: false` and no user. Provider verification failures, including outages, timeouts, security lockouts, invalid service credentials and malformed responses, skip that provider and continue to the next enabled provider. If none verifies the user, the final message names unavailable services and asks the user to retry later. An ordinary no-match in every available service shows a generic account-not-found message. The progress modal marks unavailable providers as skipped. Cognito directory checks and provisioning failures still stop the request; a partial account creation must never be retried through another provider. The progress modal follows the selected order.

Configure Cloud service credentials in the server environment, never in frontend code or committed files:

```dotenv
LEGACY_MIGRATION_ENABLED=true
MIGRATION_CLOUD_ENABLED=true
SSO_CLOUD_API_URL=https://your-cloud-api
SSO_CLOUD_API_USERNAME=your-service-account
SSO_CLOUD_API_PASSWORD="your-service-password"
```

The portal calls `GET /Account/generate-token` with `userName` and `password` from the service account. The response must contain a nonempty `Token` and a future absolute `ExpireTime`, for example `2026-11-01T11:50:31+00:00`. The returned `URL` is ignored; requests always use the configured base URL. Credentials in this GET query must be redacted from infrastructure request logs.

A token response with explicit null `Token`, `CreatedTime` and `ExpireTime` fields means the Cloud service credentials were rejected. The portal logs `portal.cloud.token.failed` with reason `invalid_service_credentials`, marks Cloud unavailable/skipped in the progress modal, and continues to the next enabled provider without sending user credentials to Cloud authentication. Credentials and response bodies are never included in that log.

The bearer token is encrypted in Laravel's cache until `ExpireTime`. It is shared across users and requests, scoped to the base URL and service credentials. A cache lock and a second cache check prevent concurrent requests from generating duplicate tokens. Use a persistent shared cache store for multiple application instances; the `array` store is suitable only for tests. A failed authentication request does not regenerate an unexpired token.

With that token, the portal sends JSON `{"identifier":"<entered username or email>","password":"<entered password>"}` to `POST /backstage/ssoauthenticate`. A 200 response containing `{"authenticated":false,"user":null}`, or HTTP 404, means no match. HTTP 401 with `authenticated: false` and `user: null` continues to the next provider, including when a Bearer header is present. HTTP 401 with `authenticated: false` and a user object stops sign-in with “Incorrect credentials.” A plain HTTP 401 without that flag or an authentication challenge remains a no-match. For responses without an explicit `authenticated: false` credential rejection, challenge errors such as `invalid_token` fail Cloud verification and continue to the next enabled provider. Other Cloud HTTP errors do the same. Successful responses require `authenticated: true` and the `user` fields in the verified identity contract below. The supplied Cloud user example matches that contract, including string ID, email-style username and `email_verified: true`. Email-style usernames receive a username built from given and family names (for example `aaron.osullivan`); the returned email remains the verified sign-in alias. Names, including apostrophes, are preserved. Missing identity fields or email-verification evidence block provisioning.

## Stronger password during verified migration

A pre-creation Cognito password-policy rejection retains only the verified identity and validated application journey, encrypted in the shared cache for five minutes and bound to the browser session. Neither password is cached or flashed. The session and CSRF token rotate when this setup starts. A browser without JavaScript receives an equivalent password-setup page.

The new-password form shows the pool requirements, requires confirmation and validates again on the server. Proof is consumed before account writes and protected by a lock. Provisioning rechecks account collisions and uses the existing migration lock; it cannot replace or adopt an existing Cognito account. Only another definite pre-creation password-policy rejection restores the proof within its original lifetime. Partial creation failures consume it and require review.

After creation, the portal signs in directly to Cognito with the new password, preserving any MFA challenge and the original application redirect. It does not send the new password back to the legacy provider. If sign-in fails after successful creation, the user is told to sign in with the new password; creation is never repeated through the consumed setup.

## WordPress password-recovery fallback

`LEGACY_PASSWORD_RECOVERY_ENABLED=false` keeps recovery lookup disabled independently of password-based login migration. When enabled together with `MIGRATION_WORDPRESS_ENABLED=true`, a Cognito `UserNotFoundException` from the portal's forgot-password form calls the WordPress lookup endpoint before returning the normal Cognito error. The portal signs `POST /wp-json/cognito-migrate/v1/lookup` with `X-Cognito-Migrate-Timestamp` and the HMAC-SHA256 signature required by WP-Cognito, over the exact JSON body `{"username":"<entered identifier>"}`.

A successful lookup creates a role-free Cognito account with a generated server-only password, then invokes Cognito's normal forgot-password operation. The reset code therefore goes only to the WordPress account's returned email and Cognito remains responsible for the final password reset. No legacy password, WordPress role, or WordPress session is transferred.

## MusicTeacher WordPress

The MusicTeacher adapter uses the endpoint already supplied by the `wp-cognito-sso` plugin:

`https://woocommerce-1671671-6675507.cloudwaysapps.com/wp-json/cognito-migrate/v1/verify`

Laravel sends the exact JSON body `{ "username": "...", "password": "..." }`. It signs `<unix timestamp>.<raw body>` with HMAC-SHA256 and sends the timestamp and hex signature as `X-Cognito-Migrate-Timestamp` and `X-Cognito-Migrate-Signature`. The shared secret must match the plugin's **Cognito user migration → Shared secret** setting (or its `COGNITO_MIGRATION_SHARED_SECRET` constant).

```dotenv
LEGACY_MIGRATION_ENABLED=true
MIGRATION_WORDPRESS_ENABLED=true
MIGRATION_WORDPRESS_ENDPOINT=https://woocommerce-1671671-6675507.cloudwaysapps.com/wp-json/cognito-migrate/v1/verify
MIGRATION_WORDPRESS_SHARED_SECRET=replace-with-the-existing-shared-secret
```

The endpoint verifies the actual WordPress password and supports either login name or email. A successful response is normalized to Cognito attributes. To remain compatible with accounts created by the former migration Lambda, the canonical Cognito username is `wp_<WordPress user ID>` and the WordPress login is stored as `preferred_username`. Email and legacy username sign-ins both resolve back to that canonical username. Blank WordPress names use the same `Unknown` / `User` fallback as the Lambda.

MusicTeacher's existing Lambda and WordPress-to-Cognito sync both treat the email on a password-verified WordPress account as authoritative, so the Laravel migration preserves that behavior and creates it as `email_verified=true`.

## Cloud adapter contract

Cloud uses the token acquisition and caching flow above. Its authentication endpoint expects the following successful response:

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

Cloud takes priority for direct sign-in and other applications; MusicTeacher (WordPress) takes priority only for a validated MusicTeacher sign-in journey. Unverified email, missing names, mismatched identifiers and directory outages block migration. Existing Cognito identities always retain their own authentication flow, and no legacy password can reset an existing pool account. The submitted legacy password is initially used for the new pool account. If Cognito rejects it with `InvalidPasswordException` before account creation, the portal opens a new-password modal. The user creates and confirms a stronger password, then provisioning and Cognito sign-in continue with that new password. The original platform password is unchanged.

Only the listed identity fields are imported; no roles or admin flags are copied. Email-style legacy usernames receive a sanitized lowercase `givenname.familyname` Cognito username; email sign-in continues to use the verified alias. If a name is already taken, the portal checks candidates with numeric suffixes (`_2`, `_3`, and so on, up to 100 attempts). It also retries a Cognito `UsernameExistsException` if another signup takes a candidate during creation. Existing email aliases still block migration; password-setting failures after creation require operator review and never retry creation. The same naming applies to password-recovery provisioning. Normal legacy usernames are retained. Provisioning uses a shared-cache lock by email, checks username/email collisions again, suppresses welcome messages, and never enables `ForceAliasCreation`. AdminCreateUser and AdminSetUserPassword cannot form one AWS transaction: a partial failure requires operator review, with no automatic overwrite, adoption or deletion. Native registration or another application can still race between AWS checks; validate concurrent alias behavior in staging before rollout.
The WordPress endpoint returns 401/404 for invalid credentials or absent accounts. Cloud no-match responses use the format described above. For login, throttling and outages mark that provider unavailable and continue to the next provider. A remaining verified provider may complete migration; otherwise the final response reports the unavailable services.

## Safety and provisioning

- Only ID, username, email and names are accepted; roles and admin flags are never imported.
- Passwords and authorization credentials are never logged. Verification requests do not redirect or retry.
- The original password, or the user-confirmed replacement from the new-password modal, is set as the permanent Cognito password and must meet the pool policy.
- A cache lock serializes migration by normalized email. Username and email collisions are checked again before creation.
- Welcome messages are suppressed and `ForceAliasCreation` stays false.
- `AdminCreateUser` and `AdminSetUserPassword` cannot form one AWS transaction. A partial failure requires operator review; the portal does not overwrite, adopt or delete an account automatically.
- The existing Cognito migration Lambda is not modified by this implementation. Remove it from the pool only after the Laravel path has been enabled and verified with designated accounts.
