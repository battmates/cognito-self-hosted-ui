# Verification: first implementation pass

7 September 2026. No staging application settings, Cognito pool/client configuration, or administrative user records were changed. The local database received the authorization-code table; local environment values now use the staging dashboard URLs and an existing registered social callback. At the initial verification, administrative writes and portal migrations were disabled; the later administration follow-ups below enable management writes. Portal migrations remain disabled.

## Automated checks

- `php artisan test`: 61 tests, 235 assertions passed. External HTTP calls are faked and stray HTTP requests are blocked in the test base class.
- Coverage includes encrypted/single-use authorization codes, exact callbacks, client secrets/HTTP Basic, S256 PKCE, state including whitespace, code expiry/replay, cross-client rejection, returning/expired sessions, separate browser journeys, full registration/confirmation return flow, Backstage recovery prompts, CSRF boundaries, Cognito issuer/audience/token-use/expiry, MFA and temporary passwords, social state/nonce/replay, role revocation, read-only administration and migration conflicts/outages.
- Management SDK mutations are verified with mocks only; no real password, enable/disable, confirmation or provisioning action was used.
- `npm run build`: production assets built successfully.
- Laravel views compile; PHP formatting and diff whitespace checks pass.
- Compatible dependency updates removed the advisories reported in the existing Composer lockfile. Composer resolves against PHP 8.2; the local test runtime is PHP 8.4. A PHP 8.2 runtime has not been executed here.

## Live checks

- Read-only Cognito DescribeUserPool and DescribeUserPoolClient confirmed the staging schema, direct-auth flows, registered URLs and enabled Google provider.
- The supplied administrator signed in through the local browser using Cognito password authentication. Genuine tokens passed the portal's signature and claim verification.
- The portal displayed Guestlist/Backstage dashboard links and the administrator user-management link.
- Live administrator search returned matching users, statuses and full attributes. Mutation controls were disabled.
- Local sign-out and subsequent sign-in succeeded.
- At a 390-pixel viewport, sign-in/register tabs switch correctly; the Guestlist referral headline and Google icon render. Desktop dashboard layout was also inspected. Temporary viewport overrides were reset. The footer logo was verified in both themes: every filled shape and divider stroke uses light grey (#d7dee7) in dark mode; original dark colours remain in light mode.

## Still unverified or blocked

- End-to-end login from the actual staging applications after changing their Cognito domain settings. This needs a server-accessible HTTPS deployment and explicit switch approval; local consumer contract tests are not a live staging cutover.
- Backstage's database-stored ACF client ID/secret; only its repository contract was available.
- Live Google federation completion, live registration email/confirmation and recovery email delivery, real MFA challenges, and administrative mutations. These remain staging acceptance tests; their application flows are covered with fakes.
- WordPress/RSL Cloud migration endpoints, live migrated accounts, collision decisions and partial-provisioning recovery. The adapters are a proposed integration contract and remain disabled.
- Cloud, MusicTeacher.com and Spotlight client/callback/token contracts; a different Cognito client requires further work.
- Final policy content, production IAM review (including who can write admin-role attributes), deployment/session/cache operations, and additional identity providers.

## Follow-up: live filtering and enabled administrator writes

At the user's request, `/admin/users` now filters after a 350ms pause in typing and immediately when the search field changes. Browser checks confirmed live email results without submission, enabled management actions and password fields, and clearing a previous selection when changing filters. The local setting and example/default now enable `COGNITO_MANAGEMENT_WRITES_ENABLED`; migrations remain disabled.

The updated suite passes 66 tests with 268 assertions, including JSON search/pagination, clearing a filter, expired sessions, directory failures, denied non-admin requests and an authorised management POST through a mocked AWS SDK client. Build, formatting and whitespace checks pass. Existing staging user records were not modified for this verification.

## Follow-up: profiles, deletion and Load more

The suite passes 75 tests with 332 assertions. Coverage now includes separate profile rendering without ListUsers, escaped attributes, preserved back-link and action filters, missing profiles, next-batch cursors and empty batches, deletion confirmation and authorization, self-deletion through an alias, stale subject rejection, successful mocked AdminDeleteUser, audit logging and SDK failure handling. The frontend build, formatting and whitespace checks pass.

Browser verification confirmed:
- Load more increased the visible list from 30 to 60 accounts without navigating away or replacing the first batch.
- Typing a new filter replaced the accumulated list with 18 matches and removed Load more at the end of the results.
- Selecting a user showed only a full-width profile and account actions; Back to users retained the search filters.
- The signed-in administrator’s Delete user option was disabled. Another account offered Delete user and displayed the permanent-deletion warning, exact-username input and delete button when selected. The confirmation was not completed or submitted.

No live accounts were deleted or otherwise changed during these checks.

## Follow-up: list columns, row actions and partial email search

All 83 tests pass with 371 assertions; the build, formatting and whitespace checks pass. Added coverage verifies case-insensitive email substrings across batches, middle-of-address fragments, bounded scanning and continuation, thirty-result limits without discarded users, unchanged exact identity checks and username-prefix filters, directory failures, person-name headings, and confirmed row actions returning only to the filtered list.

Browser checks confirmed the five requested columns, readable confirmation labels including Confirmed and Force change password, three-dot menus and a correctly targeted Disable account confirmation dialog. The dialog was cancelled without submitting. Searching for an email domain returned 25 matches, and Load more appended further matches to reach 47. A real profile displayed Matt Bates as the heading and its Cognito username underneath. No staging account mutations or pool configuration changes were performed.

## Follow-up: DataTables integration

DataTables 3.0.3 is installed locally through npm, with JavaScript and CSS emitted by a dedicated Vite entry used only by the admin directory. The user table uses the DataTables row API when replacing or appending results. The separate pool search remains active, pagination remains disabled, and column sorting explicitly applies to loaded accounts.

The build and all 83 tests (371 assertions) pass. JavaScript syntax and whitespace checks pass. The install surfaced existing npm advisories; compatible dependency fixes were applied, and npm audit now reports zero vulnerabilities.

Browser checks verified sortable column controls and changed row order, Load more increasing a sorted table from 30 to 60 rows while preserving the sort, partial-domain filtering to 25 rows, row menus opening a correctly targeted action dialog, and an empty search followed by fresh results. The action dialog was cancelled; no staging account mutations were performed.
