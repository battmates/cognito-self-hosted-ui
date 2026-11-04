# Cloudways live deployment

Pushes and merges into `main` trigger `.github/workflows/live-deploy.yml`, using the GitHub `live` environment. Deployment uses SSH keys and rsync to Cloudways and preserves the existing private/public layout. The destination does not need a Git checkout.

## Server layout

```text
<application>/
├── private_html/app/        Laravel application, vendor, .env, storage
│   └── public/build/       Vite assets and manifest read by Laravel
└── public_html/             Existing web document root
    ├── index.php           Existing host-specific entry point, preserved
    ├── .htaccess           Existing web rewrite rules, preserved
    └── build/              Vite assets and manifest served to browsers
```

Keep the Cloudways web document root at `public_html`. The existing `public_html/index.php` must load the maintenance file, Composer autoloader and Laravel bootstrap from `../private_html/app`, as in the existing server configuration. The repository's standard `public/index.php` is only synced into the private application's `public` folder; it never overwrites the host-specific public entry point.

## GitHub environment and secrets

Create or use **Settings → Environments → live** and allow only `main` if deployment branch restrictions are enabled. Add this application's secrets:

| Secret | Value |
| --- | --- |
| `CLOUDWAYS_APP_PATH_LIVE` | Absolute path to this application's existing `public_html`, without a trailing slash |
| `CLOUDWAYS_HOST_LIVE` | Cloudways server IP or SSH hostname |
| `CLOUDWAYS_PORT_LIVE` | Optional SSH port, default `22` |
| `CLOUDWAYS_USER_LIVE` | Application SSH username with access to both directories |
| `CLOUDWAYS_KEY_LIVE` | Full unencrypted private deployment key authorized for that user |

The private application path is derived from the public path: `<public_html>/../private_html/app`. No extra path secret is needed. Do not set `CLOUDWAYS_APP_PATH_LIVE` to the private directory or `public_html/public`. Directory names must contain only letters, digits, underscores and hyphens. Keep the private key only in GitHub secrets and authorize its public key on Cloudways. No SFTP password or Cloudways API key is used. Local `.cloudways`, `.env` and `.vscode` files are excluded from deployment.

## Prepare Cloudways

1. Enable SSH for the application user, authorize the public deployment key and allow GitHub-hosted runners to reach SSH under your server access rules.
2. Connect as that user, enter the intended application's `public_html` and run `pwd`. Use that exact absolute path for `CLOUDWAYS_APP_PATH_LIVE`. Confirm its sibling `private_html/app` is the intended Laravel application. Both directories, the public `index.php` and `.htaccess`, and `private_html/app/.env` must already exist; preflight checks fail otherwise.
3. Preserve the existing private `.env`, `APP_KEY`, storage and database. Configure the live URL, database, Cognito, AWS credentials, HTTPS/proxy settings, secure session cookies and persistent sessions/cache as required by `.env.example`; use `APP_DEBUG=false`.
4. Ensure PHP 8.2+ with the extensions required by `composer.lock`, Composer 2 and rsync are available in non-interactive SSH sessions. The user must be able to write to both deployment folders, including private `storage` and `bootstrap/cache`.
5. Keep the document root at `public_html` and verify its existing entry point boots `private_html/app`. If uploaded files are served publicly, preserve or configure `public_html/storage` to link to `../private_html/app/storage/app/public`. Running Laravel's default `storage:link` alone creates a link in the private `public` folder, which is not the website's document root.
6. Run Laravel's scheduler every minute from `private_html/app` for expired authorization-code cleanup. If using SQLite, create/configure the database separately; deployment preserves SQLite files.
7. Take a backup before the first deployment. Private application sync uses `--delete` and removes obsolete application files unless excluded. Verify paths before enabling it.

## Deployment behavior

1. Check out `main`; compare Composer files with the push's previous commit. An unavailable previous commit counts as changed dependencies.
2. Run `npm ci` and `npm run build` using `.nvmrc` (Node 20.19.0). The build is required because `public/build` is ignored by Git.
3. Install PHP dependencies on the GitHub runner and run Laravel tests with a test-only key and in-memory SQLite. Failures stop deployment before upload; the test key never replaces the server key.
4. Validate SSH configuration, existing private/public directories, public entry-point files, private `.env` and required server commands. Create build asset directories in both roots.
5. Upload hashed frontend assets to **both** `private_html/app/public/build/assets` and `public_html/build/assets` before publishing any new manifest. Existing hashed assets are retained so the old manifest remains usable while uploading.
6. Sync Laravel code to `private_html/app` with `--delete`. Preserve `.env` and `.env.*`, `storage`, `vendor`, `node_modules`, `bootstrap/cache`, SQLite files, `public/storage` and `public/build`. Exclude Git/GitHub files, local credential/editor files and the Vite development marker.
7. Sync public resources from the repository's `public/` into `public_html`, excluding `index.php`, `.htaccess`, `build`, `storage` and `hot`. This step does **not** delete existing public files, preserving host-specific resources and uploads. Removed public resources and old hashed assets require separate cleanup.
8. From `private_html/app`, install production Composer dependencies when Composer files changed or `vendor/autoload.php` is missing. Run `php artisan migrate --force`, `php artisan view:clear` and `php artisan optimize:clear`.
9. Publish `manifest.json` to both build directories only after assets, code and server commands succeed.

No database resets or seeders run. The concurrency group prevents overlapping runs, and active deployments are not cancelled by new pushes. GitHub can replace an older pending run with a newer pending run.

This is an in-place deployment, without atomic releases, automatic rollback or a health check. Requests can encounter mixed code versions during deployment. A failure after uploads start can leave a partial deployment; inspect the Actions log and recover before assuming the application is healthy. For a failed Composer installation, rerun that failed workflow to retain its Composer-change result; a later push without Composer changes only reinstalls when the autoloader is missing. Clear stale private Laravel config manually if it prevents Composer hooks or migrations from booting.

The rsync and SSH actions retain their default host verification behavior; this workflow does not configure pinned host fingerprints.

## Verification

1. Configure the existing split server layout and GitHub `live` environment secrets before pushing to `main`.
2. Push or merge to `main`; inspect **Actions → Deploy live**. Confirm builds, tests, preflight checks, both asset uploads, private/public sync, migrations and both manifest uploads succeed.
3. Check Laravel code and dependencies are in `private_html/app`, with its original `.env`, application key, database and storage intact. Confirm public `index.php` and `.htaccess` remain unchanged.
4. Confirm the two build manifests match and their referenced assets exist in both roots. Open the live HTTPS URL and check login, database access, styling, JavaScript and uploaded files where applicable.
5. Verify private files such as `.env` and `composer.json` are not web-accessible. Confirm the document root remains `public_html`.
6. Make a small visible change through `main` and verify automatic deployment. Other branches must not trigger it.

Repository changes do not create GitHub secrets, change the Cloudways account, push code, deploy, or switch downstream applications. Social federation still needs an approved Cognito callback for the live hostname; keep `COGNITO_DOMAIN` pointing to AWS and verify downstream callbacks before switching traffic.

## References

- [Rsync Deployments action](https://github.com/Burnett01/rsync-deployments)
- [SSH action](https://github.com/appleboy/ssh-action)
