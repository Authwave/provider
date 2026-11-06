Hosted or self-hosted Authwave provider.
========================================

The Authwave provider handles user authentication and provides the `/admin` screens for user account administration. Users can log in with a password or a security code sent by email. An official provider is hosted by Authwave, managed at www.authwave.com, and the provider software can also be self-hosted.

A subdomain of your application should be used to access the provider, whether hosted or self-hosted. For example, if your application is hosted at www.example.com, the provider can be accessed at a subdomain such as account.example.com.

*****

User authentication flow
------------------------

1) The user agent arrives at your application. For example, www.example.com.
2) Your application uses the Authwave client library to check whether the user is logged in. If they are not authenticated, your application can display a "login" button.
3) When the login button is clicked, the client library redirects the user agent to the configured provider URI, for example, account.example.com. Encrypted authentication details are passed to the provider via a cipher.
4) The user agent is now on the provider server rather than your application.
5) Authentication is completely handled by the provider. The user enters their email address, then logs in with their password or requests a security code by email.
6) After successful authentication, the provider returns encrypted user details to your application.
7) The client library stores the authenticated user in your application's session, giving your application access to their ID and email address.

Login return destinations
-------------------------

The client can send a requested return destination in the hexadecimal `u` query parameter when starting login. The provider keeps a validated destination in the login session and returns the encrypted authentication response to that path, query string and fragment.

Root-relative paths, query-only or fragment-only references, and absolute URLs on the deployment's configured client origin are supported. An absolute URL must match the configured scheme, host and effective port. External origins, credentials, scheme-relative URLs, malformed encodings and unsafe characters are ignored; login then uses the deployment's configured client return URI. Destinations without `u` also use that default, with the encrypted `returnQuery` value supported for query-only integrations.

User account administration
---------------------------

The `/admin` screens provide user account administration and are currently being built. You can access them by visiting the `/admin` path of the base provider URI, for example, account.example.com/admin.

Visiting `/admin` uses the normal login screens and returns to `/admin` after authentication. Non-administrators receive HTTP 403. All pages in `page/admin` require an authenticated administrator.

Administrators logging in from a client application see “Continue to ApplicationName” and “User Administration” on the success page. Other users continue to the application automatically. Administrator sessions remain available on the provider so the dashboard can be opened after login.
  
Application administrators are granted in the application admin screeen, but a super admin can be added to the provider config file (useful for adding the first admin account to self-hosted providers).

Creating an application at `/admin/applications/` saves the application and the
signed-in administrator's assignment in a single database transaction. The saved
applications populate the list and application switcher, survive logout, and
advance account setup. Names support up to 128 characters. Apply numbered schema
changes with `gt migrate` before running the provider.
The applications table shows Name, ID, Client and Login host. Its host columns use
only the selected production deployment; both show `-` when production is unset.
Tables containing `a[data-table-link]` are enhanced in the root `_common` page
logic by `UI\TableLink`. The existing link's `href` supplies its row's destination.
Additional cell links use `tabindex="-1"`, preserving the original keyboard target.
Other links and form controls remain untouched. This works without JavaScript.

Creation redirects directly to `/admin/applications/APP_ID/`. This screen saves
the application name and its production deployment, restricted to deployments
belonging to that application. It also creates and edits deployments. Deployment
names support 32 characters; Host is a hostname with an optional port, Client URL
is an HTTP/HTTPS origin, and Client login path is a root-relative path.

`Model\ApplicationRepository` owns application/deployment reads, creation,
updates, deletion, administrator assignments and application counts. Mutations
and audit writes share one database transaction. Component handlers orchestrate
HTTP input, flash messages and redirects; validated values and page data remain
plain typed classes. There are no separate application creation/store/registry
services.

Database entities live in `Authwave\Model`: `Application`, `ApplicationDeployment`,
`ApplicationTheme`, `User`, `UserAuthCode`, `UserAdmin`, `Email` and `Audit` map to
tables with those exact case-sensitive names. Their column properties preserve database
nullability and stored values. Foreign-key IDs are explicit alongside the
existing typed relationships. The application list uses `ManagedApplication` for
its derived production-host columns. Application settings and deployment forms bind repository entities
directly; field names match database columns and use `data-bind:value="@"`.
These are plain readonly classes, with no ORM integration yet.

The migration history uses these table names from the start. Existing databases
with lowercase table names need an in-place table rename and migration baseline
update; replaying `gt migrate` alone does not rename already-migrated tables.

