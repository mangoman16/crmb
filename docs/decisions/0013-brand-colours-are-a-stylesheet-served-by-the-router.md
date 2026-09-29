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

- dark shades are computed from the light choices, and each one can be overridden later
  (empty = computed);
- the main colour becomes the portal default, and everybody can still pick a personal
  accent (`accounts.accent`, `accents()`, `accent_for()` in `app/shell.php`).

The constraints:

- `boot_http()` sends `style-src 'self'`. An inline `style` attribute or `<style>` block is
  refused by the browser (see the comment in `views/profile.php`). Colours can only reach
  the page as a stylesheet from the portal's own origin.
- The login page is public and should wear the colours too.
- `public/` is overwritten by the next update and may not be writable (ADR 0008), so the
  stylesheet cannot be written there.
- A free colour choice can make text unreadable. Every preset in `accents()` was picked so
  that white text on it stays readable, and the components rely on that. `.button` and
  `.composer-send` print `#fff` on `--teal` in light mode, and the dark block prints
  `#0b1218` on it. `a`, `.tabs`, `.chip` and `.eyebrow` use `--teal` as text on
  `--surface`.
- A portal nobody customised must look exactly as it does today and make no extra request.

## Decision

### Settings

There is one new kind, `colour`, in `app/defaults.php`. Its value is `''` (the built-in
colour) or a lower-case `#rrggbb`. There are eight new settings, all `kind colour`,
`default ''`, in a new group `branding`:

| Key | Token family | Notes |
| --- | --- | --- |
| `brand_primary` | `--teal`, `--teal-ink`, `--teal-soft`, `--teal-border`, `--focus` | the portal default accent |
| `brand_secondary` | `--navy`, `--navy-2`, `--nav-bg`, `--nav-bg-2`, `--nav-active`, `--nav-ink`, `--nav-ink-2`, `--nav-hover`, `--on-navy`, `--on-navy-faint`, plus two new tokens, `--on-nav` and `--brand-ink` | |
| `brand_highlight` | `--teal-bright` | |
| `brand_background` | `--bg`, `--scrim-bg` | `'text_on' => '#5d6e7e'` |
| `brand_primary_dark`, `brand_secondary_dark`, `brand_highlight_dark` | the same families, dark scheme | `'advanced' => true` |
| `brand_background_dark` | the same family, dark scheme | `'advanced' => true`, `'text_on' => '#9aabba'` |

- A dark override applies only while its light colour is set. The form's hint says so.
- `defaults_registry_save` gains `branding` in its `choose()` list. It is admin-only, like
  `portal`. **No new action.**
- `default_accent` stays. Its hint changes to „Gilt, solange keine eigene Hauptfarbe
  gesetzt ist." Existing portals that chose a preset keep it until a main colour is set.

`setting_validate()` gains `case 'colour'`:

- empty returns `''`;
- otherwise `colour_normalise()` accepts `#rgb`, `#rrggbb`, with or without `#`, in any
  case, and returns lower-case `#rrggbb`, or the save is refused with „Bitte eine Farbe wie
  #1f5fa9 eingeben.";
- if the spec has `text_on`, a colour whose contrast with that text colour is below 4.5:1 is
  refused. The message says the grey text on it would be hard to read. Only backgrounds are
  refused. The brand colours are adjusted instead (see below), because they are often a
  club's fixed colours and she cannot change them.

The form control is a text field (`maxlength 7`). `<input type=color>` cannot be empty, so
it cannot express "built-in". `app.js` may add a native picker that writes into the text
field, as an enhancement only.

### Where the code lives

**`app/colour.php`, new, pure.** It has no database, no settings and no IO. It is required
directly after `validate.php`, because `setting_validate()` in `defaults.php` calls it and
nothing it needs comes later. It holds:

- `colour_normalise(string): ?string`
- `colour_luminance(string): float`, the WCAG relative luminance
- `colour_contrast(string, string): float`
- `colour_mix(string $a, string $b, float $weightOfA): string`
- `colour_text_on(string $bg): string`. It returns `#ffffff` or `#0b1218`, whichever
  contrasts more.
- `colour_until_contrast(string $c, array $against, float $min, string $toward): string`.
  It steps `$c` toward `#000000` or `#ffffff` until it reaches `$min` against every colour
  in `$against`.

