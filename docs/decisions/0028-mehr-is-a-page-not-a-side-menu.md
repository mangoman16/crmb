---
status: accepted
date: 2026-10-07
---

# 0028. „Mehr" is a page, not a side menu

## Context

The owner, 2026-10-07: "Make sure it is as intuitive and easy to use as a modern ios app, design,
design language". The design language
([docs/design/2026-10-07-ios-design-language-and-goal-screens.md](../design/2026-10-07-ios-design-language-and-goal-screens.md),
Part 0, C1, C3 and row 13 of 0.7) proposes that staff's „Mehr" on a phone becomes a page,
`?page=more`. Its reasons: a page needs no JavaScript, every screen behind it gets a „‹ Mehr", and it
is what an iOS app does.

What „Mehr" is today, read from the code:

- **It is the whole side menu, slid in.** `views/layout.php` draws it as a link to `#sidebar` after
  the bar's four entries, outside `mobile_nav_entries()`. The stylesheet opens the side menu when
  `#sidebar` is the target. `public/assets/app.js`, lines 10–41, turns the link into a button and
  toggles `body.menu-open`. The backdrop, „Menü schließen" and Escape close it. Three pieces of
  markup exist only for it: `#menu-toggle`, `.menu-backdrop` and `.menu-close`.
- **It repeats the bar.** The drawer holds all of `nav_entries()`, the four entries on the bar
  included.
- **Its foot carries the help links.** It holds „Etwas funktioniert nicht" (`.feedback-link`, drawn
  only on phones), the privacy notice and the version. Mein Konto has the same three in its group
  „Datenschutz und Hilfe", for everybody.
- **It is no place to go back to.** `nav_back()` (f5d3c28) has no parent for Kurse, Geld,
  Rechnungen, Einstellungen, a trainer's Verwaltung, the checklist while it is unfinished, or staff's
  Mein Konto. The home-screen app has no browser Back (Part 0, 0.5), so on those pages the only way
  up is the page's own links.
- **ADR 0011 capped the menu at seven partly for the drawer:** "a list of seven fits on a phone". An
  administrator already has eight entries while the checklist is unfinished.

The spec disagrees with itself on one row. C3 puts Neuigkeiten on the „Mehr" page. C1's table of back
buttons, `nav_owner()` and the built `nav_back()` keep staff's news under Chats, reached from the chip
at the top of Nachrichten.

## Decision

### 1. On a phone, staff's „Mehr" is a page; the desktop sidebar is unchanged

- **The page:** `?page=more`, `views/more.php`, for staff only. A family's bar keeps its four entries
  and has no „Mehr" (the owner, 2026-10-07).
- **The desktop sidebar** (761 px and wider) keeps every entry of `nav_entries()`, with the privacy
  link and the version at its foot (Part 0, C4).
- **On a phone the sidebar is not shown** (`display:none`). Its markup stays, because the server does
  not know the screen width. A larger iPhone held sideways is 844 px wide and gets the sidebar.
- **The bar's fifth entry** comes from `mobile_nav_entries()`, like the other four: route `more`,
  icon `more`, label „Mehr". The layout's literal link goes.

### 2. What the page holds

1. **The places the bar does not hold.** `more_entries(array $user)` sits in `app/ui.php` beside
   `mobile_nav_entries()`. It returns `nav_entries()` without the routes on the bar, in
   `nav_entries()`' order, with their counts. Today that is:
   - for an administrator: (Einrichtung, while unfinished) · Kurse · Geld · Einstellungen;
   - for a trainer: Kurse · Geld · Verwaltung.

   The list is worked out, never written down a second time. An entry added to the full menu
   appears here by itself, and G6's count on Geld (Part 8) reaches both menus from one edit.
2. **Mein Konto**, a row to `profile`.
3. **„Datenschutz und Hilfe"**: the privacy notice, „Etwas funktioniert nicht" and the version.
   - „Etwas funktioniert nicht" links to `#feedback`, which ends this page as it ends every page.
   - One helper in `app/ui.php` draws the group here and on Mein Konto. Its markup moves out of
     `views/profile.php`; it is not copied.
   - It keeps the name Mein Konto uses and the suites pin. The spec's „Hilfe und Datenschutz" would
     be a second name for the same group.
4. **„Abmelden"**, an action row that posts the existing `logout` through `start_form()`. No action
   is added.

The view only reads (ADR 0003). How the rows look (groups, chevrons, red counts) is the designer's
(Part 0, C5).

