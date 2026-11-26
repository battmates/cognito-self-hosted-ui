# RSL authentication portal launch checklist

Planned launch: Thursday 26 November 2026. Prepared and checked on 25 November 2026.

DNS cutover reported by Matt on 26 November. Verified at 11:14 GMT: both authoritative nameservers, Cloudflare DNS and Google DNS return A `46.101.76.53`; the new portal responds over HTTPS. Application acceptance checks below remain pending unless individually ticked.

Progress at 11:25 GMT on 26 November: Matt reports all social providers and Backstage, Guestlist and Greenroom sign-in working. Greenroom initially showed the WordPress invalid-grant message (described as token already used); logout and a fresh sign-in succeeded. Root cause is unconfirmed; repeat a clean Greenroom login before closing acceptance. Cloud team is deploying login and migration changes; Cloud acceptance is pending. Public DNS, HTTPS 200 and HTTP-to-HTTPS 301 were independently rechecked.

After the Cloud release on 26 November: Cloud requested `/admin/users/cognito-login`, which was missing from the portal allowlist. Added that exact HTTPS callback alongside `/cognito/callback`, saved a private environment backup, and rebuilt configuration. Chrome then reached Cloud's `/secure` dashboard as Matt and accessed the protected Downloads page. A direct migration-service probe from the portal using a nonexistent test email obtained the service token and returned HTTP 401 with JSON `authenticated: false` and `user: null`, correctly handled as no match. No Cognito account was created by the probe. Successful legacy-user migration and explicit Cloud logout/relogin remain pending.

Recovery test on 26 November: Matt subsequently confirmed Cloud login and ordinary legacy login migration work. Password recovery for a Cloud-only account is blocked: production `recoverForPasswordReset` implements WordPress lookup only, and WordPress migration is intentionally disabled. The specified test account has no matching Cognito identity. Its Mailinator inbox contains the Cloud New Account email but no reset email. The Cognito client has `PreventUserExistenceErrors=ENABLED`; a generic forgot-password success is not evidence of an email being sent. Cloud needs a trusted lookup-by-email recovery integration before this acceptance item can pass. Do not enable WordPress migration as a workaround, guess user identities, or manually create the test Cognito account to bypass this test.

Recovery fix progress on 26 November: the colleague's recovery integration was pulled, and its response handling adjusted to accept Cloud's actual HTTP 200 `authenticated: false` plus `user` payload without requiring `found`. The fix and dependent recovery services were deployed to staging only. All 264 tests pass, including Cloud identity guards and fallback to the existing signed WordPress lookup. A read-only lookup from the updated staging code accepts the live API's test identity. Staging remains configured for the staging Cloud API, which returns 404/no match for that live test account. Full staging reset-email/password completion and production deployment of the compatibility fix remain pending. No test identity was provisioned and no reset email sent by these checks.

Launch the portal for Cloud, Backstage, Guestlist and Greenroom first. MusicTeacher stays disabled with a grey Coming soon card until the separate activation steps below are completed. Use this checklist during the change; tick an item only after its stated check succeeds.

## Launch details

| Item | Value |
| --- | --- |
| Portal | `https://auth.rslgroup.io` |
| Cloudways server | `46.101.76.53` |
| Production application | `ktbkxftspg` |
| Laravel directory | `/home/1454445.cloudwaysapps.com/ktbkxftspg/private_html/app` |
| Production environment file | Laravel directory above, `.env` |
| Web document root | `/home/1454445.cloudwaysapps.com/ktbkxftspg/public_html` |
| Live Cognito pool | `eu-west-2_pg2smtPib`, region `eu-west-2` |
| Shared Cognito client ID | `7gmkvrve0vnn7q28dl1jeq790d` |
| Portal federation upstream | `eu-west-2pg2smtpib.auth.eu-west-2.amazoncognito.com` |
| Previous DNS and rollback target | CNAME `auth.rslgroup.io` → `d3v5fmhv5tow4v.cloudfront.net` |
| Published cutover DNS | A `auth.rslgroup.io` → `46.101.76.53`, verified 26 November at 11:14 GMT |
| Existing CNAME TTL | 600 seconds |

Do not change the Cognito pool, shared client credentials, application key or federation upstream as part of the DNS switch. Keep the existing Cognito custom-domain configuration available for rollback. Do not change staging.

## Checks completed before launch