Deployment IDs and API keys are generated once and remain read-only when editing.
All changes commit with their audit records; API keys are excluded from audit JSON.
The deployment list uses one repeating HTML template for saved deployments and
the creation form, with CSS hiding the creation form's ID and API-key fields.
Settings precede deployments in the HTML and sit alongside them when space permits.
Deployment disclosures share a native `name`, following Flair's exclusive group
pattern so opening one closes the other without JavaScript.
Existing rows also have a POST-only Delete deployment button. Deletion and its
audit snapshot commit together. Deleting production clears its selection and
records that application change in the same transaction; no replacement is
selected automatically. Deployments with users or email history cannot be deleted.
Migration 018 enforces this restriction with foreign keys, preventing cascading
loss of authentication data even if a dependent record is inserted concurrently.

Delete buttons share a native HTML dialog showing `Confirm deleting NAME` with
Cancel and Confirm delete actions. Cancel and Escape preserve the current form.
Without scripting or dialog support, the initial POST opens an HTTP confirmation
form; no deletion occurs until explicitly confirmed for the same target ID.
New delete controls use `data-confirm-delete` and `data-delete-name` and must keep
that server-side confirmation check as well as their ownership/CSRF checks.

The remaining administration screens retain their layouts with unfinished
controls disabled. There is no demo state, simulated reporting, billing data or
CSV export. Application selection uses the saved catalogue directly; application
and deployment forms remain live. Admin browser checks run against the real
application through Behat rather than a simulated HTTP backend.

Audit logging
-------------

Audit rows identify the actor and timestamp in their own columns. Detail JSON
contains only event-specific information: requests store `method` and `path`;
updates store target IDs and changed `from`/`to` fields; creation stores `to` and
deletion stores `from` once. Unchanged fields, duplicate diffs, empty state
wrappers and credentials are omitted. Events needing no extra context use null.
Existing historical audit rows are not rewritten.

Application creation and its administrator assignment commit in the same
transaction as `APPLICATION_CREATED`. An audit failure rolls everything back.
Rejected names, application selection, authentication state changes, password/code
outcomes, user creation, email scheduling and logout are also logged. Every page
request records its method/path. Request logs omit query parameters
and POST bodies; detailed mutation events supply the relevant safe data instead.

Browser-only interactions, including the current local-storage theme toggle and
opening disclosures, do not send HTTP requests and are not covered by server
request auditing.


Deployment domain verification
------------------------------

Saved deployments classify `localhost` and IPv4 loopback addresses (`127.*`) as
local, including hosts with ports. They need no DNS verification. Local and
production deployments are labelled in their disclosure summaries.

Public domains must delegate to both `ns1.authwave.com` and `ns2.authwave.com`.
The provider then checks `authwave-confirmation.<hostname>` for an exact TXT
match to `authwave.domain_confirmation` (default `dev-2611`). Configure this value
to match the marker served by the authoritative DNS servers. Missing records or
temporary DNS failures leave the deployment unverified. Hostnames ending in
`.test` bypass DNS checks and verify immediately through the same
persisted, audited verification action.

The Verify popover explains the NS records. "I've set these records" starts a
normal GET verification request. Flux enhances the status and account-progress
regions with `data-flux="live" data-flux-rate="10"`; it checks every ten seconds
while the page remains open without replacing unsaved form fields. Without
JavaScript, use Check again. Successful verification is persisted and audited in
one transaction and completes the third account-setup step. Local deployments
do not complete the domain-verification step. Changing the hostname clears verification, while changing only
its port retains it. Migration 019 adds the `domainVerified` column.


Production client setup
-----------------------

The selected production deployment completes "Connect your application" when
its verified, non-local Authwave host receives a decrypted client login request.
A subsequent successful client sign-in completes "Your first user signs in".
Direct provider visits, admin-area sign-ins and development-login requests
do not complete these milestones. Migration 020 stores `clientConnected` and
`userLoggedIn` on `ApplicationDeployment`; first transitions and their actors are
audited in the same transaction. Changing the provider host, client URL or login
path clears both flags. The checklist follows the currently selected production
deployment, and earlier logins are not inferred from historical audit entries.


Sudo fields
-----------

Mark a protected value with `data-sudo`. Add `data-sudo="key"` for API keys:
this retains the prefix through the second underscore and four secret characters,
then obscures the remainder. Add `data-copy` to offer a clipboard button when
JavaScript is available. Read-only inputs still need their normal `name`.