**Neuigkeiten is not on the page.**

- Staff's news belongs to Chats. ADR 0011's table, `nav_owner()` and the built `nav_back()` all say
  so, and the spec's own C1 table leaves it there.
- The trainer's overview keeps its Neuigkeiten group, with „Neuigkeit schreiben" (Part 2.2, item 5).
- A row on „Mehr" would open a page that shows „‹ Chats" and lights Chats: one page with two
  parents.
- If news should live under „Mehr", it gets an entry of its own in `nav_entries()` and arrives there
  through item 1 (see Rejected).

### 3. The router

`more` joins `$allowed` and the `require_staff()` list in `public/index.php`. No existing page changes
its classification, as ADR 0011 requires.

### 4. Which tab is lit

- **`nav_owner()` gains two rows:** `more` → `more`, and staff's `profile` → `more` (today `''`). A
  family's `profile` stays `student`.
- **On the bar, „Mehr" is lit** while the open page is `more`, or belongs to an entry that is on
  „Mehr". That covers:
  - Kurse and a course;
  - Geld and Rechnungen;
  - Einstellungen and the pages of its hub;
  - Verwaltung and Team und Zugänge;
  - the checklist;
  - Mein Konto.
- **One function answers "is this page under „Mehr"?"** Both the bar and `nav_back()` call it, so the
  back button and the lit tab cannot disagree.
- **In the sidebar nothing changes.** On Kurse, Kurse is lit.
- **The check needs routes, not counts.** It runs on every page, and must not run `nav_entries()`'
  counts again there: the unread query is not small. One way is a `$counted=false` argument on
  `nav_entries()` and `mobile_nav_entries()` that leaves every count at 0, so the entries are still
  listed once.

### 5. `nav_back()`

The rows that change:

| Page | Back today | Back now |
| --- | --- | --- |
| Kurse, the list | none | „Mehr" |
| Geld | none | „Mehr" |
| Rechnungen | none | „Mehr". It is Geld's other half, not a page below Geld. |
| Einstellungen | none | „Mehr" |
| Verwaltung, for a trainer | none | „Mehr" |
| Einrichtung, while unfinished | none | „Mehr" |
| Mein Konto, for staff | none | „Mehr" |
| Mehr | — | none: it is a root, like the four entries beside it |

The rule behind the new rows: a page with no parent of its own that is under „Mehr" (§4) goes back to
„Mehr".

Every other row stands as built:

- a course → Kurse;
- Verwaltung, Team und Zugänge, Änderungen and the hidden checklist → Einstellungen, for an
  administrator;
- Team und Zugänge → Verwaltung, for a trainer;
- news and Postausgang → Chats;
- a family's Mein Konto → Profil.

The doc comment's sentence that these pages "have no back button" goes.

### 6. The drawer goes

- **`views/layout.php`:**
  - „Menü schließen";
  - the backdrop;
  - the `#sidebar` link;
  - the side menu's `.feedback-link`;
  - `id="sidebar"`, if nothing else reads it.
- **`public/assets/app.js`:** lines 10–41, all of which belong to the drawer.
- **`public/assets/app.css`:**
  - `.menu-open`, `.sidebar:target`, `.menu-backdrop`, `.menu-close` and `.feedback-link`;
  - the phone rules that slide the sidebar in;
  - on a phone, `.sidebar` becomes `display:none`.

### 7. What ADR 0011's menu of seven becomes

- **The full menu, `nav_entries()`, stays one flat list without sections**, and it is the desktop
  sidebar.
- **Its length is not a number.** A new entry is measured against the desktop fold: TESTING.md 5.1a,
  no scrollbar of its own at 110 % and 125 % zoom.
- **On a phone,** the bar holds four places and „Mehr", and „Mehr" holds the rest of the full menu,
  worked out as §2 says. Every entry is on one or the other, and never missing from both.
- **A page without an entry** is still reached from the entry that owns it, through `nav_owner()`, as
  0011 decided.

## Rejected

- **Keeping the drawer, restyled.**
  - Its good version needs JavaScript, and its fallback is `:target`.
  - A drawer is the web pattern the owner's ask moves away from.
  - It is no place to go back to, so seven pages would keep no back button in the home-screen app.
- **Neuigkeiten as a row on „Mehr", still belonging to Chats.** One page with two parents: opened
  from „Mehr", it shows „‹ Chats" and lights Chats.
