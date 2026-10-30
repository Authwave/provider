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


Running unit tests
------------------

After installing Composer dependencies, run the provider unit tests with:

```bash
vendor/bin/phpunit -c phpunit.xml
```

The login return tests exercise request handling and successful authentication with encrypted messages, using controlled repositories and sessions without requiring a database or browser.