The admin common logic masks values on the server and adds a native View button.
Password confirmation at `/login/sudo/` enables sudo for 60 seconds; configure
`authwave.sudo_duration` in seconds to change that period. A key is disclosed only
in the response requested by View or Copy, rather than every response during the
sudo period. Other sudo fields are available while sudo is active. JavaScript
re-obscures authorised responses, provides view/copy controls, and clears visible
values when sudo expires or the page is left. After password confirmation, the
requested View resumes automatically using a one-use `sudo-view` query parameter.
Copy still requires a fresh click. Without JavaScript the authorised View
response displays the key until the next navigation.

The SUDO button beside Log out immediately revokes authorisation. Logging out
also discards it. Each authorisation request, rejection, success, field access,
expiry and explicit revocation is audited without credentials. Protected admin
responses use `Cache-Control: no-store`.


Testing
-------

Use PHP 8.4.1 or newer, Composer, and Chrome/Chromium. Install dependencies and run
all checks with:

```bash
composer install
composer test
```

The Composer scripts run Behat, PHPUnit, PHPCS, PHPMD, and PHPStan at level 6.
Each check can also run separately: `composer behat`, `composer phpunit`,
`composer phpcs`, `composer phpmd`, and `composer phpstan`.

* `test/phpunit` contains class-based unit tests, running on PHPUnit 13. Each
  `XTest` tests class `X`, using `testMethod_context` names and a single `$sut`.
  Run these with `vendor/bin/phpunit --testsuite unit`.
* `test/integration` retains SQL, filesystem, and PHP-rendered page checks.
  Run these with `vendor/bin/phpunit --testsuite integration`. Both PHPUnit
  suites run through `composer phpunit` and `composer test`; no configured
  database or mail server is required. Warnings, risky tests, and PHPUnit
  notices/deprecations fail the suites.
* `test/behat` runs the real provider in headless Chromium, including password
  login and first-application onboarding. Page JavaScript is disabled by default
  to verify the HTTP-first flow. After building assets, use
  `BEHAT_JAVASCRIPT=1 composer behat` to check Flux enhancement too.
  The runner uses a five-second sudo period to exercise expiry quickly,
  starts its own server/browser on available ports and cleans up
  after success or failure. Undefined or pending steps fail the suite.
* Stateful scenarios use a disposable SQLite database and isolated session
  directory. No development database credentials are used or mutated. A database
  snapshot is taken before fixtures; named states live in
  `test/behat/database/<state_name>`. The snapshot is restored between scenarios,
  after fixture failures, and during runner cleanup. This uses the real application
  classes and query files; the SQLite schema is a dev-only fixture, not a replacement
  for the MySQL production migrations.
  Set `CHROME_BIN` if Chrome/Chromium is not on your PATH; extra Behat arguments
  are forwarded, for example `composer behat -- --name="first visit"`.

New administrator onboarding is based on the signed-in user's `user_admin`
application assignments. The provider's
login application is not implicitly treated as one of the user's applications.
The first setup step (creating the account) is complete; creating the first
application is the current second step. The remaining help content is intentionally
empty until those features are implemented.

`phpcs.xml` and `phpmd.xml` are copied from api.horse. Existing classes have been
formatted and annotated for those checks, with local PHPMD exceptions for
stateless framework/utility calls, established public `id` fields, and the legacy
workspace adapter. PHPStan uses no baseline or ignored errors.

Deployment form constraints are declared in the HTML and checked server-side
with `phpgt/domvalidation`. Composer currently uses the local checkout at
`../../PhpGt/DomValidation` while its HTML `required` and Unicode-length parity
fixes await release. That checkout must be available when installing dependencies.

The supplementary browser regression scripts remain available through
`npm run test:ui`. They require Node.js and installed npm dependencies, including
this project's local Flair package, and exercise login and administration pages
with isolated fixtures. They are separate from the basic Behat smoke coverage.

Manual development login
------------------------

HTTP development providers need `[session] cookie_secure=false` in
`config.dev.ini`. WebEngine 5.1 or later is required for this setting to reach
the session service. Otherwise a Secure cookie is issued over HTTP and the
login session is lost on the next request. Keep Secure cookies enabled for
HTTPS deployments.


For manual testing without a client application, open
`http://localhost:9105/?dev-login=<deployment-id>` on your provider (adjust the
host and port). Use an existing application deployment ID from your database.
This starts a fresh login session with a generated IV, clears previous login
state, and opens the normal email/password flow. After authentication, the
success page stays open instead of redirecting to the client. Revisit the URL
to start another login.

This helper requires `authwave.dev_login=true` in `config.dev.ini`.
It is always disabled when `app.production=true`. It still requires the normal
deployment database and user authentication; it does not bypass credentials.