- [x] Production `APP_URL` is `https://auth.rslgroup.io`.
- [x] Production mode enabled; debug and staging badge disabled.
- [x] Direct HTTPS request to Cloudways for `auth.rslgroup.io` succeeds with a valid certificate.
- [x] HTTP redirects with 301 to `https://auth.rslgroup.io/`.
- [x] Portal response has `Cache-Control: no-store, private`.
- [x] Database reachable and all listed database migrations applied.
- [x] Database sessions and secure, host-only session cookies configured.
- [x] Cognito client allows the portal's `https://auth.rslgroup.io/callback`.
- [x] Production portal has the live destinations and allowlists in the table below.
- [x] Cloud legacy API URL set to `https://rslapi.azurewebsites.net/api`; configuration cache rebuilt. API authentication and the new verification operation still require testing after the Cloud release.
- [x] MusicTeacher migration disabled, its five URL settings cleared, and effective production configuration verified.
- [x] Rendered production dashboard verified: MusicTeacher card says Coming soon, has no destination link, and is disabled. Learner/parent accounts continue to have the existing card restrictions.
- [x] No `profile.musicteacher.com` callback or sign-out destination remains in the portal allowlists.
- [x] Private production environment backup saved before deferring MusicTeacher. Its shared secret and other credentials were preserved.
- [x] Owner confirmed the older admin, my, instructor and Franscape integrations are retired. Microsoft proxy remains in scope and is being updated.

| Application | Destination | Allowed callback | Allowed sign-out destination |
| --- | --- | --- | --- |
| Cloud | `https://cloud.rslawards.com/` | `https://cloud.rslawards.com/admin/users/cognito-login` (deployed); `https://cloud.rslawards.com/cognito/callback` also retained | `https://cloud.rslawards.com/signed-out` |
| Backstage | `https://backstage.rockschool.io` | `https://backstage.rockschool.io/cognito-login` | `https://backstage.rockschool.io/logout` |
| Guestlist | `https://guestlist.rockschool.io` | `https://guestlist.rockschool.io/cognito/callback` | `https://guestlist.rockschool.io/logout` |
| Greenroom | `https://greenroom.rslgroup.io` | `https://greenroom.rslgroup.io/cognito-login` | `https://greenroom.rslgroup.io/logout` |

The portal's callback allowlists are separate from Cognito's. Downstream applications exchange portal-issued codes at `https://auth.rslgroup.io/oauth2/token`. The portal itself continues to use the AWS domain for federation. A Mac hosts override affects only that Mac, not token requests from downstream servers.

## Final readiness gate

Launch coordinator: __________  Cloud deployment owner: __________  Support and monitoring owner: __________

Agreed launch time and timezone: __________  Rollback decision owner: __________

- [ ] Take a fresh Cloudways application/database backup and confirm how to restore it. Retain the existing `APP_KEY`.
- [ ] Record the approved portal release and Cloud release; save current Cloud configuration and rollback instructions. Freeze unrelated deployments, including pushes to `main` that trigger production deployment.
- [x] Cloud deployment reported complete by Matt. Public login redirect uses the live shared client and `/admin/users/cognito-login`; the portal allowlist was corrected and authenticated Cloud access verified in Chrome. Explicit logout/relogin is still a separate acceptance check.
- [x] Cloud migration service connection and negative lookup checked from the production portal against `rslapi.azurewebsites.net/api`. Service-token authentication succeeded; nonexistent account returned the expected no-match JSON contract. Matt subsequently confirmed successful legacy login migration. Forgot-password migration remains a separate blocked check below.
- [ ] Complete Microsoft proxy updates and test its full return journey through the AWS Cognito domain. Obtain explicit agreement on provider availability if Microsoft is not ready; do not advertise an untested sign-in option as working.
- [ ] Verify Google, Facebook and Apple use the AWS Cognito return endpoint and complete sign-in with the production pool. Check existing Apple users as well as a test identity.
- [ ] Confirm native login, registration plus confirmation email, and password recovery on the approved release. Include the duplicate-username/email notices and any required email/MFA challenge.
- [ ] Confirm the current privacy, terms and help links open correctly.
- [ ] Verify each linked app's server can reach the portal after DNS cutover. Arrange a controlled rehearsal if needed; browser-only hosts-file testing does not prove server-side token exchange.
- [ ] Confirm rollback can restore Cloud compatibility as well as DNS. Keep the old Cognito custom domain and certificate in place.
- [ ] Obtain go/no-go agreement. MusicTeacher activation is not a dependency for this first cutover.

## DNS cutover

