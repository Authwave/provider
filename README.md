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

Login presentation
------------------

Login headings, browser titles, logo descriptions and return links use the
application's name, never the deployment's title. The selected production
deployment has no environment badge. Other deployments show their title in a
badge above `#main-content`; when production is unset, every deployment shows
its badge. The shared login header is `page/login/_header.html`, and
`View\LoginBranding` supplies the identity and badge bindings.

Login return destinations
-------------------------

The client can send a requested return destination in the hexadecimal `u` query parameter when starting login. The provider keeps a validated destination in the login session and returns the encrypted authentication response to that path, query string and fragment.

Root-relative paths, query-only or fragment-only references, and absolute URLs on the deployment's configured client origin are supported. An absolute URL must match the configured scheme, host and effective port. External origins, credentials, scheme-relative URLs, malformed encodings and unsafe characters are ignored; login then uses the deployment's configured client return URI. Destinations without `u` also use that default, with the encrypted `returnQuery` value supported for query-only integrations.

Client logout
-------------

The client clears its own authentication state and sends an encrypted logout
request to the provider. The provider audits `LOGOUT_REQUESTED`, clears its
session and returns the browser to the explicit `redirectTo` destination or the
deployment's default client return URI. It does not append `do=logout`: the
return is navigation, not another invocation of the client's logout handler.

The audit uses the authenticated user when the provider still has enough session
state to resolve them, otherwise an anonymous actor. Successful non-admin login
clears the provider session, so logout must not assume that identity remains
available. A client-supplied identity must be authenticated and checked against
its deployment before being accepted as an audit actor.

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
is an HTTP/HTTPS origin, and Client login path is a root-relative path. Host
fields support ordinary ASCII hostnames, localhost, IPv4 and optional ports
containing one to five digits. IPv6 and exhaustive DNS/port-range validation are outside the current
supported formats.

`Model\ApplicationRepository` owns application/deployment reads, creation,
updates, deletion, administrator assignments and application counts. Mutations
and audit writes share one database transaction. Component handlers orchestrate
HTTP input, flash messages and redirects; validated values and page data remain
plain typed classes. There are no separate application creation/store/registry
services. The repository is `readonly`; its `getBy*` methods return nullable
entities. Alternate-key lookups fetch one ID through `getIdBy*.sql` with
`fetchString`/`fetchInt`, then delegate to the canonical ID lookup for hydration.
`getClientRedirectUri()` returns a `Uri` for the page's HTTP response.

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
The confirmation POST requires `confirmDelete` to match the saved target ID.

The remaining administration screens retain their layouts with unfinished
controls disabled. There is no demo state, simulated reporting, billing data or
CSV export. Application selection uses the saved catalogue directly; application
and deployment forms remain live. Admin browser checks run against the real
application through Behat rather than a simulated HTTP backend.

Dashboard reporting
-------------------

`/admin/` reports the selected application's saved data. Its deployment selector
defaults to production when configured, otherwise All deployments. The selection
scopes every dashboard section and survives period, comparison, filter and page
changes. Select All deployments explicitly for combined reporting.
`Admin\Dashboard\DashboardRepository` owns scoped reporting queries; each query
checks `UserAdmin` membership and aggregates or limits results in SQL. A render
uses ten reporting queries, independent of the number of displayed users. There
are no per-row user/deployment lookups. Migration 028 adds indexes for audit
aggregation and user creation-date filtering.

Today and single-day custom ranges use 24 hourly chart buckets (00:00–23:00 UTC),
with missing hours filled with zeroes. Their comparison uses matching hours from
the previous day. Longer ranges use daily buckets.

Reporting periods are UTC calendar days: Today, seven days, thirty days, or an
inclusive custom range of at most 366 days. Comparison figures cover the preceding
range of equal length. Today remains incomplete, so compare its totals cautiously
with a complete previous period. Configure the database/server to store audit
and user timestamps in UTC; these timestamp columns have no timezone information.

Successful logins count `LOGIN_COMPLETED` audit events, including provider/admin
and development logins within the selected application's deployments. Failed
attempts count `PASSWORD_LOGIN_FAILED` and `SECURITY_CODE_REJECTED`. The success
ratio is successful logins divided by successful logins plus those rejected
attempts; no outcomes display a dash rather than an invented percentage.
Security codes count requests, not confirmed email deliveries. Password changes
count their audit events. New users count saved User records by `createdAt`,
including manually added accounts. The same email in two deployments counts as
two user records; top usage also ranks these identities separately. The daily
line/area chart uses the restored Apache ECharts implementation, with
SVG rendering, this/previous-period lines and an accessible data table. It follows
the current theme, reduced-motion setting and Flux navigation, and disposes
charts when their elements are replaced. The table remains available without
JavaScript.

