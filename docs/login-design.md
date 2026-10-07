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

## Application branding

Migration `014-application-theme.sql` creates `application_theme`. Each application can have one row for `light` and one for `dark`, enforced by the composite primary key `(applicationId, colourScheme)`. `applicationId` references `application.id`; deleting an application deletes its themes. `colours` is a JSON object containing only the overrides you want.

The earlier uncommitted primary/secondary column migrations have been replaced. If you already applied them locally, their columns and values are not automatically removed or copied; this code no longer reads those columns. Apply the new table migration and enter your palettes below. Existing login sessions keep their application snapshot, so start a fresh login after changing theme rows.

For example, insert separate palettes for an application (use your actual application ID):

```sql
insert into application_theme (applicationId, colourScheme, colours)
values
    ('APP_01K1XFMGPSAJ5KNXPESS', 'light', '{
        "primary": "#123456",
        "secondary": "#687fa0",
        "pageBackground": "#f5f7fa",
        "panelBackground": "#ffffff",
        "bodyText": "#354052",
        "headingText": "#162438",
        "buttonPrimaryText": "#ffffff"
    }'),
    ('APP_01K1XFMGPSAJ5KNXPESS', 'dark', '{
        "primary": "#90bfff",
        "secondary": "#b6a0ff",
        "pageBackground": "#121820",
        "panelBackground": "#1c2530",
        "panelBorder": "#384658",
        "bodyText": "#dce5ef",
        "headingText": "#ffffff",
        "buttonPrimaryText": "#102030"
    }');
```

To replace an existing palette, use `update application_theme set colours = '{...}' where applicationId = '...' and colourScheme = 'dark'`. To reset an individual colour, remove its JSON key; to reset a whole scheme, delete that row or set `colours` to `'{}'`.

Values accept hex colours: `#RGB`, `#RGBA`, `#RRGGBB`, or `#RRGGBBAA`. For transparent colours, use an alpha component, such as `#ffffff66` or `#0000`. Unknown keys and invalid values are ignored. Missing rows or keys keep the defaults for that scheme; light overrides never leak into dark mode. CSS derives related colours (including hover and active states) unless explicitly overridden. `primary` supplies the brand/button colour and input accents; `secondary` supplies outlines, code borders, and interaction tints. More specific keys take precedence over these defaults.

Validated overrides are rendered after the main stylesheet in rules scoped to `data-color-scheme`. System colour-scheme changes update both the palette and the selected logo without a reload. No admin UI is included yet.

All palette properties are supported through these stable JSON keys, mapped by `ApplicationTheme::COLOUR_PROPERTIES`:

| JSON key | CSS property |
| --- | --- |
| `primary` | `--pal--theme` |
| `secondary` | `--pal--theme-secondary` |
| `codeInputBorder` | `--pal--code-input--border` |
| `positive` | `--pal--positive` |
| `negative` | `--pal--negative` |
| `warning` | `--pal--warning` |
| `interactionShade` | `--pal--interaction--shade` |
| `focusOutline` | `--pal--focus--outline` |
| `pageBackground` | `--pal--page--background` |
| `panelBackground` | `--pal--panel--background` |
| `panelBorder` | `--pal--panel--border` |
| `controlBorder` | `--pal--control--border` |
| `controlBackground` | `--pal--control--background` |
| `controlBorderHover` | `--pal--control--border-hover` |
| `controlBackgroundActive` | `--pal--control--background-active` |
| `controlHighlight` | `--pal--control--highlight` |
| `controlShade` | `--pal--control--shade` |
| `controlHighlightHover` | `--pal--control--highlight-hover` |
| `controlShadeHover` | `--pal--control--shade-hover` |
| `buttonBackground` | `--pal--button--background` |
| `buttonText` | `--pal--button--text` |
| `buttonPrimaryText` | `--pal--button--text-primary` |
| `buttonPrimaryBackground` | `--pal--button--background-primary` |
| `buttonBorder` | `--pal--button--border` |
| `buttonPrimaryBorder` | `--pal--button--border-primary` |
| `buttonBorderHover` | `--pal--button--border-hover` |
| `buttonBackgroundActive` | `--pal--button--background-active` |
| `buttonPrimaryBorderHover` | `--pal--button--border-primary-hover` |
| `buttonPrimaryBackgroundActive` | `--pal--button--background-primary-active` |
| `bodyText` | `--pal--body--text` |
| `controlPlaceholder` | `--pal--control--placeholder` |
| `headingText` | `--pal--heading--text` |
| `linkText` | `--pal--link--text` |
| `linkTextHover` | `--pal--link--text-hover` |
| `linkTextActive` | `--pal--link--text-active` |
| `linkBackgroundActive` | `--pal--link--background-active` |

