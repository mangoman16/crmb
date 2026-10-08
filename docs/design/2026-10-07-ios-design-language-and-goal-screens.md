# UI spec: the portal's design language (Part 0), age on the students list, and the gaps G1–G8 (ui-ux-designer, 2026-10-07)

**Status.** This is a specification only; nothing in it is built.

**What was measured.**
- Measured on commit `1b32834`, the HEAD when this work started, exported with `git archive` into the scratchpad.
- `c77348b` was committed meanwhile. `git diff 1b32834 c77348b` changes no markup, class or stylesheet on these screens (0 changed lines touch a tag or class; no asset changed), so the numbers hold for it.
- Stack: MariaDB 10.11.14, PHP 8.4.26, Chromium 141 (Playwright 1.56.1), with the example data from `setup.php`.

**No iPhone.**
- Every number is Chromium with an iPhone user agent.
- The system font stack resolved to DejaVu Sans on the test machine. DejaVu is about 15 % wider than Liberation Sans, which has Arial metrics close to San Francisco. So every width and wrap below is an upper bound for an iPhone.

**How to read it.**
- Part 0 is the reference the whole portal is restyled from.
- Parts 1–10 specify the age list and G1–G8 in Part 0's terms; Part 1 is revised by „Part 1, revised 2026-10-08".
- Part 11 ranks the gaps and groups the builds.
- Where something somebody asked for would go, it is marked (owner).

**Measuring words.**
- "Screens" = y ÷ 716 px: a 780 px viewport minus the 64 px bottom bar. On signed-out pages it is y ÷ 780.
- "Prototype" = the real page edited in the browser with the stylesheet's own classes (no code changed), then checked with `tests/mobile.mjs`'s own rules plus an overlap check, at 320 and 390, light and dark. A prototype is an estimate of the built screen; mobile-tester measures the real one.

---

## Part 0. The design language: a modern iOS app, in plain CSS

### 0.1 Principles

Every screen does one thing, and its one primary action is a filled button, full width on a phone, at the bottom of the thumb's reach. Everything else is a row, a plain button or a link. A screen opens with a large title that says where you are. The content sits in inset grouped lists: white rounded groups on a light grey ground, a short header above each group, and at most one sentence below it. Words are few: a row says a name and a value, a button one or two words beside an icon, and an explanation is a footnote under its group, never a paragraph above it. The controls are the phone's own: native selects and date wheels, checkboxes drawn as switches, choices drawn as segmented controls, file pickers that open the camera. Every tap answers at once: what is pressed darkens, the next page fades in where the browser can, and a saved change says so in a banner at the top. Nothing looks like a web admin panel: no shadows, no boxes with borders, no underlined tab strips, no tables, no row of five grey buttons.

### 0.2 Tokens

**Typography.**
- `font-family: -apple-system, BlinkMacSystemFont, system-ui, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif`, with the emoji fonts after it. That gives San Francisco on Apple devices, Roboto on Android and Segoe on Windows.
- Inter goes. It was never bundled (there is no `@font-face`), so Apple devices already fall back.
- `html{font-size:100%}` replaces today's `html{font-size:16px}`. The fixed size shuts out the reader's own browser text size.
- Amounts and times use `font-variant-numeric: tabular-nums`.
- Remove today's negative letter-spacing (it was tuned for Inter). San Francisco tracks itself.

| Style | iOS pt | rem (16 px root) | Weight | Line height | Used for |
| --- | --- | --- | --- | --- | --- |
| Large title | 34 | 2.125 | 700 | 1.2 | `page_head()`'s h1 |
| Title 1 | 28 | 1.75 | 700 | 1.2 | the amount on the pay page |
| Title 2 | 22 | 1.375 | 700 | 1.25 | the „Fertig!" of a wizard |
| Title 3 | 20 | 1.25 | 600 | 1.25 | empty-state titles, h2 inside content |
| Headline | 17 | 1.0625 | 600 | 1.3 | row titles, buttons |
| Body | 17 | 1.0625 | 400 | 1.35 | text, inputs (17 px also stops iOS zooming into a field) |
| Callout | 16 | 1 | 400 | 1.35 | notices |
| Subheadline | 15 | .9375 | 400 / 600 | 1.33 | row subtitles, descriptions; group headers at 600 |
| Footnote | 13 | .8125 | 400 / 600 | 1.38 | group footers, field hints; segmented labels and badges at 600 |
| Caption 1 | 12 | .75 | 400 / 500 | 1.33 | time stamps; the tab bar (12 px fixed) |

Caption 2 (11 pt) is not used: the 12 px floor holds.

**Colours.** Today's token names are kept. These values passed the audit in 0.7.

| Token (iOS role) | Light | Dark | Measured |
| --- | --- | --- | --- |
| `--bg` (grouped background, the ground) | `#F2F2F7` | `#000000` | |
| `--surface` (groups, fields) | `#FFFFFF` | `#1C1C1E` | |
| `--surface-2` (tertiary: tracks, nested fields) | `#F2F2F7` | `#2C2C2E` | |
| `--surface-3` / new `--pressed` (pressed row) | `#E5E5EA` | `#3A3A3C` | |
| `--ink` (label) | `#000000` | `#FFFFFF` | 18.82 / 17.01 |
| `--ink-2` | `#3C3C43` | `#EBEBF5` | |
| `--muted` (secondary label) | `#636366` | `#AEAEB2` | 5.99 on white, 5.37 on ground / 7.69, 6.30 on `#2C2C2E` |
| `--muted-2` (chevrons, field borders) | `#8A8A8E` | `#8E8E93` | 3.44 / 5.22, non-text |
| `--border` (separator) | `#C6C6C8` | `#38383A` | decorative |
| new `--field-border` | `#8A8A8E` | `#7C7C80` | 3.44 white, 3.08 ground / 4.09, 3.35 on `#2C2C2E` (3:1 rule) |
| new `--fill` / `--fill-2` | `rgba(120,120,128,.20)` / `.12` | `.36` / `.24` | |
| `--red`, `--red-ink` (destructive) | `#D70015` | `#FF6961` | 5.38, 4.83 / 6.03, 4.94 |
| `--ok-ink` / `--ok-bg` | `#1D7A33` / `#E3F4E7` | `#30D158` / `#12301C` | badge 4.73 / 7.09 |
| `--amber-ink` / `--amber-bg` | `#C93400` / `#FFF0E3` | `#FF9F0A` / `#3A2A10` | badge 4.74 / 6.73 |
| `--red-bg` (badge) | `#FDE8EA` | `#3A1A1C` | badge 4.59 / 5.54 |
| new `--badge-bg` / `--badge-ink` (counts) | `#D70015` / `#FFF` | the same | 5.38 |
| `--bar-bg` (bars, under blur) | `rgba(249,249,249,.94)` | `rgba(22,22,24,.94)` | |

**iOS's own colours fail the 4.5:1 floor as text**, so Part 0 does not use them:
- secondary label at 60 %: 3.44:1 on white;
- system red `#FF3B30`: 3.55;
- system green `#34C759`: 2.22;
- system orange `#FF9500`: 2.20;
- system blue `#007AFF`: 4.02.

**The tint is the club's colour**: the existing accent and brand settings (ADR 0013).
- `--teal` is the fill, the active tab, a switch that is on, and the focus ring.
- `--teal-ink` is tinted text.
- `--teal-soft` is a tinted button's background.
- `--on-accent` is text on the fill.
- The names stay, so the brand stylesheet keeps working.

Two things change with the tint:
1. **The default teal darkens from `#077e76` to `#06736C`.** Today's teal reads 4.42:1 on the new ground; `#06736C` reads 5.71 on white and 5.12 on the ground, and white text on it 5.71. The other seven built-in accents pass on the ground as text: blue 5.76, violet 5.67, pink 5.09, red 4.94, orange 4.89, green 4.70, slate 6.80.
2. **`app/brand.php` checks a club colour against `BRAND_SURFACE_LIGHT` (`#ffffff`) only, with `BRAND_SURFACE_DARK` = `#182430`.** It must check against `#FFFFFF` and `#F2F2F7`, and use `#1C1C1E`/`#2C2C2E` for dark. Backend makes that change in the same commit as the tokens, or a club colour may drop under 4.5 on the new ground.

**Spacing.**
- A 4/8 grid: 4, 8, 12, 16, 20, 24, 32, 40.
- Page side margin 16 px up to 560 px, so groups, segmented controls and the search field all sit 16 px in.
- 24 px between groups; 6 px from a header to its group and from a group to its footer.
- Rows have 11 px vertical and 16 px horizontal padding.

**Corner radii.**

| Element | Radius | Note |
| --- | --- | --- |
| groups (`--radius`) | 16 px | today's value |
| fields (new `--radius-field`) | 10 px | |
| buttons, segmented controls, chips, badges | 999 px | capsules |
| sheets (top corners) | 20 px | |
| thumbnails | 8 px | |

Today the portal uses five radii: 16, 9, 8, 7 and 6.

**Shadows.**
- `--shadow: none`.
- Exactly two places keep one:
  - the selected segment's thumb: `0 1px 3px rgba(0,0,0,.16)`;
  - floating layers (menus, sheets): `0 8px 32px rgba(0,0,0,.18)` in light, and in dark none, with a 0.5 px border instead.
- Today there are 4–15 shadowed elements per screen.

**Separators.**
- Hairlines: `0.5px solid var(--border)`, which is one device pixel on a 2× or 3× screen.
- Between rows only, inset from the left where the text starts: 16 px, or 68 px in a row with a 40 px leading avatar.
- No separator above the first row, and none around a group.

**The token block** (verified in 0.7; frontend-dev copies it):

```css
:root{--bg:#F2F2F7;--surface:#FFF;--surface-2:#F2F2F7;--surface-3:#E5E5EA;--pressed:#E5E5EA;--ink:#000;--ink-2:#3C3C43;
--muted:#636366;--muted-2:#8A8A8E;--border:#C6C6C8;--border-strong:#8A8A8E;--field-border:#8A8A8E;
--fill:rgba(120,120,128,.20);--fill-2:rgba(120,120,128,.12);--red:#D70015;--red-ink:#D70015;--red-bg:#FDE8EA;
--ok-ink:#1D7A33;--ok-bg:#E3F4E7;--amber-ink:#C93400;--amber-bg:#FFF0E3;--badge-bg:#D70015;--badge-ink:#FFF;
--teal:#06736C;--teal-ink:#06736C;--teal-soft:#E1F1EF;--focus:#06736C;--radius:16px;--radius-field:10px;--shadow:none;
--bar-bg:rgba(249,249,249,.94);--t-large:2.125rem;--t-title3:1.25rem;--t-headline:1.0625rem;--t-body:1.0625rem;
--t-sub:.9375rem;--t-foot:.8125rem;--t-cap:.75rem}
/* dark, for [data-theme=dark] and inside @media(prefers-color-scheme:dark){html:not([data-theme=light]){…}} */
{--bg:#000;--surface:#1C1C1E;--surface-2:#2C2C2E;--surface-3:#3A3A3C;--pressed:#3A3A3C;--ink:#FFF;--ink-2:#EBEBF5;
--muted:#AEAEB2;--muted-2:#8E8E93;--border:#38383A;--border-strong:#7C7C80;--field-border:#7C7C80;
--fill:rgba(120,120,128,.36);--fill-2:rgba(120,120,128,.24);--red:#FF6961;--red-ink:#FF6961;--red-bg:#3A1A1C;
--ok-ink:#30D158;--ok-bg:#12301C;--amber-ink:#FF9F0A;--amber-bg:#3A2A10;--bar-bg:rgba(22,22,24,.94)}
```

In dark mode the accents keep their dark values, and `--on-accent` stays `#0b1218` (9.85:1 on the dark teal).

### 0.3 Components

Phase 1 is CSS plus the helper changes named below, with no change to the views' markup. Phase 2 is the per-screen markup moves, done screen by screen, mostly with the builds of Part 11.

**C1 The navigation bar** (`header.topbar`, `views/layout.php`).
- **Size and surface.** 44 px plus `env(safe-area-inset-top)`, on `--bar-bg` with `backdrop-filter: saturate(180%) blur(20px)` and a hairline at the bottom.
- **Left.**
  - On a top-level page: the club's mark, from `brand_block()` as today.
  - On every other page: `<a class="nav-back">`, a chevron plus the parent page's name, tinted, at least 44 × 44, for example „‹ Schüler".
  - If the parent's name is longer than 12 characters the label reads „Zurück".
- **Centre.** `<span class="nav-title" aria-hidden="true">`, the page title, fading in once the large title has scrolled away:
  - `@supports (animation-timeline: scroll()) { … animation-timeline: scroll(root); animation-range: 40px 80px }`;
  - where it is not supported, it is not shown.
