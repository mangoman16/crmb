# Decisions

One file per decision, numbered in sequence, never renumbered. A decision that is
later reversed is not deleted: it gets `status: superseded by NNNN` and the new file
explains what changed.

```
NNNN-kebab-case-title.md
```

Front matter, then four sections:

```markdown
---
status: accepted | proposed | superseded by NNNN
date: YYYY-MM-DD
---

# NNNN. Title

## Context
What forced a choice. The constraint, not the preference.

## Decision
What was decided, in the present tense.

## Rejected
What else was on the table and why it lost. An ADR that lists only the
winner is half a record.

## Consequences
What this costs, and what now has to stay true.
```

The numbers in these files are load-bearing: `CLAUDE.md` and the `architect` agent
refer to them, and a review that says "ADR 0003" should land somewhere.