Place logos in `data/upload/<applicationId>/`: `logo.<extension>` for light mode and optionally `logo_dark.<extension>` for dark mode. SVG, PNG, JPG/JPEG, GIF, WebP and AVIF are supported, including uppercase extensions; the two logos can use different formats. Keep one file per logo name. If multiple supported files exist, the first alphabetical path is used. Each login logo uses a `<picture>` with a dark-mode media source and a light-mode `<img>` fallback. Without a dark logo, both modes use the light logo; without a light logo, light mode uses the default Authwave logo.

The five code boxes progressively enhance a single required `token` input. Without JavaScript the single input remains usable. With JavaScript the boxes support paste/autofill, navigation, Backspace and focus on Confirm when complete. Desktop autofocus and viewport scrolling are app scripts, separate from reusable controls.

Forms marked `data-flux` use `@phpgt/flux` for submission. The submitted button fades to a spinner while fields stay focusable but read-only, and repeated submissions are blocked. Failed requests release the form for retry. Reduced-motion preferences disable the spin and fades. The body and title are update targets so redirects between login steps, the admin success choice and the 403 page replace the complete screen.

Flux requests carry `X-Authwave-Flux: 1`. For ordinary users, the success handler returns a page with a `data-client-redirect` link instead of letting fetch follow an external redirect. The after-render handler navigates the browser to that link; native form submissions keep their existing HTTP redirect. Administrator success pages retain the application/admin choice. The account-switching form on the 403 page uses the same loading behaviour.

Social-provider demo buttons are omitted because this repository has no matching actions. “Didn't receive the code?” returns to authentication to request another code using the existing handler. Re-enter a proposed password there if setting or changing it.

## Security-code emails

`data/email/securityCode.html` resolves the same theme roles as the login screen: `pageBackground`, `panelBackground`, `panelBorder`, `bodyText` and `headingText` are used directly. The code box follows the web security-code inputs: `controlBackground` falls back to `panelBackground`, its text uses `bodyText`, and `codeInputBorder` falls back to `secondary`. Primary does not tint unrelated surfaces. Light and dark themes are independent; missing or invalid values use the corresponding defaults from `style/variable/palette.scss`, mirrored in `EmailBranding::DEFAULTS`. Its constructor accepts an associative array of default overrides. Transparent colours are composited over their actual underlying surface to produce email-compatible hex colours. Inline styles provide the light layout, with `prefers-color-scheme` overrides for email clients supporting dark mode.

The logo URL is `https://{{providerHost}}{{logoPath}}`, using the deployment's provider host and uploaded application logo. Email prefers a raster logo when multiple formats are present. Clients supporting the dark-mode media query switch to `logo_dark` on the dark panel background. Without a dark logo, the standard logo remains on white for readability.

`EmailTemplate` selects `.html` before `.md` when given a name without an extension. HTML templates use `<title>` as the subject and bypass Markdown conversion; Markdown templates retain their first-line subject convention. Placeholder values are HTML-escaped. Plain-text content is generated from the rendered HTML, removing markup and head/style content while preserving paragraph boundaries.

## Administration pages

The admin section keeps the provider palette. The sidebar starts with the application selector, and account setup progress remains at the bottom. Its shared sidebar and sticky header appear on Dashboard, Security, Emails, Applications, Customisation, Users, Integrations and Billing. A single native application select submits a GET form, with one option per application. Switching applications resets the deployment context; deployment selection belongs to the individual page. Views always belong to a single application; the first available application is selected by default. Sample applications from every demo organisation are available. Scoped links and forms retain the chosen organisation, application and deployment. The desktop sidebar scrolls independently, and the same native details menu opens it on mobile. Account setup appears only in the sidebar; Continue setup links to the next incomplete step.

`DemoWorkspace` provides sample organisations, applications/deployments, users and settings in the `AUTHWAVE_ADMIN_DEMO` session store. Settings forms use POST, validate the submitted values and redirect back to the scoped page. They never write application, user, security, email or billing database records. Creating an organisation starts the seven-step setup checklist; creating an application updates both the list and scope selector. Application settings inherit organisation defaults unless overridden. SMTP credentials, API keys and security actions on these pages are demo values/actions. No test email or invitation is sent and no real user session is revoked.

