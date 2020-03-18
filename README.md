Hosted or self-hosted Authwave provider.
========================================

The Authwave provider is the software that renders the login user interface and application/user management section for administrators. An official provider is hosted by Authwave, managed at www.authwave.com, and the provider software can also be self-hosted.

A subdomain of your application should be used to access the provider, whether hosted or self-hosted. For example, if your application is hosted at www.example.com, the provider can be accessed at a subdomain such as login.example.com.

*****

User authentication flow
------------------------

1) User arrives at your application. For example, www.example.com.
2) Your application checks the Authwave API for authentication status. If they are not authenticated, your application can display a "login" button.
3) When the login button is clicked, your application can redirect to the preconfigured provider URI, for example, login.example.com. Client cipher is passed to the provider for encryption purposes.
4) The user is now on the provider server rather than your application. 