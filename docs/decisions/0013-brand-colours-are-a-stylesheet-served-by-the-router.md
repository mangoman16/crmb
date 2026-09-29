---
status: accepted
date: 2026-09-29
---

# 0013. Brand colours are a generated stylesheet, served by the router

## Context

The owner wants to set the portal's colours in Settings:

- a **main colour**, today the `--teal` family (`--teal`, `--teal-ink`, `--teal-soft`,
  `--teal-border`, `--focus`);
- a **second colour**, today `--navy` and the `--nav-*` family (sidebar, `.stat.accent`,
  the public header's name);
- a **third colour**, today `--teal-bright` (the dot on the brand mark, the active-menu
  marker);
- a **background colour**, today `--bg`.

She decided:

- dark shades are computed from the light choices, and each can be overridden later
  (empty = computed);
- the main colour becomes the portal default, and everybody can still pick a personal
  accent (`accounts.accent`, `accents()`, `accent_for()` in `app/shell.php`).

The constraints:

- **The CSP.** `boot_http()` sends `style-src 'self'`. An inline `style` attribute or
  `<style>` block is refused by the browser (see the comment in `views/profile.php`).
  Colours can only reach the page as a stylesheet from the portal's own origin.
- **The login page is public** and should wear the colours too.
- **`public/` is overwritten by the next update** and may not be writable (ADR 0008).
- **A free colour choice can make text unreadable.** Every preset in `accents()` was picked
  so that white text on it stays readable, and the components rely on that:
  - `.button` and `.composer-send` print `#fff` on `--teal` in light mode;
  - the dark block prints `#0b1218` on it;
  - `a`, `.tabs`, `.chip` and `.eyebrow` use `--teal` as text on `--surface`.
- **Nothing changes for a portal nobody customised.** It must look exactly as it does today
  and make no extra request.
- **Settings are not `tracked()`.** A mis-set colour has no undo in the change log.

## Decision

### Settings

There is one new kind, `colour`, in `app/defaults.php`. Its value is `''` (the built-in
colour) or a lower-case `#rrggbb`.

There are eight new settings, all `kind colour`, `default ''`, in a new group `branding`:

| Key | Token family | Notes |
| --- | --- | --- |
| `brand_primary` | `--teal`, `--teal-ink`, `--teal-soft`, `--teal-border`, `--focus` | the portal default accent |
| `brand_secondary` | `--navy`, `--navy-2`, `--nav-bg`, `--nav-bg-2`, `--nav-active`, `--nav-ink`, `--nav-ink-2`, `--nav-hover`, `--on-navy`, `--on-navy-faint`, new `--on-nav`, new `--brand-ink` | |
| `brand_highlight` | `--teal-bright` | |
| `brand_background` | `--bg`, `--scrim-bg` | `'text_on' => '#5d6e7e'` |
| `brand_primary_dark`, `brand_secondary_dark`, `brand_highlight_dark` | the same families, dark scheme | `'advanced' => true` |
| `brand_background_dark` | the same, dark scheme | `'advanced' => true`, `'text_on' => '#9aabba'` |

- A dark override applies only while its light colour is set. The form's hint says so.
- The same group holds the two header switches of ADR 0014, `header_hide_name` and
  `header_hide_subtitle`.
- The whole group is one form, the „Aussehen" card in Settings.
- It is saved by `defaults_registry_save`, which gains `branding` in its `choose()` list and
  is admin-only, like `portal`. **No new action.**
- `defaults_registry_save` writes **every** key of a group from one POST. Any setting in
  `branding` must therefore be on that card, or a save would blank it.
- `default_accent` stays. Its hint changes to „Gilt, solange keine eigene Hauptfarbe gesetzt
  ist." Existing portals that chose a preset keep it until a main colour is set.

**The way back.** For the `branding` group, the save's flash message lists the values it
replaced, for example „Gespeichert. Vorher: Hauptfarbe #077e76, Zweitfarbe Standard, …". She
can type them back in. Settings are not `tracked()`, so this sentence is the undo. An empty
value is named as the built-in one („Standard"), so a return to the built-in look is shown
too. The exact wording is the `ui-ux-designer`'s.

**Validation.** `setting_validate()` gains `case 'colour'`:

- empty returns `''`;
- otherwise `colour_normalise()` accepts `#rgb` or `#rrggbb`, with or without `#`, in any
  case, and returns lower-case `#rrggbb`. Anything else refuses the save with „Bitte eine
  Farbe wie #1f5fa9 eingeben.";
- when the spec has `text_on`, a colour whose contrast with that text colour is below 4.5:1
  is refused, saying the grey text on it would be hard to read.

Only backgrounds are refused. The brand colours are adjusted instead (below), because a
club's colours are not hers to change.

**The form control** is a text field (`maxlength 7`). `<input type=color>` cannot be empty,
so it cannot express "built-in". `app.js` may add a native picker that writes into the text
field, as an enhancement only.

### Where the code lives

**`app/colour.php`, new, pure.** It has no database, no settings and no IO. It is required
directly after `validate.php`: `setting_validate()` in `defaults.php` calls it, and nothing it
needs comes later. It holds:

- `colour_normalise(string): ?string`
- `colour_luminance(string): float`, the WCAG relative luminance
- `colour_contrast(string, string): float`
- `colour_mix(string $a, string $b, float $weightOfA): string`
- `colour_text_on(string $bg): string`, which returns `#ffffff` or `#0b1218`, whichever
  contrasts more
- `colour_until_contrast(string $c, array $against, float $min, string $toward): string`,
  which steps `$c` toward black or white until it reaches `$min` against every colour in
  `$against`

**`app/brand.php`, new.** It is required directly after `uploads.php` and **before**
`portal_icon.php`, because `web_manifest()` reads `brand_theme_colour()`. It needs
`setting()`, the `colour_*` functions and the cache helpers in `uploads.php`. Nothing earlier
calls it. It holds:

- `brand_palette(): array`, memoised. It returns
  `['light'=>[token=>hex], 'dark'=>[...], 'adjusted'=>[key=>['chosen'=>…, 'used'=>…]]]`.
  Every setting is re-normalised on read. A value that does not normalise counts as `''`, so
  a tampered or old row falls back to built-in and never reaches the CSS.
- `brand_css(): string`
- `brand_css_version(): string`: the first 12 hex characters of
  `sha256(app_version() . json of the eight settings)`. `app_version()` is included so a
  release that changes the derivation also changes the address.
- `brand_css_url(): string`, which is `''` when all eight settings are empty
- `serve_brand_css(): never`
- `brand_theme_colour(string $scheme): string`. For `light` it is the effective nav colour
  (today `#13243a`); for `dark` it is the effective dark background (today `#101922`).
- the logo functions of ADR 0014

**Cache helpers.**

- `shared_cache_control()` moves from `portal_icon.php` to `uploads.php`, next to
  `send_cache_control()`. It is generic, and `brand.php` loads before `portal_icon.php`.
- The year that club assets are kept is one constant, **`CLUB_ASSET_MAX_AGE`** (31536000),
  also in `uploads.php` beside `send_cache_control()`. The icon, logo and stylesheet all
  read it.

### Derivation

`P`, `N`, `H` and `B` are the chosen colours. For the dark scheme, each is its `_dark`
override if set, otherwise the light choice. The surfaces are fixed, as today: `#ffffff` in
light and `#182430` in dark.

| Token | Light | Dark |
| --- | --- | --- |
| `--teal`, `--focus` | `P` toward black until ≥ 4.5 against `#ffffff` | `P` toward white until ≥ 4.5 against `#182430` **and** `#0b1218` |
| `--teal-ink` | `--teal` toward black until ≥ 4.5 against `--teal-soft` | `--teal` toward white until ≥ 7 against `#182430` |
| `--teal-soft` | `mix(P, #ffffff, .12)` | `mix(P, #101922, .18)` |
| `--teal-border` | `mix(P, #ffffff, .30)` | `mix(P, #101922, .35)` |
| `--navy`, `--nav-bg` | `N` | the override, else `mix(N, #0d141b, .25)` |
| `--on-nav` (new) | `colour_text_on(nav)` | the same on the dark nav |
| `--navy-2`, `--nav-bg-2`, `--nav-active` | `mix(nav, on-nav, .08 / .08 / .14)` | same formula |
| `--nav-ink`, `--nav-ink-2`, `--on-navy` | `mix(on-nav, nav, .82 / .68)`, until ≥ 7 / 4.5 / 7 against nav | same formula |
| `--nav-hover`, `--on-navy-faint` | on-nav at alpha `0d` / `26` | on-nav at alpha `12` / `1a` |
| `--brand-ink` (new) | nav if ≥ 4.5 against `--bg`, else `--ink` | not used |
| `--teal-bright` | `H` toward on-nav until ≥ 3 against nav | the same against the dark nav |
| `--bg`, `--scrim-bg` | `B`, and `B` + `ee` | the override, else `mix(B, #101922, .06)` |

**Why no `--on-teal` token is needed.** The two rules for `--teal` keep the existing
hard-coded button text correct:

- in light mode, ≥ 4.5 against white means white on `--teal` is also ≥ 4.5;
- in dark mode, the button text is `#0b1218`, and `--teal` is held to ≥ 4.5 against it.

When a colour had to be moved, `adjusted` records it, and the „Aussehen" card shows „Für gute
Lesbarkeit verwendet: #…" beside her choice.

### How it reaches the page

- **Route.** `brand` is in `$allowed` and `$public` in `public/index.php`, answered by
  `if($page==='brand')serve_brand_css();` next to `icon` and `manifest`. It has no view.
- **Response.** `Content-Type: text/css; charset=utf-8`.
  - When `v` equals `brand_css_version()`:
    `shared_cache_control(CLUB_ASSET_MAX_AGE, true)`.
  - Otherwise: `no-cache`.
- **Layout.** `views/layout.php` prints `<link rel="stylesheet" href="brand_css_url()">`
  directly **after** `app.css`, and only when `brand_css_url() !== ''`.
- **Selectors.** The CSS repeats `app.css`'s own shape, so specificity and order decide as
  they do today:
  - the primary tokens go under `html[data-accent=brand]`, then under
    `@media(prefers-color-scheme:dark){html:not([data-theme=light])[data-accent=brand]{…}}`,
    then under `html[data-theme=dark][data-accent=brand]{…}`;
  - the other families go under `:root`, then under the same two dark selectors without the
    accent;
  - `.accent-dot.is-default{background:P}`, plus swatch classes for the settings preview.
- **`accent_for()`** tries, in order:
  1. the person's own valid accent;
  2. `'brand'`, when `colour_normalise(setting('brand_primary'))` is not null;
  3. `default_accent`;
  4. `'teal'`.

  `'brand'` is **not** added to `accents()`. „Wie eingestellt" is that choice. If the
  stylesheet fails to load, `data-accent=brand` matches no rule and the built-in teal
  applies.
- **Theme colour.** The `theme-color` meta tags in the layout, and `theme_color` /
  `background_color` in `web_manifest()`, use `brand_theme_colour()`.

### What `app.css` changes (`frontend-dev`)

- Add `--on-nav:#fff` and `--brand-ink:var(--navy)` to `:root`.
- These literal colours on the nav colour use the tokens instead:
  - `.sidebar{color:white}`
  - `.sidebar nav a[aria-current]{color:#fff}`
  - `.stat.accent{color:white}`
  - `.brand-mark{background:#fff}`
  - `.public-header .brand-mark{color:white}`
- `.public-header .brand` uses `var(--brand-ink)`.
- Nothing else changes. The personal accent blocks stay as they are.

## Rejected

**A `<style>` block or `style` attribute in the layout.** The CSP refuses it. Relaxing the
policy with `'unsafe-inline'` or a nonce weakens the defence against an escaping mistake in
any of 30 views, only to save a request.

**Writing a CSS file into `public/assets/` on save.** Updates overwrite it, it is often not
writable, and it would be a second copy of the settings.

**Making `--surface` customisable.** Every text token is tuned against it. Changing it means
a new palette, not a new colour. It needs its own ADR.

**Refusing a main, second or highlight colour that fails contrast.** A club's colours are not
hers to change. Adjusting them and showing the adjusted value is honest. Backgrounds are a
free choice, so refusing one with a reason is clearer.

**Deleting `default_accent` and folding it into `brand_primary`.** A portal that chose
„Blau" would turn teal on update.

**`tracked()` for settings, as the way back.** Settings have never been tracked
(`defaults_registry_save`, `portal_icon_save`). Tracking one group would be a second
mechanism for one card. The previous values in the save message are enough to retype.

**The header switches on the logo card.** `defaults_registry_save` writes every key of a
group from one POST. A group split across two forms blanks whichever half was not posted.

**An `--on-teal` token and a sweep of every button.** The contrast rules make it unnecessary.

**The maths inside `brand.php`.** `setting_validate()` loads earlier and needs the contrast
check, and calling forward in the load order breaks rule 5.

**Colour maths in `app.js`.** The page must be right without JavaScript.

## Consequences

- **`structure` suite:**
  - classify `brand` as public;
  - add the handler exception `brand` → `serve_brand_css`;
  - add `app/colour.php` and `app/brand.php` to the expected files.
- **A new `colour` suite:**
  - normalisation;
  - the WCAG figures for known pairs;
  - each of the eight presets as `P` yields `--teal` ≥ 4.5 against `#fff`, and dark
    `--teal` ≥ 4.5 against `#0b1218`;
  - a pale yellow `P` is adjusted and reported;
  - a tampered value such as `red;}body{display:none` produces no CSS text.
  - Break the clamp in `colour_until_contrast()` and watch it fail.
- **The `settings` or `actions` suite:** a `branding` save's flash names the previous values.
- **Must not:**
  - interpolate a raw setting value into the stylesheet; only `colour_normalise()` output
    may reach it;
  - put a `branding` key anywhere but the „Aussehen" card.
- **Maintenance mode:** the stylesheet answers 503, so the built-in colours show. Accepted.
- **`mobile-tester`:** 320 and 390 px, in light and dark, with a dark `N`, a light `N`
  (`#e8eef4`) and a pale yellow `P`.
- **`TESTING.md`:**
  - set each colour, then check the login page, a signed-in page, both schemes and the
    status-bar colour;
  - clear every colour, and confirm there is no `page=brand` request;
  - the save message names the old values, and retyping them restores the look.
- No migration and no dependency.
