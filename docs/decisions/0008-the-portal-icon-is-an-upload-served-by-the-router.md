---
status: accepted
date: 2026-09-24
---

# 0008. The portal icon is an upload, served through the front controller

## Context

The owner wants to set her own icon from Settings. Today `views/layout.php` hard-codes
`assets/favicon.svg` (`rel=icon`) and `assets/apple-touch-icon.png`, and
`public/manifest.webmanifest` is a static file that lists `icon-192.png`, `icon-512.png` and
`favicon.svg` and names the portal "Badminton" whatever `club_name` says.

The constraints:

- She updates by uploading a release over the install. Anything written into `public/`
  can be overwritten by the next update, and `public/` may not be writable at all.
  `storage/` is the one directory the installer requires to be writable.
- The login page is public, so the icon must be served without a session.
- `app/uploads.php` depends on nothing beyond `mime_content_type()` (fileinfo, guarded by
  `function_exists`). Nothing in the codebase uses GD, and `install_requirements()` does
  not check for it. Resizing cannot be assumed.
- An SVG is a document. It can carry script, and it would be served from the portal's
  own origin.
- iOS takes its home-screen icon from `apple-touch-icon`, which must be a PNG. Android
  takes it from the manifest.

## Decision

**Storage.** The icon is an upload of a new kind, `icon`: `store_upload('icon', 'icon')`,
random name, `storage/uploads/icon/`. `upload_types('icon')` is `['image/png' => 'png']`.
The stored name is kept in the setting `portal_icon`, declared in `app/defaults.php` as
`kind raw`, `internal`, group `portal`, default `''`. No migration.

**What is accepted.** A square PNG of at least 180 × 180 pixels, within `upload_limit()`.
`check_portal_icon(string $storedName)` reads the stored file with `getimagesize()`. That
function is part of ext/standard, not GD, and reads only the header. It refuses anything
that is not `IMAGETYPE_PNG`, not exactly square, or smaller than 180. It deletes the file
it refused. Nothing is resized or re-encoded.

**Code.** A new file, `app/portal_icon.php`, is required in `app/bootstrap.php` directly
after `uploads.php`. It belongs in the presentation and IO group because it needs
`upload_dir()` and `send_download_headers()`. Nothing earlier calls it: only the router and
`views/layout.php` do, at request time. It holds:

- `portal_icon(): string` returns the stored name, or `''` when none is set *or the file
  is missing*. A restore without `storage/` must fall back to the default rather than
  link to a 404.
- `portal_icon_url(): string` returns `url('icon', ['v' => …])`, or `''`.
- `check_portal_icon(string $storedName): void`, described above.
- `serve_portal_icon(): never`
- `serve_web_manifest(): never`

**Routes.** There are two new pages, `icon` and `manifest`. Both are in `$allowed` and in
the `$public` list in `public/index.php`. Each is answered by one line next to the
`download` line, after `$public`/`$user` are set, so the classification the `structure`
suite reads is the one that is actually applied. Neither has a view.

**Versioning.** `v` is the first 12 characters of the stored name. Because the name is
random per upload, it already acts as a version and needs no second setting. When the
request's `v` matches, the response is `Cache-Control: public, max-age=31536000,
immutable`. This header is set after `send_download_headers()` and replaces its
`no-store`. Otherwise, or when falling back to the built-in `assets/icon-512.png`, the
response is `no-cache`.

**Layout.** When `portal_icon_url()` is not empty, both `rel=icon` (`type="image/png"`) and
`apple-touch-icon` point at it. When it is empty, the layout prints today's tags unchanged,
so an uncustomised portal makes no extra PHP request.

**Manifest: dynamic.** The link is `<link rel="manifest" href="<?=e(url('manifest'))?>"
crossorigin="use-credentials">`. Without `use-credentials` the browser fetches the manifest
without cookies, and `boot_http()` would start a new anonymous session on every fetch.
Fields:

- `name` and `short_name` come from `club_name`.
- `start_url` is `url('dashboard')` and `scope` is `app_url` with a trailing slash. Both
  are absolute, because the manifest no longer sits at the web root.
- `icons`: a custom icon is one entry, with its real size from `getimagesize()` and
  `purpose: any`. It is never `maskable`, because her logo was not drawn for a safe zone.
  With no custom icon, the list is today's.
- `Content-Type: application/manifest+json` and `Cache-Control: public, max-age=86400`.

`public/manifest.webmanifest` and the `AddType` line for it are deleted.

**Change and removal.** One action, `portal_icon_save`, in `app/actions_settings.php`
(ADR 0003 puts settings there). It follows the order `require_admin()` → `store_upload()` →
`check_portal_icon()` → `set_setting()` → `audit()` → `delete_upload()` of the previous file.
With `remove=1`, it sets `''` and deletes the old file, and the layout is back on the
built-in icon.

**Sweep.** `upload_references()['icon']` is
`SELECT REPLACE(setting_value,'"','') AS name FROM settings WHERE setting_key='portal_icon'`.
Setting values are JSON, so the stored string carries quotes. Without `REPLACE`, the sweep
would find no match and delete the live icon an hour after upload. `REPLACE(x,y,z)` has the
same signature in MariaDB, MySQL and SQLite.

## Rejected

**Writing into `public/assets/`.** It is overwritten by the next update, and on many hosts
it is not writable.

**Accepting SVG.** It is script-capable and same-origin, and the CSP is a second layer, not
the first. Sanitising SVG properly needs a library, which is a dependency to keep patched.

**JPEG, WebP, ICO.** `apple-touch-icon` wants PNG, and one format that serves all four uses
(tab, iOS home screen, Android install, Settings preview) avoids guessing which browser
takes what. An iPhone screenshot, cropped square, is a PNG, which is the practical way for
her to produce one.

**Resizing with GD.** GD is not guaranteed on shared hosting. The feature would depend on
an optional extension, and decoding the image is exactly the attack surface
`getimagesize()` avoids.

**A second entry point, `public/icon.php`.** It would be a second front controller, with
its own boot path and no place in the router's classification.

**Serving through `page=download`.** That page is not public. Making it public would leave
invoices and payment proofs protected only by their per-branch checks.

**Keeping the static manifest.** Android installs would keep the old icon, and the name
"Badminton" would stay wrong for every club not called that. The fix is about 25 lines in a
file this change creates anyway.

**Leaving `icon` out of the sweep.** The `uploads` suite requires every kind to be swept, and
unswept kinds keep refused and replaced files for ever.

**A version setting.** The random file name already is one.

## Consequences

- `structure` suite (`qa-tester`):
  - Classify `icon` and `manifest` as `public`.
  - Change "Every page the router allows has a view file" to name its exceptions with
    their handler (`icon` → `serve_portal_icon`, `manifest` → `serve_web_manifest`), and
    assert the router calls each. Do not add stub views.
  - Add `app/portal_icon.php` to the expected-files list.
  - Add `app/actions_settings.php portal_icon_save` to the refuses-before-it-writes
    orderings, anchored on the call `check_portal_icon`.
- `uploads` suite: `icon` is swept, and a fixture proves the live icon survives
  `prune_uploads()`. Remove the `REPLACE` and watch that assertion fail.
- Removal deletes the file, so the way back is uploading it again. It is not an undoable
  `tracked()` change, matching how `defaults_registry_save` treats settings.
- In maintenance mode the icon URL answers 503 like everything else. This is accepted.
- `TESTING.md`: check the browser tab, Add to Home Screen on a real iPhone, and Install on
  Android, each before and after a change and after removal.