Activity shows 25 events per page, with a bounded extra row for Next, and filters
by event type. Top usage ranks five users by successful logins in the period.
New users lists the five most recently created accounts; Awaiting first login
lists up to five users created in the period with no recorded login at any time.
This is not proof that signup was abandoned. Email values use the existing sudo
protection; user-detail links keep the selected application in the URL.

Country/device reporting and reliable signup-abandonment tracking are unavailable.
Anonymous audit events and events whose user has been deleted cannot reliably
be assigned to an application through the current reporting queries. Old users with null `createdAt` cannot
be included in date-based creation totals. These figures are operational views,
not an immutable billing ledger. Header search and Add user link to the existing
Users screen within the current application.


Audit logging
-------------

`Security\Audit` owns both request and business-event auditing; there are no
separate audit forwarding services, diff factories or payload classes. Audit
rows identify the actor and timestamp in their own columns. Detail JSON
contains only event-specific information: requests store `method` and `path`;
updates store target IDs and changed `from`/`to` fields; creation stores `to` and
deletion stores `from` once. Unchanged fields, duplicate diffs, empty state
wrappers and credentials are omitted. Events needing no extra context use null.
Existing historical audit rows are not rewritten.

Application creation and its administrator assignment commit in the same
transaction as `APPLICATION_CREATED`. An audit failure rolls everything back.
Rejected names, application selection, authentication state changes, password/code
outcomes, user creation, email scheduling and logout are also logged. Every page
request records its method/path. These database audit rows omit query parameters
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

Use `data-sudo="email"` for stored email addresses. It keeps the first two
characters on each side of `@` and masks the remaining characters, for example
`al•••@ex••••••••••`. Email addresses in both Users lists use this rule. Their
View icon appears on hover or keyboard focus (and stays available on touch screens).
All email fields remain visible throughout sudo mode, with a Copy icon replacing
View when JavaScript is available. They are masked again when sudo expires or is
disabled, restoring the View icon.

The admin common logic masks values on the server and adds a native View button.
Password confirmation at `/login/sudo/` enables sudo with a 60-second inactivity
timeout; configure `authwave.sudo_duration` in seconds to change it. Each provider
request renews active sudo mode, including page reloads. With JavaScript, user
interaction also renews it; automatic background refreshes do not. Once the idle
period expires, activity cannot restore sudo without password confirmation.
A key is disclosed only
in the response requested by View or Copy, rather than every response during the
sudo period. Other sudo fields are available while sudo is active. JavaScript
re-obscures keys on arrival, provides view/copy controls, and clears visible
values when sudo expires or the page is left. After password confirmation, the
requested View resumes automatically using a one-use `sudo-view` query parameter.
Copy still requires a fresh click. Without JavaScript the authorised View
response displays the key until the next navigation.

The SUDO button beside Log out immediately revokes authorisation. Logging out
also discards it. Each authorisation request, rejection, success, field access,
expiry and explicit revocation is audited without credentials. Protected admin
responses use `Cache-Control: no-store`.


Styling and browser enhancements
--------------------------------

* Shared control patterns come from Flair, installed from
  `../../BrightFlair/flair`; theme-specific values belong in its theme rather
  than local overrides. Application layout styles remain under `style/`.
* Form-ending controls use `.actions` for spacing equivalent to an input caption
  and its gap. Save/Continue is first in the HTML so Enter submits it; CSS places
  secondary controls, including deletion, to the left. Icon-only controls retain
  screen-reader text. Existing panels use readable maximum widths and responsive
  columns without changing their meaningful HTML order.
* Login, domain-verification and development-preview spinners share
  `style/animation/flux-loader-spin.scss`, including reduced-motion handling.
  The SVG mask stays stationary while its shading animates. CSS adjustments
  belong in that shared implementation; the icon asset is not a workaround for
  animation geometry or rasterisation problems.
* Forms and links work without JavaScript. Flux enhances them after rendering;
  pre-script appearance, including hidden autosave controls, is handled by
  HTML/CSS. Cross-page forms must reach the destination's handler rather than
  carrying an unrelated component-routing field.

After changing source assets, rebuild the assets served by the provider:

```bash
vendor/bin/build --default vendor/phpgt/webengine/build.default.ini
```

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

New administrator onboarding is based on the signed-in user's `UserAdmin`
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

User administration
-------------------

