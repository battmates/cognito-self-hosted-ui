# Legacy migration adapter contract (disabled)

`LEGACY_MIGRATION_ENABLED=false` keeps migrations disabled. Administrator management writes are now enabled by user request (`COGNITO_MANAGEMENT_WRITES_ENABLED=true`); this alone does not enable migrations. Enabling either provider additionally requires its HTTPS endpoint and server API token. This is a proposed adapter contract, not a claim that either legacy system already implements it.

The portal posts JSON with `identifier` (username or email, entered by the user) and `password`, authenticated with `Authorization: Bearer <provider token>`. The endpoint must validate the actual password through the legacy platform's supported authentication API; a user-exists response is insufficient. It must support both identifier types. Use constant-time password verification, generic failure responses and rate limits. Never log the password or authorization header.

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

Both enabled providers are checked. Two matches require manual resolution; there is no silent priority. Unverified email, missing names, mismatched identifiers and directory outages block migration. Existing Cognito identities always retain their own authentication flow, and no legacy password can reset an existing pool account. The exact submitted password is used for the new pool account and must satisfy its current password policy.

Only the listed identity fields are imported; no roles or admin flags are copied. Email-style legacy usernames receive a stable provider-prefixed Cognito username; email sign-in continues to use the verified alias. Normal legacy usernames are retained. Provisioning uses a shared-cache lock by email, checks username/email collisions again, suppresses welcome messages, and never enables `ForceAliasCreation`. AdminCreateUser and AdminSetUserPassword cannot form one AWS transaction: a partial failure requires operator review, with no automatic overwrite, adoption or deletion. Native registration or another application can still race between AWS checks; validate concurrent alias behavior in staging before rollout.

To activate, obtain endpoints from MusicTeacher WordPress and RSL Cloud owners, confirm authoritative email verification semantics, decide how overlapping identities should be linked, approve SDK writes, and test with specifically designated migration accounts. The existing Cognito migration Lambda stays untouched. This adapter intercepts absent identities before direct Cognito password authentication when enabled; accounts already in the pool use Cognito normally. Not-yet-migrated password recovery still needs a separate workflow decision.