Each admin page owns its bindings in its sibling PHP file. Each dynamic component has a matching `page/_component/*.php` file using WebEngine’s scoped `Element` and `Binder`. For example, application-switcher owns the application options, admin-header owns search/export links, admin-top-usage owns the Users/Countries/Devices tabs, and admin-new-users and admin-abandoned-users own their timestamped lists. `DemoReport` supplies shared sample report calculations; `AdminView` provides reusable binding/form helpers. The test renderer invokes these files through WebEngine’s LogicExecutor and ComponentBinder, in component-before-page order. Email addresses are masked by default; the user reveal controls are ordinary GET forms. Template previews use sandboxed iframes and saved HTML/CSS overrides never style the admin section. Quick actions open Add user/Security or download a CSV of masked sample users.

The actual admin authentication/access check remains required for every page and POST action. Logout is a real POST action: it clears the login session and destroys the provider session. It defaults to the public `/logged-out/` landing page. Self-hosted instances may set `authwave.logout_redirect` in config.ini to a customised destination. The header link points to the client root, without its login callback path.

Users are identified by email only. `UI\EmailAvatar` generates deterministic inline SVG artwork from the normalised full email before masking; the hash selects a silhouette, zero to two corner symbols, a radial/parallel/quadrant slicing pattern, whether to slice neither layer, only the background, only the shapes or both, and palette entries. CSS derives six subdued accent hues at several lightness levels and two contrasting neutrals from the primary theme colour. The SVG has no email text, external resources or shared clipping IDs. No external avatar service is used.

Charts use Apache ECharts through modular npm imports in the single `/script.js` bundle. Component initialisers select their own elements and run again after Flux renders; charts resize through ResizeObserver. PHP binds escaped JSON to chart data attributes and native chart-data tables provide the same sample series without JavaScript. The chart plot spans the container width; space for its legend and labels is kept.

Reusable Sass layouts/patterns cover the sidebar, stacks/splits, card grids, toolbars, segmented controls, settings panels/disclosures, record lists and responsive tables. Record tables stack all fields into labelled rows on mobile; the authentication table retains its conditional details column. The admin font stays at 16px to keep native controls and breakpoints consistent.

Run `vendor/bin/phpunit test/ui` and `node test/ui/admin-browser.mjs` for isolated checks. Optional `UI_SCREENSHOT_DIR` saves screenshots of each section. Browser fixtures exercise scope changes and demo POST forms through a temporary server; the PHP handler tests cover administrator checks and logout. The fixtures never query the configured database or send email.

## Build and verification

WebEngine’s default build configuration handles CSS, assets and the shared JavaScript bundle. To run it directly, use `vendor/bin/build --default vendor/phpgt/webengine/build.default.ini`. With the repository’s dependencies installed, the equivalent commands are:

```sh
node_modules/.bin/sass style/style.scss www/style.css
node_modules/.bin/esbuild script/script.es6 --bundle --sourcemap --outfile=www/script.js --loader:.es6=js --target=chrome105,firefox105,edge105,safari15
vendor/bin/sync ./asset ./www/asset --symlink
```

Run the isolated integration checks:

```sh
npm run test:ui
```

This command builds the assets, runs the PHP UI tests and exercises the login and admin pages in Chromium. Shared layout checks detect overflowing visible content even when the page edge is clipped, verify that the page cannot scroll sideways, and check footer spacing and desktop alignment. Admin checks include open disclosures and sticky headers across widths from 280 to 2560 pixels.

The browser test needs Node 22+ and Chromium (`CHROMIUM` may specify its executable). Optional `UI_SCREENSHOT_DIR` saves screenshots. It renders the actual templates using the framework's component expansion, route classes and binding, then serves temporary fixtures. PHP tests call actual page handlers with in-memory sessions and mocked repositories. Neither suite connects to the configured database or sends email.

Coverage includes password and email-code paths, code errors, cancellation, encrypted callback data, mobile/desktop layouts, light/dark mode, asset loading, code entry and paste, Confirm/Backspace focus and the no-JavaScript fallback. Browser checks also cover spinners, duplicate submissions, network-failure retries, repeated code submissions, redirects between screens, the HTTP 403 page, account switching and navigation to a simulated client on another origin. These checks do not replace testing actual email delivery, a deployed client integration and the operating-system keyboard. The legacy Behat runner changes the configured database and is not part of these isolated checks.