The Users page searches users in the selected application's deployments. Plain text
matches any part of the email address; `*` patterns match the whole address, with
`*` standing for zero or more characters. `%` and `_` are literal characters.
The Deployment filter combines with the email search and sort order, and offers
only deployments in the selected application. Accounts are deployment-specific;
choose a deployment when creating a user. Administrators
may set an immediately usable password (at least twelve characters), or leave the
account using email security codes. With scripting disabled, use "Apply authentication
options" after changing "Set password" to reveal the password field.

Optional login instructions use `data/email/loginInstructions.md`. User creation,
its audit entry and the queued email commit together; delivery happens afterwards
using the application's email settings. Passwords are never included in the message
or audit trail. Delivery failures leave the email queued.

Client sessions are recorded in `UserSession` after successful client logins.
They stop counting on logout or expiry. The default lifetime is 30 minutes:
`UserRepository::DEFAULT_SESSION_DURATION` is expressed in seconds. Override it
with `[authwave] client_session_duration=1800` in configuration. This is a reporting
estimate; the client still controls its own authentication session.

An HttpOnly, host-only cookie holds an opaque session identifier for each deployment,
so client logout can identify the user even after the provider's login session has
been cleared. Separate browsers count separately; repeated handoffs for the same
browser session do not add to the count. Development login previews and provider
administration logins do not create client sessions. The Users panel counts all
active sessions for the selected application and lists up to 25 distinct users,
with five visible rows in a scroll area and a count of any additional users.
Email addresses are protected by sudo. Tracking begins with new client logins.

Login counts and latest failures come from audit records. The failures panel shows
up to ten users whose latest failed password or security code was within 24 hours.
Older users without a recorded creation date display a dash rather than a fabricated date.

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

New-user notifications
----------------------

New users are sent immediately by GET to the existing Client URL + Client login
path. `AUTHWAVE_NEW_USER` contains the encrypted payload and `AUTHWAVE_NEW_USER_IV`
contains its public IV, both URL-encoded. No request body, separate notification
URL or endpoint is needed.

The updated php-client recognises the request when `Authenticator` is constructed
at that route. It exposes the user through the normal `getAuthUser()` API and
invokes the existing `onAuthenticated` callback, so the application's usual user
creation/upsert code runs. No custom route handler or JSON parsing is needed.

The client marks the response with `Authwave-User-Change: received`. The provider
requires both this acknowledgement and a final 2xx status. An older client returning
its normal HTML page has no acknowledgement, and an application error returning
5xx is a failed delivery even if construction already completed.

The provider commits the user and its audit records before sending, and waits for
the client response. Connection and total timeouts are three and ten seconds;
redirects are not followed. Production delivery requires HTTPS.

`User.clientNotificationStatus` stores the latest HTTP status (`0` for a transport
failure), and `clientNotificationAcknowledged` records whether it was acknowledged.
Attempts are audited without message contents or response bodies. If
delivery cannot be confirmed, the user remains created and **Redeliver** appears
in the user details synchronisation notice only after a recorded failed attempt, and only until
the user has logged in through the client. Users with no notification attempt are
not treated as failures. Redeliver retries immediately with fresh encryption, using
the current client login URL and deployment secret. There is no queue or automatic
retry. Clients must upsert by Authwave user ID because a timeout can happen after
the client has already processed the message.

User details and deletion
-------------------------

User popovers have bookmarkable URLs: `/admin/users/?application=APPLICATION_ID&user=USER_ID`.
Opening or closing details updates browser history; bookmarks, reload and Back/Forward
restore the selected user. Sudo returns to these details with Delete enabled;
confirmation is still a separate action.

The Edit column opens user details, including up to ten successful login timestamps
and deployments in the selected application with the same email address. Email
addresses remain protected by sudo mode. Failed synchronisation is marked with a
negative row tint and cloud icon; its details notice contains the manual retry.

Deletion requires active sudo mode, followed by a separate confirmation. Expired
sudo authorisation must be renewed before confirming or retrying a deletion.

Confirmed deletion sends `AUTHWAVE_DELETE_USER` and `AUTHWAVE_DELETE_USER_IV` to
the existing client login URL, using the same encrypted payload with action
`user-deleted`. The client must configure `Authenticator`'s `onDeleted` callback to
remove its user by Authwave ID. No callback means no acknowledgement.

Only after a final 2xx response with `Authwave-User-Change: received` does the
provider delete the user and its sessions, recording `USER_DELETED` in the same
transaction. A failed delivery retains the user and records the pending action
in `User.clientNotificationAction`; Redeliver retries deletion rather than creation.
Deletion callbacks must be idempotent because an acknowledgement can be lost after
the client has already removed its user.
