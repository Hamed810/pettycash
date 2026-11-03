# Pettycash — Cost Center Manager visual references

This folder contains the Manus management review screenshots as supplied by the product owner. These are **visual references**, not implementation or validated API evidence. The queue is sheet-first, while decisions are transaction-level.

## Screenshot index

| File | Meaning |
| --- | --- |
| `01-queue-desktop-en.png` | Sheet-based Cost Center Manager queue — desktop (1440 × 1237). |
| `01-queue-mobile-en.png` | Sheet-based Cost Center Manager queue — mobile (370 × 2048). |
| `02-workspace-state-a-pending-desktop-en.png` | A: manager has pending assigned decisions — desktop (1440 × 1000). |
| `02-workspace-state-a-pending-mobile-en.png` | A: manager has pending assigned decisions — mobile (390 × 1300). |
| `02-workspace-state-b-manager-done-stage-pending-desktop-en.png` | B: this manager is done, other managers still have unresolved transactions — desktop (1440 × 1000). |
| `02-workspace-state-c-stage-complete-desktop-en.png` | C: sheet-wide Cost Center processing complete; next-stage eligibility subject to the agreed conditions — desktop (1440 × 1000). |
| `alternates/02-workspace-initial-desktop-en.png` | Earlier/general workspace visual; secondary reference — desktop (1440 × 1000). |
| `alternates/02-workspace-initial-mobile-en.png` | Earlier/general workspace visual; secondary reference — mobile (390 × 1300). |

## Duplicate source files

Two uploaded pairs were byte-identical; only one copy per pair is included:
- `screen-2-desktop (2).png` = `screen-2-desktop (3).png`
- `screen-2-mobile.png` = `screen-2-mobile (1).png`

The files under `alternates/` are earlier/general variants; prefer explicit state A/B/C images when evaluating workflow states. Not all workflow states have mobile references. Do **not** fabricate missing images or claim those mobile views were approved.

## Design and implementation boundaries

- Reuse the already approved Purchaser UI visual design system; these management screenshots may need visual alignment before acceptance.
- Show only manager-authorized transaction information, not all sheet transactions. A manager finishing their assigned work does not necessarily complete the sheet-wide Cost Center Review stage.
- Senior Review visibility requires that all required Cost Center processing for the sheet is resolved **and** at least one transaction is eligible for that senior review. Rejected transactions do not automatically advance; returned-for-correction transactions remain unresolved until required correction processing is complete.
- Current backend capabilities and previously ratified workflow specs must be checked via the real Spec Kit workflow before implementation. Known sheet-grouping, manager-specific aggregations, and stage-eligibility limitations must not be papered over with fake data or unsafe client-only grouping.
- Where backend support is missing, faithfully render the approved UI but keep unsupported actions disabled or display an honest unavailable state; never fabricate amounts, review outcomes, or authorization.
- No sheet-level approval action. Do not assume these screenshots constitute final owner visual acceptance.
- This README is a design handoff index, **not** a replacement for `spec.md`, `plan.md`, or `tasks.md`.
