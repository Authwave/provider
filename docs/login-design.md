# Login design

The `new-design` branch integrates the UI from `new-provider` with the provider's existing PHP authentication handlers. Application PHP, database queries, email delivery and client encryption are unchanged.

The stylesheet is built from variables, decorations, elements, objects, patterns, layouts, components and pages. Theme properties follow `--{type}--{optional-usage}--{key}`; colours use `pal`. Shared controls and panels use these variables in both colour schemes. The security-code component uses Fira Mono; icons in `asset/icon` use CSS masks and inherit text colour.

## Existing handler contracts

| Page | Submitted fields/action | Behaviour |
| --- | --- | --- |
| `/login/` | `email`, `do=continue` | Store email and show authentication choices |
| `/login/authenticate/` | `password`, `do=password` | Password login, registration or password reset |
| `/login/authenticate/` | `do=link` | Email a security code; existing PHP uses password flow when password is filled |
| `/login/security-check/` | `token`, `do=confirm` | Verify the five-digit code and show flash errors |
| `/login/?do=cancel` | Query action | Cancel and return to the client |
| `/login/success/` | Session state | Encrypt identity and redirect to the client |

Templates retain `applicationName`, `logoPath`, `email`, `title`, `returnUri` bindings and the `error` list template. Existing uploaded logos are supported, with the default logo as fallback.

The five code boxes progressively enhance a single required `token` input. Without JavaScript the single input remains usable. With JavaScript the boxes support paste/autofill, navigation, Backspace and focus on Confirm when complete. Desktop autofocus and viewport scrolling are app scripts, separate from reusable controls.

Social-provider demo buttons are omitted because this repository has no matching actions. “Didn't receive the code?” returns to authentication to request another code using the existing handler. Re-enter a proposed password there if setting or changing it.

## Build and verification

The existing `build.json` handles CSS, JavaScript and asset publishing. With the repository's dependencies installed, the equivalent bundle commands are:

```sh
node_modules/.bin/sass style/style.scss www/style.css
node_modules/.bin/esbuild script/script.es6 --bundle --sourcemap --outfile=www/script.js --loader:.es6=js --target=chrome105,firefox105,edge105,safari15
vendor/bin/sync ./asset ./www/asset --symlink
```

Run the isolated integration checks:

```sh
vendor/bin/phpunit --bootstrap vendor/autoload.php test/ui/LoginDesignTest.php
node test/ui/browser.mjs
```

The browser test needs Node 22+ and Chromium (`CHROMIUM` may specify its executable). Optional `UI_SCREENSHOT_DIR` saves screenshots. It renders the actual templates using the framework's component expansion, route classes and binding, then serves temporary fixtures. PHP tests call actual page handlers with in-memory sessions and mocked repositories. Neither suite connects to the configured database or sends email.

Coverage includes password and email-code paths, code errors, cancellation, encrypted callback data, mobile/desktop layouts, light/dark mode, asset loading, code entry and paste, Confirm/Backspace focus and the no-JavaScript fallback. These checks do not replace testing actual email delivery, the client handoff and the operating-system keyboard on a deployed environment. The legacy Behat runner changes the configured database and is not part of these isolated checks.
