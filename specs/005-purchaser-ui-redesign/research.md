# Research: Purchaser UI Redesign

**Feature**: `005-purchaser-ui-redesign` | **Date**: 2026-10-19

This research records the decisions taken while bringing the existing Purchaser UI Redesign work under Spec Kit management. It resolves *management* questions (identity, traceability, evidence, status vocabulary, Git boundaries). It deliberately does **not** re-open product, domain, or backend questions; those carry the IDs assigned by the audit package and remain owned by the product owner.

---

## Decision 1: Feature identity and directory

- **Decision**: The redesign is registered as `005-purchaser-ui-redesign` under `specs/`. Feature IDs `001`–`004` are already in use (`001-pettycash-v1-core`, `002-admin-dashboard-parity`, `003-app-menu-entry`, `004-in-app-navigation`), verified by `create-new-feature.sh --dry-run` and by listing `specs/`.
- **Rationale**: The next valid sequential ID is 005. The name is descriptive, not a branch name. The feature directory is a **logical** identity; the working branch stays `feat/pettycash-purchaser-ui-batch-a`, and this specification authorizes no branch operation.
- **Alternatives considered**: Registering the redesign as an amendment to `002-admin-dashboard-parity` (rejected — different scope, different role surface, and it would muddy an already-approved spec). Creating `005-…` on a new branch (rejected — a branch write is a Git operation requiring owner approval).

## Decision 2: Traceability — imported card IDs, Spec Kit ledger as canonical status

- **Decision**: The 16 `UI-PUR-*` and 6 `BE-CR-*` identifiers are imported unchanged and mapped to 005 requirement IDs (`FR-005-nnn`) and canonical Spec Kit tasks (`Tnnn`). `tasks.md` becomes the canonical execution ledger. The DeepSeek audit package stays in the repository as **audit evidence**, not as a second requirement source.
- **Rationale**: `docs/decisions/ADR-002-spec-kit-source-of-truth.md` establishes Spec Kit artifacts as the durable source of truth. Keeping the original IDs preserves traceability to the audit, the owner clarifications, the execution plan and the Batch A report without copying their content.
- **Alternatives considered**: Re-numbering the cards into fresh Spec Kit tasks only (rejected — loses the audit trail the owner has already been reading). Copying the card text into `tasks.md` (rejected — duplicates requirements and creates two places to drift).

## Decision 3: Domain non-duplication

- **Decision**: `spec.md` cites the authoritative `001-pettycash-v1-core` requirements (FR-020…FR-089) instead of restating them. Where a redesign requirement depends on a domain rule, the 001 ID appears in the requirement text or in the traceability matrix.
- **Rationale**: Constitution Principle I makes `specs/` the governing source for delivery; the 001 spec is already authoritative for account/currency rules, draft minimums, submission preconditions, immutability, revisions, evidence ceilings, filters, Jalali periods, RTL/390px, replay safety, deletion, and the post-save destination. Restating them would create a divergence risk on financial rules.
- **Alternatives considered**: A self-contained 005 spec that repeats the rules (rejected — silent divergence risk). A spec that references nothing (rejected — untraceable acceptance).

## Decision 4: Status vocabulary for the imported cards

- **Decision**: One vocabulary across `spec.md`, `tasks.md`, and the governance updates:

  | Status | Meaning |
  |---|---|
  | `DONE_VERIFIED` | Implemented and technically verified (tests and/or recorded browser evidence). **Not** product-owner visual acceptance. |
  | `PARTIAL` | Implemented in part; named residual work remains. |
  | `READY_FOR_OWNER_APPROVAL` | No blocker found; work is **not** started and **not** approved. |
  | `BLOCKED_BY_BACKEND` | A frontend card whose contractually-correct completion requires an unapproved `BE-CR`. |
  | `BLOCKED_PENDING_OWNER_APPROVAL` | A backend change request awaiting explicit owner approval. |
  | `NOT_STARTED` | No work performed; no blocker other than the absence of approval. |

- **Rationale**: The audit's own vocabulary (`PROPOSED`) is mapped by `OWNER_DECISION_RECONCILIATION.md` §5 to `READY_FOR_OWNER_APPROVAL`. `DONE_VERIFIED` is deliberately distinct from visual acceptance, because the Batch A report explicitly records that browser verification is not owner approval.
- **Alternatives considered**: Reusing `PROPOSED` (rejected — ambiguous between "not started" and "blocked"). A binary done/not-done model (rejected — hides the blocked/ready distinction the owner needs).

## Decision 5: Blocked items — no workarounds, no contract edits

- **Decision**: `BE-CR-001…006` remain `BLOCKED_PENDING_OWNER_APPROVAL`. No specification, contract, OpenAPI, workflow, authorization, financial, or `lib/` change may be made to unblock them. Frontend cards that depend on them (`UI-PUR-003`, `UI-PUR-007`, `UI-PUR-014`, and the evidence tier of `UI-PUR-016`) stay `BLOCKED_BY_BACKEND` and are not approximated client-side.
- **Rationale**: `BLOCKED_ITEMS.md` §2 records that two of the three affected cards have **no contractually-correct frontend-only workaround**; `CHARTER.md` and `DECISIONS.md` D-005 require separate approval for backend/contract change. The Batch A report confirms OPEN-first ordering was deliberately left unimplemented rather than faked.
- **Alternatives considered**: "Temporary" client-side sort (rejected — produces lying page boundaries, the exact defect `UI-PUR-003` exists to fix). Editing the contract to match implementation (rejected — a contract write without approval).

