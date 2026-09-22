---
status: accepted
date: 2026-09-22
---

# 0001. Record architecture decisions

## Context

This codebase carries a lot of decided-and-defended structure — writes go through one
place, migrations are append-only, there is no JavaScript framework on purpose — and all
of it lived in three prose documents (`CLAUDE.md`, `PROJECT.md`, `ROADMAP.md`) mixed in
with style guidance and a task list. Somebody arriving could read all three and still not
be able to answer "may I add a file to `app/`, and where?"

It matters more now than it did: work on this repository is being split across several
agents with narrow remits. A boundary that exists only as a habit is a boundary the next
implementer breaks without noticing.

## Decision

Architecture decisions are recorded here, one per file, in the format `README.md`
describes. The architect writes them; anybody may propose one. Code review may cite an
ADR number as grounds for a FAIL.

An ADR records a decision that is expensive to reverse: a boundary, a dependency, a data
model, a rule about who may write what. It does not record style, naming or anything the
test suite already enforces — those stay in `CLAUDE.md`, which is the file every session
reads.

## Rejected

**Leaving it in `CLAUDE.md`.** That file is loaded into every session and is already at
the length where things get skimmed. Decisions with reasoning are long; rules are short.
Mixing them makes both worse.

**A single `ARCHITECTURE.md`.** One file means edits that touch unrelated decisions, and
no way to mark one superseded without rewriting history in place.

## Consequences

- `docs/decisions/` is now part of the repository's surface: new decisions go here, and a
  change that contradicts an accepted ADR needs the ADR superseded first, not ignored.
- The first few files record decisions already made. Recording them is not re-deciding
  them; it is writing down what is already true so it can be cited and, if it ever needs
  to change, changed deliberately.
