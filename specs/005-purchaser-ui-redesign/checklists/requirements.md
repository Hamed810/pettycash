# Specification Quality Checklist: Purchaser UI Redesign

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-10-19
**Feature**: [spec.md](../spec.md)
**Reviewer**: management-migration pass (agent-assisted; owner review pending)

> **Marker semantics**: `[x]` means the requirements-quality criterion was reviewed and satisfied. It is not an implementation status. Reviewer notes record the reasoning where a criterion needed interpretation; deliberate deviations from the generic Spec Kit template are listed under Notes rather than hidden.

## Content Quality

- [x] No implementation details (languages, frameworks, APIs)
      — *Reviewer note*: `spec.md` names existing interface anchors (payload field names such as `totals.current`, ARIA roles/attributes, the `pettycash.calendarPreference` key) because the imported acceptance criteria are expressed against those interfaces and must stay verifiable. No technology choice, framework, architecture, or new API is prescribed; the technical approach lives in `plan.md`. Deviation recorded in Notes.
- [x] Focused on user value and business needs
- [x] Written for non-technical stakeholders
      — *Reviewer note*: the narrative sections (scenarios, priorities, blocked journeys) are owner-readable; the traceability matrix is intentionally tabular for auditors. Persian delivery accompanies this pass.
- [x] All mandatory sections completed

## Requirement Completeness

- [x] No [NEEDS CLARIFICATION] markers remain
      — *Reviewer note*: unresolved items are not left as markers; they are explicit blocked cards with a named gate (`BE-CR-001/002/003/005/006`, `REC-01`, intra-OPEN ordering). `BE-CR-004` is approved and is tracked as implementation work, not an open clarification.
- [x] Requirements are testable and unambiguous
      — *Reviewer note*: each FR names an observable outcome; blocked FRs state what MUST NOT be done until the gate opens, which is testable as a prohibition.
- [x] Success criteria are measurable
- [x] Success criteria are technology-agnostic (no implementation details)
      — *Reviewer note*: `SC-005-002/005/006` name exact suites and one test count. Each pairs the command with its outcome; they are verification anchors for a management feature whose deliverable *is* a verification claim, not a technology mandate. Deviation recorded in Notes.
- [x] All acceptance scenarios are defined
- [x] Edge cases are identified
- [x] Scope is clearly bounded
- [x] Dependencies and assumptions identified

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria
- [x] User scenarios cover primary flows
      — *Reviewer note*: five user stories cover the purchaser's list → detail → transaction journeys; the six blocked journeys are listed separately and are correctly **not** presented as deliverable.
- [x] Feature meets measurable outcomes defined in Success Criteria
- [x] No implementation details leak into specification
      — *Reviewer note*: same deviation as item 1; the spec cites interfaces but prescribes no implementation.

## Traceability and Governance (feature-specific)