## Decision 6: Evidence tiers and what this management pass may claim

- **Decision**: Evidence is recorded in four distinct tiers and never collapsed:
  1. **Re-executed in this pass** — the targeted Vitest run (`4 files / 168 tests`, 2026-10-19).
  2. **Report-recorded** — the Batch A report's `npm run check` (23 files / 481 tests) and its Playwright scripts (32/32, 8/8+8/8, 5/5). Not re-run here.
  3. **Source-verified** — code paths inspected; recorded with file references.
  4. **Not yet obtained** — live calendar-change surface, mutating negative test, English catalog, product-owner visual acceptance, any Batch B evidence.
- **Rationale**: Constitution Principle V requires evidence appropriate to the claim; the Batch A report itself distinguishes "browser-verified" from "authorised scope". Claiming tier 2 as tier 1 would be a false completion claim; `FR-005-034` and `SC-005-009` forbid it.
- **Alternatives considered**: Re-running the aggregate gate in a documentation-only pass (rejected — the tree did not change; per repository policy, unchanged full-project gates are not re-run for the same evidence). Claiming the report as this pass's evidence (rejected — it is a different pass).

## Decision 7: Governance document updates are evidence-only

- **Decision**: `CHARTER.md`, `DECISIONS.md`, `STATUS.md`, `ROADMAP.md` are updated only with facts that have evidence in this repository. Owner decisions that have not been made are recorded as `Proposed`/`Open`/`Blocked`, never as accepted. No principle, phase gate, or previously accepted decision is rewritten.
- **Rationale**: The four files are the redesign's own declared source of truth (Charter principle 10). `STATUS.md` in particular is materially obsolete (it says F1 is ongoing and no code changed, while Batch A is implemented in the working tree); reconciling it is required, but rewriting its principles is not.
- **Alternatives considered**: Leaving the governance files untouched (rejected — they would then assert factually false status). Rewriting phase definitions (rejected — a material change requiring explicit approval per the charter's normative rule).

## Decision 8: Git operations are planned, not executed

- **Decision**: This pass produces a numbered, approval-gated Git operation plan (`GIT-PLAN-001`) covering **only** management artifacts. No `add`, `commit`, `push`, `merge`, `rebase`, `reset`, `clean`, branch, tag, or config operation is performed. Generated build output (`js/`, `css/`), pre-existing user changes (`AGENTS.md`, `CLAUDE.md`, `.claude/`, `.env*`, `docs/Review uploaded pasted content.zip`), and large reference trees (`references/`, `prototype/`, `handoff/`, `_archives/`) are excluded.
- **Rationale**: The task explicitly withholds approval for Git writes. The working tree is dirty with 700+ entries dominated by regenerated build output; an indiscriminate stage would mix mechanical output with documentation and could not be reviewed.
- **Alternatives considered**: Committing the documentation set directly (rejected — no approval). Staging the build output with the docs (rejected — unrelated, mechanical, and it would obscure the review).

## Decision 9: Task-ledger layout

- **Decision**: `tasks.md` uses a single `T001…T052` sequence grouped into phases: management migration (T001–T015), Batch A closure (T016–T025), owner-gate actions (T026–T027), correctness and blocked cards (T028–T034), workspace/Batch B candidates (T035–T040), backend change requests (T041–T046), and verification/polish gates (T047–T052). Every task carries its card ID, requirement IDs, status, and evidence or gate.
- **Rationale**: The 004 ledger proves the repository's Spec Kit convention works with `[x]`/`[ ]` and inline evidence notes. The card IDs must remain visible in the ledger so the traceability matrix in `spec.md` resolves.
- **Alternatives considered**: One task per requirement (rejected — 34 plus 22 cards would fragment the ledger). Free-form checklist without IDs (rejected — breaks `SC-005-001`).

## Decision 10: What "completion" means for this management feature

- **Decision**: Feature 005's management baseline is complete when: the artifacts exist and agree, all 22 imported cards are mapped with a status and a gate, the governance files state only evidence-backed facts, and the Git plan is delivered for approval. Implementation of any Batch B card is **outside** this baseline.
- **Rationale**: The requested deliverable is a management migration plus an approval-gated Git plan, not code. Treating Batch B as "done" would violate `FR-005-032`.
- **Alternatives considered**: Requiring Batch B approval as part of this feature (rejected — only the owner can grant it, and it is explicitly not requested).

## Decision 11: The R1 checkpoint has its own approved scope

- **Decision**: `GIT-PLAN-001-R1` supersedes the checkpoint scope in Decision 8. It records the Spec Kit 005 migration, governance reconciliation, audit evidence, inspected design handoff, Batch A source/tests, and generated build output in six content commits (C1–C6) plus one post-success status commit (C7) on `feat/pettycash-purchaser-ui-batch-a`. `tasks.md` remains untouched. No push, merge, tag, branch switch, or Batch B work is included.
- **Rationale**: The working tree contains durable management, audit, design, source, and build artifacts that must be recoverable together. Ordered commits with exact allowlists keep each step reviewable and preserve the boundary between Batch A and Feature 004.
- **Exclusions**: Pre-existing user changes (`AGENTS.md`, `CLAUDE.md`, `.claude/`, `.env.testing`, `docs/Review uploaded pasted content.zip`), Feature 004 tests and reports, `_archives/`, `review/bunny/screenshots/`, `test-results/`, the 10 excluded desktop references, the generated mobile duplicates, and the mobile contact sheet remain unstaged.
- **Evidence rule**: Commit results are recorded only after the commits succeed. A failed step is reported as a stop, never as partial completion.