- **Moving news under „Mehr" (`nav_owner('news')` → `more`) without a menu entry.** The desktop
  sidebar has no „Mehr", so on a computer news would light nothing, and lose its way in once the chip
  goes.
- **Giving news its own entry now.**
  - It makes the trainer's sidebar eight entries, moves the chip and changes three tables.
  - Her overview already opens news in one tap.
  - It is left open (Consequences).
- **Listing the page's rows by hand.** That is a second copy of the menu, and it drifts from the
  first: the next entry or count would be added to only one of them.
- **„Mehr" for families.** The owner decided their bar has four entries.
- **Sending `?page=more` to the overview on a wide screen.** The server does not know the width, and
  the page is harmless there.
- **Leaving „Mehr" unlit on the pages under it.** iOS keeps a tab lit through everything opened from
  it. With nothing lit, the bar would not say where she is.

## Consequences

- **Load order.** No new file in `app/`.
  - `more_entries()` and the help group's helper go in `app/ui.php`.
  - `public/index.php` requires `app/ui.php` after the four action files, and it is not in
    `app/bootstrap.php`'s chain.
  - Everything the helpers call is loaded by then: `setup_unfinished()`, `pending_request_count()`
    and `unread_count()`.
- **Boundaries crossed:**
  - a new view, `views/more.php`, which only reads;
  - the router's `$allowed` and staff lists: one new page, and no page reclassified;
  - the tests that pin the drawer:
    - in `tests/suites/shell.php`, the `menu-toggle` checks, and "only the owner is highlighted"
      checked across both menus at once, which becomes one check per menu;
    - TESTING.md 5.0a and 5.1b;
    - a comment in `tests/e2e.mjs`.
- **Nothing else changes.** No action, setting, schema or dependency is added, and `app.js` loses
  about thirty lines (ADR 0002).
- **backend-dev:** the two router lists.
- **frontend-dev:**
  - `app/ui.php`: `more_entries()`, the bar's fifth entry, `nav_owner()`, the shared "under Mehr"
    check, `nav_back()`, the help group's helper and the doc comments;
  - `views/more.php`, `views/profile.php` and `views/layout.php`;
  - `app.css` and `app.js`.
- **qa-tester**, breaking each rule once:
  - `structure`: `more` is classified as staff;
  - `more_entries()` is `nav_entries()` without the bar, for an administrator (checklist open, and
    hidden) and for a trainer;
  - every full-menu entry is on the bar or on „Mehr";
  - the staff bar ends in `more`, and a family's bar has no `more`;
  - no `menu-toggle`, `menu-backdrop`, `menu-close` or `#sidebar` is left in the layout or in
    `app.js`;
  - on every page, one entry is lit in the sidebar and one on the bar, and on the bar it is „Mehr"
    for the pages of §4;
  - `nav_back()`, row by row, for an administrator, a trainer and a family. It has no test today.
    Every target is a page the router lets that person open;
  - the reachability check picks up `more`, and staff's Mein Konto under it, by itself;
  - `tests/mobile.mjs` opens `more` (Part 13 already asks for it).
- **mobile-tester:**
  - the page at 320 and 390 px, as an administrator and as a trainer, in light and dark, and with
    JavaScript off;
  - „‹ Mehr" and the lit tab on each page in §5;
  - a phone held sideways, wider than 760 px, shows the sidebar.
- **docs-writer:** TESTING.md 5.0a and 5.1b, and the CHANGELOG's unreleased section.
- **Must stay true:**
  - the rows of „Mehr" are worked out from `nav_entries()`;
  - one function says whether a page is under „Mehr", for the tab and for the back button;
  - no page on a phone needs JavaScript to be reached;
  - a family has no „Mehr".
- **ADR 0011** is amended by this record. Its note names what changes.
- **Open, for the designer and the project manager:**
  - whether news gets a menu entry of its own;
  - whether the „Mehr" tab shows the sum of its rows' counts. Today's „Mehr" shows none, and the
    trainer's „Zu tun" shows requests and receipts.

## In plain words, for the owner

- On a phone, „Mehr" opens a page instead of sliding a menu in. The page lists Kurse, Geld and
  Einstellungen (Verwaltung for the trainer), Mein Konto, help and privacy, and Abmelden.
- Every page opened from it has „‹ Mehr" at the top left. That matters most in the app on the home
  screen, which has no Back button of its own.
- On a computer nothing changes.
- Neuigkeiten stay where they are: under Chats, and on the overview.
