# Legacy account migration

The portal owns the migration flow. On password sign-in it resolves the supplied email or username in Cognito first. If no pool account matches, it tries enabled providers in configuration order and stops at the first provider that verifies the credentials. The order is MusicTeacher WordPress, then RSL Cloud.

`LEGACY_MIGRATION_ENABLED=false` is the global kill switch. A provider also needs its own enable flag, endpoint and credential. `COGNITO_MANAGEMENT_WRITES_ENABLED=true` is required only to create a migrated account; existing Cognito users can still sign in while writes are disabled.

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

## RSL Cloud adapter contract

RSL Cloud remains disabled. Its planned JSON bearer adapter posts `identifier` and `password` and expects:

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

It must return 401/404 for invalid credentials or an absent account, and 429/5xx for throttling or outages. Provider outages stop authentication and are never treated as an absent account.

## Safety and provisioning

- Only ID, username, email and names are accepted; roles and admin flags are never imported.
- Passwords and authorization credentials are never logged. Verification requests do not redirect or retry.
- The exact submitted password is set as the new permanent Cognito password and must meet the pool policy.
- A cache lock serializes migration by normalized email. Username and email collisions are checked again before creation.
- Welcome messages are suppressed and `ForceAliasCreation` stays false.
- `AdminCreateUser` and `AdminSetUserPassword` cannot form one AWS transaction. A partial failure requires operator review; the portal does not overwrite, adopt or delete an account automatically.
- The existing Cognito migration Lambda is not modified by this implementation. Remove it from the pool only after the Laravel path has been enabled and verified with designated accounts.