- [ ] Record start time: __________. Confirm final backups and release readiness.
- [x] Cloud deployment reported complete; portal callback mismatch resolved and authenticated Cloud access verified on 26 November.
- [x] Matt replaced the `auth` CNAME with A `46.101.76.53`; verified on both authoritative nameservers at 11:14 GMT on 26 November.
- [x] Authoritative lookup returns no CNAME or AAAA record for `auth.rslgroup.io`.
- [x] New A record TTL is 600 seconds. Exact save time not recorded; first verified at 11:14 GMT.
- [x] Both authoritative nameservers and public resolvers `1.1.1.1` and `8.8.8.8` return the new A record. Older cached answers may remain elsewhere.
- [ ] Test from a device without a hosts override, preferably on another network. Remove the testing Mac's override when validating public DNS and flush its local DNS cache as appropriate.
- [ ] Monitor for split-DNS failures: a browser reaching the portal while a linked server still exchanges codes with Cognito can produce `invalid_grant`. Retry a fresh login after resolution is consistent; do not reuse an old authorization code.

Example checks, run from a machine with network access:

```bash
dig +noall +answer auth.rslgroup.io A
dig @1.1.1.1 +noall +answer auth.rslgroup.io A
dig @8.8.8.8 +noall +answer auth.rslgroup.io A
curl --head https://auth.rslgroup.io/
```

The expected public A answer is `46.101.76.53`. Inspect the page as well as the HTTP status to confirm it is the new portal.

## Production acceptance checks

Keep existing Backstage, Guestlist and Greenroom sessions intact. Do not perform a blanket logout or clear session stores for this DNS change. Test existing sessions alongside fresh sign-ins. A login that straddles the cutover may need to be restarted with a fresh authorization code.

- [x] Portal loads over HTTPS on public DNS; HTTP redirects to HTTPS. Rechecked 26 November at 11:25 GMT.
- [ ] Native Cognito login succeeds, including an account with a configured verification challenge.
- [ ] Registration and email confirmation succeed using a disposable test account.
- [ ] Password recovery succeeds for a designated existing Cognito test account.
- [x] Cloud legacy login migration reported working by Matt on 26 November.
- [ ] Confirm username-only login correctly skips Cloud verification.
- [ ] Cloud-only account password recovery migration: compatibility fix is deployed to staging and API lookup tested. Complete staging recovery with a Cloud staging test account, then obtain production rollout approval and verify Cognito provisioning, reset-email receipt, password reset and subsequent sign-in on production.
- [x] Google, Facebook, Apple and Microsoft sign-in reported working by Matt on 26 November.
- [x] Backstage sign-in reported working by Matt on 26 November.
- [x] Guestlist sign-in reported working by Matt on 26 November.
- [x] Greenroom sign-in reported working after logout and retry by Matt on 26 November.
- [ ] Repeat Greenroom sign-in in a fresh private window; verify no recurring invalid-grant error. Investigate if a fresh flow fails again.
- [ ] Backstage, Guestlist and Greenroom: complete the remaining authorised-page and explicit logout/relogin checks for each site.
- [x] Cloud login through the corrected callback reaches `/secure`, and the protected Downloads page is accessible in Matt's Chrome session.
- [ ] Cloud: explicitly sign out and back in, verifying the deployed sign-out destination.
- [ ] Test a fresh browser session, an existing app session and switching between applications. Confirm expected roles and access permissions.
- [ ] Confirm MusicTeacher remains Coming soon and no MusicTeacher migration messages or requests occur. Existing Cognito identities remain usable for the other applications.
- [ ] Monitor portal, Cloud and linked-app logs for callback errors, `invalid_grant`, verification errors and HTTP 5xx responses. Do not copy passwords, tokens or full authentication URLs into the checklist.
- [ ] Record acceptance time and owner: __________. Keep monitoring through the agreed observation period: __________.

## Activate MusicTeacher last

Complete this section only after the initial portal cutover is accepted. Production destination is **`https://profile.musicteacher.com/`**, not the marketing site's bare domain.

### Prepare Kinsta and the plugin

- [ ] Take a Kinsta backup and preserve local WordPress administrator access for recovery.
- [ ] Install the approved WP Cognito SSO plugin package, including production dependencies. Configure it as on staging, using live values rather than copying staging credentials.
- [ ] Set the plugin Cognito domain to `auth.rslgroup.io` without scheme or path. Use live pool `eu-west-2_pg2smtPib`, region `eu-west-2`, shared client ID `7gmkvrve0vnn7q28dl1jeq790d`, and its existing client secret from secure storage.
- [ ] Set callback path `/cognito-login`, logout destination path `/logout`, and scopes `openid email profile`. These resolve to the exact URLs in the environment block below.
- [ ] Preserve the tested staging role mappings, provisioning and redirect settings. Keep JWT verification enabled. Configure the plugin's role blacklist to include `learner,parent`; hiding portal cards alone does not prevent direct sign-in.
- [ ] Set the plugin's Cognito user migration shared secret to match production portal `MIGRATION_WORDPRESS_SHARED_SECRET`. That value was retained when migration was disabled; confirm it matches rather than replacing it with a placeholder. The existing `MIGRATION_WORDPRESS_API_TOKEN` is a legacy fallback and need not be changed when an explicit shared secret is configured.
- [ ] Keep optional WordPress-to-Cognito administrative sync disabled unless separately approved; it is distinct from sign-in and legacy migration.
- [ ] Exclude `/login/`, `/wp-login.php`, `/cognito-login`, `/logout` and the migration REST endpoints from interfering caches or authentication challenges. Confirm Kinsta/security rules allow server-to-server POST requests and the migration HMAC headers.
- [ ] Confirm both migration routes are provided by the plugin and accessible to the portal. An unauthenticated request should not return identity data; test with the trusted portal and a designated account.