- [x] `CHK101` All 22 imported card IDs (`UI-PUR-001…016`, `BE-CR-001…006`) appear in both `spec.md`'s traceability matrix and `tasks.md`, each with a requirement, a task, a status, and a gate (`SC-005-001`)
- [x] `CHK102` Card IDs are preserved unchanged; none is renumbered, merged, or dropped (`FR-005-030`)
- [x] `CHK103` The status vocabulary is closed to the six values reviewed in `research.md` Decision 4, and every non-verified status names its exact unblocking condition
- [x] `CHK104` `spec.md` cites the authoritative 001 requirements instead of restating them; no financial, lifecycle, authorization, currency, evidence, or revision rule is redefined (`FR-005-031`, spec §"Domain non-duplication rule"). The approved receipt rule is cited as 001 FR-089–FR-092, not duplicated.
- [x] `CHK105` The 001 specification, its OpenAPI contract, and its workflow contract are out of scope for edits **except** the 2026-10-23 `BE-CR-004` amendments (FR-089–FR-092/SC-017, `evidenceRequired`, conditional submit/resubmit, per-transaction `422`), the 2026-10-24 **gated** proposals for 001 `T436`/`T437` (bodyless/replay-safe delete contract; `ADR-008` pre-submission boundary), and the **2026-10-27 explicitly approved `BE-CR-001` planning exception**: the `FR-019`/`FR-021` clarification text, Phase 77 `T438`–`T441` registration, plan/data-model planning notes, and the matching 005 cross-references. The `T436`/`T437` text remains a proposal and does not authorize implementation until `G-12`/`G-13` open; the 2026-10-27 exception is documentation-only, changes no contract/schema/task state, and does not authorize `T438`–`T441`
      — *Reviewer note (2026-10-27)*: the owner-approved planning exception was reviewed against the 001/005 ledgers. It adds no requirement ID, applies no OpenAPI edit, starts no task, and leaves `T079` `DONE_VERIFIED`, `T080` `BLOCKED_BY_BACKEND`, and `T041` `BLOCKED_PENDING_OWNER_APPROVAL` for implementation authorization only. The owner selected the immutable-account policy (Option A), so the replacement path is withdrawn and `T438`–`T441` were ready under authorization. The criterion is satisfied by the recorded approval and the unchanged contract, not by an implementation claim. *Update (2026-10-28):* the owner separately authorized `T438`–`T441`; all four are implemented and verified, `T041` is `DONE_VERIFIED`, and `T080` retains the `BLOCKED_BY_BACKEND` label pending its own separate implementation authorization and visual gate.
- [x] `CHK106` Approving this specification, plan, or task ledger is explicitly **not** an approval of any card, Batch B, or any `BE-CR`; the 2026-10-23 directive is a separate owner approval limited to `BE-CR-004` (`FR-005-032`)

## Blocked-Item Discipline (feature-specific)

- [x] `CHK107` Five `BE-CR` items (`001/002/003/005/006`) are marked `BLOCKED_PENDING_OWNER_APPROVAL` with their open questions enumerated; `BE-CR-004` records the 2026-10-23 owner approval and is **implemented and technically verified 2026-10-24** (001 `T431`–`T435`; 005 `T044`, `T053`–`T063`; evidence in `docs/reports/005-receipt-creation-slice.md`)
- [x] `CHK108` The three dependent frontend cards (`UI-PUR-003`, `UI-PUR-007`, `UI-PUR-014`) are `BLOCKED_BY_BACKEND`, and the spec states no client-side approximation is permitted (`FR-005-012`, `FR-005-019`, `FR-005-020`)
- [x] `CHK109` The evidence tier of `UI-PUR-016` is now server-derived and no longer permanently `unknown`; readiness uses `evidenceRequired`/`evidenceCount`, and `T057` owns the mapping (`FR-005-027`, `FR-092`)
- [x] `CHK110` `REC-01` (English catalog) and the residual owner options (`REC-04` alternative, FR-062 amendment) are recorded as decisions/options, not silently adopted
- [x] `CHK111` No task in `tasks.md` authorizes a workaround for a blocked item; the approved slice has explicit tasks (`T053`–`T063`, 001 `T431`–`T435`) and does not unblock any other card

## Evidence Discipline (feature-specific)

- [x] `CHK112` Every current-state fact in `spec.md` cites its evidence or names it as report-recorded
- [x] `CHK113` The four evidence tiers are distinguished (re-executed / report-recorded / source-verified / not yet obtained) and never collapsed (`FR-005-034`)
- [x] `CHK114` `DONE_VERIFIED` is defined as technical verification only and is not presented as product-owner visual acceptance (`SC-005-008`)
- [x] `CHK115` No aggregate gate result is claimed as executed by this pass; the Batch A `npm run check` result is quoted as report evidence
- [x] `CHK116` Unverifiable claims (English visual acceptance, live calendar surface, mutating negative test) are named as outstanding rather than implied complete

