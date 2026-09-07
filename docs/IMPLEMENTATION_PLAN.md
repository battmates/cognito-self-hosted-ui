# Self-hosted Cognito portal: delivery plan

## Contract and findings (7 September 2026)

Guestlist (`../GuestsList/app/Http/Controllers/Auth/CognitoController.php`) redirects to `/login`, exchanges a code at `/oauth2/token`, and logs out through `/logout`. Settings are `AWS_COGNITO_DOMAIN`, `AWS_COGNITO_CLIENT_ID`, `AWS_COGNITO_CLIENT_SECRET`, and `AWS_COGNITO_REDIRECT_URI`. It currently decodes the Cognito ID token, provisions its local user, and continues to its intended page.

Backstage (`../Rockschool-Backstage-staging/inc/sso-cognito.php`) uses `/oauth2/authorize`, `/oauth2/token`, and `/logout`. Its ACF global options are `cognito_domain`, `client_id`, `client_secret`, and `scope`. Callback is `/cognito-login`. Its opaque `state` contains the final WordPress destination and must be returned unchanged. `prompt=forgot_password` opens account recovery. Both apps force HTTPS for their configured domain.

Read-only AWS inspection confirmed:
- Guestlist and this portal use the same staging pool/client. Both staging callbacks and logout URLs are already registered. Backstage's actual ACF client values still need verification.
- Username is the primary identifier; email is a case-insensitive alias. Email, given name and family name are required.
- Direct password and refresh-token auth are enabled. MFA is optional.
- Only Google is enabled as an external identity provider.
- The supplied admin's native account is confirmed with `custom:user_role=administrator`. Other accounts share its email: matching an arbitrary email search result is unsafe.
- The existing WordPress migration Lambda remains configured and untouched.

## Implementation sequence

1. Replace the custom `sso_token` scheme with a limited authorization-code broker using genuine Cognito tokens. Validate exact client/callback pairs; preserve state; expire and atomically consume encrypted codes; authenticate token requests; support S256 PKCE. Never substitute a token for another Cognito client.
2. Refresh and expire portal sessions, regenerate session IDs at sign-in, handle supported Cognito challenges, validate logout destinations, and keep tokens server-side. Build the staging application dashboard and referral headline while retaining split sign-in/register.
3. Add Google/provider icons and environment-based availability; use Cognito federation to retain Cognito identities. Use the already registered local `/callback` URL. Strengthen callback state, nonce and PKCE handling.
4. Add admin search, pagination, full attributes/status, password setting, confirmation, reset, enable/disable, and sign-out actions. Re-read the administrator's current pool role for each request; use CSRF, throttling, audit events and a default-off write switch. Test mutations with fakes only.
5. Add disabled migration adapters for WordPress and RSL Cloud with an explicit identity-verification contract. Only a proven absent pool identity may migrate. Fail on ambiguity/outage; never overwrite or silently link an existing identity, import privileged roles, or persist passwords.
6. Test authentication and code exchange, invalid redirects/clients, replay/expiry/PKCE, role enforcement, migration edge cases, then inspect the browser UI. No staging application settings or pool configuration changes during implementation.

## Status after the first implementation pass

Steps 1–4 are implemented for the audited shared-client flows. Step 5 is implemented behind disabled feature switches against a proposed adapter contract. Step 6 passes locally, including real administrator sign-in and read-only directory access. No staging application cutover or live migration/admin mutation was performed. See [verification details](VERIFICATION.md) for evidence and acceptance tests still needed.

## Boundaries and remaining decisions

