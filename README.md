Hosted or self-hosted Authwave provider.
========================================

The Authwave provider is the software that renders the login user interface and application/user management section for administrators. An official provider is hosted by Authwave, managed at www.authwave.com, and the provider software can also be self-hosted.

A subdomain of your application should be used to access the provider, whether hosted or self-hosted. For example, if your application is hosted at www.example.com, the provider can be accessed at a subdomain such as account.example.com.

*****

User authentication flow
------------------------

1) The user agent arrives at your application. For example, www.example.com.
2) Your application checks the Authwave API for authentication status. If they are not authenticated, your application can display a "login" button.
3) When the login button is clicked, the user agent can be redirected to the preconfigured provider URI, for example, account.example.com. A client cipher is passed to the provider to facilitate two-way encryption.
4) The user agent is now on the provider server rather than your application.
5) After authentication, the provider requests supplementary information (such as name, address, etc.) that is optionally configured in the provider's administration section.
6) The user agent is redirected back to your application after successful authentication.
7) Your application has access to the user's authenticated details, such as email, id, and any supplementary information requested by your application.

User profile management
-----------------------

Authenticated users can manage their user profile data by visiting the `/profile` path of the base provider URI. This is usually done by redirecting from within the client application. For example, clicking the "profile" button in the client application at www.example.com will redirect to account.example.com/profile.

From within the `/profile` path, users have the ability to manage the following:
 
+ Change their email address.
+ Set their password.
+ Add multifactor authentication.
+ Add social logins.
+ Delete their account.
+ Download their authentication data.

Deleting an account and downloading of data is done by redirecting back to the client application, and it is the responsibility of the application developer to complete the steps required to fulfil these steps on the application database. 

Application administration
--------------------------

As well as authentication and user profile management, the provider handles application and user administration. This is done by visiting the `/admin` path of the base provider URI. This will trigger an authentication flow, but will remain on the provider server rather than returning to the client application.

Only administrators' email addresses can be used to authenticate to the administration section. Non-administrators will receive an email explaining how to correctly authenticate to the client application.

To create an administrator account, sign into the Authwave provider application (either at account.authwave.com/admin or self-hosted) and configure a new client application. Administrators will be able to manage the application and user details once the application is hosted under the client application's domain.
  
When self-hosting a provider, it is necessary to create the first administrative user manually. This is done by adding the email address to the project configuration. The email address should be added to the `admin_email` key of the `authwave` section of the provider's config.ini.