### Set the production portal environment

Edit `/home/1454445.cloudwaysapps.com/ktbkxftspg/private_html/app/.env` on the production application. Back it up privately first. Replace the existing entries with the values below; do not append duplicate keys.

```dotenv
SSO_MUSICTEACHER_URL=https://profile.musicteacher.com/
SSO_MUSICTEACHER_CALLBACK_URLS=https://profile.musicteacher.com/cognito-login
SSO_MUSICTEACHER_LOGOUT_URLS=https://profile.musicteacher.com/logout

MIGRATION_WORDPRESS_LOOKUP_ENDPOINT=https://profile.musicteacher.com/wp-json/cognito-migrate/v1/lookup
MIGRATION_WORDPRESS_ENDPOINT=https://profile.musicteacher.com/wp-json/cognito-migrate/v1/verify
MIGRATION_WORDPRESS_ENABLED=true
```

Keep `LEGACY_MIGRATION_ENABLED=true` and `LEGACY_PASSWORD_RECOVERY_ENABLED=true` for the intended migration/recovery behaviour; retain the configured shared secret. Do not alter Cloud's API URL or credentials. The portal already uses the live pool, client and AWS federation domain.

Rebuild configuration from the Laravel directory:

```bash
cd /home/1454445.cloudwaysapps.com/ktbkxftspg/private_html/app
php artisan config:cache
```

Do not run `key:generate`, copy a staging `.env`, or clear all user sessions as part of activation. GitHub Actions preserves the server `.env`; adding these values to `.env.example` alone would not configure production.

- [ ] Read back effective `sso.consumers.musicteacher` and the WordPress migration enabled flag and endpoint values without printing credentials.
- [ ] Enable the plugin's sign-in behaviour and the intended logout behaviour; verify its login/callback/logout routes.
- [ ] Confirm the card is active for permitted roles and hidden for learner/parent roles.
- [ ] Test an existing Cognito account signing in from MusicTeacher and through the portal card.
- [ ] Test a designated legacy MusicTeacher-only account through migration and verify its WordPress access and Cognito identity/role.
- [ ] Test password recovery for a designated not-yet-migrated account.
- [ ] Test learner/parent denial through direct MusicTeacher SSO, and test logout plus a subsequent login.
- [ ] Check logs and mark MusicTeacher accepted. Activation time and owner: __________.

## Rollback

Rollback decision trigger: sustained sign-in/token-exchange failures, broken access to a required application, or failed acceptance checks that cannot be resolved inside the agreed change window. Decision owner records the reason and time: __________.

- [ ] Pause further changes and capture non-sensitive error details.
- [ ] Restore the previous Cloud release/settings where required. DNS alone cannot reverse an application compatibility change.
- [ ] Replace the portal A record with CNAME `auth.rslgroup.io` → `d3v5fmhv5tow4v.cloudfront.net`. Coordinate any independent AAAA changes. Allow for mixed DNS answers during rollback as well.
- [ ] Verify managed Cognito sign-in from an external network and linked-app server token exchanges.
- [ ] Do not delete newly created/migrated Cognito users or restore old identity data just to roll back the front end. Keep the same pool and credentials.
- [ ] If only MusicTeacher activation fails, roll back that activation independently: disable its plugin sign-in behaviour as appropriate, set the six deferred values below, rebuild portal configuration, and retain working Cloud/Backstage/Guestlist/Greenroom service.

MusicTeacher deferred configuration, verified on 25 November:

```dotenv
MIGRATION_WORDPRESS_ENABLED=false
MIGRATION_WORDPRESS_LOOKUP_ENDPOINT=
MIGRATION_WORDPRESS_ENDPOINT=
SSO_MUSICTEACHER_URL=
SSO_MUSICTEACHER_CALLBACK_URLS=
SSO_MUSICTEACHER_LOGOUT_URLS=
```

Keep secrets in the server environment, not this document or Git. Leave staging available for investigation and further testing.
