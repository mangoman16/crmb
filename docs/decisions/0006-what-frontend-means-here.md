---
status: proposed
date: 2026-09-22
---

# 0006. What "front end" means in a project with no client application

## Context

The agent roster names a `frontend-dev` for "web UI, state, API calls, loading/error/empty
states, form validation". Three of those have no referent here:

- **No client-side state.** State lives in the session and the database.
- **No API calls.** A view reads from PHP directly; a form posts to an action (ADR 0003).
  There is no JSON endpoint and no `fetch` in `app.js`.
- **No loading states.** The page arrives rendered.

What does exist: 30 files in `views/`, 784 lines of CSS, 175 lines of JavaScript, bilingual
text through `t('Deutsch','English')`, a 44pt minimum touch target, and a rule that every
page works without JavaScript (ADR 0002).

An agent briefed for a React app will invent work this project has explicitly rejected.

## Decision — proposed, needs the owner

`frontend-dev` owns `views/*.php`, `public/assets/app.css` and `public/assets/app.js`, and
is briefed on what those actually are:

- **Form validation** is server-side in `app/validate.php` and comes back as a rejected
  form filled in with what was typed. HTML attributes (`required`, `inputmode`) are a
  convenience on top, never the check.
- **Empty and error states** are what a page shows when a list is empty or a save was
  refused — `.empty`, `.flash.error`, the "Noch zu tun" card — not spinners.
- **Escaping is the front end's first duty**: everything printed goes through `e()`, and
  the `structure` suite enforces it.
- **Mobile-first and measured**: 320px as well as 390px, and `tests/mobile.mjs` is the
  check, not an opinion.

The boundary with `ui-ux-designer`: the designer decides what a screen should contain and
how it behaves; `frontend-dev` writes the view and the CSS.

## Rejected

**Dropping the role.** The work is real — half the defects found in the last review pass
were in views and CSS.

**Renaming it `views-dev`.** Clearer about the code, worse at its actual job, which
includes accessibility, touch targets and what a parent sees.

## Consequences

- `frontend-dev` and `ui-ux-designer` share files; the PM assigns one owner per task.
- Any suggestion of a framework, a bundler or a JSON API is out of scope by ADR 0002.

## Open for the owner

Confirm this scope, or say what `frontend-dev` should own instead.