- **Right.** The bell and the account menu, 44 × 44 each.
- **The language switch** leaves the signed-in bar; the language is set in Mein Konto. It stays on the public pages (sign-in, the link pages), where a family that reads no German must find it before signing in (owner).
- **Helpers.**
  - `page_head()` keeps its signature and also records the title for the bar, read with a new `page_title()`.
  - A new `nav_back(string $page, ?array $user=null): ?array` (`['href'=>…, 'label'=>…]`) sits beside `nav_owner()` and is one table:

| Page | Back goes to |
| --- | --- |
| a child's page, staff | „Schüler" |
| a sub-page of a child's page | the child's page, „{Vorname}" |
| a course | „Kurse" |
| a sub-page of a course | „{Kurs}" |
| a chat thread | „Chats" |
| one news item | „Neuigkeiten" |
| the pay steps (G2) | where they came from: „Übersicht" or „Beiträge" |
| wizard steps | the previous step |
| Kurse, Geld, Rechnungen, Verwaltung, Einstellungen, Mein Konto (staff) | „Mehr" |

  - The back button goes up the hierarchy, as an iOS navigation stack does, never `history.back()`.
- **Desktop (≥761 px).** As today, restyled with the tokens.

**C2 The large title** (`.page-heading`).
- h1 at Large title, with the description as one line of Subheadline in `--muted`.
- A screen's one primary action, such as „+ Schüler anlegen", sits under the title as a full-width filled button on phones.
- `page_head()`'s signature is unchanged.

**C3 The tab bar on phones** (`nav.mobile-nav`).
- **Size.** 49 px plus `env(safe-area-inset-bottom)`, on `--bar-bg` with blur and a hairline on top.
- **Entries.** Icon 24 px over a 12 px label, both fixed px: the bar is chrome, like iOS's, which does not scale with text size.
- **States.**
  - Inactive is `--muted`.
  - Active is the tint, with no pill.
  - Counts are red capsules (`--badge-bg`).
- **The trainer and administrator:** Übersicht · Schüler · Anwesend · Chats · Mehr.
  - The set is today's; the short label „Post" becomes „Chats", and a screen reader still hears „Nachrichten".
- **A family:** Übersicht · Beiträge · Chats · Profil. Four entries, so each is 80 px wide at 320 instead of 64.
  - „Neues" goes: news reaches families through the bell (G5) and the overview's news group.
  - „Konto" (Mein Konto) becomes a row on Profil, „Anmeldung und Darstellung".
  - This is a change families will notice (owner).
- **„Mehr" for staff** becomes a page (`?page=more`): an inset list of Kurse (count), Geld (count), Neuigkeiten, Verwaltung or Einstellungen, Mein Konto, Hilfe und Datenschutz, Abmelden.
  - Today it slides in the side menu, a web pattern decided in ADR 0011 → architect.
  - A page needs no JavaScript and gives every screen behind it a „‹ Mehr".
- **Helpers.** `mobile_nav_entries()` and `nav_entries()` change their family entries; `nav_owner()` gains `more`.