## 2026-10-24 A–E Amendment (implemented 2026-10-25)

- [x] `CHK117` Each finding A–E has one named owner, requirement, contract rule, task IDs, and acceptance tests: A → `FR-005-039`, C-14, `T064`/`T065`/001 `T436`; B → `FR-005-040`/`041`, C-15, `T066`/`T067`; C → `FR-005-042`, C-16, `T068`; D → `FR-005-043`, C-17, `T069`/`ADR-008`; E → `FR-005-044`, C-18, `T070`; fixture residue → `T071`; evidence → `SC-005-014…018`/`T072`
- [x] `CHK118` `T064`–`T072` were executed under `G-11`/`G-12` and each checked task names its evidence; no task is marked complete without its named tier (targeted Vitest, backend PHPUnit, or four-configuration browser evidence)
- [x] `CHK119` The three gates are recorded accurately: `G-11` and `G-12` are satisfied (2026-10-25); `G-13` is **deferred**. `ADR-008` remains `Proposed — not approved, not implemented`, and no text claims `T437` or the pre-submission server boundary is done
- [x] `CHK122` Verification is recorded at its actual tier: targeted Vitest 12 files / 302 tests, `npm run check` 31 files / 610 tests plus typecheck/lint/stylelint/build, 001 `T436` PHPUnit suites, four browser configurations (`en`/`fa` × desktop/390px), and two consecutive `t061` runs with the fixture baseline restored. No frontend-only test is presented as proof of the 001 `T436` server behavior
- [x] `CHK120` Receipt timing is unchanged: receipts remain enforced at sheet submission/resubmission (`FR-089`–`FR-092`), and no delete/edit/preview/history task adds a pre-submission receipt requirement
- [x] `CHK121` History and fixture integrity are bounded: `T069` hides/filters draft-stage history but deletes no revision/audit/evidence row; `T071` restores the 25/30/35/40 baseline (130 transactions / 66 receipts) around mutating runs, and 41/31 is recorded as test residue, not a product count

## Notes

1. **Deliberate template deviations (reviewed, accepted).** Two generic criteria are interpreted rather than applied literally, because this feature's deliverable is a *management baseline over an existing, already-audited redesign*:
   - Interface identifiers are cited as anchors so imported acceptance criteria stay machine-checkable. The spec does not prescribe implementation; `plan.md` owns the technical approach.
   - Three success criteria name exact test suites/counts because this feature's acceptance is itself a verification claim. Each still states a user-visible outcome.
   Reviewer position: neither deviation weakens the spec's own contract; both are recorded here so the owner can reject them if a stricter reading is preferred.
2. **This checklist is not an approval.** `[x]` records requirements-quality review only. Cards outside the 2026-10-23 slice remain unapproved, Batch B outside the slice is unstarted, and `BE-CR-001/002/003/005/006` remain blocked. `BE-CR-004` is approved by the separate owner directive; the 2026-10-24 A–E work is approved by `G-11`/`G-12`.
3. **Owner visual acceptance remains pending for Batch A and Batch B.** The 2026-10-23 directive records the owner's receipt/creation decision; it does not review the remaining blocked cards. Items CHK106/CHK110 remain placeholders for those gates.
4. `/speckit.implement` reads checkbox state as a gate and must not modify markers; the approved slices are `T053`–`T063` / 001 `T431`–`T435` and, from 2026-10-25, `T064`–`T072` / 001 `T436`. No task is complete until its evidence exists.
5. **The 2026-10-24 A–E amendment was executed under explicit approval.** `CHK117`–`CHK122` record that the findings are mapped, gated, and verified. `G-11`/`G-12` opened on 2026-10-25; `G-13` stayed deferred, so `T437`/`ADR-008` remain unapproved and unimplemented. The implementation added the named source, test, l10n, and E2E files plus 001 `T436`; no migration or stored-row rewrite was performed. Nothing was committed or pushed.
