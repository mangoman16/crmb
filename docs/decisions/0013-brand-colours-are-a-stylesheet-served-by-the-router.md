---
status: accepted, amended by f5d3c28
date: 2026-09-29
---

# 0013. Brand colours are a generated stylesheet, served by the router

> **Amended 2026-10-07, by the design language.** Commit `f5d3c28` ("The portal looks and
> behaves like an iOS app…") built Part 0 of
> `docs/design/2026-10-07-ios-design-language-and-goal-screens.md`; there is no ADR for it. The
> portal is now white groups on a grey ground in light and dark groups on black in dark, so the
> colours the derivation works against changed. What no longer holds, and what holds instead:
>
> - **Context, the main colour.** `--teal-border` is gone. The family is `--teal`, `--teal-ink`,
>   `--teal-soft` and `--focus`.
> - **Context, the second colour.** Neither `.stat.accent` nor the browser's bar takes it any
>   more. It colours the side menu, and the brand mark and the club's name on the sign-in page.
> - **Settings table.** Three built-in colours changed: `brand_primary` is `#06736c`,
>   `brand_background` is `#f2f2f7` with `'text_on' => '#636366'`, and `brand_background_dark`
>   is `#000000` with `'text_on' => '#aeaeb2'`. The other five stand.
> - **Fixed colours in `app/brand.php`.** `BRAND_SURFACE_LIGHT` and `BRAND_SURFACE_DARK` are
>   gone. `BRAND_SURFACES_LIGHT` is `['#ffffff', '#f2f2f7']`, the white group and the grey ground
>   around it; `BRAND_SURFACES_DARK` is `['#1c1c1e', '#2c2c2e']`, the dark group and a field
>   inside it. A soft tint is mixed into the first of each pair. `BRAND_INK_LIGHT` is `#000000`.
>   `BRAND_NAV_SHADE` stands.
> - **Derivation.** `G` is `#000000`. In the table:
>   - `--teal`, `--focus`: light, `P` darker until ≥ 4.5 against `#ffffff` and `#f2f2f7`; dark,
>     `P` lighter until ≥ 4.5 against `#1c1c1e`, `#2c2c2e` and `COLOUR_DARK_INK`.
>   - `--teal-ink`, dark: `--teal` lighter until ≥ 7 against `#1c1c1e`, `#2c2c2e` and
>     `--teal-soft`. The light rule stands.
>   - `--teal-soft`, dark: `mix(P, #1c1c1e, .18)`, into the dark group as light mixes into the
>     white one. At `f5d3c28` it was mixed into `G`; frontend-dev's change after it moves it.
>   - The `--teal-border` row is gone, and so are `--navy-2`, `--nav-bg-2`, `--on-navy` and
>     `--on-navy-faint`. Of their rows, `--nav-active` (`mix(on-nav, nav, .14)`), `--nav-ink`,
>     `--nav-ink-2` and `--nav-hover` (on-nav + `0d` light, `12` dark) stand.
>   - `--brand-ink` still falls back to `BRAND_INK_LIGHT`, now `#000000`.
> - **Browser colours.** `brand_theme_colour()` returns the ground, not the menu colour: what
>   `brand_background` and `brand_background_dark` end up as, `#f2f2f7` and `#000000` unless the
>   club set a background, which the `--bg` row then works out. The phone's status bar runs into
>   the portal's own bars without a band (Part 0.5). The manifest's `theme_color` and
>   `background_color` are therefore the same colour.
>
> Everything else here stands: the eight settings and the „Aussehen" card, `app/colour.php`,
> adjusting lightness only, the route and its caching, and what may leave as CSS.

## Context

The owner wants to set the portal's colours in Settings:

- a **main colour**, today the `--teal` family (`--teal`, `--teal-ink`, `--teal-soft`,
  `--teal-border`, `--focus`);
- a **second colour**, today `--navy` and the `--nav-*` family (sidebar, `.stat.accent`, the
  public header's name);
- a **third colour**, today `--teal-bright` (the dot on the brand mark, the active-menu
  marker);
- a **background colour**, today `--bg`.

She decided:

- dark shades are computed from the light choices, and each can be overridden later (empty
  means computed);
- the main colour becomes the portal default, and everybody can still pick a personal accent
  (`accounts.accent`, `accents()`, `accent_for()` in `app/shell.php`).

The constraints:

- `boot_http()` sends `style-src 'self'`. An inline `style` attribute or `<style>` block is
  refused by the browser (see the comment in `views/profile.php`). Colours can only reach
  the page as a stylesheet from the portal's own origin.
- The login page is public and should wear the colours too.
- `public/` is overwritten by the next update and may not be writable (ADR 0008).
- A free colour choice can make text unreadable. Every preset in `accents()` was picked so
  that white text on it stays readable.
- A portal nobody customised must look exactly as it does today and make no extra request.
- Settings are not `tracked()`, so a mis-set colour has no undo in the change log.

## Decision

### Settings

There is one new kind, `colour`, in `app/defaults.php`. Its value is `''` (the built-in
colour) or a lower-case `#rrggbb`.

- **`builtin`.** A `colour` setting declares its built-in colour once, as `'builtin' =>
  '#…'`. The form shows it as the placeholder, a refusal names it, and `brand_palette()`
  uses it for an empty family.
- **No second copy.** No constant in `brand.php` repeats a built-in value. An earlier
  `BRAND_GROUND_DARK` is gone: the dark scheme shades toward the `builtin` of
  `brand_background_dark`.

There are eight settings, all `kind colour`, `default ''`, group `branding`:

| Key | `builtin` | Notes |
| --- | --- | --- |
| `brand_primary` | `#077e76` | the portal default accent |
| `brand_secondary` | `#13243a` | menu colour |
| `brand_highlight` | `#23cbbb` | |
| `brand_background` | `#f3f6f9` | `'text_on' => '#5d6e7e'` |
| `brand_primary_dark` | `#3fd0bd` | advanced |
| `brand_secondary_dark` | `#131d27` | advanced |
| `brand_highlight_dark` | `#3fd0bd` | advanced |
| `brand_background_dark` | `#101922` | advanced, `'text_on' => '#9aabba'` |

- A dark override applies only while its light colour is set. The form's hint says so.
- The same group holds `header_hide_name` and `header_hide_subtitle` (ADR 0014).
- The whole group is one form, the **„Aussehen" card**, saved by `defaults_registry_save`.
  That action gains `branding` in its `choose()` list and is admin-only. **No new action.**
  It writes every key of a group from one POST, so every `branding` key must be on that
  card.
- `default_accent` stays. Its hint says it applies only while no main colour is set, so a
  portal that chose „Blau" is unchanged.

**The way back.** A `branding` save's flash lists the values it replaced, for example
„Gespeichert. Vorher: Hauptfarbe #077e76, Zweitfarbe Standard, …". This sentence is the
undo, because settings are not `tracked()`. The exact wording is the ui-ux-designer's.

**Validation.** `setting_validate()` has a `case 'colour'`:

- `colour_normalise()` accepts `#rgb` or `#rrggbb`, with or without `#`, in any case, and
  returns lower-case `#rrggbb`.
- `setting_colour_refusal($spec, $colour)` refuses a `text_on` colour that is below 4.5:1
  against that text. Its message names the built-in value and says nothing was saved.
- Only backgrounds are refused. The brand colours are adjusted instead.
- `brand_chosen()` applies the same refusal on every read. A stored background that fails it
  counts as unset, so a row written by hand or by an older version never reaches the page.

**Form control.** A text field. `<input type=color>` cannot be empty. `app.js` may add a
picker as an enhancement.

### Where the code lives

**`app/colour.php`, pure.** It is required directly after `validate.php`, because
`setting_validate()` needs it. It holds:

- `COLOUR_DARK_INK` (`#0b1218`), the ink the dark scheme prints on the main colour;
- `colour_normalise()`, `colour_channels()`, `colour_luminance()`, `colour_contrast()`,
  `colour_mix()` (weight clamped to 0..1), `colour_text_on()`, `colour_contrasts_with_all()`;
- `colour_to_hsl()` and `colour_from_hsl()`;
- `colour_until_contrast(string $colour, array $against, float $min, string $direction)`.
  `$direction` is `'darker'` or `'lighter'`; anything else throws.

**How a colour is adjusted: lightness only.** `colour_until_contrast()` keeps hue and
saturation in HSL and moves only the lightness toward 0 or 1.

- It moves in 512 steps and returns the first colour that reaches `$min` against every
  colour in `$against`. Step 0 is the colour itself, so a readable colour is returned
  unchanged.
- If even black or white does not reach `$min`, it returns that end rather than the colour
  that failed.
- **Changed after implementation.** The first method mixed toward black or white. It turned
  a pale yellow into grey-olive (`#fdf6b2` → `#7b7756`). She would not recognise that as her
  colour. Keeping hue and saturation gives a dark mustard.
- **HSL, not OKLCH.** At a fixed hue and saturation, every HSL lightness is a colour a
  screen can show, so nothing is ever clipped. In OKLCH, clipping back into range shifts
  the hue of exactly the saturated club colours this is for.

**`app/brand.php`.** It is required after `uploads.php` and before `portal_icon.php`.

- **Fixed colours**, none of them hers to choose: `BRAND_SURFACE_LIGHT` (`#ffffff`),
  `BRAND_SURFACE_DARK` (`#182430`), `BRAND_NAV_SHADE` (`#0d141b`), `BRAND_INK_LIGHT`
  (`#172d42`).
- **Choices:** `brand_families()`, and `brand_chosen()` (normalised and refusal-checked on
  every read).
- **`brand_palette()`**, memoised per set of choices. It returns:
  - `light` and `dark`: token → colour, only for customised families;
  - `used`: the colour each setting ends up as;
  - `placeholder`: what empty means, the builtin or the colour computed from the light
    choice;
  - `adjusted`: `[chosen, used]` where readability moved her own choice.
- **Derivation helpers:** `brand_scheme_light()`, `brand_scheme_dark()`,
  `brand_nav_tokens()`, and `brand_toward($onNav)`, which gives `'lighter'` when white
  reads on the menu colour and `'darker'` otherwise.
- **Stylesheet:** `brand_css_rule()`, `brand_css()`, `brand_css_version()`,
  `brand_css_url()`, `brand_css_cache_control()`, `serve_brand_css()`,
  `brand_swatch_class()`.
- **Browser colours:** `brand_theme_colour($scheme)`, and `brand_background_colour()` for
  the manifest's `background_color`.
- The logo functions of ADR 0014.

`shared_cache_control()`, `send_cache_control()` and **`CLUB_ASSET_MAX_AGE`** (31536000)
live in `app/uploads.php`.

### Derivation

`P`, `N`, `H` and `B` are the chosen light colours. For the dark scheme, `P` and `H` are
their overrides if set, otherwise the light choice. `G` is the `builtin` of
`brand_background_dark` (`#101922`).

| Token | Light | Dark |
| --- | --- | --- |
| `--teal`, `--focus` | `P` darker until ≥ 4.5 against `#ffffff` | `P` lighter until ≥ 4.5 against `#182430` and `COLOUR_DARK_INK` |
| `--on-accent` | `#ffffff` | `COLOUR_DARK_INK` |
| `--teal-soft` | `mix(P, #ffffff, .12)` | `mix(P, G, .18)` |
| `--teal-border` | `mix(P, #ffffff, .30)` | `mix(P, G, .35)` |
| `--teal-ink` | `--teal` darker until ≥ 4.5 against `--teal-soft` | `--teal` lighter until ≥ 7 against `#182430` and `--teal-soft` |
| `--navy`, `--nav-bg` | `N` | the override, else `mix(N, BRAND_NAV_SHADE, .25)` |
| `--on-nav` | `colour_text_on(nav)` | the same |
| `--navy-2`, `--nav-bg-2` / `--nav-active` | `mix(on-nav, nav, .08 / .14)` | the same |
| `--nav-ink` (= `--on-navy`) / `--nav-ink-2` | `mix(on-nav, nav, .82 / .68)`, then `brand_toward` until ≥ 7 / 4.5 against nav | the same |
| `--nav-hover`, `--on-navy-faint` | on-nav + `0d` / `26` | on-nav + `12` / `1a` |
| `--teal-bright` | `H`, `brand_toward` until ≥ 3 against nav | the same, against the dark nav |
| `--bg`, `--scrim-bg` | `B`, `B`+`ee` | the override, else `mix(B, G, .06)` |
| `--brand-ink` | nav if ≥ 4.5 against bg, else `BRAND_INK_LIGHT` | not set |

**Button text.** `--on-accent` is the token for text on the main colour. The two rules for
`--teal` guarantee that white reads on it in light mode, and that the dark ink reads on it
in dark mode.

**Adjusted colours.** When her colour was moved, the „Aussehen" card shows „Für gute
Lesbarkeit verwendet: #…" beside it.

### How it reaches the page

- **Route `brand`.** It is public, answered by `serve_brand_css()` next to `icon` and
  `manifest`, and has no view.
  - It sends `text/css; charset=utf-8`.
  - When `v` equals `brand_css_version()`, it sends
    `shared_cache_control(CLUB_ASSET_MAX_AGE, true)`. Otherwise it sends `no-cache`.
  - With nothing customised, it serves an empty stylesheet, not an error.
- **The version.** `brand_css_version()` is the first 12 characters of `sha256(brand_css())`,
  the stylesheet's own bytes. *(Amended 2026-10-01: it was the release number plus her choices,
  and the release number did not change when the colour calculation did, so a year-long cache
  kept the old colours.)*
- **When there is no request.** `brand_css_url()` is `''` when no light colour is set. A dark
  override alone does not apply yet, so it costs no request.
- **Where it loads.** The layout links the stylesheet directly after `app.css`, only when the
  URL is not empty.
- **Selectors.** `brand_css()` uses `app.css`'s own selectors:
  - the primary tokens (including `--on-accent`) go under `html[data-accent=brand]`;
  - the rest go under `:root`;
  - both are repeated under the two dark selectors;
  - it adds `.accent-dot.is-default` and the swatch classes for the „Aussehen" card.
- **Nothing but colours leaves.** `brand_css_rule()` prints a declaration only when its name
  is a token or `background`, and its value matches `#rrggbb` (optionally with alpha).
- **`accent_for()`** tries, in order:
  1. the person's own accent;
  2. `'brand'` when a main colour is set;
  3. `default_accent`;
  4. `'teal'`.

  `'brand'` is not in `accents()`. If the stylesheet fails to load, no rule matches
  `data-accent=brand` and the built-in teal shows.
- **Browser colours.** The `theme-color` tags use `brand_theme_colour()`. The manifest uses
  `brand_theme_colour('light')` and `brand_background_colour()`.

### What `app.css` carries (`frontend-dev`)

- `--on-accent`, `--on-nav` and `--brand-ink` tokens with the built-in values.
- The literal white or `#0b1218` on the main colour and on the menu colour replaced by
  those tokens.
- `.public-header .brand` in `--brand-ink`.
- The personal accent blocks are unchanged.

## Rejected

**A `<style>` block or `style` attribute.** The CSP refuses it. `'unsafe-inline'` or a nonce
weakens the defence against an escaping mistake in 30 views.

**Writing a CSS file into `public/assets/`.** Updates overwrite it, it is often not writable,
and it would be a second copy of the settings.

**Making `--surface` customisable.** Every text token is tuned against it. It needs its own
ADR.

**Refusing a brand colour that fails contrast.** A club's colours are not hers to change.
Backgrounds are a free choice, so a refusal with a reason is clearer there.

**Mixing toward black or white to adjust.** It desaturates. A pale yellow became grey-olive
(`#fdf6b2` → `#7b7756`).

**OKLCH lightness.** It needs gamut clipping, which shifts the hue of saturated colours.

**A second constant for a built-in colour (`BRAND_GROUND_DARK`).** It is the same value as
`brand_background_dark`'s `builtin`, and two copies drift.

**Deleting `default_accent`.** A portal that chose „Blau" would turn teal on update.

**`tracked()` for settings.** Settings have never been tracked. The previous values in the
flash are enough to retype.

**The header switches on the logo card.** Their group is saved from one POST. A group split
across two forms blanks the half that was not posted.

**Colour maths in `brand.php` or `app.js`.** `setting_validate()` loads earlier, and the page
must be right without JavaScript.

## Consequences

- **`structure`:**
  - `brand` is public, with the handler `serve_brand_css`;
  - `app/colour.php` and `app/brand.php` are in the expected files.
- **The `colour` suite:**
  - normalisation;
  - the WCAG figures;
  - each preset as `P` meets both `--teal` rules;
  - `#fdf6b2` keeps its hue and saturation (within rounding), and meets 4.5 against white;
  - an unknown direction throws;
  - a colour no lightness can rescue returns black or white;
  - a tampered setting produces no CSS text.
  - Break each rule once and watch the suite fail.
- **Settings:** a `branding` save's flash names the previous values.
- **Must not:**
  - print a raw setting value into the stylesheet;
  - repeat a built-in colour outside `builtin`;
  - put a `branding` key anywhere but the „Aussehen" card.
- **Maintenance mode:** the stylesheet answers 503, and the built-in colours show. Accepted.
- **`mobile-tester`:** 320 and 390 px, light and dark, with a dark `N`, a light `N`
  (`#e8eef4`) and `#fdf6b2` as `P`.
- **`TESTING.md`:**
  - each colour set, on the login page and a signed-in page, in both schemes, with the
    status bar;
  - clearing every colour makes no `page=brand` request;
  - retyping the „Vorher" values restores the look.
- No migration and no dependency.
