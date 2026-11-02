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
