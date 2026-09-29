---
status: accepted
date: 2026-09-29
---

# 0014. The portal logo is a second upload beside the icon

## Context

The owner wants her own logo top left, replacing the „B" mark in `.brand`. The mark appears
twice in `views/layout.php`: in the sidebar and in `.public-header`.

She chose a **separate** upload from the portal icon of ADR 0008:

- it may be wide, not only square;
- it may be PNG, JPEG or WebP.

The fallback order is logo, then portal icon, then „B". She also wants two switches:

- hide the portal name;
- hide the „Verwaltung" / „Mein Portal" line under it. This line is not `portal_tagline`,
  which is the top bar's `.topbar-context`.

The icon's constraints carry over: storage under `storage/`, a public route (the login page
shows it), no GD, no SVG, and `getimagesize()` reading only the header.

One constraint is new. The sidebar is the nav colour: dark by default, and brand-chosen from
ADR 0013. The public header is the page background, which is light. A logo drawn for one is
invisible on the other.

## Decision

**Storage.**

- Upload kind `logo`: `store_upload('logo','logo')`, stored in `storage/uploads/logo/`.
- `upload_types('logo')` is `['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp']`.
- Setting `portal_logo`: `kind raw`, `internal`, group `portal`, default `''`.
- No migration.

**Code.** These functions go in `app/brand.php` (ADR 0013), because they are the club's
look, like the colours:

- `portal_logo(): string`. It returns `''` when the setting is unset, when the name does not
  match `^[a-f0-9]{32}\.(png|jpg|webp)$`, or when the file is missing.
- `portal_logo_url(): string`, which is `url('logo', ['v' => upload_version($name)])`, or
  `''`.
- `portal_logo_size(): array`, which is `[w, h]` from `getimagesize()`, memoised. The
  `<img>` carries `width` and `height` attributes, not a `style`: the CSP refuses a style.
- `check_portal_logo(string $storedName): void`. It refuses the upload and deletes the file
  when:
  - `getimagesize()` cannot read it;
  - its type does not match the extension;
  - its height is under `PORTAL_LOGO_MIN_HEIGHT` (88, which is 44 px at 2×);
  - either side is over `PORTAL_LOGO_MAX_SIDE` (2048);
  - its width-to-height ratio is outside 1:2 to 5:1.

  Each message names the measured size and what is needed. `ui-ux-designer` may tune the
  numbers; the mechanism stays.
- `serve_portal_logo(): never`. It serves inline, with the MIME type taken from
  `upload_types('logo')` by extension.
  - When `v` is current: `shared_cache_control(CLUB_ASSET_MAX_AGE, true)`.
  - Otherwise: `no-cache`.
  - With no logo: 404 and `no-cache`.
- `brand_header(?array $user): array`. It returns
  `['logo'=>url|'', 'icon'=>url|'', 'show_name'=>bool, 'show_subtitle'=>bool]`. This is the
  one place the fallback and the switches are decided. `show_name` is false only when
  `header_hide_name` is on **and** a logo or icon is shown: with only „B", the name always
  shows.

**Switches.** `header_hide_name` and `header_hide_subtitle` are `kind bool`, default
`false`, group **`branding`**.

- They are on the **„Aussehen" card** with the colours, saved by `defaults_registry_save`
  (ADR 0013), **not on the logo card.** That save writes every key of a group from one POST,
  so a group split across two forms blanks the half that was not posted.
- The logo card may say where the switches are.
- The switches get no action of their own.

**Route.** `logo` is in `$allowed` and `$public`:
`if($page==='logo')serve_portal_logo();` beside `icon`, `manifest` and `brand`. It has no
view.

**Change and removal.** A new action, `portal_logo_save`, in `app/actions_settings.php`
directly after `portal_icon_save`. It takes the same steps in the same order:

1. `require_admin()`
2. `store_upload()`
3. `check_portal_logo()`
4. `set_setting()`
5. `audit('portal_logo.saved|removed')`
6. `delete_upload()` of the old file

`remove=1` clears the logo. It is not `tracked()`, as in ADR 0008.

**Sweep.** `upload_references()['logo']` is
`SELECT REPLACE(setting_value,'"','') AS name FROM settings WHERE setting_key='portal_logo'`.

**Layout.** Both `.brand` blocks render from `brand_header()`. If `frontend-dev` puts the
markup in `app/ui.php` as `brand_block(?array $user, bool $public): string`, that helper is
added to the `structure` suite's HTML-helper list.

- The logo is `<img class="brand-logo" src alt width height>`. `alt` is the club name when
  the name is hidden, and `alt=""` when the name is printed beside it.
- The icon uses the 44 px `.brand-mark` box, without the highlight dot.
- A hidden name or subtitle stays in the markup with `visually-hidden`.
- The logo sits on a fixed light plate (`#fff`, rounded, padded), in the sidebar and in the
  public header, in both schemes. `ui-ux-designer` specifies its size and the phone top bar.

## Rejected

**Generalising `app/portal_icon.php`.** An upload over the install would leave the old file
behind on every server. ADR 0008 and the `structure` suite name it, and the icon code works.

**Reusing the icon upload.** The icon must be a square PNG for iOS.

**SVG and GIF.** SVG for the reasons in ADR 0008. GIF was not asked for, and it animates.

**A second logo „for dark backgrounds".** It doubles the upload, the setting, the sweep and
the checks, for a problem the plate solves. It can be added later.

**Resizing or cropping.** That needs GD. The `width`/`height` attributes and CSS scale the
logo.

**The switches on the logo card.** See Switches above.

**Serving through `page=download`.** Not public (ADR 0008).

## Consequences

- **`structure`:**
  - classify `logo` as public;
  - add the handler exception `logo` → `serve_portal_logo`;
  - add `portal_logo_save` to the refuses-before-it-writes orderings, anchored on
    `check_portal_logo`.
- **`uploads`:**
  - `logo` is swept, and the live logo survives `prune_uploads()`;
  - `check_portal_logo()` refuses a JPEG renamed `.png`, a 40 px tall image and a 6:1
    banner, and accepts a 600×150 WebP.
- **`settings`:** saving the „Aussehen" card leaves `header_hide_*` as posted, and saving
  the logo card leaves them untouched.
- **`TESTING.md`:**
  - upload a wide PNG, an iPhone JPEG and a WebP;
  - check the sidebar in light and dark, the login page, and the phone top bar at 320 px;
  - check each switch with a logo, with only the icon, and with neither;
  - remove the logo and see the icon, then „B", come back.
- No migration and no dependency.
