---
status: accepted
date: 2026-09-22
---

# 0002. Procedural PHP, server-rendered, without a framework

## Context

The application is ~8,200 lines of procedural PHP in `app/`, 30 server-rendered view
files, 784 lines of CSS and **175 lines of JavaScript**. There is no build step, no
`package.json`, and two Composer dependencies: `phpmailer/phpmailer` and
`bacon/bacon-qr-code`.

Three constraints drive this and none of them is taste:

- It is installed by opening `setup.php` on ordinary web hosting and updated by uploading
  files. A build step would mean the operator running a command, and she has no shell.
- It runs on an old iPhone over a phone connection. Server-rendered HTML arrives ready.
- Every dependency is something one non-technical person has to keep patched, forever.

## Decision

PHP 8.2+, procedural, server-rendered from `views/`, dispatched by one front controller
(`public/index.php?page=…`). Progressive enhancement only: **every page works with
JavaScript disabled**, and JavaScript adds convenience on top of a form that already
submits.

A framework, a bundler, a client-side router or a component library is out of scope and
does not need re-arguing per change. `PROJECT.md` states this under "Explicitly not
planned"; this ADR is the citable form.

## Rejected

**A JavaScript framework.** It buys component reuse this app is too small to need, and
costs a build step the operator cannot run, a dependency tree she cannot audit, and a
blank page on a slow connection.

**Laravel or Symfony.** Real benefits — routing, validation, migrations, an ORM — most of
which exist here already in a few hundred lines each (`app/schema.php`, `app/validate.php`,
`app/tx.php`). The cost is a framework upgrade path on a server nobody logs into.

**Replacing the whole thing with an off-the-shelf CRM.** Reasoning is in `AUDIT.md`; the
short version is that the billing rules and the German-first interface are the product.

## Consequences

- Anything proposing React, Vue, Tailwind, a bundler or a package manager for the front
  end is a FAIL, not a discussion, unless this ADR is superseded first.
- The `frontend-dev` remit is `views/`, `public/assets/app.css` and
  `public/assets/app.js` — not a separate client application. There is no API to call:
  a view reads from PHP directly and a form posts to an action.
- Reuse comes from functions in `app/ui.php`, not components. When two views grow the same
  markup, it becomes a helper there.
- Keeping `app.js` small is a measurable goal, not a vibe: 175 lines today.
