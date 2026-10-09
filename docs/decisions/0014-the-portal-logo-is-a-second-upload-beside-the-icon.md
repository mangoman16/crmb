---
status: accepted
date: 2026-09-29
---

# 0014. The portal logo is a second upload beside the icon

> **Amended on 2026-10-08: the built-in mark is a shuttlecock.** Where this record says „B" (the
> mark the logo replaces, the last step of the fallback, and the walk in `TESTING.md`), read the
> shuttlecock that the design document's Part 0, C16a, makes the portal's own mark, drawn by
> `icon('shuttle')` and `favicon.svg`. A club's own logo or icon still replaces it, in the same
> order: logo, then icon, then the built-in mark. Everything else stands.

## Context

The owner wants her own logo top left, replacing the „B" mark in `.brand`. The mark appears
twice in `views/layout.php`: in the sidebar and in `.public-header`.

She chose a **separate** upload from the portal icon of ADR 0008. It may be wide, not only
square, and it may be PNG, JPEG or WebP. The fallback order is logo, then portal icon, then
„B".

She also wants two switches:

- hide the portal name;
- hide the „Verwaltung" / „Mein Portal" line under it. This line is not `portal_tagline`.

The icon's constraints carry over:

- the file is stored under `storage/`;
- the route is public, because the login page shows it;
- there is no GD;
- SVG is not accepted;
- `getimagesize()` reads only the header.

Two constraints are new:

- The sidebar is the menu colour: dark by default, and brand-chosen under ADR 0013. The
  public header is the light page background. A logo drawn for one is invisible on the
  other.
- The logo is loaded by every sign-in page. The general upload limit (`upload_max_kb`,
  default 4 MB) is far more than a header picture needs on a phone connection.

## Decision

**Storage.**

- The upload kind is `logo`, stored in `storage/uploads/logo/`.
- `upload_types('logo')` accepts PNG, JPEG and WebP.
- `portal_logo_types()` maps each extension to its `IMAGETYPE_*`.
- The setting is `portal_logo`: `kind raw`, `internal`, group `portal`, default `''`.
- There is no migration.

**Code, in `app/brand.php`:**

- **Constants:**
  - `PORTAL_LOGO_MIN_HEIGHT` 88 (44 px drawn, at 2×);
  - `PORTAL_LOGO_MAX_SIDE` 2048;
  - **`PORTAL_LOGO_MAX_BYTES` 1048576 (1 MB)**;
  - `PORTAL_LOGO_MAX_RATIO` 5.0 and `PORTAL_LOGO_MIN_RATIO` 0.5.
- `portal_logo()`, `portal_logo_url()` (`v` = `upload_version()`), and
  `portal_logo_size()` (memoised, for the `width` and `height` attributes).
- `check_portal_logo()`. It refuses the upload and deletes the file, checking in this order:
  1. **more than 1 MB.** This is checked first: however well formed, a larger file is not
     what every sign-in page should load. The message gives the size with
     `megabytes_label($bytes, true)`, which rounds up so that a file one byte over reads
     „1,1 MB", not the „1,0 MB" the form allows. It states the limit with
     `upload_limit_label(PORTAL_LOGO_MAX_BYTES)`.
  2. unreadable, or a type that does not match the extension;
  3. too short;
  4. too large;
  5. too wide or too tall.

  Each message names what it measured and what is needed.
- `portal_logo_shape_hint()` and `portal_logo_times()` produce the shape limits in words, for
  the card and for the messages.
- `serve_portal_logo()`, with `portal_logo_cache_control()`:
  - when `v` is current: `shared_cache_control(CLUB_ASSET_MAX_AGE, true)`;
  - otherwise: `no-cache`;
  - with no logo: a 404 with `no-cache`.
- `brand_header(?array $user)`, the one place the fallback and the switches are decided. It
  returns `logo`, `logo_width`, `logo_height`, `icon`, `name`, `subtitle`, `show_name` and
  `show_subtitle`.
  - `show_name` is false only when `header_hide_name` is on **and** a logo or icon is shown.

**In `app/uploads.php`:**

- `megabytes_label(int $bytes, bool $roundUp = false)` is the one formatter for a size in
  MB. The limit a form states and the size a refusal names read alike.
- `upload_limit_label(?int $cap = null)` gives the smaller of the server's limit and a
  kind's own cap. A form never promises more than its check accepts.

**Switches.** `header_hide_name` and `header_hide_subtitle` are `kind bool`, group
`branding`. They are on the „Aussehen" card with the colours, saved by
`defaults_registry_save`, and **not on the logo card**. That action writes every key of a
group from one POST.

**Route.** `logo` is public, answered by `serve_portal_logo()` beside `icon`, `manifest` and
`brand`.

**Change and removal.** `portal_logo_save` sits in `app/actions_settings.php` after
`portal_icon_save`, in the same order:

1. `require_admin()`
2. `store_upload()`
3. `check_portal_logo()`
4. `set_setting()`
5. `audit()`
6. `delete_upload()` of the old file

It is not `tracked()`.

**Sweep.** `upload_references()['logo']` uses the same `REPLACE` query as the icon.

**Layout.**

- **The logo** is `<img class="brand-logo" src width height alt="">`, always with an empty
  `alt`. **PM decision.**
- **The club's name is always in the markup.** When hidden, it is kept as `visually-hidden`
  text. A screen reader hears the name once, from the text, and never twice.
- **The icon** fills the 44 px `.brand-mark` box, without the highlight dot.
- **The plate.** The logo sits on a fixed white plate, in the sidebar and in the public
  header, in both schemes.

## Rejected

**Generalising `app/portal_icon.php`.** An upload over the install would leave the old file
on every server.

**Reusing the icon upload.** The icon must be a square PNG for iOS.

**SVG and GIF.** SVG for the reasons in ADR 0008. GIF was not asked for, and it animates.

**The general upload limit for the logo.** A 4 MB header picture on every sign-in page, over
a phone connection.

**`alt` set to the club's name, with the name hidden.** The name would then exist in two
forms that can disagree. The visible or `visually-hidden` text is the single one, and the
picture is decoration beside it.

**A second logo for dark backgrounds.** The plate solves the same problem. It can be added
later.

**Resizing or cropping.** It needs GD.

**The switches on the logo card.** The `branding` group is saved from one POST.

**Serving through `page=download`.** That page is not public.

## Consequences

- **`structure`:**
  - `logo` is public, with the handler `serve_portal_logo`;
  - `portal_logo_save` is in the refuses-before-it-writes orderings.
- **`uploads`:**
  - `logo` is swept, and the live one survives;
  - `check_portal_logo()` refuses a file over 1 MB before reading it as a picture, and names
    it as „1,1 MB" when it is one byte over;
  - it refuses a JPEG named `.png`, an image 40 px tall, and a 6:1 banner;
  - it accepts a 600×150 WebP;
  - `upload_limit_label(PORTAL_LOGO_MAX_BYTES)` never exceeds the server's limit.
- **`views`:** the logo's `alt` is empty, and the club name is present, as visible or
  `visually-hidden` text, with every combination of switch, logo and icon.
- **`TESTING.md`:**
  - a wide PNG, an iPhone JPEG over 1 MB (refused, with its size) and a WebP;
  - the sidebar in light and dark, the login page, and the phone bar at 320 px;
  - VoiceOver reads the club name once;
  - removing the logo brings back the icon, then „B".
- No migration and no dependency.