**C4 The desktop sidebar** (≥761 px) stays as the primary navigation with every entry, in its own colours (the club's „Menüfarbe", ADR 0013). Only its rows take the row shape: 44 px, 16 px radius on the selected one. No colour change; the iOS language is phone-first.

**C5 Inset grouped lists.** These replace the cards and record rows.
- **Markup.** `<h2 class="group-header">` (new), then `<div class="card list">` (`list` is a new modifier, padding 0), then the rows, then an optional `<p class="group-footer">` (new).
- **`.card`** itself becomes the group box: no border, no shadow, radius 16, padding 16. A box of free content, such as a form or a sentence, stays a plain `.card`.
- **Rows.** The existing row classes are restyled as one rule set, so no markup changes for them in Phase 1: `.record-row`, `.student-card`, `.payment-row`, `.class-row`, `.editor-list-item`, `.member-row`, `.notification`, `.timeline-item`.
  - At least 44 px tall, 60 px with a subtitle.
  - Title in Headline; subtitle in Subheadline `--muted`.
  - Value right in a new `.row-value` (`--muted`, tabular).
  - An inset hairline between rows; `:active` shows `--pressed`.
  - Rows that lead somewhere end in `icon('chevron')`, in `--muted-2` (3.44:1, so it meets the 3:1 rule; iOS's `#C7C7CC` is 1.68), instead of today's `icon('arrow')`.
  - **Action rows** (iOS Contacts style): a row whose whole width is one button, text in the tint, or red for something destructive, with a leading icon. Markup: `<form><button class="row-action">…</button></form>`, or `<a class="row-action">`.
- **Headers in Phase 1.** An `<h2>` that is today the first child of a card is styled as a title row inside the group. Phase 2 moves it above the box as `.group-header` when a screen is rebuilt.
- **Header style.** Group headers are Subheadline 600 `--muted`, in sentence case. iOS's uppercase Footnote reads badly in German („NOTFALLKONTAKTE").

**C6 Buttons.**

| Class | Kind | Look |
| --- | --- | --- |
| `.button` | filled | tint fill, `--on-accent` text |
| `.button.secondary` | tinted | `--teal-soft` background, `--teal-ink` text, 4.90:1 |
| `.button.subtle` | plain | no background, tint text |
| `.button.danger` | destructive filled | red fill, white text, 5.38 |
| `.subtle.danger-text` | destructive plain | red text |

- All are capsules in Headline type, at least 44 px tall.
- A screen's primary button is 50 px and full width on a phone, inside the existing sticky `.form-footer`.
- At most one filled button per screen.
- **Pressed:** `:active { opacity: .7 }`.
- **Busy:** with JavaScript, the submitter gets `aria-busy="true"` and a spinner, and further taps are ignored. The server's request id already refuses a second post.
- **Helpers:** `submit_button($label='', $class='primary', $name='', $value='', string $icon='')` and `link_button($label, $page, $params=[], $class='primary', string $icon='')` each gain `$icon`, printed as `icon($icon)` before the label (ADR 0026 §4: an icon beside a short word).

**C7 Switches.**
- **Helper.** `check_field(…, bool $switch=false)` gains `$switch`. It prints `<label class="check switch">` with `<input type="checkbox" role="switch">`; label left, switch right, as in iOS Settings.
- **Drawing.** The input has `appearance:none`: a 51 × 31 track with a 27 px knob.
  - On: the tint, 4.94:1 against white.
  - Off: `--fill` with a 1 px `--field-border` outline (3.44:1), because iOS's own off track is 1.21:1.
  - The knob moves in 200 ms.
  - The 44 px target is the whole row.
- **Without JavaScript** it is still a checkbox; without CSS, a plain one.
- **Use it for** on/off settings: the three mail ticks in Mein Konto, „Jeden Monat automatisch anlegen", „Im Portal veröffentlichen", „Auch per E-Mail" (G7), the archive ticks, and the registry's booleans.
- **Not for** an acknowledgement: „Ich habe die Datenschutzhinweise gelesen" stays a checkbox.

**C8 Segmented controls**, in place of underlined tabs.
- **`tabs()`** draws one when it has two or three entries:
  - a `--fill-2` capsule track with 2 px padding;
  - equal segments, each a link at least 44 px tall (iOS uses 32; our 44 wins);
  - Footnote 600 labels;
  - the current one a `--surface` thumb with the one allowed shadow, `aria-current`.
  - `tabs()` gains an optional aria label (default „Bereiche"), so a sort reads „Sortieren".
- **Label fit at 320.** At 13 px semibold on the wide test font, a segment holds 126 px with two segments, 78 px with three and 55 px with four.

| Strip | Widest label | Fits? |
| --- | --- | --- |
| Geld: Beiträge · Rechnungen | 91 | yes |
| Kurse: Kurse · Anfragen (12) | 102 | yes |
| A–Z · Nach Alter | 77 | yes |
| Offen · Bezahlt | 55 | yes |
| Änderungen: Alle · Von Familien | 94 | yes |
| Verwaltung: Mitgliedschaft · Geld & Zahlungen | 131 | no (111 in Liberation); rename the second „Bankkonto" |
| a course's four tabs | 95 | no → drill-down list |
| a child's five to seven tabs | 95 | no → drill-down list |

- **Drill-down lists.** The child's page and the course page each open as a list of rows with chevrons; each row opens today's tab as its own page with „‹ {name}". That is iOS Settings or Contacts, and it ends the tab strips that run off the screen (0.7).
- **The attendance control** (`.segmented`, radios) keeps its markup and takes the same look. „Entschuldigt" (92 px; 79 in Liberation) does not fit a third of 320, so it keeps today's wrap to two rows.

**C9 Form rows.**
- **Short values on one row** (selects, dates, times, numbers, switches): inside `.card.list`, a `.field` becomes a row with the label left (Headline) and the control right, borderless, its value and the native chevron showing.
- **Free text stays stacked**, label above and input below: names, addresses, e-mail, passwords, notes, IBAN. Long values read better at 320.
- **Stacked inputs:**
  - 44 px tall at Body;
  - `--radius-field`;
  - a 1 px `--field-border` (3.44:1; today's `#c9d5de` border is about 1.4:1).
- **Pure CSS on today's markup:** `.card.list .field:has(select, input[type=date], input[type=number])`.

**C10 Pickers** are the native ones: `<input type=date>`, `<select>`, and `time_field()`'s two selects, which become native wheels on iOS. No custom picker.

**C11 Sheets and action sheets**, for confirmations and destructive actions.
- **Markup** stays a `<details class="sheet" data-sheet>`. Today these are `.danger-zone` and `.account-delete`.
- **Without JavaScript** it opens in place, as today.
- **`app.js`** turns a tap on its summary into a `<dialog>`:
  - `showModal()`;
  - a grabber, the summary as the title, the content moved in, and „Abbrechen", which closes it and moves the content back;
  - Escape and a tap on the backdrop close it;
  - it slides up in 250 ms (no slide under reduced motion);
  - 20 px top corners, at most 560 px wide on a desktop;
  - the dialog is labelled by its title.
- **Helpers** add the attribute, with no signature change: `login_delete_details()`, `invitation_withdraw_details()`, `signin_link_details()`, and the views' danger zones.

**C12 Badges.**
- `badge()` keeps its API: capsules, Footnote 600, colours as in 0.2 (measured 4.59–7.09).
- Grey is `--fill-2` with `--muted`.
- Counts on the tab bar and the bell are red with white text in both themes (5.38). The dark-mode red with white text was 2.82, found by the audit.

**C13 Empty states.**
- `empty_state($title, $body='', $action='', string $icon='users')` gains `$icon`.
- Layout:
  - a 32 px icon in a 64 px `--fill-2` circle;
  - the title in Title 3;
  - one sentence in Subheadline `--muted`;
  - one filled button.

**C14 The banner** (`.flash`).
- It stays in the page, at the top of `<main>` under the bar.
- Radius 12, no border, a check or exclamation icon, one sentence in Subheadline 600.
- Success is `--ok-bg` with `--ok-ink`; an error is `--red-bg` with `--red-ink`; `role="status"` as now.
- It does not fade away: people here read slowly.

**C15 The progress line for wizards.**
- A new `wizard_progress(int $step, int $total): void` prints `<ol class="steps" aria-hidden="true">`: one capsule per step, 4 px high. Done steps and the current one are tinted; the rest are `--fill`.
- It sits under the large title.
- The words „Schritt 2 von 3: …" stay in `page_head()`'s description, for reading and for screen readers.
- Used by `student_new`, G1 and G2.

**C16 Icons.**
- **Style**, in the spirit of SF Symbols but our own paths (SF Symbols' licence forbids them on the web): a 24 × 24 grid, a 2 px margin, `stroke-width` 1.75 (today 1.7), round caps and joins, `currentColor`.
- **Sizes:** 20 px in rows and buttons, 24 px in the tab bar, 32 px in empty states.
- **The existing set** (home, users, mail, news, settings, wallet, arrow, plus, check, lock, logout, calendar, more, bell, camera, eye, help) already has this style.
- **Changes to the set:**
  - `arrow` stops being the row's disclosure; `chevron` takes over.
  - Messages use `chat`, not `mail`; `mail` stays for e-mail.
  - Mein Konto uses `person`, not `lock`.
  - `mic` goes with voice notes (ADR 0022 §11).
- **New paths:**

```php
'chevron'=>'<path d="m9 5 7 7-7 7"/>',                           // rows; flipped with scaleX(-1) for „Zurück"
'chat'   =>'<path d="M20.5 11.5c0 4.1-3.8 7.5-8.5 7.5-1.3 0-2.6-.3-3.7-.8L4 19.5l1.3-3.6A7 7 0 0 1 3.5 11.5C3.5 7.4 7.3 4 12 4s8.5 3.4 8.5 7.5Z"/>',
'person' =>'<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
'sick'   =>'<path d="M14 14.8V5a2 2 0 0 0-4 0v9.8a4 4 0 1 0 4 0Z"/><path d="M12 11v6"/>',      // a thermometer
'cancel' =>'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18M10 14l4 4m0-4-4 4"/>',
'copy'   =>'<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h8"/>',
'search' =>'<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/>',
```

### 0.4 Motion and feedback

**Pressed states.**
- Rows (`a.student-card`, `a.payment-row`, `a.class-row`, `a.editor-list-item`, `a.notification`, `.row-action`, the tab bar) show `background: var(--pressed)` on `:active`.
- Buttons, text links and segments show `opacity: .7`.
- Transition: 100 ms.

**The iPhone needs one line for `:active`.** iOS Safari shows `:active` only when a touch listener exists, and an inline `ontouchstart` is refused by the portal's CSP. So:
- `app.js` adds `document.addEventListener('touchstart', () => {}, {passive: true});`.
- It also adds the class `js` to `<html>`.
- Only `html.js` sets `-webkit-tap-highlight-color: transparent`, so without JavaScript the system's grey flash remains the feedback.

**Between pages.**
```css
@view-transition { navigation: auto; }
.topbar { view-transition-name: navbar; }
.mobile-nav { view-transition-name: tabbar; }
```
- The bars stay still and the content cross-fades in about 200 ms, in Chromium 126+ and Safari 18.2+.
- Elsewhere navigation is instant.
- No push or pop slide: a page cannot tell forward from back without script, and a slide the wrong way is worse than none.

**Elsewhere.** Sheets slide up in 250 ms and switches move in 200 ms. Nothing else moves.

**Reduced motion.**
- `@media (prefers-reduced-motion: reduce) { @view-transition { navigation: none } }`.
- Today's `*{transition:none!important}` under reduced motion stays.

### 0.5 The home-screen app

- **Already in place:** `viewport-fit=cover`, `apple-mobile-web-app-capable`, the manifest's `display: standalone`, and `theme-color` per scheme (`views/layout.php`).
- **The status bar** stays `default`. `brand_theme_colour()` returns the new grounds, `#F2F2F7` and `#000000`. Whether the status-bar text follows dark mode in standalone is checked on a real iPhone (Part 13).
- **Safe areas.**
  - Top: the bar's padding includes `env(safe-area-inset-top)`.
  - Left and right: main, the top bar and the tab bar pad with `max(16px, env(safe-area-inset-left/right))`, for landscape.
  - Bottom: as today.
- **Standalone has no browser chrome.**
  - There is no Back, so every non-root screen has its own back button (C1).
  - Files open with `target="_blank"` (invoice and confirmation PDFs, receipt photos), so they appear in Safari's sheet with „Fertig" instead of stranding the app on a file with no way back.
  - There is no pull-to-refresh, so every action ends on a fresh page (it does: post, redirect, get) and the bell counts on every view.

### 0.6 Accessibility

- **44 pt targets.**
  - Rows at least 44, buttons 44 (50 for the primary), the tab bar 49, segments 44, switches by their whole row.
  - The 2-px-tall wizard progress bar is decoration, `aria-hidden`.
- **Contrast of at least 4.5:1** for text in both themes: the token table, and 0.7 for measured screens.
  - Text on `--teal-soft` always uses `--teal-ink`; tint text on its soft background is the failure found today.
- **Text scaling.**
  - `rem` everywhere except the bars' 12 px labels and 24 px icons.
  - The in-app „Schriftgröße" becomes 112.5 %, 125 % and 150 % (today 17.5, 19 and 21 px).
  - The layout must survive 200 %: measured below, today and with the Part 0 stylesheet.
  - Following the iPhone's own text size (`font: -apple-system-body` when „Schriftgröße" is left as on the device, limited to iOS with `@supports (-webkit-touch-callout: none)`, because macOS Safari answers 13 px) is a later step after a real-iPhone test (open issue 9).
- **Focus.** `:focus-visible` gets a 3 px `--focus` outline with a 2 px offset; the tint is at least 3:1 on both grounds (5.71 and 5.12 for the default).
- **Screen readers.**
  - The visually-hidden pattern stays.
  - `aria-current` on the tab bar, segments and the sidebar.
  - `role="switch"` on switches.
  - The bar's small title is `aria-hidden`.
  - The back link is named „Zurück zu Schüler".
  - The progress bar is hidden, with its words in the description.
  - Sheets are labelled by their title.
  - Copy buttons announce „Kopiert" (`role="status"`, as now).

### 0.7 The audit: today's portal against Part 0

**Method.**
- 25 screens: 16 for the trainer, 9 for a family.
- Each at 320 and 390, light and dark: 100 measurements.
- For every visible text, the contrast against the background actually behind it, with translucent layers composited and visually hidden text skipped.
- Counts of cards, rows, tab strips, buttons and shadows, words in `<main>`, and the type in use.
- 200 % text (a 32 px root) on 9 screens at 320 and 390: sideways scroll, elements wider than the screen, and text under a button. Folds that are closed and the fixed tab bar are excluded; Chromium lays out a closed `<details>` for measuring, which my first version mistook for 25 overlaps.

**Today.**
- **Type.** Page titles are 26.4 px/720 (Large title is 34/700) and body is 16 px (iOS 17). There are 5–9 type sizes per screen and 30 different `font-size` declarations in `app.css`. The fixed 16 px root ignores the reader's text size.
- **Boxes.** Every screen is cards with a 1 px border and a shadow: 1–7 cards and 4–15 shadowed elements per screen, in five radii.
- **Rows** sit in 26 px-padded cards, with full-width borders and → arrows.
- **Tab strips overflow** on the child's page (family 6, staff 7), the course page (4) and Verwaltung (6), at 390 too. At 320 the family's Kurse (x 385–452) and Abwesenheit (x 457–572) tabs are off the screen even while open.
- **Buttons.** 9 px radius; not one `:active` rule anywhere, so no tap gives visual feedback; no busy state.
- **Tab bar.** A tinted pill on the active entry; teal counts; labels fixed at 12 px.
- **Navigation bar.** 64 px, holding the brand, EN/DE, the bell and the avatar.
- **No back button** on any page. In the home-screen app a screen below the top level has no way back but the links in its content („Alle Schüler" and „Alle Kurse" are page-heading buttons on staff pages only).
- **Contrast in light mode**:
  - the lowest is 3.33:1 (the attendance „–" mark);
  - on 19 of 25 screens the tint on its soft background reads 4.33:1: the active tab-bar entry, active chips, the „Noch zu ergänzen" links, „Offen (0)" on Rechnungen, the selected course chip on Anwesenheit.
- **Dark mode passes everywhere** (lowest 5.21).
- **200 % text.**
  - No sideways scroll on the 9 screens at 320 or 390.
  - One overlap: today's row on the trainer's overview at 390. At 100 % it is already there at 320: „Anwesenheit" covers „Jugendtraining" and the time by 20 px. `tests/mobile.mjs` does not test overlap, and passed it.
  - Three things do not scale (px): the brand mark, the emoji cells and the tab-bar labels.
- **Words in `<main>`:** trainer 42–274, family 29–181.

**With the Part 0 stylesheet** (0.2 and C1–C16's CSS, Phase 1, injected on today's markup; no view changed):
- Measured on 15 of those screens (8 trainer, 7 family), × 4:
  - h1 at 34/700, body 17, capsule buttons;
  - lowest contrast 4.59 in light and 5.38 in dark: no text under 4.5;
  - no sideways scroll and nothing wider than the screen at 200 %.
- `tests/mobile.mjs`'s own rules (loaded from the file, not rewritten), run on 24 screens × 4 = 96: **0 problems**.
- The first run found three failures of my own, now in the tokens above:
  - a selected chip's subtitle at 1.05:1;
  - the „–" mark at 3.44;
  - the dark red count badge at 2.82.
- What the check does not fix: the segmented controls still need the 16 px margins (they run to the screen edge today), and the students list's three chips stack one per line at 320 (Part 1 makes them a segmented control).

**What changes globally**, ranked by how much it changes how the portal feels against its size:

| # | Change | Where | Feel | Size |
| --- | --- | --- | --- | --- |
| 1 | Tokens and type (0.2), `html` 100 %, the system stack | `app.css`; `app/brand.php`'s two surface constants and its checks against the ground | very high | S |
| 2 | Groups without border or shadow; rows with inset hairlines, chevrons, pressed state | `app.css`; `icon('chevron')` in `student_card()` and about ten views | high | S |
| 3 | Capsule buttons; tinted, plain, destructive; pressed and busy | `app.css`; `app.js` (the touchstart line, `html.js`, busy) | high | S |
| 4 | View transitions | `app.css`, six lines | medium–high | XS |
| 5 | Tab bar: no pill, 24 px icons, red counts, „Chats"; the family's four entries (owner) | `app.css`; `mobile_nav_entries()`, `nav_entries()`; Profil gains the account row | high | S (M with the family set) |
| 6 | Contrast fixes: text on soft uses `--teal-ink`, the „–" mark uses `--muted`, default teal `#06736C` | `app.css` | small but required | XS |
| 7 | The navigation bar: back button and parent name, title on scroll, no language switch | `views/layout.php`; `nav_back()`, `page_title()` in `app/ui.php` | high (and the home-screen app needs it) | M |
| 8 | Segmented controls for 2–3 entries | `tabs()`, `app.css`; „Bankkonto" | medium | S |
| 9 | Switches | `check_field($switch)`; Mein Konto, Geld, Neuigkeit, the registry | medium | S |
| 10 | Banner, empty-state icon, wizard progress | `app.css`; `empty_state($icon)`, `wizard_progress()` | medium | S |
| 11 | Sheets for destructive folds | `app.js` (dialog), `data-sheet` in three helpers and the views' danger zones | medium | M |
| 12 | Drill-down lists for the child's page and the course page | `views/student.php`, `views/classes.php` | high on those screens | L |
| 13 | „Mehr" as a page (architect, ADR 0011) | new `views/more.php`, `layout.php`, `app.js` loses the drawer code | medium | M |

**Per screen** (Phase 2 markup, beyond the global CSS):

| Screen | Today (320, measured) | In Part 0 |
| --- | --- | --- |
| Trainer: Übersicht | 4 stat tiles (332 px), today's row 1.45 screens down with a 20 px overlap | Part 2.2: groups Heute, Zu tun, Termine; the tiles go (owner) |
| Trainer: Schüler | first child 1.66 screens down under a 642 px filter card | Part 1: search field, two segmented controls, filter as a disclosure row, sections by age |
| Child's page (both) | 6–7 underlined tabs, off screen | a drill-down root: header (initials, name, status), then rows; each tab its own page with „‹ {Vorname}" (staff also: Zugang zum Portal; „Schüler löschen" as a destructive sheet) |
| Kurse / one course | the course's 4 tabs overflow; Termine's form 2.5 screens down | the course root as rows: Kinder, Tarife, Termine, Anwesenheit; Termine as a list of date rows, one tap opens a date's form |
| Anwesenheit | first child 1.17 screens down under course chips | the course chips become a segmented control when there are two or three courses, else a select row; rows as Part 0 |
| Geld | the first open charge 1.73 screens down under the 767 px „Beiträge anlegen" | segmented Beiträge · Rechnungen; Part 8's receipts group first; „Beiträge anlegen" folds behind a row „Monatsbeiträge" › with its status as the value |
| Chats | composer as built | rows with chevrons; nothing else (ADR 0022 §11 is its own spec) |
| Family: Übersicht | 2,518 px; the receipt button 1.17 screens down | Part 2.1 |
| Family: Beiträge | two 700 px QR boxes; upload 4.25 screens down | Part 5: groups Offen and Bezahlt; paying is Part 4 |
| Family: Profil | the form under 6 tabs | the drill-down root above |
| Mein Konto | 4 cards, 15 shadows | groups; the mail ticks as switches; „Abmelden" as a destructive action row |
| Sign-in and the link pages | auth card | one group on the ground; the large title in place of the eyebrow |

---

## Part 1. Age on the students list

> **Revised 2026-10-08.** The owner keeps levels and the age bands; a child's band comes from the birth date only. „Part 1, revised 2026-10-08", after this part, replaces what it names here.

**What the trainer is trying to do.**
- „Wer sind meine Neun- bis Zwölfjährigen?" She asks it planning at home, or in the hall when she splits the group.
- She is on her phone, with one hand.
- Today she cannot get the answer: the list sorts by last name only, the card shows a band name („Unter 12") instead of an age, and the age filter is a configured band that round 3 removes.

**The open question: whole years or Jahrgang (the trainer's to answer).**
- **What she would see either way**, with two children born in 2009: David Fuchs (20.09.2009) and Anna Lechner (14.12.2009).

| | Whole years | Jahrgang |
| --- | --- | --- |
| Section headers | „16 Jahre" (Anna), „17 Jahre" (David) | „Jahrgang 2009" (both) |
| The card | „16 Jahre", „17 Jahre" | „16 Jahre", „17 Jahre" (unchanged) |
| The filter | „Alter von 16 bis 16" → Anna | „Jahrgang von 2009 bis 2009" → both |
| When it changes | on the birthday: Anna moves on 14.12. | on 1 January; headers never change, they are years |

- **Recommendation: whole years.** Four reasons:
  1. ADR 0026's note already says „Alter von – bis", in whole years.
  2. The bands the club used were whole years (commit `1f37b61`: „Unter 12, Jugend 12–17, Erwachsene").
  3. Families and children say „9 Jahre".
  4. The two functions that stay, `student_age()` and `latest_birth_date_for_age()`, count whole years.
- Badminton's age classes count by Jahrgang, so if the trainer groups children as for tournaments, Jahrgang is right.
- **Ask her before round 3 ships:** „Wenn du die Kinder nach Alter einteilst: Zählst du, wie alt sie heute sind, oder nach Jahrgang wie bei Turnieren?"
- **If she answers Jahrgang**, only the header key, the filter's two labels („Jahrgang von / bis") and its bounds change; the screen, the sort and the card do not.

**The screen at 320** (trainer and administrator; families never see this list). Top to bottom:
1. **Large title „Schüler"**, with „{n} in dieser Auswahl" under it, and the filled button „+ Schüler anlegen" (icon `plus`). The 2026-10-05 spec's §1 already drops „Per E-Mail einladen" from the heading.
2. **The existing notices and the open-invitations fold**, unchanged.
3. **A search field** (new `.search`).
   - A GET form with `q`, at Body size.
   - A `--fill-2` capsule, the `search` icon, placeholder „Suchen", a visually hidden label.
   - It leaves the filter fold: finding a child is the commonest reason to open the list.
4. **Segmented control „Alle | Überfällig | Krank"** (`tabs()`, three links: none, `overdue=1`, `absence=sick`). These are the two chips ADR 0026 §8 keeps, in Part 0's form.
5. **A disclosure row „Filter"**: a `<details>` whose summary is a row with a chevron. When a filter is set, the summary shows it as the value, for example „9–12 Jahre · Kindertraining" (from `filter_summary()`), truncated.
   - Inside, a group of form rows:
     - Kurs (select row);
     - Mitgliedschaft (select row);
     - Alter, two number boxes on one row: „Alter von [ ] bis [ ]", `inputmode="numeric"`, `min="0"`, `max="99"`, each at least 44 px;
     - „Anwenden", a full-width tinted button.
   - The filter's „Aktuell abwesend" select and „Nur überfällige Beiträge" tick leave the form: the segmented control does the same.
6. **Segmented control „A–Z | Nach Alter"** (`tabs()`, two links that keep the filters; aria label „Sortieren").
7. **A line, only when an age range is set** and children without a birth date would otherwise match: „2 Kinder ohne Geburtsdatum sind nicht dabei." plus a plain button „Zeigen". It goes to the same selection without the range, sorted by age, at `#no-birth-date`.
8. **The list.**
   - **A–Z:** one group, rows as today with a chevron.
   - **Nach Alter:** one group per age, youngest first.
     - Group header: „7 Jahre" left and the count right, in Headline (at Title 3, „Ohne Geburtsdatum" wrapped its count to a second line at 320; measured).
     - Within an age: birth date descending, then last name.
     - Children without a birth date come last, under „Ohne Geburtsdatum" (`id="no-birth-date"`).
   - **Each row:**
     - initials;
     - the name in Headline;
     - the subtitle „{n} Jahre · {price}" or „Geburtsdatum fehlt · {price}" (the level and the band go);
     - the status badge;
     - an overdue amount as a red second subtitle;
     - a chevron.
9. **Pagination** as today, carrying `sort` and the filters. A section continues on the next page under the same header, with the count of the whole section.

**Text.**
- `t('Suchen','Search')`
- `t('Alle','All')`, `t('Überfällig','Overdue')`, `t('Krank','Sick')`
- `t('Filter','Filter')`, `t('Anwenden','Apply')`
- `t('Alter von','Age from')`, `t('Alter bis','Age to')`
- `t('A–Z','A–Z')`, `t('Nach Alter','By age')`; aria label `t('Sortieren','Sort')`
- Headers and the card: `plural($age,'Jahr','Jahre','year','years')`; `t('Ohne Geburtsdatum','No date of birth')`; `t('Geburtsdatum fehlt','No date of birth yet')`
- The filter's summary values: `strtr(t('{from}–{to} Jahre','{from}–{to} years'),…)`, `strtr(t('ab {from} Jahren','{from} and older'),…)`, `strtr(t('bis {to} Jahre','up to {to} years'),…)`
- `plural($n,'Kind ohne Geburtsdatum ist nicht dabei.','Kinder ohne Geburtsdatum sind nicht dabei.','child without a date of birth is not included.','children without a date of birth are not included.')`, `t('Zeigen','Show')`

**Without JavaScript.** Everything is a GET link or a GET form, and `<details>` opens natively. Nothing else is needed.

**Empty and error states.**
- **No students at all:** `empty_state(t('Noch keine Schüler','No students yet'), t('Leg den ersten an – Schritt für Schritt.','Add the first one – step by step.'), link_button(t('Schüler anlegen','Add student'),'student_new',['from'=>'students'],'primary','plus'), 'users')`.
- **A selection with nobody in it:** `empty_state(t('Niemand in dieser Auswahl','Nobody in this selection'), '', link_button(t('Alle Schüler','All students'),'students',[],'secondary'))`.
- **Bad values in the address are ignored.** Anything that is not a whole number from 0 to 99 leaves its box empty; nothing is refused, because a GET filter is never refused. If „von" is greater than „bis", the two are swapped. An unknown `sort` means A–Z.

**The way back.** The list only reads. „Alle" in the first segmented control clears the selection, and „A–Z" returns the order.

**What a family sees.** No list. Their own child's age shows on their pages:
- **Persönliche Daten:** the birth date's hint becomes `plural($age,'Jahr alt','Jahre alt','year old','years old')` (without „· Altersgruppe: …").
- **When the birth date is missing:**
  - for a family, `t('Fehlt noch. Deine Trainerin teilt die Gruppen nach dem Alter ein.','Still missing. Your coach groups children by age.')`;
  - for staff, `t('Fehlt noch. Ohne Geburtsdatum steht das Kind beim Sortieren nach Alter ganz unten.','Still missing. Without it the child comes last when sorted by age.')`.
- **The same family sentence replaces „Danach richtet sich die Altersgruppe."** in `family_next_steps()` and on the activation page.
- **`student_new`'s hint:** `t('Für die Sortierung nach Alter. Kann auch später ergänzt werden.','For sorting by age. Can be added later.')`.

**Reuses.**
- `filters_from()`, `filtered_students()`, `render_filters()` (reworked), `student_card()`.
- `student_age()`, `latest_birth_date_for_age()`.
- `tabs()`, `plural()`, `badge()`, `empty_state()`, `link_button()`.
- `filter_summary()`. ADR 0026 §8 removes it with saved views; this spec keeps it, without levels, bands, tariffs and fields, and with the age → architect.

**Takes away.**
- The level and age-group selects, and the „Einteilung" card's two selects (round 3).
- The „Tarif und eigene Felder" fold and the saved views (round 2).
- „Nach Nachnamen sortiert" and „Auswahl anschreiben".
- The always-open 642 px filter card.
- Proposed: the filter's „Aktuell abwesend" select and „Nur überfällige" tick.

**Schema.** None: `students.birth_date` is enough.

**For backend-dev.**
- **Sort.** `sort=age` gives `ORDER BY s.birth_date IS NULL, s.birth_date DESC, s.last_name, s.first_name, s.id`; anything else gives today's order. It is a literal chosen from a fixed list (ADR 0026 §12).
- **Range.**
  - Bounds, in whole years: `s.birth_date IS NOT NULL AND s.birth_date <= latest_birth_date_for_age($from)`, and `s.birth_date > latest_birth_date_for_age($to + 1)` when „bis" is set.
  - Jahrgang variant: `s.birth_date BETWEEN '{from}-01-01' AND '{to}-12-31'`, with the years bounded 1900 to this year.
- **The „nicht dabei" count** is the same selection without the range, with `birth_date IS NULL`.

**Measured** (prototype, 320 and 390, light and dark: 0 problems).
- First child: 576 px (0.80 screens) with today's styles and the filter folded, against 1,188 (1.66) today.
- In Part 0's layout and stylesheet: 599 (0.84) at 320 and 541 at 390.
- The filter unfolded, in this order: 406 px, against 642 today.

---

## Part 1, revised 2026-10-08

**What changed.**
- The owner, 2026-10-07 at 19:03 UTC: "Skill levels were good to have / age levels will also be needed, but it would be enough if the app can dynamically output in which age group one falls in".
- What stays: the levels, and the age bands the trainer edits under Verwaltung › Altersgruppen.
- What changes: a child's band is worked out from the birth date alone. The pin goes. „Alter von – bis" is not built, because the bands do that job.
- Ages count as today, in whole years: the owner chose age over Jahrgang, so Part 1's open question and its table are closed.
- Everything in Part 1 not named below stands: the search field, „Alle | Überfällig | Krank", the „A–Z | Nach Alter" control, pagination, the empty states.

**What the trainer is trying to do.**
- „Wer ist bei mir in der Jugend, und wer in Unter 12?"
- She answers it in her own bands, which she named under Verwaltung, and sees each child's level beside it.
- Today the list sorts by last name only, and a pinned child can show a band their age left long ago: Sophie Reiter (14) reads „Erwachsene".

**The filter disclosure** (replaces Part 1's item 5).
- **Summary row:** „Filter" on the left.
  - When something is chosen, the chosen values on the right in the fields' order, joined by „ · ", on one line cut with an ellipsis: „Kindertraining · Anfänger · Unter 12".
  - Nothing chosen: only „Filter".
  - The search and the segmented choice are not repeated there, because both are visible on their own.
- **Form rows, in this order:**
  1. Kurs;
  2. Mitgliedschaft;
  3. Leistungsgruppe: the levels in Verwaltung's order;
  4. Altersgruppe: the bands in Verwaltung's order, archived ones left out, then „Ohne Altersgruppe";
  5. „Anwenden", a full-width tinted button that keeps the search and the sort.
- **The line under the controls** (replaces item 7), only when a band is chosen and children without a birth date would otherwise match: „2 Kinder ohne Geburtsdatum sind nicht dabei." plus „Zeigen", which goes to `age_group=none&sort=age#no-birth-date`.

**„Nach Alter"** (replaces item 8's age sections).
- **One section per band**, in Verwaltung's order (`age_groups()`: `sort_order`, `min_age`, `id`). A band with nobody in the selection gets no section.
- **Section header**, using the built `.section-heading`:
  - the band's name in Headline, not Title 3 (see Measured);
  - under it, the band's span from `age_group_range()`: „bis 11", „12 bis 17", „18 und älter";
  - on the right, the count as a grey `badge()`.
- **Inside a section**, rows come in Part 1's sort: youngest first, then by name.
- **A child appears once**, under the first band in Verwaltung's order that covers their age, so overlapping bands do not repeat anybody. Verwaltung's counts and the child's page say the same band. Archived bands place nobody.
- **After the bands, „Ohne Altersgruppe"**: children whose age no band covers, when the trainer's bands leave a gap (`age_group_warnings()` names the gap under Verwaltung).
  - Rows show the age.
  - Footer: „Keine deiner Altersgruppen passt." plus a plain link „Altersgruppen ansehen", which goes to `manage&tab=ages`.
- **Last, „Ohne Geburtsdatum"** (`id="no-birth-date"`): header and count, no footer.
- **No bands at all** (every band archived or deleted): a notice above the list, „Es gibt noch keine Altersgruppen." with „Altersgruppen anlegen". Every child with a birth date is then under „Ohne Altersgruppe", youngest first. The seeds create three bands, so this is rare.

**Each row** (replaces the row in item 8). The status line stays as it is: the status badge and an overdue amount.

| List | Subtitle |
| --- | --- |
| A–Z (also the overview's Schüler group) | the band, then the level: „Unter 12 · Anfänger". Age with no band: „18 Jahre · Fortgeschritten". No birth date: „Geburtsdatum fehlt · Könner". |
| Nach Alter | the age, then the level: „9 Jahre · Anfänger"; the band is the header. No birth date: „Geburtsdatum fehlt · Könner". |

- **No level** (only if every level was archived): the subtitle ends without one.
- **The price leaves the row.** With the age, band and level beside it, it wrapped every row at 320 to 3–5 lines (measured).
  - The one thing it signalled that matters on a list, a child in no current course, becomes an amber badge „Ohne Kurs" in the status line.
  - Prices stay on the child's page (Kurse, Beiträge) and on Geld.
  - *Decided by the project manager, 2026-10-08:* the price leaves the rows. The owner sees it in the screenshots before the build ships, and it is cheap to put back.

**Text** (only the strings that differ from Part 1).
- Filter fields:
  - `t('Leistungsgruppe','Level')`, `t('Altersgruppe','Age group')`;
  - the option `t('Ohne Altersgruppe','No age group')`;
  - `t('Anwenden','Apply')`, as in Part 1.
- Sections:
  - the band's name and `age_group_range()`;
  - `t('Ohne Altersgruppe','No age group')`, `t('Ohne Geburtsdatum','No date of birth')`;
  - the footer `t('Keine deiner Altersgruppen passt.','None of your age groups fits.')` with `t('Altersgruppen ansehen','View the age groups')`;
  - with no bands, `t('Es gibt noch keine Altersgruppen.','There are no age groups yet.')` and `t('Altersgruppen anlegen','Create age groups')`.
- Rows:
  - the band's name; `plural($age,'Jahr','Jahre','year','years')`; `t('Geburtsdatum fehlt','No date of birth yet')`;
  - the level's name;
  - `badge(t('Ohne Kurs','No course'),'amber')`.
- The line under the controls keeps Part 1's `plural(…'Kinder ohne Geburtsdatum sind nicht dabei.'…)` and `t('Zeigen','Show')`.
- **Gone with the range:** `t('Alter von','Age from')`, `t('Alter bis','Age to')`, and the strings `{from}–{to} Jahre`, `ab {from} Jahren`, `bis {to} Jahre`.

**Empty and error states** (changes only).
- A band id that is unknown or archived in the address is ignored, as is any value other than a band id or `none`.
- The rule that swapped „von" and „bis" goes, with the range.

**The child's page.**
- **„Einteilung"** (staff):
  - Mitgliedschaft, Dabei seit, Mitgliedschaft bis and Leistungsgruppe stay as they are, with the level's hint „Du wählst sie. Neue Kinder starten in {default}.".
  - The select „Altersgruppe festlegen" goes, with both of its hints.
  - The paragraph above the fields („Drei verschiedene Dinge, die leicht durcheinandergehen: …") goes too. It was there to tell a chosen grouping from a worked-out one, and Part 0 puts no paragraph above a group.
  - No read-only band row replaces the select: the band shows once, under the birth date it comes from.
- **The age line** (the birth date's hint, both roles):

| Case | Staff see | A family sees |
| --- | --- | --- |
| A band covers the age | `plural($age,'Jahr alt','Jahre alt','year old','years old').' · '.t('Altersgruppe: ','Age group: ').{band}` — today's wording; it now never names a pinned band (Sophie Reiter: „14 Jahre alt · Altersgruppe: Jugend") | the same |
| No band covers the age | the same, then `' · '.t('keine Altersgruppe passt','no age group fits')` | only `plural($age,'Jahr alt','Jahre alt',…)`; the gap is the trainer's to close |
| No birth date | today's `t('Fehlt noch. Danach richtet sich die Altersgruppe.','Still missing. It decides the age group.')` | the same |

**What a family sees** (replaces Part 1's).
- No list.
- None of Part 1's text changes is made, so every sentence it replaced stays as it is today:
  - „Persönliche Daten" keeps „· Altersgruppe: {band}" on the age line; Part 1 dropped it.
  - „Fehlt noch. Danach richtet sich die Altersgruppe." stays, for families and staff; Part 1 replaced it with „Deine Trainerin teilt die Gruppen nach dem Alter ein."
  - `family_next_steps()`' „Danach richtet sich die Altersgruppe." and the activation page's hint stay.
  - `student_new`'s „Bestimmt die Altersgruppe. Kann auch später ergänzt werden." (staff) stays.
- New for families: nothing. They never read „keine Altersgruppe passt".

**Reuses.**
- `age_groups()`, `age_group_for_age()`, `age_group_range()`, `student_age()`, `levels()`.
- `age_group_warnings()`, under Verwaltung, unchanged.
- `filters_from()`, `filtered_students()`, `render_filters()`, `student_card()`.
- `tabs()`, `badge()`, `plural()`, `empty_state()`.
- The built `.section-heading`, `details > summary`, `.tabs.is-segmented` and `.student-card`.

**Takes away** (replaces Part 1's).
- The pin: the select „Altersgruppe festlegen" and `students.age_group_id`.
- „Einteilung"'s paragraph.
- The price from the list's rows.
- Part 1's „Alter von – bis" and all that came with it.
- As Part 1 said: „Tarif und eigene Felder" and saved views (gone in round 2); „Nach Nachnamen sortiert"; the always-open filter card. Proposed: the „Aktuell abwesend" select and the „Nur überfällige" tick.
- **Not taken away any more:** the filter's level and age-group selects, and Verwaltung's „Leistungsgruppen" and „Altersgruppen".

**Schema.** Migration 038 (`038_age_group_from_the_birth_date_only.sql`) drops `students.age_group_id` with its key `student_age_group`. `levels`, `students.level_id` and `age_groups` stay.

**For backend-dev.**
- **The sort clause stays** as Part 1 has it.
- **One rule for a child's band, everywhere:** `age_group_for_age(student_age($birthDate), $bands)`, the first match in Verwaltung's order, archived bands left out.
  - `student_age_group()` loses its pinned branch.
  - The list's sections, the filter, the rows, the age line and Verwaltung's counts (`group_usage()`) all ask this rule.
- **The band filter returns exactly the children the band view shows under that band.**
  - Today's SQL bounds may stay as a first cut, with the pin clause gone.
  - But where bands overlap, a child can be inside a later band's bounds and belong to an earlier one, so the final decision is the rule above, in PHP. The list is read whole and paged with `array_slice()` already.
  - `none` means the rule places them in no band: no birth date, or no band covers the age.
- **One function beside `filtered_students()`** turns the sorted rows into sections:
  - each band in order, with its rows;
  - then „Ohne Altersgruppe", then „Ohne Geburtsdatum";
  - each keeping the SQL order;
  - with the bands read once per page.
- **`filters_from()`:** `age_group` is a band id (not archived) or `none`; `sort` is `age` or absent; anything else is ignored (ADR 0026 §5).
- **The „nicht dabei" count:** with a band chosen, the children without a birth date the rest of the selection would include.
- **The summary value** needs the chosen names: course, status label, level, band or „Ohne Altersgruppe". `filter_summary()` went in round 2, so it is one small helper beside `render_filters()` (frontend-dev).
- **`student_card()`** learns which subtitle to print (an argument, for example `bool $underBand`) and shows „Ohne Kurs" when the child is in no current course.
- **The pin's code goes in the same commit as 038.** Otherwise the first request after the update fails, or `demo:fill` does:
  - `student_save` stops writing `age_group_id`;
  - the select goes;
  - `filtered_students()` loses the pin clause;
  - `group_usage()` stops selecting `age_group_id`;
  - `app/demo.php`'s insert stops writing it (it pins Sophie Reiter today).
  - The change log keeps the label „Altersgruppe" for `age_group_id`, so older lines still read.

**Consequences elsewhere in this spec.**
- **Part 0, C8.** Verwaltung keeps four entries (Leistungsgruppen, Altersgruppen, Mitgliedschaft, Geld & Zahlungen), so it gets no segmented control.
  - It scrolls, as `tabs()` draws four today, until Verwaltung becomes a list that leads to each part, as the child's page will.
  - The shorter „Bankkonto" still stands.
- **Verwaltung's „Wer gehört wohin?"** The age group's sentence drops „Nur im Ausnahmefall festlegen." and becomes „Ergibt sich aus dem Geburtsdatum und ändert sich mit jedem Geburtstag."
- **Part 13, `tests/mobile.mjs`.**
  - Add `students&sort=age`, `students&age_group={a band}` and `students&age_group=none`.
  - `manage&tab=ages` and `manage&tab=levels` stay in its list; ADR 0026 §4 had them leave.
  - The example data's bands leave no gap and every child has a birth date, so neither end section is ever measured. The stripped-down example data keeps one child without a birth date. A gap is a TESTING.md step: change a band by hand.

**Measured.**
- **Conditions.**
  - Commit `bdb2412`, with the restyle built; exported with `git archive` and installed with the example data.
  - MariaDB 10.11.14, PHP 8.4.26, Chromium 141.
  - Two changes to the throwaway data only: „Erwachsene" from 21 instead of 18, a gap at 18–20, so Marie Winkler (18) falls into no band; Mia Gruber's birth date removed.
  - Prototype = the built page edited in the browser, checked with `tests/mobile.mjs`'s own rules (loaded from the file) and a text-under-button check, at 320 and 390, light and dark.
  - The test font is DejaVu Sans, wider than San Francisco, so the wraps are upper bounds.
- **Today on `bdb2412`:** the first child at 987 px (1.35 screens) at 320 and 914 at 390, under a 482 px filter card; row subtitles 2–3 lines at 320.
- **Revised, at 320:**
  - the first child at 516 px (0.72 screens) in A–Z and 590 (0.82) in „Nach Alter";
  - the filter unfolded is 379 px (the first child then at 896);
  - five sections: Unter 12, Jugend, Erwachsene, Ohne Altersgruppe, Ohne Geburtsdatum.
- **Row subtitles, 15 children:**

| Subtitle | 320 | 390 |
| --- | --- | --- |
| age · band · level · price | every row 3–5 lines, rows 131–172 px | 2–3 lines |
| age · band · level | every row 2–3 lines, 111–137 px | 6 of 15 wrap |
| band · level (A–Z) | 7 of 15 wrap, 91–137 px | none wrap, 91 px |
| age · level („Nach Alter") | 6 of 15 wrap | none wrap |

- **Section titles.** At Title 3, „Ohne Altersgruppe" and „Ohne Geburtsdatum" push their count to a second line at 320. At Headline, none do, at either width in either theme. The check reported the Title 3 wraps, so it can fail.
- **„Einteilung":** 842 → 567 px at 320, 749 → 514 at 390.
- **Every variant:** 0 problems, no overlaps, both widths, both themes. Chromium only; no iPhone.

---

## Part 2. The two overviews

Four gaps land on the overviews (G2, G4, G6, G7) and one in the bell (G5). Specified one by one, they would pile four cards on top of today's. This is the frame they share, built a part at a time (Part 11).

### 2.1 The family's overview at 320

1. **Large title „Hallo {Vorname}".** No line under it and no button: „Nachricht schreiben" is the „Chats" tab.
2. **Group „Heute"** (G4, G7). Only shown when the child is in a course.
3. **Group „Offen"** (G2, G3).
4. **„Noch zu ergänzen"**: `next_steps_card()` as a list with a numbered tinted circle on each row. It comes after „Heute" because the address step stays on most families' list.
5. **Group „Nächste Termine"**: the next three dates, as rows. No past dates; today is group 2.
6. **Group „Neuigkeiten"**: the latest three as rows, with a footer link „Alle Neuigkeiten" ›.

**Taken away (owner):**
- the description line;
- the heading's „Nachricht schreiben";
- the child's own card: the name is the greeting, the amounts are in „Offen", the status is on Profil;
- the „Offen" stat tile;
- the „Schon überwiesen?" strip;
- the three past dates.

**Measured (prototype):** „Heute krank" at y 277 (0.39 screens), the first „Bezahlen" at 509 (0.71), the page 1,802 px against 2,518 today; 0 problems.

### 2.2 The trainer's overview at 320

1. **Large title „Hallo {Vorname}"** and the filled „+ Schüler anlegen" (decided 2026-10-05).
2. **Group „Heute"** (G7, with G4's reports).
3. **Group „Zu tun"**: value rows with chevrons, only rows with something in them.
   - „Belege prüfen" with the count → Geld#receipts (G6);
   - „Kursanfragen" with the count → Kurse › Anfragen;
   - „Überfällig" with the total → Geld, overdue only.
   - These are queues, not ordered steps, so they are value rows. ADR 0026 §4's numbered card stays for steps.
4. **Group „Termine"** without today (it is in group 2) and without „Termin ändern".
5. **„Schüler" and „Neuigkeiten"** as the 2026-10-05 spec leaves them.

**Taken away (owner):**
- the line under the greeting;
- the four stat tiles (332 px): „Heute abwesend" moves into „Heute", the overdue total into „Zu tun", the open total stays on Geld, the active count goes.

**Measured (prototype):** „Anwesenheit" at 0.51 screens, „Heute fällt aus" at 0.60, against 1.52 and 3.48 plus four steps today; no overlap.

---

## Part 3. G1: a family's first sign-in, through to asking for the course

**Who, where, in their words.**
- A parent, or the student, on the sofa in the evening, with the invitation e-mail or the trainer's link.
- „Ich will mich anmelden und in den Kurs."
- Today:
  - the invitation page for an address alone is 1,668 px long, 98 words, with 9 controls and „Konto aktivieren" at y 1476;
  - it then lands on Profil with three to-dos;
  - the course is asked for on the Kurse tab (off screen at 320, „Anmeldung anfragen" 1.63 screens down);
  - the contact is added on the Kontakte tab;
  - nothing says how many steps are left.

**The plan.**
- Worked out when the link page opens and kept in the session (ADR 0026 §4). Without the session (another device, hours later), each page works out what is missing and shows no step line.
- Steps:
  1. **Wer** (who): only for an invitation by address alone (`setup_creates_student()`);
  2. **Zugang** (access): always;
  3. **Kurs** (course): when `wants_a_course()` and a course is open;
  4. **Notfallkontakt** (emergency contact): when there is none.
- N is the number planned. N = 1 shows no step line.

**The screens at 320.** Each is a large title, `wizard_progress()`, one group and the primary button in the sticky footer.
- **A „Wer trainiert?"** (signed out; the link page):
  - the club's name as the eyebrow;
  - the security sentence;
  - one sentence;
  - stacked fields for first name, last name and date of birth (`max` = today);
  - „Weiter" (filled, `chevron`).
  - It posts a new step action that checks the three values (`own_student_details()`) and keeps them in the session under the link's hash.
- **B „Dein Passwort"**, or „Hallo {Vorname}!" when a child exists:
  - the address or username, read only;
  - Passwort and Passwort wiederholen, with today's hints;
  - „Datenschutzerklärung lesen" (`target="_blank"`), the acknowledgement checkbox, and the two mail switches (with an address);
  - „Weiter";
  - a plain button „Zurück" to A, for an invitation by address alone.
  - It posts `activate`, which for an address alone takes the details from the session, not the post.
- **C „Willkommen, {Vorname}!" / „Schritt 3 von 4: Dein Kurs"** (new view `welcome`, signed in, tab bar shown):
  - one group per open course;
  - header: the course name;
  - value rows: Wann (`class_schedule()`), Wo, Beitrag (amount and interval);
  - a „Wie zahlst du?" select row only when the course has more than one tariff;
  - the footer „Deine Trainerin sagt dir Bescheid, sobald dein Platz fest ist.";
  - one course: the sticky filled „Ja, anmelden" (`check`), posting `enrolment_request` (join);
  - several courses: a tinted „Diesen Kurs anfragen" in each group;
  - a full course: the badge „Voll" and no button;
  - a plain „Später" goes to the next step.
  - There is no „Zurück": the login was made in B, and names and passwords are changed under Profil and Mein Konto.
- **D „Wen rufen wir an?" / „Schritt 4 von 4: Notfallkontakt"**:
  - the sentence „Falls im Training etwas passiert.";
  - `contact_fields()` (stacked);
  - „Fertig" (`check`), posting `contact_add`;
  - plain „Später" and „Zurück" (to C).
- **After the last step** comes the overview with a banner. There is no done page.
- **Later**, the overview's „Kurs wählen" and „Notfallkontakt eintragen" lead to C and D without a step line, because those are the phone-sized places for both.

**Text.**
- `t('Wer trainiert?','Who is training?')`
- `strtr(t('Schritt {n} von {total}','Step {n} of {total}'),…)`
- `strtr(t('Diese Einladung ist für {email}. Nicht deine Adresse? Dann trag nichts ein und lösch die E-Mail.','This invitation is for {email}. Not your address? Then enter nothing and delete the email.'),…)`
- `t('Für ein Kind? Dann die Angaben des Kindes.','For a child? Then the child’s details.')`
- `t('Weiter','Next')`
- `t('Dein Passwort','Your password')`
- `t('Zurück','Back')`
- After B: `strtr(t('Dein Konto ist bereit. Du meldest dich ab jetzt mit {login} an.',…))`
- `strtr(t('Willkommen, {name}!','Welcome, {name}!'))`
- `strtr(t('Schritt {n} von {total}: Dein Kurs','Step {n} of {total}: Your course'))`
- `t('Wann','When')`, `t('Wo','Where')`, `t('Beitrag','Fee')`, `t('Wie zahlst du?','How do you pay?')`
- `t('Deine Trainerin sagt dir Bescheid, sobald dein Platz fest ist.','Your coach lets you know once your place is confirmed.')`
- `t('Ja, anmelden','Yes, sign me up')`, `t('Diesen Kurs anfragen','Ask for this course')`, `t('Voll','Full')`, `t('Später','Later')`
- `t('Wen rufen wir an?','Who do we ring?')`
- `strtr(t('Schritt {n} von {total}: Notfallkontakt','Step {n} of {total}: Emergency contact'))`
- `t('Falls im Training etwas passiert.','In case something happens at training.')`
- `t('Fertig','Done')`
- Banners:
  - after C: `strtr(t('Angefragt: „{course}“.','Asked for: “{course}”.'))`;
  - after D: `strtr(t('Fertig! Deine Anfrage für „{course}“ ist bei deiner Trainerin.','Done! Your request for “{course}” is with your coach.'))`, or `t('Fertig!','Done!')`.

**Without JavaScript.** Every step is one form with one button; „Später" and „Zurück" are links.

**Empty and error states.**
- A refused step comes back to itself with what was typed. Passwords are not refilled.
- The link lapses between A and B (48 h): today's „Link nicht mehr gültig" page.
- No course exists: C is left out of the plan.
- **C after a double tap or Back:** the existing refusal is replaced by the notice `strtr(t('Angefragt: „{course}“. Deine Trainerin sagt Bescheid.','Asked for: “{course}”. Your coach will let you know.'))` with „Weiter".
- **A course fills up between showing and the tap:** C again, the course marked „Voll", and `t('Dieser Kurs ist inzwischen voll. Schreib deiner Trainerin – sie meldet sich bei dir.','This course has filled up meanwhile. Write to your coach – she will get back to you.')`.
- **All courses full:** today's notice „Gerade ist kein Kurs frei", with „Nachricht schreiben" and „Weiter".

**The way back.**
- A ↔ B, with the details kept; „Später" on C and D; D → C.
- A wrong course request: the trainer declines it (she is told, and today's notice tells the family), or the family writes to her. There is no new withdraw action.
- A wrong contact: edit or remove it under Profil › Notfallkontakte, as today.

**Reuses.**
- `views/activate.php` and `activate`; `own_student_details()`.
- The session draft pattern of `student_draft` (ADR 0023 §5).
- `wants_a_course()`, `free_courses_for()`, `course_is_full()`, `class_schedule()`, `class_tariffs()`.
- `enrolment_request`, `contact_add`, `contact_fields()`, `primary_contact()`, `family_next_steps()`.
- `page_head()`, `select_field()`, `submit_button()`, `wizard_progress()`, `.form-footer`.

**Takes away.**
- Landing on Profil after the first sign-in.
- The long welcome sentence.
- The page's paragraph „Trag deine Angaben ein…".
- „Konto aktivieren" and „Speichern und anmelden" (both become „Weiter").
- The trips through the Kurse and Kontakte tabs for the first course and contact.

**Schema.** None. The session holds the plan, and the details for A.
- `enrolment_request` and `contact_add` return to the next step when posted from `welcome` (backend).
- G1a (C and D, signed in) can go first.
- G1b (splitting the signed-out link page) touches the unauthenticated path → architect and security-reviewer.

**What a family sees.** All of it; this is theirs.

**Measured (prototypes, 0 problems).**
- A: 964 px, „Weiter" at y 772 (signed out, 0.99 of 780).
- B: 1,157 px, „Weiter" at 897.
- A and B together: 43 + 45 words, against 98 on one page.
- C: „Ja, anmelden" at 608 (0.85, sticky).
- D: „Fertig" at 580 (0.81).

---

## Part 4. G2: paying one charge

**Who, where, in their words.** A parent at home, on a phone. „Ich will den Beitrag überweisen und zeigen, dass ich bezahlt habe."
- Today, with two open charges and an IBAN set: two QR boxes of 700 px each, then the upload 4.25 screens down (its button at 4.52).
- That upload names no charge: `views/student.php` posts only `student_id`, although `proof_upload` accepts `charge_id`.
- On a phone the family cannot scan a code shown on its own screen. Copying is what works, so copying comes first.

**The screens at 320.** New view `pay&charge={id}`: families only, their own charges; staff are sent to the child's charge.

**Step 1 of 2, „Überweisen".**
- Large title: the charge's label, for example „Beitrag Oktober". Back: „‹ Übersicht" or „‹ Beiträge".
- `wizard_progress(1,2)`.
- **A group with one row:**
  - „Zu überweisen", with the amount in Title 1 (the open remainder, `money()`);
  - the subtitle „fällig am 07.10.2026", or „überfällig" in red.
- **Group „So überweist du":**
  - **value rows:** „An" (recipient), „IBAN" (mono, `iban_groups()`), „Verwendungszweck" (`charge_reference()`), and „BIC" only if set;
  - **trailing on the IBAN and reference rows:** a plain button „Kopieren" (`copy`), with the existing `[data-copy-from]` script; the values sit in hidden inputs, the IBAN without spaces;
  - **when `show_payment_qr` is on:** the QR on its white plate (`.pay-qr`, `qr_payload()` and `qr_svg()` as today) in its own group, with the footer „Oder mit einem zweiten Handy scannen – die Bank-App füllt dann alles aus.";
  - **the profile's own note**, if set, as a footer.
- **Sticky filled „Überwiesen"** (`check`): a link to step 2. Nothing is written.

**Step 2 of 2, „Beleg schicken".**
- `wizard_progress(2,2)`.
- `start_form('proof_upload',['student_id'=>…,'charge_id'=>…],'form',true)`.
- `file_field('proof', t('Foto vom Beleg','Photo of the receipt'), 'proof', t('Ein Bildschirmfoto aus der Bank-App genügt.','A screenshot from the banking app is enough.'))`.
- One footer sentence, said before the tap: `t('Ohne Beleg dauert es länger: Deine Trainerin bestätigt, sobald sie das Geld auf dem Konto sieht.','Without a receipt it takes longer: your coach confirms once she sees the money in the account.')`.
- Sticky filled „Beleg schicken" (`camera`).
- Plain buttons „Ohne Beleg fertig" (to where they came from) and „Zurück" (step 1).

**After the upload.** A banner back where they came from: `t('Danke! Deine Trainerin bestätigt die Zahlung, sobald das Geld da ist. Du bekommst dann einen Hinweis.','Thank you! Your coach confirms the payment once the money is there. You will get a notice then.')`.

**Entry points.**
- **The „Offen" rows** on the overview and on Beiträge:
  - title: the label;
  - subtitle: „37,00 € · fällig am 07.10." or „überfällig" in red;
  - trailing: a tinted capsule „Bezahlen" (`wallet`, at least 44 px), in App Store style.
- **A receipt already sent** adds the line „Beleg geschickt am 07.10." under the row.

**Without JavaScript.**
- Step 1 is text; step 2 is a multipart form.
- The copy buttons stay hidden: they need the clipboard, which `app.js` checks for, and Safari offers it only over https. The values can still be selected.

**Empty and error states.**
- Paid already: `t('Schon bezahlt – danke!','Already paid – thank you!')`.
- Cancelled: `t('Dieser Beitrag ist storniert. Hier ist nichts zu bezahlen.','This charge has been cancelled. There is nothing to pay.')`.
- No IBAN: `t('Die Bankverbindung fehlt noch. Frag deine Trainerin, wie du bezahlst.','The bank details are not set up yet. Ask your coach how to pay.')`, and no „Überwiesen". Without an IBAN there is no „Bezahlen" capsule either.
- No file chosen, a file too large, the wrong type: the existing upload refusals, back on step 2.
- Somebody else's charge id: not found, as `student()` scoping does today.

**The way back.**
- A wrong photo is removed by its sender while the charge is unpaid: a destructive sheet „Beleg entfernen" on the receipt line, with `t('Der Beleg wird gelöscht. Du kannst danach einen neuen schicken.','The receipt is deleted. You can send a new one afterwards.')`.
- `proof_delete` then allows its uploader, as long as the charge is still open (backend).
- „Überwiesen" writes nothing, so it needs no way back.

**Reuses.**
- `qr_payload()`, `qr_svg()`, `charge_payment_profile()`, `charge_reference()`, `iban_groups()`.
- `.pay-qr`, the copy script, `file_field()`, `proof_upload` (with `charge_id`), `proof_delete`, `wizard_progress()`.

**Takes away (for families).**
- The 700 px QR boxes from Beiträge.
- The bottom „Zahlungsbeleg" card.
- The overview's „Offen" tile and „Schon überwiesen?" strip.

**Schema.** None: `payment_proofs.charge_id` exists.

**What a family sees.** All of it.

**Measured (prototypes, 0 problems).**
- Step 1: „IBAN kopieren" at 0.73; „Überwiesen" in view at load.
- Step 2: „Beleg schicken" at 0.53; the page fits one screen.

---

## Part 5. G3: open and paid apart, a document for a paid charge, a notice when it is confirmed

**Who, in their words.** „Was ist offen, was ist bezahlt, und wo ist meine Quittung?"
- Today open and paid are one list in due-date order.
- A paid charge has a document only if staff issued an invoice.
- Nobody is told when a payment is confirmed.

**The family's „Beiträge"** (a tab-bar root; today `student&id&tab=payments`). A large title, then:
1. **Group „Offen".** The Part 4 rows.
   - Nothing open: one row with `check`, „Alles bezahlt."
2. **Group „Bezahlt".** The six newest rows:
   - the label, with the amount as the value;
   - the subtitle „bezahlt am 05.08." (the latest confirmed payment's `paid_on`);
   - a trailing plain link to the document, `target="_blank"`:
     - if a live invoice holds the charge, „Rechnung {number}";
     - otherwise „Bestätigung" (part B);
   - a footer row „Alle bezahlten" ›.
3. **Cancelled charges are not shown to families.** A charge with money on it cannot be cancelled (`cancel_charge()`), so nothing paid ever disappears.

**The family's „Rechnungen" tab folds into these rows.**
- An old `tab=invoices` for a family opens Beiträge.
- `notify_invoice()`'s mail links to Beiträge.
- Staff keep Rechnungen.

**Part A: the notices.**
- **Any payment becoming confirmed** (`confirm_payment()`, `payment_add` with „bestätigt", `invoice_mark_paid()`, G6's one tap) tells the child's login once:
  - title `strtr(t('Bezahlt: {label}','Paid: {label}'))`, body `strtr(t('{amount} sind angekommen. Danke!','{amount} has arrived. Thank you!'))`, a link to Beiträge;
  - for an invoice paid as a whole, `strtr(t('Bezahlt: Rechnung {number}','Paid: invoice {number}'))`.
- **A confirmed payment voided** tells them `strtr(t('Wieder offen: {label}','Open again: {label}'))` / `t('Deine Trainerin hat die Zahlung korrigiert.','Your coach corrected the payment.')`.
- One function does it for every path (backend), so no path is forgotten.
- Notices only, no e-mail.

**Part B: „Zahlungsbestätigung" (PDF).**
- Built from what is stored, when it is downloaded, as invoices are; no number, nothing stored.
- It states:
  - the club's name and address (from the organisation settings);
  - „Für: {Name}";
  - the charge and its period;
  - the amount;
  - paid on, and how;
  - confirmed on;
  - the line `t('Diese Bestätigung ist keine Rechnung.','This confirmation is not an invoice.')`.
- It is a new document type and a new download kind → architect, and the owner confirms families may download it.

**Without JavaScript.** Lists and links.

**Empty states.**
- No charges at all: `t('Noch keine Beiträge.','No payments yet.')`.
- Nothing paid yet: the „Bezahlt" group is absent.

**The way back.** Part A's void notice.

**Reuses.** `student_charges()`, `payments_by_charge()`, `live_invoices_of_charges()`, the download route, `app/pdf.php`'s layout (B), `notify()`.

**Takes away (for families).** The Rechnungen tab, Beiträge's two stat tiles, and cancelled charges.

**Schema.** None.

**What a family sees.** All of it.

---

## Part 6. G4: „Heute krank" in one tap

**Who, where, in their words.**
- A parent in the morning: „Lena ist heute krank."
- The trainer wants to know before training.
- Today:
  - Profil → swipe the tab strip (Abwesenheit is off screen at 320 and 390) → Abwesenheit → choose a reason → „Abwesenheit eintragen" at 1.23 screens;
  - six taps, a swipe and two page loads;
  - the trainer is not told.

**The family's group „Heute"** (Part 2.1, first under the title).

| State | Course row | Action row | After the tap |
| --- | --- | --- | --- |
| Training today | „Kindertraining", subtitle „16:00–17:30 · Sporthalle Nord" | „Heute krank" (tint, `sick`), a POST | a banner |
| Reported | badge „Krank gemeldet" (amber) | plain „Doch nicht krank" | |
| Cancelled (G7) | badge „Fällt aus" (red), the trainer's note as the subtitle | none | |
| No training today | header „Nächstes Training"; „Do 08.10. · Kindertraining" (`day_label()`) with time and place | plain „Abwesenheit melden" → Profil › Abwesenheit, today's form | |

**The tap.**
- The server takes today's date when the tap arrives, so a page left open overnight cannot report yesterday.
- It records `absences(reason 'sick', today, today)` once; a second tap changes nothing.
- It tells staff.

**Every absence a family enters tells staff** (`notify_staff()`, a new kind `absence`, icon `sick`): the one-tap report, the form, and the withdrawal. The notices are in Part 12.

**The trainer sees:**
- the bell;
- „gemeldet: Lena (krank)" in her „Heute" group (Part 7);
- „Krank gemeldet" beside the name when she takes attendance, as today.

**Text.**
- `t('Heute','Today')`, `t('Heute krank','Sick today')`, `t('Krank gemeldet','Reported sick')`
- `t('Doch nicht krank','Not sick after all')`, `t('Nächstes Training','Next training')`, `t('Abwesenheit melden','Report an absence')`
- Banners:
  - `t('Gute Besserung! Deine Trainerin weiß Bescheid.','Get well soon! Your coach has been told.')`;
  - `t('Alles klar – deine Trainerin weiß Bescheid.','All right – your coach has been told.')`.

**Without JavaScript.** One form with one button.

**Empty and error states.**
- Not in a course: the group is absent.
- A double tap: one absence.

**The way back.** „Doch nicht krank" deletes the absence. `absence_delete` already lets a family remove its own. The trainer is told.

**Reuses.**
- `absence_add` (with a mode, or a small `absence_today`; backend), `absence_delete`.
- `absences_on()`, `class_calendar()`, `session_label()`, `day_label()`, `notify_staff()`, `badge()`.

**Takes away.** Nothing more than Part 2.1. The Abwesenheit page stays for planned absences.

**Schema.** None.

**What a family sees.** All of it.

**Measured (prototype).** „Heute krank" at 0.39 screens; 0 problems.

---

## Part 7. G5: news in the bell

**Who, in their words.** „Ich will mitbekommen, wenn die Trainerin etwas Neues schreibt."

**Behaviour.**
- When a news item is published for the first time (new and published, or published 0 → 1), every active student login gets a notice: kind `news`, icon `news`, with:
  - the news title as the title;
  - its first 120 characters as the body;
  - a link to the item.
- Editing a published item tells nobody again.
- Unpublishing removes its notices from every bell.
- `news` joins `notice_kinds_shown_while_viewing()`: it quotes nothing private.
- The e-mail switch („Auch per E-Mail an alle, die Neuigkeiten bekommen") stays as it is.

**Without JavaScript.** Nothing on the page changes.

**Empty states.** None.

**The way back.** Unpublishing.

**Reuses.** `news_save`, `notify()`, `notification_icon()`.

**Takes away.** Proposed: the family's „Neues" tab (Part 0, C3, owner).

**Schema.** None.

**What a family sees.** A notice in the bell, worded by the trainer.

---

## Part 8. G6: receipts waiting, one tap to paid

**Who, where, in their words.**
- The trainer, weekly, with her banking app open: „Ist das Geld von Lena da? Dann abhaken."
- Today, per receipt:
  1. find the child;
  2. Beiträge;
  3. scroll down to the photo and back up to the charge;
  4. „+ Zahlung erfassen";
  5. tick „Zahlungseingang bestätigen";
  6. „Zahlung erfassen".
- That is about seven taps, and nobody tells her a receipt arrived.

**The screen.**
- On Geld, under the segmented control and above everything else: header „Belege prüfen" with the count, as a `.card.list`, with the footer `t('Ist das Geld auf dem Konto? Dann „Bezahlt“.','Is the money in the account? Then “Paid”.')`.
- **Each row:**
  - the child's name;
  - the subtitle „Beitrag Oktober · 37,00 €";
  - a second subtitle „Beleg vom 07.10." plus the family's note;
  - a tap on the row opens the receipt (`target="_blank"`);
  - trailing: a filled capsule „Bezahlt" (`check`, at least 44 px).
- **What „Bezahlt" records**, in a new small action (backend; the server works out the remainder when the tap arrives, so a list opened an hour ago cannot pay twice): a confirmed payment of the charge's open remainder,
  - dated the day the receipt arrived;
  - with the first of „Zahlungsarten";
  - with the note „Beleg vom {date}".
- **Then:**
  - the banner `strtr(t('Bezahlt: {name}, {label}, {amount}.','Paid: {name}, {label}, {amount}.'))`;
  - the row moves to the group „Heute bestätigt" below, with a trailing plain destructive „Stornieren".
- **The list holds** receipts whose charge is still open and not cancelled.
- **Older receipts that name no charge** show without „Bezahlt", with a trailing plain „Zuordnen" → the child's Beiträge.
- **A different amount:** the footer adds `t('Ein anderer Betrag? Auf der Seite des Kindes unter Beiträge erfassen.','A different amount? Record it on the child’s page under Beiträge.')`.

**Telling her.**
- Each upload puts a notice in staff's bells (Part 12).
- „Zu tun" shows the count (Part 2.2).
- The side menu's Geld and the „Mehr" page's Geld row show it too.

**Without JavaScript.** Forms with one button each, and links.

**Empty and error states.**
- No receipts: the group is absent.
- Already paid (another device): `t('Dieser Beitrag ist schon bezahlt.','This charge is already paid.')`, and the row disappears.

**The way back.**
- „Stornieren" voids it (`payment_state`): the receipt reappears in the list, and the family is told (Part 5 A).
- One tap on „Bezahlt" puts it back.

**Reuses.**
- `payment_proofs`, `charge_paid_sql()`, `payment_add`'s insert, `payment_state`.
- `notify_staff()`, `nav_entries()`' count, rows, badges.

**Takes away.** For receipts, the three-step „Zahlung erfassen", which stays on the child's page for everything else.

**Schema.** None: „waiting" means the receipt's charge is not yet paid in full.

**What a family sees.** „Bezahlt: Beitrag Oktober" in the bell (Part 5 A).

**Measured (prototype).**
- The first „Bezahlt" at 0.74 screens at 320.
- The first version linked „Foto ansehen" inside small text, a 16 px target; now the whole row is the link.

---

## Part 9. G7: „Heute fällt aus"

**Who, where, in their words.**
- The trainer, ill, in bed at seven in the morning: „Heute fällt alles aus, sag den Familien Bescheid."
- Today, per course:
  1. Mehr;
  2. Kurse;
  3. the course;
  4. Termine;
  5. scroll to the form (2.54 screens);
  6. „Entfällt";
  7. scroll to the e-mail tick (3.38);
  8. „Speichern" (3.48).
- About eight taps.
- Families hear only if she ticks the box, and that notice links them to a page they may not open (ROADMAP item 5).

**Her group „Heute"** (Part 2.2, first under the title).
- **Her courses today:** the course's `trainer_id` is her; for courses without one, everybody's. An administrator sees all.
- **One row per course:**
  - name;
  - the subtitle „17:00–18:30 · Sporthalle Nord";
  - a second subtitle „6 Kinder · gemeldet: Paul (krank)";
  - a chevron to that course's attendance for today.
- **A cancelled course** shows the red badge „Fällt aus" and has no chevron.
- **No training today:** one row, „Heute ist kein Training."
- **The action row at the end** of the group, red text with the `cancel` icon: „Heute fällt aus". It is shown when one of her courses today has not been cancelled; when all have, it reads „Doch stattfinden" (tint).
- **This replaces today's row** in the timeline, the one where „Anwesenheit" covers the course name at 320.

**„Heute fällt aus" is a sheet** (C11). Without JavaScript the same content opens in place.
- **Content:**
  - title „Heute fällt aus";
  - the line `strtr(t('{weekday}, {date}. Die Familien bekommen sofort einen Hinweis.',…))`;
  - with more than one course, one switch row per course („Jugendtraining", subtitle „17:00–18:30 · 6 Familien"), all on;
  - stacked `input('note', t('Hinweis für die Familien','Note for the families'), …, 'textarea', false, t('Freiwillig. Steht im Hinweis und in der E-Mail.','Optional. Goes into the notice and the email.'), t('z. B. Ich bin krank. Mittwoch wieder wie immer.','e.g. I am ill. Back to normal on Wednesday.'))`;
  - the switch „Auch per E-Mail" (on; only when mail can go out);
  - the filled destructive „Absagen" (`cancel`);
  - „Abbrechen", which closes the sheet.
- **Placement measured.** The consequence goes in the description line. When it stood above a sticky button, the sticky bar covered it at 320. The first, longer button label wrapped to two lines.
- **The action** (backend) writes each chosen course's session for today, as `class_session_save` does (status `cancelled`, the note).
- **Families are told:**
  - in the bell, always: title `t('Heute kein Training','No training today')`, body `strtr(t('{course} fällt heute aus.','{course} is off today.'))` plus the note, a link to their overview;
  - by e-mail when switched on, through `notify_class_change()`, with the link to the overview.
- **Banner:** `strtr(t('Abgesagt: {courses}. {n} Familien wissen Bescheid.','Cancelled: {courses}. {n} families have been told.'))`, with `plural()`.

**What families see.**
- Their „Heute" group shows „Fällt aus" with the note (Part 6).
- The dates list shows the red badge „Entfällt", as today.

**The way back.**
- „Doch stattfinden" opens the same sheet, titled `t('Heute doch Training','Training today after all')`, with the e-mail switch and a tinted „Bescheid geben" (`check`).
- It restores the usual pattern.
- It tells families `t('Heute doch Training','Training today after all')`, with body `strtr(t('{course}, {time} · {place}.'))`.

**Without JavaScript.** The link opens the content in place; one form.

**Empty and error states.**
- No training today: the action row is absent.
- Already cancelled: „Doch stattfinden".
- Mail not set up: no e-mail switch, and the bell only.

**Reuses.**
- `class_calendar()`, `class_session_save`'s write (backend shares it), `notify()`, `notify_class_change()`.
- `check_field($switch)`, `input()`, the sheet.

**Takes away.**
- Today's row in the timeline, and „Termin ändern" (2026-10-05).
- The stat tiles (owner).

**Schema.** None.

**Measured (prototype as a page).** One screen; „Absagen" at 0.91 (sticky); 0 problems after the two fixes above.

---

## Part 10. G8: the day list is enough

**The owner's words:** „have a list which students were in a course and which not."
- The attendance for a training day (`views/_class_attendance.php`) is literally that list: who was there and who was not, with reported absences beside the names.
- „Frühere Trainings" opens every earlier day, and each child's page shows the rate and the last 12 sessions.

**A term view.**
- A grid of children against dates is a wide table, and that fails at 320.
- Nobody has asked for counts across a term.
- So: **no term view.** Nothing is built; the day list gets Part 0's rows.

**The one case that would need one** is a club's funding application asking for attendance per term. Ask the trainer: „Brauchst du jemals eine Liste über mehrere Wochen, z. B. für eine Förderung?"
- If she says yes, the smallest form is a list per course and period, „Lena Hofer · 8 von 10", sorted fewest first. Not a grid.

**Another reading.** „Which students are in a course and which not" could mean membership. The students list answers it: a child's card says „in keinem Kurs", and the Kurs filter selects a course.

---

## Part 11. Ranking G1–G8, and the builds

| Rank | Gap | Who it helps, how often | What it saves (measured at 320) | Size |
| --- | --- | --- | --- | --- |
| 1 | G5 news in the bell | every family, each news item | news is noticed without opening a tab | XS |
| 2 | G4 Heute krank | a family per sick day; the trainer each time | 6 taps, a swipe and 2 loads become 1 tap at 0.39 screens; the trainer is told (today nobody is) | S |
| 3 | G2 + G6, with G3's „Bezahlt" notice | every family monthly; the trainer for every receipt | family: an upload 4.25 screens down that names no charge, against „Bezahlen" at 0.71 and two short steps. Trainer: about 7 taps a receipt, against 1 in one list | M |
| 4 | G7 Heute fällt aus | the trainer a few times a year; every family in her courses | about 8 taps and 3.4 screens per course, families told only by a tick, against 2 taps with every family told | M |
| 5 | G3, the rest | every family when they look | open and paid apart; a document without asking her | M (A) + M (B, architect) |
| 6 | G1 first sign-in | each new family once; spares the trainer's phone calls | a 2.1-screen form, then three places, against short steps with a count | L (G1a M; G1b M + security) |
| 7 | G8 | — | nothing to build | — |

**Builds, in order.**
1. **The age list (Part 1)**, with round 3 of the removals. The ROADMAP needs it first.
2. **Part 0, Phase 1**: rows 1–11 of 0.7 that need no owner's word, plus `app/brand.php`'s constants. Every later build is then built once, in the new language.
3. **„Heute": G4 + G7 + G5.** They share both overviews' „Heute" groups, the `absence`, `news` and schedule notices, the `sick` and `cancel` icons, `submit_button($icon)`, the sheet, and the fix for today's overlapping row. „Zu tun" arrives here with requests and the overdue total, if the owner lets the stat tiles go.
4. **„Bezahlen": G2 + G6 + G3 A**, with the child's page as a drill-down (Part 0, row 12). They share a receipt that names its charge, the one tap, the notices on confirm and void, the family's Beiträge as a tab-bar root, the folding of their Rechnungen tab, and the receipts row in „Zu tun".
5. **G3 B**, the Zahlungsbestätigung, after the architect.
6. **G1a**, then **G1b**, after the architect and security-reviewer.
7. **„Mehr" as a page, and the course page as a drill-down**, when the architect has decided.

---

## Part 12. Every notice in the bell

| Kind (icon) | To | Title (DE / EN) | Body | Link | When |
| --- | --- | --- | --- | --- | --- |
| `absence` (`sick`) | staff | „Heute krank: {Name}" / "Sick today: {name}" | today's courses and times | `attendance&id&on=today` | a family's „Heute krank" |
| `absence` | staff | „Abwesend: {Name}" / "Away: {name}" | „{Grund}, {von}–{bis}" | the child's Abwesenheit | a family's form |
| `absence` | staff | „Doch nicht krank: {Name}" / "Not sick after all: {name}" | today's course | as above | „Doch nicht krank" |
| `payment` (`wallet`) | staff | „Beleg: {Name}" / "Receipt: {name}" | „{Beitrag} · {Betrag}" | `payments#receipts` | a receipt uploaded |
| `payment` | family | „Bezahlt: {Beitrag}" / "Paid: {label}" | „{Betrag} sind angekommen. Danke!" | Beiträge | any confirmation |
| `payment` | family | „Wieder offen: {Beitrag}" / "Open again: {label}" | „Deine Trainerin hat die Zahlung korrigiert." | Beiträge | a confirmed payment voided |
| `news` (`news`) | families | the news title | its first 120 characters | the item | first publication |
| `schedule` (`calendar`) | families | „Heute kein Training" / "No training today" | „{Kurs} fällt heute aus." plus the note | dashboard | G7 |
| `schedule` | families | „Heute doch Training" / "Training today after all" | „{Kurs}, {Zeit} · {Ort}." | dashboard | G7 reversed |

- `notification_icon()` gains `absence` → `sick`.
- `notice_kinds_shown_while_viewing()` gains `news`.
- `notify()` already skips placeholders.

---

## Part 13. Checks

**`tests/mobile.mjs` (qa-tester).**
- It opens only the Profil tab of a family's child: Beiträge, Abwesenheit, Kurse, Kontakte and Rechnungen are never measured. I measured them by hand: clean in both themes at both widths.
- **Add:**
  - those tabs (as the new sub-pages);
  - `students&sort=age` and `students&age_from=9&age_to=12`;
  - the pay steps;
  - the `welcome` steps;
  - `more`;
  - Geld with a receipt waiting.
- **The example data needs an IBAN** (ADR 0026 §9 expects Lena's QR to show, but `payment_profiles` has none, so the QR box has never been measured; I set `AT61 1904 3002 3457 3201` in my throwaway copy), and a receipt that names a charge.
- **Add an overlap rule**: text under a button. Exclude a closed `<details>`' content (Chromium lays it out) and fixed bars. Today it would fail on the trainer's overview at 320, a defect the sweep passes.
- **Add a contrast rule**: at least 4.5:1 against the composited background, 3:1 for text of 24 px, or 18.66 px bold. Today it fails in light mode on 19 screens, and passes with Part 0.

**TESTING.md, by hand on an iPhone in Safari, light and dark, 320 (an SE) and 390.**
- The Part 0 bars at the notch, in portrait and landscape.
- The status-bar text in standalone mode, in dark mode.
- `:active` pressed states.
- A view transition between two pages.
- A sheet opening, and closing with „Abbrechen".
- A PDF and a receipt photo open in Safari's sheet and „Fertig" returns to the app.
- „IBAN kopieren" pastes into a real banking app.
- A camera photo arrives as a receipt (`upload_types('proof')` has no HEIC; Safari should convert it).
- The QR, scanned from the screen by a second phone's banking app.
- „Heute krank" reaches the trainer's bell.
- „Heute fällt aus" reaches a family's bell and mailbox, and its link opens the overview.
- The first sign-in from the invitation mail, saving the password in iCloud Keychain, with an app switch between steps.
- The iPhone's text size set large (only once open issue 9 is decided).

---

```
DESIGN REPORT
What I did:      Read CLAUDE.md, ROADMAP.md, ADR 0026 and the 2026-10-05 spec. Ran the committed code
                 (1b32834, git archive) on my own MariaDB and PHP (ports 4417, 8897; now stopped).
                 Swept it with tests/mobile.mjs as admin, family and trainer. Measured where every
                 target sits at 320 and 390, the tab strips, overlaps, contrast and 200 % text.
                 Prototyped the age list, both overviews, the payment steps, the receipts list,
                 „Heute fällt aus", the wizard steps and the split link page in the browser; then
                 wrote Part 0's stylesheet as a prototype and audited 25 screens against it.
Spec:            the document above. Part 0 (design language, tokens, C1–C16, motion, home-screen
                 app, accessibility, audit); Part 1 (age list); Part 2 (overviews); Parts 3–10
                 (G1–G8); Part 11 (ranking, builds); Part 12 (notices); Part 13 (checks).
Measured:        HEAD sweep: admin 100 + family 24 + signed-out 8 = 132 screens, nothing to fix.
                 Trainer: 108 opened, 72 hers measured clean; the 28 others are the 7 admin-only
                 pages × 4, answered 403 as they should be.
                 Audit of today: 25 screens × 320/390 × light/dark. Lowest text contrast 3.33 in
                 light, with 4.33 for tint on its soft background on 19 screens; 5.21 in dark. Tab
                 strips overflow on 3 screen kinds. 0 :active rules. One real overlap: today's row
                 at 320 (20 px), and at 390 at 200 % text.
                 Part 0 prototype stylesheet on today's markup: 60 audits with lowest contrast 4.59
                 light and 5.38 dark; nothing sideways at 200 %; tests/mobile.mjs's own rules on
                 96 screens: 0 problems.
                 Every prototype: 0 problems at 320/390, light and dark. Positions as stated in
                 each part.
                 Chromium only (DejaVu Sans, wider than San Francisco); no iPhone; MariaDB 10.11.14,
                 PHP 8.4.26.
Open issues:     1. Age: whole years (recommended) or Jahrgang. Ask the trainer before round 3 ships.
                 2. filter_summary() is kept for the folded filter; ADR 0026 §8 removes it
                    → architect.
                 3. The filter loses „Aktuell abwesend" and „Nur überfällige", which the segmented
                    control covers → project manager.
                 4. What families and the trainer stop seeing → owner. On the overviews: the four
                    stat tiles, the child's own card, „Nachricht schreiben", the description lines,
                    past dates. In the bars: the family's „Neues" and „Konto" tabs (Konto moves into
                    Profil), the language switch in the signed-in bar, „Post" renamed „Chats".
                 5. „Mehr" as a page instead of a slide-in drawer (ADR 0011) → architect.
                 6. app/brand.php checks club colours against #ffffff only, and holds #182430 as
                    the dark surface. Both must follow the new tokens in the same commit
                    → backend-dev.
                 7. G3 B, a Zahlungsbestätigung PDF: a new document and download kind → architect;
                    the owner confirms families may download it.
                 8. G1b splits the signed-out link page with a session draft → architect,
                    security-reviewer.
                 9. Following the iPhone's own text size (font: -apple-system-body, limited to iOS)
                    only after a real-iPhone test; macOS Safari would answer 13 px.
                 10. The two mail switches on the first sign-in page could move to Mein Konto, to
                     shorten step B (privacy and consent) → owner.
                 11. „Anschrift eintragen" stays on nearly every family's list for good. Ask for it
                     only when an invoice above 400 € is possible? → project manager.
                 12. The example data has no IBAN, so the QR and payment box never shows, which
                     ADR 0026 §9 expects. Add one, and a receipt that names a charge
                     → database-engineer and backend-dev, in the example-data round.
                 13. tests/mobile.mjs never opens a family's tabs other than Profil, and checks
                     neither overlap nor contrast → qa-tester (Part 13).
                 14. Families' notices about a changed date link to ?page=classes (ROADMAP item 5).
                     G7's notices and mails lead to the overview instead.
                 15. G4 tells every member of staff (notify_staff); a club with several trainers
                     may want only the course's trainer → project manager, later.
                 16. G8: does the trainer ever need attendance over several weeks? Default: no
                     build.
Verdict:         NEEDS-DECISION. Builds 1 and 2 (the age list on the default, Part 0 Phase 1) and
                 the „Heute" build can start now. The rest waits on issues 1, 4, 5, 7 and 8.
```

The measuring scripts and screenshots are in `/tmp/claude-0/-home-user-crmb/8858533c-add6-5b5d-86fd-9a8a9c5649af/scratchpad/ux-measure/` and `…/scratchpad/ux-shots/`. `…/scratchpad/ux-measure/part0.css` is the verified token and component stylesheet; `…/scratchpad/ux-site-up.sh` recreates the site.