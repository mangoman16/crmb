---
status: accepted
date: 2026-09-29
---

# 0017. Profile pictures are cached privately for a week, at a versioned address, and only for people who may see them

## Context

Every page's top bar shows the person's avatar, and lists show many more. `serve_download()`
in `app/uploads.php` sends them with `Cache-Control: private, no-store`
(`DOWNLOAD_CACHE_CONTROL`). The route also starts a session, and PHP's `nocache` limiter
adds `Expires` and `Pragma: no-cache`. The result is that every page change fetches every
picture again, over a phone connection.

The proposal is the icon's pattern from ADR 0008:

- `v` in the address is `upload_version()`, the first 12 characters of the random stored
  name;
- the response is cacheable only when `v` names the file stored now.

The first draft of this record allowed a year, `immutable`.

The security review then found that the avatar branch checks only `require_user()`. Any
signed-in account, including any family, could fetch **any** child's or account's photo by
counting up `id`.

## Decision

### Who may have the picture

The avatar branch of `serve_download()` decides this before reading a byte, and answers 404
otherwise. 404, not 403, so the answer does not confirm that a picture exists.

- **`kind=student`:** only if `student($id)` accepts the caller. That is the same scoping
  every student page uses: staff see every child, and a family sees its own child.
- **`kind=account`:** only if at least one of these holds:
  - the viewer is the account;
  - the viewer is staff;
  - the target account is staff. A family sees the trainer's picture in messages and in
    the top bar of a conversation.

  A family never gets another family's account picture.

This rule is one function in `app/uploads.php`, `avatar_visible_to(array $viewer, string
$kind, int $id): bool`. The rules for pictures stay in one place. `backend-dev` is scoping
it now.

### How long it is kept

- **`private, max-age=604800` (7 days), not `immutable`**, and only when
  `upload_version_current()` holds. Otherwise `DOWNLOAD_CACHE_CONTROL`. The rule lives
  in `avatar_cache_control()`.
  - A week stops the refetch between pages and across a week of training days.
  - A borrowed or shared device does not keep children's photos for a year.
  - Without `immutable`, a browser may revalidate on reload, which is harmless.
- **Always `private`, never `shared_cache_control()`.** A child's photograph never sits in a
  proxy or CDN.
- **The address carries `v`.** `avatar()` in `app/shell.php` adds
  `'v' => upload_version($who['avatar_name'])`.
  - A new picture gets a new address.
  - A removed picture falls back to initials.
  - An old address answers `no-store`.
- `send_cache_control()` removes `Expires` and `Pragma` whenever `max-age` is sent, so the
  session's headers do not contradict it.

The icon, logo and brand stylesheet keep their year and `immutable` (ADR 0008, 0013, 0014).
They are the club's, not a family's, and public anyway.

### Sign-out: best effort

The `logout` case sends `Clear-Site-Data: "cache"` on its response.

- Browsers that honour it drop the cached pictures at sign-out.
- Safari's support is not something to rely on, and browsers only act on the header over
  HTTPS.
- Where it is ignored, the trade-off below applies, bounded to 7 days.
- It also drops the cached `app.css` and brand stylesheet. That costs one fetch at the next
  sign-in.

**The trade-off.** After sign-out, a picture that was shown stays in that browser's private
cache for at most 7 days. It is only a picture this browser was already allowed to show. It
is not reachable by address without a session that passes the rule above. This is accepted.

Screenshots from problem reports (`what=shot`), proofs, attachments and invoices keep
`no-store`.

## Rejected

**A year and `immutable`** (the first draft). Refetches between pages stop just as well at
7 days, and a shared family iPad or a borrowed phone should not hold children's photos for
a year.

**`public` caching, or leaving out `v`.** The first puts children's photos in shared caches.
The second makes a replaced photo linger for the whole `max-age`.

**A short `max-age` without versioning.** A new picture would not appear for that long.

**ETag and 304 revalidation only.** That is still one round trip per picture per page, with
a session started for each.

**403 for a picture the caller may not see.** It confirms the record exists. 404 matches
`student()`'s own `NotFound`.

**Serving avatars from a public route like the icon.** A child's photo would become
reachable by anybody who has the address.

## Consequences

- **`backend-dev`:**
  - `avatar_visible_to()` and its use in `serve_download()`;
  - `avatar_cache_control()` with 604800 and without `immutable`;
  - the `v` parameter in `avatar()`;
  - `Clear-Site-Data` on `logout`.

  Part of the cache work is already in the working tree (`upload_version()`,
  `upload_version_current()`, `avatar_cache_control()` with a year and `immutable`, which
  must change).
- **`uploads` suite:**
  - a family fetching another family's student picture, or another family's account
    picture, gets 404;
  - a family fetching the trainer's picture gets it;
  - staff get any picture;
  - `avatar_cache_control()` returns exactly `private, max-age=604800` for the current
    version and `private, no-store` for a stale one or none;
  - it never contains `public` or `immutable`. Break each rule once and watch it fail.
- **`security-reviewer`** re-checks the avatar branch against the access rule after the fix.
- **`TESTING.md`:**
  - a second page view takes the top-bar avatar from the cache;
  - a new picture shows on the next page;
  - as a family, open another family's picture address and see "not found";
  - after sign-out, note whether the browser dropped the pictures.