**`app/brand.php`, new.** It is required directly after `uploads.php` and **before**
`portal_icon.php`, because `web_manifest()` there reads `brand_theme_colour()`. It needs
`setting()`, the `colour_*` functions, and the cache helpers in `uploads.php`. Nothing
earlier calls it. It holds:

- `brand_palette(): array`, memoised. It returns `['light'=>[token=>hex], 'dark'=>[...],
  'adjusted'=>[key=>['chosen'=>…, 'used'=>…]]]`.
  - Every setting is re-normalised on read. A value that does not normalise counts as `''`,
    so a tampered or old row falls back to built-in and never reaches the CSS.
- `brand_css(): string`
- `brand_css_version(): string`, the first 12 hex characters of
  `sha256(app_version() . json of the eight settings)`. `app_version()` is included so a
  release that changes the derivation also changes the address.
- `brand_css_url(): string`. It returns `''` when all eight settings are empty.
- `serve_brand_css(): never`
- `brand_theme_colour(string $scheme): string`. For `light` it returns the effective nav
  colour (today `#13243a`). For `dark` it returns the effective dark background (today
  `#101922`).
- The logo functions of ADR 0014.

`shared_cache_control()` moves from `portal_icon.php` to `uploads.php`, next to
`send_cache_control()`. It is a generic helper, and `brand.php` loads before
`portal_icon.php`.

### Derivation

`P`, `N`, `H` and `B` are the chosen colours. For the dark scheme, each is its `_dark`
override if set, otherwise the light choice. The surfaces are fixed: `#ffffff` in light,
`#182430` in dark, as today.

| Token | Light | Dark |
| --- | --- | --- |
| `--teal`, `--focus` | `P` toward black until ≥ 4.5 against `#ffffff` | `P` toward white until ≥ 4.5 against `#182430` **and** `#0b1218` |
| `--teal-ink` | `--teal` toward black until ≥ 4.5 against `--teal-soft` | `--teal` toward white until ≥ 7 against `#182430` |
| `--teal-soft` | `mix(P, #ffffff, .12)` | `mix(P, #101922, .18)` |
| `--teal-border` | `mix(P, #ffffff, .30)` | `mix(P, #101922, .35)` |
| `--navy`, `--nav-bg` | `N` | override, else `mix(N, #0d141b, .25)` |
| `--on-nav` (new) | `colour_text_on(nav)` | the same on the dark nav |
| `--navy-2`, `--nav-bg-2`, `--nav-active` | `mix(nav, on-nav, .08 / .08 / .14)` | the same formula |
| `--nav-ink`, `--nav-ink-2`, `--on-navy` | `mix(on-nav, nav, .82 / .68)`, until ≥ 7 / 4.5 / 7 against nav | the same formula |
| `--nav-hover`, `--on-navy-faint` | on-nav at alpha `0d` / `26` | on-nav at alpha `12` / `1a` |
| `--brand-ink` (new) | nav if ≥ 4.5 against `--bg`, else `--ink` | not used |
| `--teal-bright` | `H` until ≥ 3 against nav, toward on-nav | the same against the dark nav |
| `--bg`, `--scrim-bg` | `B`, `B` + `ee` | override, else `mix(B, #101922, .06)` |

The two contrast rules for `--teal` are what let the existing hard-coded button text stay
correct:

- in light mode, ≥ 4.5 against white means white on `--teal` is ≥ 4.5;
- in dark mode, the button text is `#0b1218`.

So **no `--on-teal` token is needed.** When a colour had to be moved, `adjusted` records
it, and the Settings card shows „Für gute Lesbarkeit verwendet: #…" beside her choice. She
is never silently shown a different colour.

### How it reaches the page

- **Route `brand`.** It is in `$allowed` and `$public` in `public/index.php`, answered by
  one line `if($page==='brand')serve_brand_css();` next to the `icon` and `manifest` lines.
  It has no view.
- **Response.** `Content-Type: text/css; charset=utf-8`. When `v` equals
  `brand_css_version()`: `shared_cache_control(VERSIONED_MAX_AGE, true)`. Otherwise
  `no-cache`.
- **Layout.** `views/layout.php` prints `<link rel="stylesheet" href="brand_css_url()">`
  directly **after** `app.css`, only when `brand_css_url() !== ''`. An uncustomised portal
  makes no extra request.
