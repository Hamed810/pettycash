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
      — *Reviewer note*: unresolved items are not left as markers; they are explicit blocked cards with a named gate (`BE-CR-001…006`, `REC-01`, intra-OPEN ordering).
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
- [x] `CHK104` `spec.md` cites the authoritative 001 requirements instead of restating them; no financial, lifecycle, authorization, currency, evidence, or revision rule is redefined (`FR-005-031`, spec §"Domain non-duplication rule")
- [x] `CHK105` The 001 specification, its OpenAPI contract, and its workflow contract are explicitly out of scope for edits
- [x] `CHK106` Approving this specification, plan, or task ledger is explicitly **not** an approval of any card, Batch B, or any `BE-CR` (`FR-005-032`)

## Blocked-Item Discipline (feature-specific)

- [x] `CHK107` All six `BE-CR` items are marked `BLOCKED_PENDING_OWNER_APPROVAL`, with their open questions enumerated (`BE-CR-001/002/003/004/006`)
- [x] `CHK108` The three dependent frontend cards (`UI-PUR-003`, `UI-PUR-007`, `UI-PUR-014`) are `BLOCKED_BY_BACKEND`, and the spec states no client-side approximation is permitted (`FR-005-012`, `FR-005-019`, `FR-005-020`)
- [x] `CHK109` The evidence tier of `UI-PUR-016` is forced to `unknown` while `BE-CR-004` is unapproved (`FR-005-027`)
- [x] `CHK110` `REC-01` (English catalog) and the residual owner options (`REC-04` alternative, FR-062 amendment) are recorded as decisions/options, not silently adopted
- [x] `CHK111` No task in `tasks.md` authorizes a workaround for a blocked item

## Evidence Discipline (feature-specific)

- [x] `CHK112` Every current-state fact in `spec.md` cites its evidence or names it as report-recorded
- [x] `CHK113` The four evidence tiers are distinguished (re-executed / report-recorded / source-verified / not yet obtained) and never collapsed (`FR-005-034`)
- [x] `CHK114` `DONE_VERIFIED` is defined as technical verification only and is not presented as product-owner visual acceptance (`SC-005-008`)
- [x] `CHK115` No aggregate gate result is claimed as executed by this pass; the Batch A `npm run check` result is quoted as report evidence
- [x] `CHK116` Unverifiable claims (English visual acceptance, live calendar surface, mutating negative test) are named as outstanding rather than implied complete

## Notes

1. **Deliberate template deviations (reviewed, accepted).** Two generic criteria are interpreted rather than applied literally, because this feature's deliverable is a *management baseline over an existing, already-audited redesign*:
   - Interface identifiers are cited as anchors so imported acceptance criteria stay machine-checkable. The spec does not prescribe implementation; `plan.md` owns the technical approach.
   - Three success criteria name exact test suites/counts because this feature's acceptance is itself a verification claim. Each still states a user-visible outcome.
   Reviewer position: neither deviation weakens the spec's own contract; both are recorded here so the owner can reject them if a stricter reading is preferred.
2. **This checklist is not an approval.** `[x]` records requirements-quality review only. Cards remain unapproved, Batch B unstarted, and `BE-CR-001…006` blocked.
3. **Owner review pending.** The product owner has not yet reviewed `spec.md`; items CHK106/CHK110 are placeholders for that gate.
4. `/speckit.implement` reads checkbox state as a gate and must not modify markers; no implementation is authorized by this feature regardless of marker state.
