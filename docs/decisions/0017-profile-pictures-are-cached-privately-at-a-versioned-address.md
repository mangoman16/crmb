---
status: superseded by 0026
date: 2026-09-29
---

# 0017. Profile pictures are cached privately for a week, at a versioned address, and only for people who may see them

> **Superseded by ADR 0026 (2026-10-07).** Profile pictures go, for accounts and students alike;
> everybody is shown by their initials. With them go `may_see_account_picture()`,
> `avatar_for_download()`, `avatar_cache_control()` and the download route's picture branch, so
> nothing is left for this record to rule. `upload_version_current()` stays for the icon and the logo
> (ADRs 0008 and 0014), and the problem report's screenshot keeps `no-store`.

## Context

Every page's top bar shows the person's avatar, and lists show many more. `serve_download()`
in `app/uploads.php` sent them with `Cache-Control: private, no-store`
(`DOWNLOAD_CACHE_CONTROL`). The route also starts a session, and PHP's `nocache` limiter adds
`Expires` and `Pragma: no-cache`. So every page change fetched every picture again, over a
phone connection.

The proposal was the icon's pattern from ADR 0008. `v` in the address is `upload_version()`,
the first 12 characters of the random stored name. The response may be cached only when `v`
names the file stored now. The first draft of this record allowed a year, `immutable`.

The security review then found a second problem. The avatar branch checked only
`require_user()`, so any signed-in account, including any family, could fetch **any**
child's or account's photo by counting up `id`.

## Decision

### Who may see a picture

**One rule, `may_see_account_picture(array $viewer, array $account): bool`, in `app/auth.php`**
beside `is_staff()`. It is true when at least one of these holds:

- the viewer is staff;
- the viewer is the account;
- the account is staff (`is_staff($account)`). These are the people a family writes to.

An account row without its `role` is never taken for staff. A family never sees another
family's account picture, not even one they have agreed to write with. The name is shown;
the photograph stays theirs.

Two callers share the rule, so a page never links to a picture the route would refuse:

- **The route.** `avatar_for_download(string $kind, int $id): string` in `app/uploads.php`
  is what `serve_download()` serves from.
  - For `student`, it goes through `student($id)`: staff reach every child, and a family
    reaches its own.
  - For `account`, it reads `id, role, avatar_name` and applies
    `may_see_account_picture()`.
  - Somebody who does not exist and somebody who may not be seen get the same `NotFound`
    (404), so the address cannot be used to find out which ids exist.
- **The drawing.** `avatar()` in `app/shell.php` draws an account's picture only when
  `may_see_account_picture(current_user(), $who)` holds, and draws **initials** otherwise.
  - **PM decision:** another family's account picture is shown as initials **everywhere**,
    messaging contacts and conversations included.
  - A child's picture is drawn as given, because every page that lists children has already
    scoped them through `student()` or its list equivalent.

### How long it is kept

- `avatar()` puts `v` = `upload_version($who['avatar_name'])` in the address.
- `avatar_cache_control()` returns **`private, max-age=604800`** (7 days) when
  `upload_version_current()` holds. It is **not `immutable`**, and never `public` or
  `shared_cache_control()`.
- For an older `v`, the picture is still served but uncached (`DOWNLOAD_CACHE_CONTROL`), not
  refused. A page drawn a moment before the picture was replaced, or a lazy image fetched
  much later, would otherwise show a broken image.
- `send_cache_control()` removes `Expires` and `Pragma` whenever `max-age` is sent.
- The top bar's `tiny` avatar is not `loading="lazy"`. It is on screen on every page, and
  lazy would only hold its request back.

The icon, logo and brand stylesheet keep a year and `immutable` (`CLUB_ASSET_MAX_AGE`; ADRs
0008, 0013, 0014). They are the club's, not a family's, and public anyway.

### Sign-out: best effort

The `logout` case sends `Clear-Site-Data: "cache"` when headers have not been sent yet.

- Browsers that honour it drop the cached pictures.
- Safari's support is not something to rely on, and browsers only act on the header over
  HTTPS.
- Where it is ignored, a picture this browser was already allowed to show stays in its
  private cache for **at most 7 days**. It is not reachable by address without a session
  that passes the rule above.

That trade-off is accepted.

Screenshots from problem reports (`what=shot`), proofs, attachments and invoices keep
`no-store`.

## Rejected

**A year and `immutable`** (the first draft). 7 days stops the refetch between pages just
as well. A shared family iPad or a borrowed phone should not hold children's photos for a
year.

**Showing agreed messaging contacts each other's pictures.** The owner's portal shows names
between families. Photographs are a family's own and stay so.

**Two copies of the rule, one in the route and one in `avatar()`.** They would drift, and a
page would link to pictures the route refuses, or worse, the other way round.

**`public` caching, or leaving out `v`.** The first puts children's photos in shared caches.
The second makes a replaced photo linger for the whole `max-age`.

**Refusing an old `v`.** It breaks pages drawn just before a replacement, for no privacy
gain: the same person may see the new picture anyway.

**ETag and 304 revalidation only.** That is still one round trip per picture per page, with
a session started for each.

**403 for a picture the caller may not see.** It confirms the record exists.

**Serving avatars from a public route like the icon.** A child's photo would become
reachable by anybody who has the address.

## Consequences

- **Tests (shipped):**
  - the `security` suite covers `avatar_for_download()` and `may_see_account_picture()`:
    a family gets its own child's, its own and the trainer's picture, and not another
    family's; staff and administrators get every picture. It also checks that `logout`
    sends `Clear-Site-Data`.
  - the `shell` suite covers `avatar()` drawing initials for a picture the viewer may not
    see, and a versioned address for one they may;
  - the `uploads` suite pins `avatar_cache_control()` to exactly `private, max-age=604800`
    for the current version and `private, no-store` otherwise.
- **Must not:**
  - add a second place that decides who sees an account picture;
  - cache avatars `public` or `immutable`;
  - draw an account picture without going through `avatar()`.
- **`TESTING.md`:**
  - a second page view takes the top-bar avatar from the cache;
  - a new picture shows on the next page;
  - as a family, another family's picture shows as initials in messaging, and its address
    answers "not found";
  - after sign-out, note whether the browser dropped the pictures.
