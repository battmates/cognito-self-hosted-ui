# Cloudways staging deployment

Pushes and merges into `staging` trigger [Deploy staging](../.github/workflows/staging-deploy.yml). It uses the GitHub `staging` environment and a separate deployment concurrency group. `main` continues to deploy live.

## Configure GitHub and Cloudways

Create **Settings → Environments → staging** and restrict deployment branches to `staging`. Add these environment secrets, pointing to the staging Cloudways application:

| Secret | Value |
| --- | --- |
| `CLOUDWAYS_APP_PATH_STAGING` | Absolute path to staging's `public_html`, without a trailing slash |
| `CLOUDWAYS_HOST_STAGING` | Staging server IP or SSH hostname |
| `CLOUDWAYS_PORT_STAGING` | Optional SSH port, default `22` |
| `CLOUDWAYS_USER_STAGING` | SSH user with access to the staging application |
| `CLOUDWAYS_KEY_STAGING` | Full private deployment key authorized for that user |

Use a separate Cloudways application and database for staging. Never point the staging path at the live application: deployment synchronizes code with `--delete` and runs migrations. Give staging its own database credentials, storage, and Redis/cache namespace if Redis is shared. A shared Cognito pool still contains shared accounts; changing `APP_ENV` does not isolate Cognito users or administrative writes.

Prepare the same [private/public server layout and prerequisites as live](cloudways-live-cicd.md#prepare-cloudways): `private_html/app/.env`, writable storage/cache folders, public `index.php` and `.htaccess` that boot the private app, SSH access, PHP, Composer and rsync. A new application must have these before the first deployment. Configure a separate scheduler and, if used, queue worker for staging.

The workflow builds assets, runs Laravel tests, checks the server layout, uploads assets and code, installs production dependencies when needed, migrates the database, clears Laravel caches, and publishes both manifests. It preserves the server's `.env`, `APP_KEY`, storage and database files. The same [deployment limitations as live](cloudways-live-cicd.md#deployment-behavior) apply, including in-place uploads and no automatic rollback or health check.

## When the portal URL/domain changes

The following example uses `https://auth-staging.example.com`; replace it with the actual staging hostname.

1. Point the hostname's DNS at the staging server, add it to the Cloudways application's domain configuration, and install a valid HTTPS certificate. Confirm the document root is still `public_html` and HTTPS redirects work.
2. Update **staging's server-side** `private_html/app/.env`:

   ```dotenv
   APP_ENV=staging
   APP_DEBUG=false
   APP_URL=https://auth-staging.example.com
   COGNITO_REDIRECT_URI="${APP_URL}/callback"
   COGNITO_LOGOUT_URI="${APP_URL}/logout"
   SESSION_DOMAIN=null
   SESSION_SECURE_COOKIE=true
   SESSION_COOKIE=authentication_portal_staging_session
   SESSION_DRIVER=database
   ```

   Explicit `COGNITO_REDIRECT_URI` / `COGNITO_LOGOUT_URI` values override the defaults derived from `APP_URL`; remove any old hard-coded hostname. Keep cookies host-only with `SESSION_DOMAIN=null`. Users will need to sign in on the new hostname. Retain the application's existing `APP_KEY` when changing a hostname. For a brand-new staging installation, generate and save its own key once.
3. In the correct **Cognito app client**, add `https://auth-staging.example.com/callback` to allowed callback URLs. If using Cognito logout redirects, add `https://auth-staging.example.com/logout` to allowed sign-out URLs. Preserve any URLs still needed by live or other applications. The scheme, hostname and path must match exactly; callbacks use HTTPS outside local development.
4. Keep the portal's `COGNITO_DOMAIN` set to the AWS Cognito hosted/custom domain used for federation. It is different from the portal hostname. If the **Cognito-hosted domain itself** also changes, update `COGNITO_DOMAIN` and the external identity provider's authorized redirect URI to `https://<new-cognito-domain>/oauth2/idpresponse` (for example, in Google's OAuth client). Changing only the portal hostname does not change that provider-to-Cognito redirect.
5. For staging consumers that should use this portal, set Guestlist's `AWS_COGNITO_DOMAIN` and Backstage's ACF `cognito_domain` to `auth-staging.example.com` **without a scheme or path**. Keep the audited client ID/secret and consumer callbacks. The current broker uses one Cognito client for enabled consumers; switching to a different client requires coordinating those settings across the portal and consumers.
6. The portal's `SSO_*_URL`, `SSO_*_CALLBACK_URLS` and `SSO_*_LOGOUT_URLS` refer to the **consumer applications**. Change them only when those applications' URLs also change, using exact callback/logout allowlists. A portal-only hostname change does not change the consumers' callback paths.
7. If SES event reporting is configured, add/confirm the intended SNS HTTPS subscription at `https://auth-staging.example.com/webhooks/ses-events` and check `SES_REPORTING_SNS_TOPIC_ARN`. Review any other external links or webhook registrations that explicitly contain the previous portal hostname.
8. From `private_html/app`, run `php artisan optimize:clear` after changing `.env`. The deployment also clears caches. Restart any long-running queue workers to load the updated configuration. Verify staging database/cache isolation before running the first deployment's migrations.

## Deploy and verify

Once the application and secrets are configured, merge this workflow into the `staging` branch or push a subsequent change there. Inspect **Actions → Deploy staging** and confirm every build, test, upload and migration step succeeds.

Open the staging HTTPS URL and `/up`, check styling/assets, and test native sign-in, Google federation, logout, password recovery, and a complete login/return journey from each staging consumer. Confirm callback URLs remain on staging, session cookies are secure and host-only, and private files such as `.env` are inaccessible over HTTP. Use designated test accounts for changes to a shared Cognito pool.

These repository changes do not create GitHub secrets, configure DNS/Cloudways/Cognito, push code, or run a remote deployment.

## References

- [Cognito app-client callback and logout settings](https://docs.aws.amazon.com/cognito-user-identity-pools/latest/APIReference/API_UserPoolClientType.html)
- [Social provider redirects through Cognito](https://docs.aws.amazon.com/cognito/latest/developerguide/cognito-user-pools-social-idp.html)
- [GitHub deployment environments](https://docs.github.com/en/actions/concepts/workflows-and-actions/deployment-environments)
