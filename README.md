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

* `test/phpunit` contains the unit and PHP-rendered UI tests, running on PHPUnit 13.
  These use controlled repositories and sessions; no configured database or mail
  server is required. Warnings, risky tests, and PHPUnit notices/deprecations fail
  the suite.
* `test/behat` is the restarted browser test bed, following api.horse's Mink and
  Chrome setup. Its initial smoke scenarios visit the real `/logged-out/` page.
  The runner starts its own PHP server and headless browser on available ports,
  uses a temporary browser profile, and cleans up after success or failure.
  Undefined or pending steps fail the suite. It never rebuilds your database.
  Set `CHROME_BIN` if Chrome/Chromium is not on your PATH; additional Behat
  arguments are forwarded, for example `composer behat -- --name="fresh browser"`.

`phpcs.xml` and `phpmd.xml` are copied from api.horse. Existing classes have been
formatted and annotated for those checks, with local PHPMD exceptions for
stateless framework/utility calls, established public `id` fields, and the demo
workspace facade. PHPStan uses no baseline or ignored errors.

The supplementary browser regression scripts remain available through
`npm run test:ui`. They require Node.js and installed npm dependencies, including
this project's local Flair package, and exercise login and administration pages
with isolated fixtures. They are separate from the basic Behat smoke coverage.

Manual development login
------------------------

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