- **Selectors.** The CSS repeats `app.css`'s own shape, so specificity and order decide the
  same way they do today:
  - primary tokens under `html[data-accent=brand]`, then
    `@media(prefers-color-scheme:dark){html:not([data-theme=light])[data-accent=brand]{…}}`,
    then `html[data-theme=dark][data-accent=brand]{…}`;
  - the other families under `:root`, then the same two dark selectors without the accent;
  - `.accent-dot.is-default{background:P}`, so „Wie eingestellt" on the profile page shows
    the real colour;
  - swatch classes for the Settings preview.
- **`accent_for()`.** The order is: the person's own valid accent, then `'brand'` when
  `colour_normalise(setting('brand_primary'))` is not null, then `default_accent`, then
  `'teal'`.
  - `'brand'` is **not** added to `accents()`, so nobody can pick it as a personal accent.
    „Wie eingestellt" is that choice.
  - If the stylesheet fails to load, `data-accent=brand` matches no rule and the page falls
    back to the built-in teal.
- **`theme-color` and the manifest.** The `theme-color` meta tags in the layout, and
  `theme_color` / `background_color` in `web_manifest()`, use `brand_theme_colour()`.

### What `app.css` changes (`frontend-dev`)

- Add `--on-nav:#fff` and `--brand-ink:var(--navy)` to `:root`.
- Replace the literal colours that sit on the nav colour with those tokens:
  - `.sidebar{color:white}`
  - `.sidebar nav a[aria-current]{color:#fff}`
  - `.stat.accent{color:white}`
  - `.brand-mark{background:#fff}`
  - `.public-header .brand-mark{color:white}`
- `.public-header .brand{color:var(--navy)}` becomes `var(--brand-ink)`.

Nothing else in `app.css` changes. The personal accent blocks stay as they are.

## Rejected

**A `<style>` block or `style` attribute in the layout.** The CSP refuses it. Relaxing the
policy with `'unsafe-inline'` or a per-request nonce weakens the one defence against an
escaping mistake in any of 30 views, and only to save a request.

**Writing a CSS file into `public/assets/` on save.** It is overwritten by updates, it is
often not writable, and it is a second copy of the settings that can disagree with them.

**Making `--surface` customisable.** Every text token (`--ink`, `--muted`, `--red`, the
badges) is tuned against it. Changing it is changing the whole palette, not a colour. It can
be reopened with its own ADR.

**Refusing a main, second or highlight colour that fails contrast.** A club's colours are
not hers to change. Adjusting them and showing the adjusted value is honest and keeps her
working. Backgrounds are different: a background is a free choice, and refusing one with a
reason is clearer than quietly darkening her text.

**Deleting `default_accent` and folding it into `brand_primary`.** A portal that chose
„Blau" would turn teal on update. That breaks "unchanged unless customised".

**An `--on-teal` token and a sweep of every button.** The two contrast rules make it
unnecessary.

**A `colour` kind without `app/colour.php`, with the maths inside `brand.php`.**
`setting_validate()` loads earlier and needs the contrast check. Calling forward in the load
order breaks the rule that load order is the dependency graph.

**Colour maths in `app.js`.** The page must be right without JavaScript, and the login page
wears the colours.

## Consequences

- **`structure` suite:**
  - `brand` is classified `public`;
  - its handler exception is `serve_brand_css`;
  - `app/colour.php` and `app/brand.php` are added to the expected files.
- **A new `colour` suite (`qa-tester`):**
  - normalisation;
  - the WCAG figures against known pairs;
  - for each of the eight presets, `brand_palette()` with it as `P` gives
    `colour_contrast(--teal, #fff) ≥ 4.5` and dark `--teal` ≥ 4.5 against `#0b1218`;
  - a pale yellow `P` is adjusted and reported in `adjusted`;
  - a tampered setting value such as `red;}body{display:none` produces no CSS text.
  - Break the clamp in `colour_until_contrast()` and watch these fail.
- `brand_css()` builds its text only from `colour_normalise()` output. **Implementers must
  not** interpolate a raw setting value into the stylesheet.
- In maintenance mode the stylesheet answers 503 and the page shows the built-in colours.
  This is accepted, as for the icon.
- **`mobile-tester`:** measure 320 and 390 px in light and dark, with a dark `N`, a light
  `N` (for example `#e8eef4`), and a pale yellow `P`.
- **`TESTING.md`:**
  - set each colour, check the login page and a signed-in page in both schemes, and check
    the phone's status-bar colour;
  - clear every colour and confirm the page makes no `page=brand` request.
- No migration and no dependency.