- This is a broker for the audited authorization-code consumers, not a complete replacement for Cognito's OIDC server. Direct API tokens have Cognito's API scopes, not arbitrary requested OAuth resource scopes. Unsupported grants/scopes/nonce requests must fail explicitly. Applications that validate extra OAuth claims, require implicit grants, resource-server scopes, or call OAuth userInfo need a separate compatibility assessment.
- Reusing the same existing Cognito client allows session reuse. Different clients cannot share Cognito refresh tokens. Cloud, MusicTeacher.com and Spotlight need their callback/logout URLs, client configuration and token-validation behavior audited before enablement.
- Public testing needs an HTTPS portal hostname reachable by staging servers. Only then change Guestlist's domain environment value and Backstage's ACF domain (hostname only), retaining the existing shared client credentials and callbacks. Do not repoint them until end-to-end staging tests are authorised.
- Google can be tested locally with `http://localhost:8000/callback`, already allowlisted. A deployed portal callback and further identity providers require approved Cognito configuration changes. Microsoft/Apple/Facebook provider credentials belong in Cognito when using federation, not in browser code; provider names/availability belong in this app's environment.
- Supply the WordPress credential-verification API/plugin details and RSL Cloud authentication API/tenant details. Both must support username and email, return a stable identity and verified email status, and specify throttling and outage semantics. Decide precedence/collisions when the same identity exists on both legacy platforms. Existing Lambda may still act during ordinary Cognito login; coordinated production cutover is outstanding.
- User management writes are now enabled following the user’s explicit request. Migration remains disabled pending real adapter configuration and approval. Deletion is now included following the user’s request, with exact username confirmation, a fresh subject check, and protection against deleting the signed-in administrator’s account.
- Privacy/terms copy, password recovery for not-yet-migrated users, MFA enrollment/selection challenges, production IAM, session/cache deployment and live migration verification remain launch work.

References: [AWS authorization endpoint](https://docs.aws.amazon.com/cognito/latest/developerguide/authorization-endpoint.html), [token endpoint](https://docs.aws.amazon.com/cognito/latest/developerguide/token-endpoint.html), [InitiateAuth](https://docs.aws.amazon.com/cognito-user-identity-pools/latest/APIReference/API_InitiateAuth.html).

## Follow-up: live user search and editable administration

The user requested automatic filtering while typing and enabled management actions. Search now uses a 350ms debounce, cancels superseded requests, preserves keyboard focus and clears a previous account selection as filters change. JSON responses use the same administrator middleware and escaped Blade rows as the full page. Administrative writes are enabled in local and example configuration; an explicit false setting remains available for maintenance. Migration settings remain disabled. Mutation verification uses the AWS SDK mock, without changing an existing staging account solely for testing.

## Follow-up: separate profiles, deletion and Load more

The directory now uses Load more to append the next Cognito batch, keeping existing rows and retrying the same batch if loading fails. Changing filters cancels an outstanding request and resets the list. Profile links open a separate full-width view without loading the directory list; Back to users preserves the field and query. Returning to the list starts with its first batch.

Delete user is available alongside the existing actions and clearly identifies the permanent removal from the shared pool. The server requires explicit action confirmation, the exact canonical username and the subject from the viewed profile. It re-reads the target before deletion and rejects a changed subject or the signed-in administrator’s canonical account, even if requested using an alias. Success returns to the filtered list; failures retain an error. SDK deletion is tested with mocks only. No staging records or pool configuration were changed.

## Follow-up: directory columns, partial emails and row actions

The list now separates User name, Email address, Email verified, Confirmation status and Status. Status badges distinguish confirmed/enabled accounts, password changes, disabled/compromised accounts and other states in both themes. Each row has a three-dot menu for profile access and the existing admin actions, with confirmation dialogs for mutations. Password and deletion dialogs retain their dedicated confirmation fields, and successful row actions return to the filtered list. Profile headings use given_name plus family_name and show the Cognito username underneath.

Email searches now match case-insensitive substrings, including domains and middle-of-address fragments. Cognito’s native filter cannot do this, so each request scans at most ten source batches and returns up to thirty matches plus the continuation cursor. The browser automatically continues through empty batches; Load more continues after a matching batch. Sparse searches can take longer in large pools. Exact email identity checks used by migration retain the native exact filter, and other fields retain prefix filtering. No pool changes are required.

## Follow-up: locally bundled DataTables

DataTables 3.0.3 now manages the user table. Its JavaScript and stylesheet are installed in npm and built through a dedicated Vite entry for the admin list. Rows from live pool search and Load more are added through the DataTables API, which keeps sorting and its row cache synchronized. Sorting applies to loaded rows; the existing pool filters remain authoritative. Pagination and a duplicate client-only search box are disabled. Row menus, status badges, theme styling and full-width profiles remain available.

The install reported existing frontend dependency advisories; compatible npm audit fixes were applied and the resulting audit reported zero vulnerabilities.
