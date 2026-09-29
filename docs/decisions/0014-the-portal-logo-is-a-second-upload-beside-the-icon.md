---
status: accepted
date: 2026-09-29
---

# 0014. The portal logo is a second upload beside the icon

## Context

The owner wants her own logo top left, replacing the „B" mark in `.brand`. That mark
appears in two places in `views/layout.php`: the sidebar and `.public-header`.

She chose a **separate** upload from the portal icon of ADR 0008:

- it may be wide, not only square;
- it may be PNG, JPEG or WebP.

The fallback order is logo, then portal icon, then „B". She also wants two switches:

- hide the portal name;
- hide the „Verwaltung" / „Mein Portal" line under it.

That line is not `portal_tagline`, which is the top bar's `.topbar-context`.

The icon's constraints from ADR 0008 all carry over:

- the file lives under `storage/`;
- the route must be public, because the login page shows it;
- there is no GD;
- no SVG;
- `getimagesize()` reads the header only.

There is one new constraint. The sidebar is the nav colour, which is dark by default and
brand-chosen from ADR 0013. The public header is the page background, which is light. A
logo drawn for one of them is invisible on the other.

## Decision

**Storage.**

- The upload kind is `logo`: `store_upload('logo','logo')`, stored in
  `storage/uploads/logo/`.
- `upload_types('logo')` is `['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp']`.
- The setting is `portal_logo`, `kind raw`, `internal`, group `portal`, default `''`.
- There is no migration.

**Code.** These functions go in `app/brand.php` (ADR 0013). They are the club's look, like
the colours, and do not belong in the icon's file:

- `portal_logo(): string`. It returns the stored name, or `''` when none is set, when the
  name does not match `^[a-f0-9]{32}\.(png|jpg|webp)$`, or when the file is missing.
- `portal_logo_url(): string`. It returns `url('logo', ['v' => upload_version($name)])`,
  or `''`.
- `portal_logo_size(): array`. It returns `[w, h]` from `getimagesize()`, memoised, so the
  `<img>` carries `width` and `height` attributes. Attributes, not a `style`: the CSP
  refuses a style.
- `check_portal_logo(string $storedName): void`. It refuses and deletes the file when:
  - `getimagesize()` cannot read it;
  - its type does not match the stored extension;
  - its height is under `PORTAL_LOGO_MIN_HEIGHT` (88, which is 44 px shown at 2×);
  - either side is over `PORTAL_LOGO_MAX_SIDE` (2048, the same memory reasoning as the
    icon);
  - its width-to-height ratio is outside 1:2 to 5:1.

  Each message names the measured size and what is needed, as `check_portal_icon()` does.
  `ui-ux-designer` may tune the three numbers. The mechanism stays.
- `serve_portal_logo(): never`. It sends inline, with the MIME type found through
  `upload_types('logo')` from the extension.
  - When `v` is current: `shared_cache_control(VERSIONED_MAX_AGE, true)`.
  - Otherwise: `no-cache`.
  - With no logo: 404 with `no-cache`. The layout never links it in that case, so there is
    no built-in logo to fall back to.
- `brand_header(?array $user): array`. It returns
  `['logo'=>url|'', 'icon'=>url|'', 'show_name'=>bool, 'show_subtitle'=>bool]` and is the
  one place the fallback and the switches are decided.
  - `show_name` is false only when `header_hide_name` is on **and** a logo or icon is
    shown. With only „B", the name always shows, or the header would say nothing.

**Switches.** Two settings: `header_hide_name` and `header_hide_subtitle`, both `kind bool`,
default `false`, group `branding` (ADR 0013). They are saved by `defaults_registry_save`,
so no new action is needed for them.

**Route.** `logo` is in `$allowed` and `$public`, with one line
`if($page==='logo')serve_portal_logo();` beside `icon`, `manifest` and `brand`. It has no
view.

**Change and removal.** A new action, `portal_logo_save`, goes in `app/actions_settings.php`
directly after `portal_icon_save`. It is the same function body with `logo` for `icon`, in
the same order:

1. `require_admin()`
2. `store_upload()`
3. `check_portal_logo()`
4. `set_setting()`
5. `audit('portal_logo.saved|removed')`
6. `delete_upload()` of the old file

With `remove=1` it clears. It is not `tracked()`, for the reason in ADR 0008.

**Sweep.** `upload_references()['logo']` is
`SELECT REPLACE(setting_value,'"','') AS name FROM settings WHERE setting_key='portal_logo'`,
with the same `REPLACE` reasoning as the icon.

**Layout.** Both `.brand` blocks render from `brand_header()`. `frontend-dev` may put the
markup in `app/ui.php` as `brand_block(?array $user, bool $public): string`. If so, it is
added to the `structure` suite's list of helpers that return HTML.

- **The logo:** `<img class="brand-logo" src alt width height>`. `alt` is the club name when
  the name is hidden, and `alt=""` when the name is printed beside it.
- **The icon:** the same 44 px box as `.brand-mark`, with no highlight dot.
- **Hidden text:** a hidden name or subtitle stays in the markup with `visually-hidden`, so
  a screen reader still hears which portal this is.
- **The plate.** The logo sits on a fixed light plate (`#fff`, rounded, padded) in the
  sidebar, in the public header, and in both schemes. One upload cannot be right on both a
  dark and a light ground, and a plate is the only choice that works for any logo.
  `ui-ux-designer` specifies its size and the phone top bar (`.mobile-brand`).

## Rejected

**Generalising `app/portal_icon.php` into one "brand" file.** An update is an upload over
the install, so the old `portal_icon.php` would stay on every server. ADR 0008 and the
`structure` suite name it. The icon code works and nobody asked to change it.

**Reusing the icon upload for the logo.** The icon must be a square PNG for iOS. A wide
logo cannot be one, and the owner chose two uploads.

**SVG and GIF.** SVG for the reason in ADR 0008. GIF was not asked for, and it animates.

**A second logo "for dark backgrounds".** It doubles the upload, the setting, the sweep and
the checks for a problem the plate solves. It can be added later without undoing anything
here.

**Resizing or cropping.** It needs GD (ADR 0008). `width` and `height` with CSS
`max-height` / `max-width` scale the image in the browser.

**Serving through `page=download`.** It is not public (ADR 0008).

## Consequences

- **`structure` suite:**
  - classify `logo` as public;
  - add the handler exception `logo` → `serve_portal_logo`;
  - add `app/actions_settings.php portal_logo_save` to the refuses-before-it-writes
    orderings, anchored on `check_portal_logo`.
- **`uploads` suite:**
  - `logo` is swept, and a fixture proves the live logo survives `prune_uploads()`;
  - `check_portal_logo()` refuses a JPEG renamed `.png`, an image 40 px tall and a 6:1
    banner, and accepts a 600×150 WebP.
- **`TESTING.md`:**
  - upload a wide PNG, a JPEG from an iPhone and a WebP;
  - check the sidebar in light and dark, the login page, and the phone top bar at 320 px;
  - check each switch with a logo, with only the icon, and with neither;
  - remove the logo and confirm the icon, then „B", comes back.
- No migration and no dependency.
