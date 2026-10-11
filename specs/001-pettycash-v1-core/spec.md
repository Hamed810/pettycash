# Feature Specification: PettyCash V1 Core Workflow

**Feature Branch**: `001-pettycash-v1-core`

**Created**: 2026-09-19

**Status**: Approved for implementation — under convergence. 327 of 377 ledger tasks complete
(case-insensitive checkbox count, 2026-10-09); T304, T326, T327, T428, T429, T430 and T171 are delivered
and no open task carries a `[CRITICAL]` tag. The purchaser journey is closed: T171's E2E suite is 7 of
7 green as of 2026-10-09. (Corrected by T430; its prescribed "323 of 374" described the pre-Phase-74
state and was superseded before it could land by T171, T428, T429 and T430 itself closing in the same
pass.)

**Input**: User description: "Read the existing business requirements and create feature
specifications from them. Do not implement anything. Only create specs."

## Clarifications

### Session 2026-09-22

- Q: What minimum audit retention period should FR-057 require for PettyCash V1? → A: 7 years
- Q: What measurable criteria should define "normal usability" in FR-073? → A: p95 latency <3s for routine views (SC-008), error rate <0.1%, zero data loss
- Q: What measurable criteria should define "clearly show" in FR-071? → A: all elements visible without horizontal scroll at 390px viewport, WCAG AA contrast, ≥12pt Persian font
- Q: How should authorization decision matrix resolve conflicts for multi-role users? → A: explicit allow when user holds required review role + scope + stage permission; ownership grants purchaser rights only
- Q: What does "advisory decision" mean for cost center manager decisions in FR-034? → A: visible to senior manager with full details; senior may decide independently without forced acknowledgment
- Q: What audit scope rules apply to configuration audit events (FR-056) for FR-055? → A: admins see all; finance see accounts they charge; managers see their cost centers
- Q: What specific evidence restrictions constitute "advanced evidence-specific restrictions" excluded from V1 in FR-030? → A: V1 permits all authorized viewers to see all evidence attached to accessible transactions; advanced restrictions (document classification, redaction, watermarking, extended access logging, time-limited access, evidence-type permissions) deferred to V2
- Q: What maximum file size limit should V1 enforce for evidence uploads per FR-074? → A: 100 MB per file
- Q: What minimum browser versions should "current Chrome, Edge, and Firefox" resolve to for FR-072? → A: Chrome 120+, Edge 120+, Firefox 115+ (ESR)
- Q: How should "separately show approved totals" (FR-062) be presented in UI? → A: separate columns (Cost Center Approved | Senior Approved | Finance Approved) alongside latest effective outcome column
- Q: What happens when purchaser has zero active accounts in FR-020? → A: default active rial account auto-created on first sheet creation attempt (idempotent provisioning) *(Qualified 2026-10-08: "zero active" means no rial account **record**; an account that exists but is inactive refuses creation with `422` instead — see the 2026-10-08 session.)*
- Q: What TTL/expiry and access logging should public evidence links (FR-031) enforce? → A: configurable TTL (default 30 days, max 1 year) + per-access audit logging (viewer UID, timestamp, IP) in configuration audit. **The "viewer UID" wording is superseded by the Session 2026-10-07 answer**: link consumption is unauthenticated, so the viewer is recorded as `anonymous` rather than as a UID. The TTL, deactivation and audit-scope parts of this answer stand.
- Q: What constitutes a "reporting period" for group exports in FR-068? → A: predefined calendar periods (month, quarter, year) plus custom date range

### Session 2026-09-25

- Q: Which database backends should PettyCash V1 formally declare as supported for application use? → A: MySQL 8.0/8.4, MariaDB 10.6/10.11/11.4/11.8, and PostgreSQL 14/15/16/17/18, verified from the Nextcloud 34 Administration Manual system requirements; MariaDB 11.4 remains the local development backend only; Oracle and SQLite are outside V1 scope as non-recommended for application use. **Superseded later in this session:** the second answer below adds `sqlite` to the declared set for compatibility verification. The final declared set is `mysql`, `pgsql`, and `sqlite`.
- Q: What evidence should satisfy the cross-database compatibility requirement during V1 acceptance, given the MariaDB development environment must stay unchanged? → A: provision ephemeral CI database backends for every declared database family and run forward migrations/schema creation, persistence/repository integration tests, representative transaction/workflow queries, and migration/schema compatibility checks on each; additionally run static portability checks on every build that reject vendor-specific SQL and schema assumptions; keep the existing local MariaDB 11.4 Docker environment unmodified, with additional databases as CI-only ephemeral test services. The answer also fixes the declared set to the three Nextcloud types `mysql`, `pgsql`, and `sqlite`, and requires that because `mysql` covers two concrete engines CI must run one MySQL job and one separate MariaDB job rather than assuming MariaDB proves MySQL compatibility, with Oracle/`oci` outside V1 scope unless explicitly added later as an Enterprise compatibility target
- Q: Should PettyCash adopt Oracle's strictest identifier limits as a blanket rule, even though Oracle is explicitly outside V1 scope? → A: no; keep the schema within the limits required by the declared V1 targets (mysql/MariaDB, PostgreSQL, SQLite), do not rename existing tables or add migrations for hypothetical future Oracle support, and do not treat Oracle-specific identifier or schema limits as current V1 requirements; Oracle/Enterprise compatibility is recorded in `plan.md` as a deferred architecture constraint that must first be re-verified against the targeted Nextcloud and Oracle versions and given an explicit compatibility and migration plan before support is added
- Q: The repository already has four migration files that create `pc_*` tables and about nineteen repositories that reference those names; since V1 is unshipped and its data is disposable, how should those migrations be converted to an app-scoped `pettycash_*` namespace? → A: edit Version0100–0103 and every repository, test, fixture, and schema assertion in place, so the clean V1 baseline creates the logical `pettycash_*` namespace from the start; do not add a rename migration and do not keep a mixed namespace; because these migrations have already run in the shared development instance, follow the repository change with an approval-gated development-only reset of PettyCash state so the rewritten baseline executes from scratch, affecting PettyCash only, preserving all unrelated apps and data, and never deleting Docker volumes or the shared Nextcloud database; current `pc_*` development data is disposable by design and MUST NOT be preserved
- Q: Nextcloud records applied migrations per app ID and the discarded prototype used the same `pettycash` app ID, so residual migration-version rows could make Nextcloud skip the rewritten `pettycash_*` baseline migrations; how should the artifacts handle that? → A: replace the legacy framing with an explicit development-environment precondition that clearing the `pettycash` app-ID migration state is part of the development reset, so the rewritten baseline always executes; treat it as a development-environment step only, not a production or data concern
- Q: What should the artifacts say about residual `oc_pc_*` prototype tables that remain in the shared development database? → A: leave them untouched and record them as unmanaged artifacts; V1 MUST NOT read, write, migrate, import, rename, or drop them, and their presence is not a V1 defect or a trigger for any cleanup action
- Q: Index and constraint names are global per database schema, so which app-scoped naming form should the V1 schema use? → A: `pettycash_<table>_<subject>_<kind>` where kind is `idx`, `uniq`, `fk`, or `chk` (for example `pettycash_transactions_status_idx`, `pettycash_transaction_revisions_number_uniq`); the old generic `pc_` prefix MUST NOT be used for indexes or constraints, and identifiers MUST stay comfortably below the PostgreSQL 63-byte and MySQL 64-character limits, abbreviating only when a full descriptive name would exceed the portable limit

### Session 2026-09-28

- Q: Should petty-cash balances and movements be their own report types, or data within the existing `accounts` report? (FR-065) → A: Data within the existing `accounts` report; the report-type enum is not extended.
- Q: Should FR-064 keep all twelve filtering facets even though five of them cannot currently be expressed, or should the requirement be narrowed to what exists? (FR-064) → A: Keep all twelve; they are V1 requirements, not deferred, and each facet's source is now stated.
- Q: Where should the list of accounts a finance officer has charged come from, since nothing currently records it? (FR-055) → A: Derived from the officer's own configuration audit events for account subjects, written by T294; no migration and no coupling to the command log.
- Q: Which column names should the configuration audit subject use, given the spec's data model and the shipped migration disagree? (T253, FR-056) → A: Keep the migration's `entity_type`/`entity_id` and correct `data-model.md`; the domain entity already uses those names, so the document is the outlier.
- Q: Which configuration audit events count as a finance officer charging an account, given that no event vocabulary exists for configuration audit? (FR-055) → A: Only account funding and balance-charging events, by a named event type; a rename, activation or deactivation does not grant visibility into that account's configuration history.
- Q: Whose accounts and balances may the `accounts` report show for each role? (FR-065, FR-055, FR-015) → A: A user sees their own accounts; a finance officer additionally sees every account they have charged; an administrator sees all.
- Q: When a finance approval consumes an account's balance, which audit stream records that the officer charged that account? (FR-055, T294) → A: Settlement also writes a configuration audit event naming the charged account, so a charge appears in both streams and the derivation is not left empty.
- Q: What counts as a view being "usable" within three seconds, so that SC-008 can actually be measured? (SC-008) → A: The view finishes loading and shows the rows the user is entitled to see, with no error state, measured end to end as a user sees it.
- Q: Should SC-008's three-second usability target be verified during V1, or is that verification deferred to a later milestone? (SC-008) → A: **Superseded 2026-10-02 — verification is deferred to post-V1.** See the Session 2026-10-02 answer. (Original 2026-09-28 answer, retained for history: Verified during V1, not deferred; the measurement is performed and its result recorded, so the criterion is reported as met or not met rather than assumed.)

### Session 2026-09-30

- Q: Both requirements about exporting selected records are numbered FR-068, so which number should the requirement about refusing unsatisfiable selections get? (FR-068) → A: Renumber it to FR-082. Both rules stay separately traceable, and the group-export coverage rule keeps FR-068 because every existing cross-reference uses FR-068 for it.
- Q: When a user opens an evidence file, should the app try to render it in the browser or only ever download it? (FR-060) → A: Render inline-safe media types in the browser -- images, PDF, plain text -- using the file's stored media type with an `inline` disposition; send every other media type as `application/octet-stream` with an `attachment` disposition, never rendered inline. The contract is amended to match, not the requirement.
- Q: When one stored record is corrupt or incomplete, what should a list or dashboard request return to the user? (FR-083) → A: `422` with an explanatory message on sheets, sheet detail and dashboard too, matching reports, with `500` reserved for genuinely unexpected faults and no unreadable record silently omitted or defaulted.
- Q: Is producing a PDF export part of V1, or is it deferred to a later release? (FR-066) → A: Part of V1. An OFL-licensed Persian font is vendored into the repository under an app-owned path with its licence text, so `pdf` is supported unconditionally and never depends on a host- or container-installed font.
- Q: Does SC-010's moderated usability test have to be run before V1 can be released? (SC-010) → A: It is measured and its result recorded, but it does not block release. When representative users or a facilitator are unavailable it is recorded explicitly as not measured, with the reason, and is never reported as met by default.
- Q: When someone picks the "month" or "quarter" export preset, which calendar defines that period's boundaries? (FR-068) → A: Jalali (Solar Hijri), matching the calendar every date in the interface is already rendered in. A period spans Farvardin to Esfand and a quarter begins at Farvardin; it resolves to a Gregorian from/to pair for querying without altering any stored expense date, and is never identified by a Gregorian year number.

- Q: When someone picks the "month" export preset, which Jalali month does it select? (FR-068) → A: The current Jalali period, running from its first day through its last, not the previous completed period and not a trailing window. Because a period in progress is incomplete, every response that resolved a predefined period states the exact date range it resolved; a previous or trailing period is requested with the explicit custom date range instead.
- Q: Which time zone decides where a Jalali month begins and ends? (FR-068) → A: The time zone of the account making the request, from that user's own account settings, falling back to the instance's configured default when the account states none; no zone is hardcoded. The resolved range is therefore relative to the requesting user, and the response states both the range and the zone it was resolved in.
- Q: When a period's boundary lands on a day, does that day count as inside the period or outside it? (FR-068) → A: Inclusive at both ends. A period covers every expense date from its first day through its last day, so a predefined period and the equivalent explicit date range select exactly the same records. A Jalali year is 365 or 366 days and is never treated as a fixed length.
- Q: If a request supplies both a predefined period and an explicit date range, which one decides the records? (FR-068) → A: They are mutually exclusive within one request. Supplying both is refused with a `422` naming the conflict and stating which of the two to remove; the application never honours one while discarding the other, and never intersects them.
- Q: When someone filters by a period such as "this month", is that a filter in its own right or simply another way of expressing a date range? (FR-064) → A: A filter in its own right. It joins FR-064's accepted list explicitly, scoped to the export and report endpoints that already accept a date range, and because FR-068 makes the two mutually exclusive it is refused alongside a date range rather than merged into or silently narrowing one.

### Session 2026-10-02

- Q: When a purchaser attaches an evidence file larger than 100 MB, what should happen if the server's own upload limit would allow it? (FR-074) → A: Reject anything above 100 MB regardless of the host limit — 100 MB is PettyCash's own hard ceiling, enforced by PettyCash and stated to the user. Where the host limit is *lower* than 100 MB the host rejects first, and that rejection is reported as a host-limit failure rather than as the 100 MB refusal, so the two ceilings are never conflated. The earlier "MUST follow the host file service rather than introduce a separate PettyCash limit" clause is withdrawn as superseded; the 100 MB figure the 2026-09-22 session settled on is retained.
- Q: Is the 3-second performance target (SC-008) verified during V1, or explicitly deferred to after V1? (SC-008, FR-073) → A: Deferred explicitly. The 3-second target remains the V1 design target governing how queries are built, but the measurement at the stated scale is not a V1 acceptance gate: V1 release readiness MUST record SC-008 as **not measured**, naming post-V1 verification as the reason, on the same principle SC-010 already uses. Any V1 latency run (T128's optimisation pass) is **informational** and MUST NOT be presented as satisfying SC-008, because a run below the stated scale or without the acceptance procedure is not the measurement the criterion describes. The 2026-09-28 answer that verification happens during V1 is superseded.
- Q: When a sheet has one transaction returned for correction and another already at the finance stage, which rule defines the sheet's status? (FR-037, FR-028) → A: Derivation stands and `CORRECTION` is a real sheet status. Each transaction enters and leaves its queue on its own decision, no transaction blocks another, and the sheet's status is the earliest stage still outstanding over the ranking `Draft 0, CostCenter 1, Correction 2, Senior 3, Finance 4, Final 5`. Because `CORRECTION` outranks `SENIOR` and `FINANCE`, such a sheet displays `CORRECTION` even while another transaction is at finance; that is FR-028's intended reading and not a defect. FR-037 is given the gate sentence — the transactions required for the sheet-stage transition are those whose active nonterminal revisions all carry a senior decision, a senior rejection counting as a senior decision — and FR-043's completion rule is the only true gate. `data-model.md`'s derivation section is corrected: its `AWAITING_*` names were `TransactionStatus` values, not `SheetStatus` values, and `CORRECTION` was missing. Verified before asking: the code (`ExpenseSheet::recalculateStatusFrom`, `lib/Domain/Expense/ExpenseSheet.php:111-179`), three passing tests, and `DbExpenseTransactionRepository::findForFinanceQueue()` filtering per transaction rather than on sheet status.
- Q: What counts as the "error rate <0.1%" that FR-073 requires the product to hold? (FR-073) → A: Define it precisely and own it. Error rate means **5xx responses as a share of all HTTP requests issued during V1 acceptance**, counted across the automated functional, contract and end-to-end suites rather than over a dedicated load run, and recorded as a **V1 release-readiness figure** by T135. A client-side 4xx is not an error for this purpose, because a request the application correctly refused is not a defect; the 4xx, 409 and 422 responses that contract tests deliberately provoke are excluded from both numerator and denominator. The result must be recorded whether or not it passes. **The exclusion list above is extended, not
  replaced, by the Session 2026-10-07 answer**, which adds a second excluded class — environment-induced
  responses such as a brute-force `429` — and an excluded-count publication plus a 1% contamination
  ceiling above which the figure is recorded as not measured. This is deliberately not deferred the way the p95 bound is: it needs no dedicated load run, since it is a byproduct of the suite that "zero data loss" already requires, and it is a real defect threshold rather than a performance benchmark. Zero data loss remains a correctness invariant owned by the atomicity and failure-injection suites, and all three bounds must be reported separately rather than as one performance claim. Verified before asking: "error rate <0.1%" appears at `spec.md` (this requirement and the 2026-09-22 clarification) and `plan.md` with no denominator or window, and T128 measures latency only — no task measured error rate.

### Session 2026-10-07

- Q: When PettyCash measures its "<0.1% error rate" release-readiness figure, how should responses that came from the test environment rather than from an application defect — for example Nextcloud's own brute-force limiter answering `429 Too Many Requests` — be counted? (FR-073) → A: **Excluded from both numerator and denominator, and made visible rather than absorbed.** A response the application or its platform produced for reasons unrelated to a defect — a brute-force `429`, an infrastructure or platform `5xx`, a transport failure — is an **environment-induced response** and is excluded from both the numerator and the denominator. Two further rules follow, and they exist because a contended run distorts the ratio rather than merely adding noise to it: the count of excluded requests MUST be published beside the figure, so a reader can see how much of the run was not measured; and if the excluded share of requests exceeds **1%** of the requests the run issued, the figure MUST be recorded as **not measured** rather than reported, on the same principle already applied to SC-008 and SC-010, because a run that was mostly throttled produces a ratio that looks better than reality precisely when the run is least trustworthy. A deliberate reading of this answer is that a `429` storm inflates the denominator with responses that never exercised application code while suppressing the successful requests that would have exercised it, so leaving such requests in the denominator would reward a broken run. The 2026-10-02 answer's exclusion of deliberately provoked 4xx/409/422 is **retained** and is a separate, narrower class: a provoked response is an expected assertion of the contract suite, whereas an environment-induced response is contamination the run did not intend and cannot distinguish from a defect by status code alone. Verified before asking: the 2026-10-02 answer excluded only "the 4xx, 409 and 422 responses that contract tests deliberately provoke"; `tasks.md` T313's note records four full-suite attempts yielding 2, 7, 30 and 151 failures, every one a `429` assertion, produced by two sessions sharing one instance.
- Q: When an administrator creates a public evidence link, exactly who is allowed to open the file through that link? (FR-031) → A: **The token is the whole authority, and it is the only unauthenticated data path in V1.** Consumption of a public evidence link is **unauthenticated**: anyone who holds the unguessable token may open the linked evidence item, without signing in and without holding any role or scope right on the transaction. This is the deliberate, recorded exception to the role-scope rule FR-003 and FR-009 otherwise apply, and it is bounded by four conditions. **(1) Abuse control is delegated to Nextcloud's existing brute-force limiter, not reimplemented**: because the source requirements delegate authentication and password/login policy to Nextcloud (§11.9), PettyCash MUST NOT add a second authentication, rate-limiting, or access-control mechanism for these links, and a link consumption attempt is subject to the platform limiter exactly as any other unauthenticated request is. **(2) The per-access audit records the viewer as `anonymous`**, together with timestamp and IP, in configuration audit as FR-031 already requires — the viewer field is never left null or absent, because an anonymous visit has no UID and a nullable field would make the audit entry unusable for the access investigation it exists to serve. **(3) The token is stored only as a hash and shown once at creation**, unchanged from FR-031, so a leaked database cannot be replayed into evidence access. **(4) An administrator's own view right is not required to create a link**, and a link created for evidence on a transaction the administrator cannot themselves view is permitted and is audited as such, so an administrator's use of the capability is reconstructable rather than silent. Because the token is the entire authority, TTL and revocation are the only remaining controls, and **an expired, deactivated or unknown token MUST be refused with the same answer**, so that the refusal reveals neither whether a link ever existed nor when it lapsed. Verified before asking: `DOC/Requirements/11-...md` §11.8 authorises the public link and requires admin control, manageability, deactivation and audit but states nothing about who may consume it; §11.9 delegates authentication policy to Nextcloud; `contracts/ocs-api.yaml` defines `POST /evidence/{evidenceId}/links` and `POST /evidence-links/{linkId}/deactivate` and **no route that consumes a token**; `tasks.md` T095 and T166 are both unchecked.
- Q: Is keyboard and screen-reader accessibility a V1 acceptance requirement, or only the contrast, font-size and no-horizontal-scroll checks that FR-071 already names? (FR-071, FR-076, SC-009) → A: **Keyboard operability and visible focus are V1 requirements; screen-reader semantics are deferred to V2.** Two criteria are added to V1 acceptance, and they are scoped to the **primary journeys** — the ones User Stories 1–7 exercise — rather than to every page: a user MUST be able to complete each primary journey **using the keyboard alone**, and every interactive element reached that way MUST show a **visible focus indicator**. "Visible" means a perceivable change to the focused element, not merely a browser default that the host theme may erase; a focus indicator the active theme makes invisible is a defect, because a keyboard user then has no indication of where they are. **Three accessibility items already present in `quickstart.md:229` are deferred to V2 and struck from that checklist**: form control label association, error-message association with the field that caused the error, and announced (live-region) status for mutations. The reason is scale rather than importance — naming every control and announcing every mutation is a compliance-sized surface to add after 71 of 276 ledger tasks are already complete, and deferring it explicitly is honest where silently leaving it unmentioned would have read as satisfied. `quickstart.md` MUST be updated to match: the keyboard-navigation and visible-focus items stay as validation steps for the primary journeys, and the three deferred items are removed from the checklist and recorded here and in FR-076 as V2. Verified before asking: `quickstart.md:229` was the only occurrence of keyboard navigation, visible focus, dialog containment, label association or error association in any feature artifact — `spec.md`, `plan.md`, `tasks.md` and `research.md` all carry no accessibility requirement, task, or success criterion beyond FR-071's contrast, font-size and no-horizontal-scroll clauses, so the project's own release checklist asserted checks that nothing owned.
- Q: When a decision arrives for a transaction that has already moved on — for example an approve that was in flight while another actor's rejection landed — what must the application do with it? → A: **First write wins, and the losing attempt is recorded as a superseded attempt rather than dropped.** When a decision is submitted for a transaction whose current state no longer accepts it — because another decision was already recorded against the same active revision and stage, or because the active revision changed underneath the request — **the decision already recorded MUST stand and MUST NOT be overwritten**, and the later request MUST apply **no** business effect: no decision recorded, no revision created, no workflow transition, no balance movement, and no audit event representing the attempted decision as having taken effect. The attempt MUST nonetheless be recorded in audit as a **superseded attempt**, naming the transaction, its active revision, the stage, the actor, the time, the decision the caller attempted, and the decision already in force. It is recorded rather than silently discarded because a discarded attempt is indistinguishable from a request that never happened, so a reviewer who acted would leave no trace; it is recorded as its own event type rather than as an approval or a rejection because recording it as either would falsify exactly the decision history Principle IV protects. The client is answered `409 Conflict` — the state it was reasoning about no longer holds — naming that a decision is already recorded and that it must reload to see the current outcome. **First write wins is chosen over last-write-wins specifically to make double-booking structurally impossible**: accepting a second approval is the only outcome that could produce two consumption movements against one transaction and decrement the account twice, and a rejected or returned transaction could be silently promoted to approved. It is chosen over silently ignoring the loser because that discards a reviewer's real action, and over serializing server-side because a blocking lock on the decision path buys no correctness property that refusing the stale request does not already provide, at the cost of latency and of a retry rule V1 has explicitly forbidden (FR-075 forbids automatic resumption, so a transparent client-side retry would contradict it). This is **distinct from FR-084**, which covers replay of the *same* command under the *same* `X-PettyCash-Command-ID`: a replay is one command arriving twice and returns the first outcome, whereas a superseded attempt is a *different* command issued against state that had already changed, which no command identifier can detect and which therefore requires the server to check the transaction's current state. Verified before asking: `data-model.md` lines 23–24 and the `lock_version` columns on the mutable aggregates already state "for optimistic checks" and "Concurrency control", but no requirement stated the resulting behaviour; FR-084 covers only same-command replay; and `409` is already the contract's `Conflict` response on the decision endpoints (`contracts/ocs-api.yaml:84, 109, 144, 169, 306`).
- Q: What should happen at finance settlement if the purchaser's petty-cash account for the sheet's currency has been deactivated since the sheet was created? (FR-040, FR-042, FR-020) → A: **Refuse the finance approval; never provision an account as a side effect of settlement.** Where no **active** petty-cash account in the sheet's currency exists for the purchaser at the moment of final finance approval, the approval MUST be **refused with `422`**, naming the missing or inactive account. The transaction MUST remain at the finance stage and **no** balance movement, consumption record, workflow transition or final outcome MUST be recorded, so the approval is not partially applied. Two properties follow, and both are the reason this answer was chosen over the alternatives. **FR-020's idempotent provisioning does not extend to settlement**: it is scoped to *sheet creation*, and extending it would mean a finance decision silently creates a funding object — moving approved expense against a balance nobody funded and nobody approved, which is precisely the silent financial effect FR-042 and Principle IV exist to prevent. **The remedy is an administrator's deliberate act**, not a side effect of a click: restoring or reactivating the account, or charging the intended account, is a configuration change that FR-015 and FR-017 already cover with their own audit. The refusal MUST therefore be actionable rather than merely valid — it states which account is missing or inactive and what an administrator must do — so that finance is not left holding a transaction it can never settle with no way forward. An approved-but-unsettleable expense is an acceptable and visible intermediate state; an expense booked against a balance that was conjured up at approval time is not. Verified before asking: FR-040 requires one consumption movement "against the petty-cash account matching the sheet currency" and FR-042 requires atomic completion, so with no such account the requirement has no satisfiable action; FR-020's auto-provisioning is scoped to the first sheet-creation attempt and covers only the zero-active-accounts rial case; and no requirement anywhere addressed account deactivation between sheet creation and settlement, although FR-013 provides administrator deactivation of accounts (`PATCH /accounts/{accountId}`, decided T409). (Corrected T412: this previously cited FR-055, which governs configuration-audit *visibility* and confers no capability.)


### Session 2026-10-08

- Q: What should happen when a purchaser whose only petty-cash account was deliberately deactivated tries to create a new sheet — should FR-020's idempotent provisioning fire and hand them a fresh rial account, or should the attempt be refused? (FR-020, FR-014, FR-017, FR-055) → A: **Refuse with `422` naming the inactive account; a deactivation is a deliberate, audited act that the purchaser's own click MUST NOT undo.** FR-020's trigger fires on *zero **active** accounts*, and a deactivated account is not active — so the trigger's precondition is met by an account somebody deliberately retired. Provisioning there would let a user's own sheet-creation click silently reverse an administrator's deactivation, replacing the account with a *new* one carrying neither the retired account's identity and history nor any explanation, in a configuration domain where FR-017 requires exactly that record for the change that retired it. This is the same silent-financial-effect argument the 2026-10-07 settlement answer applied, and it is now applied **consistently across both sides of the workflow** rather than to settlement only: provisioning as a side effect of a click creates a funding object nobody approved, and that answer already established that the remedy is *an administrator's deliberate act*.

**The carve-out is on the trigger, not on the rule.** FR-020's zero-account case is unchanged and remains the only automatic provisioning: where the purchaser has **no** rial account record at all, provisioning proceeds exactly as specified and stays idempotent. Where a rial account **exists and is inactive**, the trigger MUST NOT fire, and the sheet-creation attempt MUST be **refused with `422`** naming the inactive account and stating that an administrator must reactivate it or charge an account. The two cases MUST NOT be collapsed into one another: they are distinguished by whether a record exists, not by the active flag alone. The refusal MUST be **actionable rather than merely valid**, on the same terms as the settlement refusal — a purchaser told only “no active account” has no way to proceed and no way to learn that the remedy exists.

**The consequence, stated rather than left implicit.** FR-014's *“every user MUST have a default active rial petty-cash account”* is a **baseline that an administrator's deactivation suspends**, and the purchaser cannot lift that suspension themselves — only an administrator can, and that act is itself audited under FR-017. Sheet creation is therefore a capability a purchaser can lose and cannot self-restore. That is accepted here knowingly, because the alternative is a click that reverses an audited administrative decision and manufactures a replacement funding object, which is the worse failure. Verified before asking: FR-020 states the trigger as “first sheet creation attempt” against “zero active accounts” and does not distinguish a never-created account from a deactivated one; the spec's only other deactivation rule covers settlement (`spec.md:405`, FR-040) and is silent about what precedes creation; FR-013 provides administrator deactivation of accounts (`PATCH /accounts/{accountId}`, decided T409) and FR-017 requires that change to be audited; and the implemented behaviour was a source comment rather than a decided requirement, so nothing would have caught a later change to it. (Corrected T412: this previously cited FR-055, which governs who may *see* configuration-audit events and grants no deactivation capability.)

- Q: Should the creation of a petty-cash account be recorded in the configuration audit, given that neither of the two paths that creates one writes a row today? (FR-017, FR-013, FR-020, FR-014) → A: **Yes — and as one shared event type written from every path that creates an account, with the recorded actor doing the distinguishing.** Account creation is an audited configuration change (FR-017).

**One event type, not one per origin.** Two paths create accounts: an administrator's deliberate creation (FR-013) and FR-020's self-service provisioning. They are the same kind of fact — a spending-capable object came into existence — so a single shared event records both and the `actor_uid` already audited per FR-017 tells them apart. Two event types would fragment the very stream an administrator reads to answer "who created this, and was that authorised", and would make a filter over the trail miss one origin.

**For a provisioned account the recorded actor is the account holder — the purchaser whose own click created it.** That single fact is the most useful thing the row says: it shows at once that **no administrator authorised the account**, and that the holder's own action produced it. An audit that recorded only "an account was created" would be true of both origins and therefore useless for distinguishing them.

**Recording the creation must not be mistaken for granting one.** The audit row is a record, not an authorisation: provisioning stays self-service, a purchaser still needs no approval or role to obtain an account, and the event MUST NOT be presented or exported as evidence that an administrator consented. This mirrors the 2026-10-07 evidence-link clarification, where an administrator's own view right was explicitly not required for a capability the spec grants.

**The omission was an inconsistency rather than a policy.** Currency, cost center and expense type each record their own creation; an account's *funding* (`AccountFunded`) and *charging* (`AccountCharged`) are both recorded; and **no account-creation event type existed anywhere in the codebase**. So the object that confers spending authority was the only configuration object whose arrival left no trace. FR-017's list named "assignments, expense types, currencies, access, and control settings" and did not name accounts, which is exactly why the question was open rather than settled — the omission read equally well as an oversight or as a decision, and only one of those is defensible. FR-017 is amended to name account creation explicitly so the list is decidable by whoever writes the next such change. Verified before asking: no `AccountCreated`-equivalent exists in the configuration-audit event enum or in any test; the provisioning service has zero audit references, and the administrator creation path's only audit write is the funding event inside the separate charge operation.
- Q: Now that a deactivated account blocks sheet creation, how should FR-014's *“Every user MUST have a default active rial petty-cash account”* be worded? (FR-014, FR-020, FR-017) → A: **Qualify FR-014 at the point of the contradiction: every user MUST have a default active rial petty-cash account *except where an administrator has deactivated it*.** The previous day's ruling made a user able to hold no active rial account indefinitely — only an administrator can end that state — so FR-014 as written was an unconditional MUST that a normal configuration state violates. Two requirements that cannot both be true is a defect in the specification, not in the implementation, so it is repaired at the source rather than worked around.

**The exception is stated where the MUST lives, not only in the requirement that enforces it.** FR-020 is where a purchaser meets the consequence, but a reader arriving at FR-014 alone must not have to know that FR-020 exists in order to read it correctly. Stating it in both places is reinforcement of one rule in two places, not two rules.

**The wording deliberately calls this a suspended baseline, not a withdrawn requirement.** Deactivating an account is a deliberate, audited act (FR-017) rather than a defect, and the obligation is still live — it is suspended until an administrator's deliberate act lifts it, which is the same shape as the settlement refusal decided on 2026-10-07. Framing it as withdrawn would suggest the requirement no longer applies, which is the opposite of what was decided.

**Rejected: making FR-014 absolute** by refusing to deactivate a user's last active rial account. It removes the contradiction too, but it would leave the refusal just decided almost unreachable — if the last active rial account could never be deactivated, then a rial account existing but inactive would be a near-impossible state, and FR-020's refusal would have almost nothing to refuse. The requirement that does the refusing (FR-020) and the requirement that states the baseline (FR-014) must be able to disagree in a real state, or neither the exception nor the refusal is reachable. Verified before asking: FR-014 stated the MUST with no qualification; no test asserted the absolute form; and FR-055's deactivation capability (audited per FR-017) makes the suspended state reachable by design rather than only by data error.
- Q: When a caller retries a command because they never received the first response, how should that retry be answered? (FR-042, `data-model.md`, FR-031) → A: **A retry must be a working retry: the second attempt with the same command id and the same intent MUST return the original result, not a conflict.** Where a request carries no explicit `expiresAt`, the value derived for it MUST be **deterministic** — derived from a fixed reference rather than from the instant the request is served — so that two attempts hash to identical content and the idempotency gate recognises the second as the first. A caller that lost the first response has done exactly what the mechanism exists for, and answering it with a refusal leaves them unable to tell that refusal from a genuine duplicate, and unable to correct it.

**What was measured, and why this is not a defect in the idempotency gate.** The gate was found to be **correct**: three in-process replays of one identical payload all returned the stored result, and only a changed payload raised a conflict — precisely `data-model.md:376-377`. The refusal arose one layer up, where a derived default expiry was computed from the current time and so differed between two attempts microseconds apart. The gate was therefore right to refuse, because the two attempts really did carry different content. The obligation this answers sits on the **derivation**, not on the comparison.

**A derived value that varies per attempt is the general hazard, not this one parameter.** Any value a request derives rather than receives — an expiry, a default title, a computed amount — belongs in the idempotency payload only if it is stable across attempts. A derived value that is genuinely elapsed-time-dependent must not participate in the content comparison at all, because "the same command, retried" and "the same command, with a different default" are not distinguishable from the outside.

**The answer is deliberately the deterministic one rather than the permissive one.** Loosening the comparison to tolerate near-identical content would make the gate's guarantee unfalsifiable: a genuine conflict and a legitimate retry would become the same event. Determinism preserves the distinction, which is the entire value of the mechanism. Verified before asking: `data-model.md:376-377` states both halves of the rule; the evidence-link path derives a 30-day default expiry from the clock and hashes the result into its payload, which is the observed instance; and the same gate is used by the decision, correction, settlement and export paths, so the rule is stated generally rather than as a repair to one endpoint.
- Q: Which expense dates may a purchaser enter on a transaction, and must a date later than today be refused? (FR-023) → A: **Any valid calendar date is accepted, past or future; V1 refuses no expense date at all.** The expense date is a fact the purchaser asserts about when a cost was incurred, not a scheduling promise, so a date in the future is ordinary input rather than a validation failure.

**Refusing future dates was rejected because it would make a legitimate expense unrecordable.** A cost that has already been incurred but is invoiced or reimbursed later — fuel billed at the end of the month, a delivery invoiced after the fact — is known on the day it happens and entered later, and a purchaser preparing a transaction before the expense occurs is likewise ordinary. Refusing those dates forces a purchaser to falsify the date in order to record a real cost, corrupting the very fact the field exists to state and feeding the wrong period into period reporting.

**The date does not decide when money moves, so the risk a refusal would guard against does not arise.** Consumption is decided and booked at final finance approval (FR-040) and never on the expense date, so an approved future-dated expense decrements its account at approval and the balance is not debited before anyone approved it. **What the date governs is period membership**: FR-068's Jalali periods select by expense date, so a future-dated expense is reported under the period its date falls in, which may be a later period than the one in which it was settled. That is the intended reading rather than a defect, because a period report describes when costs were incurred while the movement describes when they were approved, and collapsing the two would misreport one of them. FR-062's headline totals are unaffected, since they aggregate outcomes rather than dates.

**No lower bound either, so back-dating stays available.** Recording a purchase made last week is the common case, not an abuse, and refusing it would break ordinary work rather than protect an invariant. The only date conditions V1 enforces are the ones already stated: an expense date must be present, and the transaction must otherwise satisfy FR-023. Verified before asking: FR-023 requires "an expense date" with no window, `contracts/ocs-api.yaml:1324` declares `expenseDate: { type: string, format: date }` with no bounds, and no domain or application check under `lib/` compares an expense date to the current date — so this was undecided in the specification rather than merely untested.
- Q: What must happen when a purchaser wants to discard an open expense sheet they never submitted? → A: **The owner may delete their own open, never-submitted sheet; the removal cascades to its draft transactions, their revisions, and their stored evidence files, atomically, and writes no audit row.**

**Deleting a draft is discarding unentered data, not erasing business history — that is why no audit row is written.** FR-051 begins transaction audit at *submission*, so a sheet that was never submitted produced no submission, no routing, no decision, no revision and no financial effect. There is nothing for a reviewer to reconstruct and nothing Principle IV protects, since the invariants it guards — revision history, decisions, consumption movements — all belong to *submitted* transactions. FR-025 already deletes an individual draft transaction with no audit entry on exactly this reasoning, so extending the same rule to the aggregate is a consistency rather than a new exemption.

**The audited alternative was rejected because capturing it would have had to break one of two requirements in order to satisfy neither.** A `SHEET_DRAFT_DELETED` row would have to live either in transaction audit — which FR-051 defines as *beginning* at submission, so the event would precede its own stream's start — or in configuration audit, which FR-055 scopes to *administrative configuration* changes, and a purchaser removing their own unentered draft is neither configuration nor an administrative act. Either placement leaves the hosting requirement internally inconsistent in order to record a deletion nobody needs audited.

**The cascade is specified rather than left to the storage layer, because a partially-deleted sheet is the failure that matters.** A sheet row removed while its transactions, revisions, or stored evidence files survived would leave orphaned records the purchaser can no longer see and that no requirement makes anyone reclaim — and a sheet whose transaction count and totals still describe rows that no longer belong to it. Deletion MUST therefore be atomic under FR-042, and evidence files MUST be deleted from storage rather than merely unlinked, because an unlinked file in private AppData is unreferenced and therefore uncleanable. FR-084's replay safety applies to this command like any other mutating operation, so a double-click removes one sheet rather than racing two.

**Scope is deliberately narrow and every boundary reuses a reason the specification already gives.** Not the caller's own sheet (FR-003), not an already-submitted sheet (FR-026), not a completed or completed-with-rejections sheet (FR-044) — deleting any of those would erase the official history FR-048 and FR-051 exist to preserve. Verified before asking: FR-025 covers draft *transaction* deletion, FR-043 and FR-044 cover completion and locking, and no requirement anywhere in `spec.md` mentions deleting a sheet; no `DeleteSheet` command exists under `lib/`; and `data-model.md:44` already holds that historical and audit records have no update or delete application operation, which a never-submitted draft is not. Recorded 2026-10-08: this adds **FR-086**, moving the requirement count from 85 to **86**, while `plan.md:93` records 85 and `tasks.md` T134's traceability range still reads FR-001–FR-084 (already behind FR-085) — both MUST be reconciled to FR-001–FR-086 in this change, per Constitution V.
- Q: What should V1 do about which file types may be uploaded as evidence, and what happens to evidence that a virus scan flags? → A: **Both are delegated to Nextcloud. V1 declares no file-type allow-list, no extension allow-list, and no content scanning of its own: an upload MUST be accepted whenever the host file service accepts it, whatever its type, and where the platform's antivirus capability is enabled its verdict governs access to the file.**

**Delegation is the constitution's own answer rather than a shortcut.** Principle III states that Nextcloud facilities MUST remain authoritative for authentication, user identity, file access, **security controls**, and technical logging — and scanning an uploaded file is a security control the platform owns and an administrator configures. Reimplementing it inside PettyCash would create exactly the second mechanism Principle III exists to prevent, on the same reasoning FR-031 already accepted when it delegated abuse control for public evidence links to Nextcloud's brute-force limiter and forbade PettyCash from adding a second rate limiter. Recording the delegation explicitly matters because "not implemented" and "deliberately delegated" are different claims, and only the second one survives review.

**The risk a type allow-list would mainly address is already closed by FR-060, so its marginal value is small.** The realistic attack against attacker-supplied evidence is a payload rendered in the viewer's session — a stored-XSS file served inline from the Nextcloud origin — and FR-060 forecloses it twice over: every media type outside images, PDF, and plain text is served as `application/octet-stream` with an `attachment` disposition and is never rendered inline, and the response uses the file's **stored** media type rather than anything the uploader asserts at upload time. An allow-list would restrict which bytes may be stored while changing nothing about how they are served.

**What is given up is stated rather than left implicit.** V1 accepts and stores an executable or an archive as evidence whenever the host accepts it, so no PettyCash rule objects to an uploaded `.exe` or `.zip`; a deployment that wants content scanning obtains it by enabling the platform's antivirus capability, and a deployment that does not enable it has accepted that risk at the platform level rather than leaving it unstated. This is the same division of responsibility the 100 MB ceiling already draws, where FR-074 permits a PettyCash ceiling *above* the host's but forbids conflating the two: a business rule PettyCash owns sits above a platform rule it does not.

**The contract gap is recorded rather than silently absorbed.** `contracts/ocs-api.yaml` declares no media type and no file name for an evidence item, so there is currently nothing for the evidence metadata to be validated against and nothing for an upload test to assert. FR-029's retained metadata already requires file name, type, and size, so the requirement and the schema agree on what is stored while the contract omits how it arrives. Reconciliation is left to the contract task rather than settled here, because choosing an accepted-types vocabulary is precisely the decision this answer declines to make — and inventing one in the specification would smuggle back the allow-list this answer removes.
- Q: What must PettyCash write to Nextcloud's technical log when a request fails with an unexpected fault? → A: **Every `5xx` MUST be recorded through Nextcloud's standard technical logging, naming the actor, the route, the `X-PettyCash-Command-ID` where the caller supplied one, the affected public identifier where one is known, and the exception class — and the response MUST carry none of that detail. A correctly refused `4xx` needs no technical log entry.**

**The gap was one of ownership, not of agreement about content.** Nothing in the specification said what a fault must record. FR-056 requires only that transaction audit, configuration audit, and technical records stay separately identifiable, and `contracts/workflow.md:127` says a `500` has its "sensitive detail logged through host technical logging, not returned" without ever saying what that detail is. An implementation that logged nothing, or logged an unstructured line carrying neither actor nor record identity, satisfied every requirement while leaving an operator unable to diagnose anything — and FR-073's error-rate figure would still have been counted, so the bound could be reported as met on numbers nobody could act on.

**`5xx` is logged and `4xx` is not, because the two answer different audiences and only one is a defect.** A `5xx` is an unexpected fault the application did not anticipate; neither the user nor support knows why it happened, so its detail belongs in the log and nowhere else. A `4xx` is a correct refusal whose reason is already in the response, and this specification makes the interesting ones explicitly self-explanatory: FR-085's `409` names the decision already recorded and tells the client to reload, and FR-040's and FR-083's `422` name the condition and rule out permissions and filters. Logging those would add volume without adding information and would create a second place where a refusal is recorded — the same fragmentation FR-085 rejected when it required a superseded attempt to be its own event type rather than filed as an approval or a rejection.

**Those five details are the minimum because each answers a different support question, and one of them is a join key.** Actor answers who hit it; route answers where; the **command id connects the fault to FR-084's recorded command outcome and to the superseded attempt FR-085 requires**, so without it a fault cannot be tied to the command that caused it; the public id lets the operator open that exact sheet, transaction, or account rather than infer which one was meant; and the exception class says whether this is a database, filesystem, or programming fault. Dropping any one of them leaves a support question answerable only by reproducing the failure.

**No correlation identifier was added to the response, deliberately, and the cost is recorded rather than assumed away.** Returning a generated id inside the OCS envelope is the usual way to close the loop and is a reasonable later answer, but at the documented V1 scale — fewer than 100 active users and 10,000 transactions a year — log volume is small enough that actor, route, and timestamp locate the entry reliably. Adding the field now would change the OCS envelope, the single client error handler FR-083 exists to provide, and every contract consumer, to solve a problem this scale does not have. The stated consequence is that a user reporting a fault gives support their approximate time and what they were doing, and support searches on that.

**Recorded 2026-10-08:** this adds **FR-087** and **SC-015**, so the specification now defines **87** functional requirements and **15** success criteria. `plan.md:93` records 85 and 14, and `tasks.md` T134's traceability ranges still read FR-001–FR-084 and SC-001–SC-014 (already behind FR-085 and FR-086) — all three MUST be reconciled to FR-001–FR-087 and SC-001–SC-015 in this change, per Constitution V. Verified before asking: the only occurrences of technical logging across the feature artifacts are FR-056, this file's Assumptions and Dependencies entries, `plan.md:116`'s constitution check, `data-model.md:347`, and `contracts/workflow.md:127`; no requirement states log content, and `contracts/ocs-api.yaml` declares no correlation, trace, or request identifier.
- Q: When the same purchaser has one draft transaction open in two places and saves from both, what should happen to the second save? → A: **The second save MUST be refused with `409 Conflict` on a stale `lock_version`, on FR-085's own terms, and the client MUST reload before saving again. Draft edits are never silently overwritten and never field-merged.**

**The hazard was real and entirely uncovered.** `data-model.md:23` places `lock_version` on seven aggregates expressly "for optimistic checks", and `:25-38` states what those checks mean — but only for a *decision*, via FR-085, which was settled on 2026-10-07 precisely because this document had described the mechanism without a stated outcome. `FR-025` meanwhile lets a purchaser add, edit, and delete draft transactions freely, so a second save from another tab or another device silently overwrote the first: the purchaser retypes an amount or a description and it vanishes with no refusal, no trace, and no way to learn it happened. On a financial record that silent loss is the worst of the available outcomes, because the purchaser's only evidence that it occurred is their own recollection.

**Extending FR-085 was chosen because it introduces no new concept.** `lock_version` is already on the aggregate; `409 Conflict` plus "reload to see the current outcome" is already the established answer to this exact hazard; and the data model already documents the mechanism. The rule therefore needs vocabulary that exists, not a new error class, audit event type, or contract status. **No `SUPERSEDED_ATTEMPT` event is recorded here, deliberately.** FR-085 requires one because a losing *decision* is otherwise indistinguishable from a request that never happened, and because recording it as an approval or a rejection would falsify the history Principle IV protects. A losing *draft edit* has neither problem: the record has no official history, no revision, and no decision, so there is nothing to falsify and no reviewer misled. Recording it would also put an event into transaction audit that FR-051 defines as *beginning* at submission — the same incompatibility that ruled out an audit row for FR-086's sheet deletion.

**Field-level merging was rejected because it invents a record nobody typed.** Merging only the fields the caller changed would preserve both edits, but it fabricates a third state — one user's amount beside the other user's cost centre — that neither user entered and neither has seen. On a record whose purpose is to be an accurate account of a single purchase, a silently-assembled hybrid is worse than a refusal that makes the conflict visible.

**A blocking row-level lock was rejected for the reason FR-085 already gave.** A lock makes the second save wait and then apply, which looks friendlier but buys no correctness that refusal does not already provide, and it implies the transparent client-side retry FR-075 forbids: a request silently held and later applied is a request the user never confirmed. Finance settlement is the one place the data model already pairs the lock with `lock_version`, and that pairing is a settlement concern rather than a precedent for the whole aggregate.

**One consequence is derived rather than chosen, and is stated so it can be overridden:** an edit that changes **no** value is not a stale version, because it asserts no competing value, so an ordinary re-save of an untouched draft is never refused for being old. Verified before asking: `spec.md` carried no requirement on concurrent purchaser editing — FR-085 covers review decisions only, and `tasks.md` T397 records that stale-version refusal there was "deliberately unchanged" — while `data-model.md:23-38` documents `lock_version` as a general optimistic-check mechanism whose stated behaviour covered only the decision path.

- Q: When a purchaser types an amount with more decimal places than their currency keeps — say three decimals in a currency that stores two — what should happen to the extra digits? → A: **Refuse it, with a validation message naming the currency's scale and the figure that was entered.** V1 MUST NOT round a purchaser's amount, silently or otherwise. **The premise is wrong and the question is therefore moot — see the SUPERSEDED note on the reasoning below. The rule that actually answers the underlying worry now lives in FR-023: a currency's scale is refused while amounts exist.**

**The gap was that no rule could exist in the code as written.** Amounts are whole minor units and the API takes `amountMinor` as an integer, so the decimal-to-minor-unit conversion happens **on the client**, where nothing checks it. Worse, the domain cannot check it either: `Money` holds an integer of minor units and a `CurrencyCode`, and **never sees the currency's configured `minor_unit`** — so there was no place in which an over-precise figure could be detected even in principle. `Currency::create()` validates `minor_unit` is 0–4 (`lib/Domain/Configuration/Currency.php:28`) and that value is genuinely used for rendering, but nothing connected it to an amount. The scale was configured, displayed, and unenforced.

**Refusing was chosen over rounding because rounding a monetary figure is silent corruption.** A rial amount of 100.005 entered at scale 0 becomes 100 or 101 depending on the client's rounding mode, and the difference between those two is not a display detail — it is the ledger. Either way the stored figure differs from the figure the purchaser read, and nothing records that it was adjusted: the sheet totals, the account balance, and the Jalali period report would all state a number the purchaser never entered. FR-083 already settled the principle this follows — a data fault is reported rather than papered over — and an amount the system cannot represent faithfully is exactly that.

**The refusal MUST name both the scale and the figure.** "Invalid amount" sends a purchaser hunting; "IRR keeps no decimal places; you entered 100.005" ends the hunt. This is the same rule the 2026-10-07 settlement refusal and the 2026-10-08 sheet-creation refusal already established: a refusal that is merely *valid* leaves the caller with no way to proceed, and a 2026-10-08 answer exists for no other reason.

**Two consequences follow from "refuse" rather than being chosen separately, and both are stated so they can be overridden.** First, the refusal applies at **every write that carries an amount**, not only at submission — because a stored over-precise amount would make the later refusal a repair of data already written, and FR-048's "correction creates a revision" machinery is the wrong instrument for a value that should never have been accepted. Second, the rule is **scale-relative, not absolute**: the permitted precision is the currency's own configured `minor_unit` (0–4), so the same figure can be valid in one currency and refused in another, and an administrator who changes a currency's scale changes what amounts are acceptable from that point on — which is a configuration change under FR-017's audit, not a data migration.

> **SUPERSEDED 2026-10-08, the same day — by measurement, not by reconsideration.** The answer above was
> reasoned from the premise that a caller supplies a decimal amount the server must convert. **That premise is
> false**, and the phase that implemented this found it: `amountMinor` is an integer *already expressed in the
> currency's minor units*, so every represented amount carries exactly the scale's precision and **no value can
> be over-precise**. The clause this answer produced was therefore unsatisfiable — there was nothing to detect,
> and no component could detect it (`Money` never reads `minor_unit`, and the client binds straight to the
> integer). Worse, it **displaced the hazard that does exist**: an administrator changing a live currency's
> scale silently reinterprets every stored amount in it, unguarded.
> **What survives from this answer is the principle, and it is the real rule now in FR-023: a stored figure must
> never silently differ from the one a person read — so a scale change is *refused* rather than applied or
> migrated.** The reasoning above about *where* rounding would have been visible (sheet totals, balances, the
> FR-068 period report) is what identified the hazard the clause missed. See the Session 2026-10-08 correction
> recorded on FR-023 and the revised edge case, and T422.

**What was deliberately not decided here — and the second half of that deferral is now decided.** Whether the *client* should also refuse before sending was left open: it is a usability affordance the backend does not depend on, and the server rule stands on its own. What a currency's **scale change** means for amounts already stored was also left open, on the reasoning that it "belongs with FR-017's audit trail rather than in a validation rule." **That was the wrong reasoning and the wrong home.** It is not an audit-trail question — FR-017 records that a configuration change happened, which is necessary and not sufficient — it is a question about whether stored financial records may be **reinterpreted**, and it belongs in the requirement that governs amounts. It is now settled in FR-023: the change is **refused** while amounts exist. Corrected 2026-10-08; the original deferral is recorded here rather than deleted so the reasoning that was wrong stays visible.
- Q: Does V1 need a delete control in the interface for an unsubmitted sheet, or is the API endpoint alone enough to satisfy the requirement? → A: **V1 includes the control on both the sheet list and the sheet detail, behind a confirmation step that names the sheet, inside FR-071's accessibility floor.**

**An endpoint a purchaser cannot invoke is not a capability, and the acceptance scenario had already decided this.** The User Story 1 scenario added earlier this session reads "*the purchaser deletes it*" — the actor performs the deletion, not a client. `FR-018` lets any authenticated user act as a purchaser, so the only thing missing was a way to act. The alternative reading — the endpoint satisfies the requirement and the control is V2 — would leave a requirement whose own acceptance scenario describes something the product does not do, which is the misaligned-test failure the clarification workflow exists to prevent.

**Both surfaces, because the two are genuinely different journeys.** The list is where a purchaser decides a sheet is *finished with* while scanning several at once; the detail is where they decide while looking at the one in front of them. Offering it in only one place means the other journey has an unremovable sheet — the abandoned-sheet problem FR-086 exists to solve.

**A confirmation step is required, and the cascade is the reason.** Deletion removes stored evidence **files** as well as database rows, and those files cannot be recovered — there is no audit trail to restore from, because a never-submitted sheet has none. A single mis-click therefore destroys data irreversibly, which makes this one of the few V1 operations where a confirmation is not a courtesy. It MUST name the sheet, because a purchaser with three open sheets cannot otherwise know which one is about to go.

**The control is hidden exactly where deletion is refused, which is a correctness requirement rather than polish.** FR-086 refuses a submitted, completed, or completed-with-rejections sheet, and FR-071 already states that an action not permitted for the current record state is not offered. The server-side refusal remains the enforcement — FR-009 already forbids relying on the interface to enforce a rule — and the absent control is what keeps the interface honest about it rather than offering a button that always fails.

**The dialog makes SC-009's existing floor load-bearing, and that is a consequence rather than an expansion.** User Story 1 is a primary journey, so SC-009 already requires it to be completable **using the keyboard alone** with a visible focus indicator on every element reached. A confirmation dialog that cannot be reached, operated, or left by keyboard would fail SC-009 — so **focus MUST move into the dialog when it opens and MUST return to the control that opened it on cancel or completion**. This is not one of the items the 2026-10-07 answer deferred (label association, error association, live-region announcements); it is what "completable with the keyboard alone" already means, and this answer is simply the first to put a dialog inside a primary journey.
- Q: On which requests should a logged fault record the public identifier of the record it was working on? → A: **On every operation that addresses exactly one record by identifier; and on none that addresses no single record.** "Where known" becomes a mechanical test rather than a judgement call.

**The clause was unmeasurable as written, and the delivered implementation exposed that rather than the other way round.** Both FR-087 and SC-015 said "where known" and neither said who decides — and when T418 built the channel, **no call site populated it**, so the identifier appeared on no fault at all. Either the requirement was trivially met or trivially unmet, and which of those it was depended on the reader. That is the same defect FR-087 exists to end: a rule with no observable consequence.

**The boundary that makes it testable is "does this operation name one record", not "is the identifier to hand".** `appinfo/routes.php` carries an identifier on **23 routes** and addresses no single record on **8** — lists, the dashboard, creations. On the first group the identifier is *mechanically* derivable from the route, so "known" is always true and the requirement always bites; on the second it is never true, so omitting the field is **correct rather than a gap**. That asymmetry is the point — a rule whose two branches read the same way cannot be falsified, and this one has two genuinely different outcomes.

**Keeping it as a separate field even though the route already contains the identifier was chosen on purpose.** The route is prose a human reads while scanning a log; a structured identifier is a field an operator can filter or correlate on, and the two answers diverge the moment someone greps. Deleting the clause as redundant (option D) would also have been defensible — it was rejected because FR-087 named the identifier as one of five required details, and quietly dropping a required detail because a neighbouring one happens to contain it is exactly the scope change this workflow exists to prevent.

**The honest reading is written onto the requirement itself rather than left in a report.** FR-087 now carries an explicit note that it was **known-partial on delivery** — channel built and tested, no call site populating it — so the next reader of the requirement sees the shortfall and closes it by supplying the identifier. Recording the tidier reading instead ("the channel exists, therefore the requirement is satisfied") would be precisely the reasoning this answer rejects.
- Q: Should V1 let a reviewer approve, reject, or return several transactions at once, or must every decision be made one transaction at a time? → A: **Single-decision stays. Every decision in V1 applies to exactly one transaction, and a bulk multi-transaction decision is deferred to V2** (recorded in FR-070 beside the other declared deferrals).

**The requirement was silent in both directions, which is the gap.** FR-033 and FR-035 say managers MUST be able to approve, reject, or return "transactions" — never saying how many at a time — and FR-038 does the same for finance. Neither permits nor forbids a bulk operation, so the tests for a hypothetical bulk endpoint could be written in whichever direction the author assumed, which is how an acceptance test ends up pinning behaviour nobody decided.

**The silent failure is worse here than it looks, because FR-085 was settled one day earlier and does not generalise.** First-write-wins answers a reviewer who submits a decision for a transaction whose state has moved. It says nothing about a reviewer holding **two** approvals when a second manager decides the first one underneath them — and the honest answer, which no requirement gave, is that the two decisions have different answers and there is no single correct result for the request as a whole. A bulk operation would have needed its own conflict rule invented for it, mid-workflow, rather than settled.

**Deferring is the low-rework direction, because the single path stays complete and authoritative.** Every rule already decided applies unchanged: FR-085's first-write-wins, FR-048's revision-on-correction, FR-051's per-transaction audit, FR-085's `SUPERSEDED_ATTEMPT`, and FR-084's replay safety keyed on one command. A bulk operation added later is then expressible as several single decisions — each with its own outcome, its own audit event, and its own supersession handling — rather than as a new kind of write with a history of its own to invent. Choosing B or D would have required deciding what a **partially** applied bulk request means, and FR-042's atomicity makes that uncomfortable: either all-or-nothing across a reviewer's queue, or a bulk write that is not atomic. Neither is obviously right, and both are decisions the specification did not need to make in V1.

**FR-070 carries the deferral so the boundary is findable in one place**, because a reader looking for "what does V1 not do" reads that requirement and not FR-033. And FR-033 states the per-transaction rule where a reviewer meets it, on the same principle already used for FR-014/FR-020 — the exception is stated where the MUST lives, not only where it is enforced.
- Q: Should the API contract publish a closed list of the error codes the application can return, or leave the code field open to any string? → A: **Publish a closed enumeration. Every code the application emits MUST appear in it, an unlisted emitted code is a defect, and a client MAY rely on the list being complete.**

**The contract declared `code: { type: string }` — open, with no list — while the whole design rested on clients branching on it.** FR-085 was decided partly so `SUPERSEDED_ATTEMPT` stays distinguishable from `ACCOUNT_VERSION_CONFLICT`, and T416 added `SHEET_VERSION_CONFLICT` on exactly that reasoning, on the same day `StaleRecordVersion` made the codes resource-scoped. All of that work is invisible to a client built from the contract, because an open string promises nothing: a code may be renamed, dropped, or misspelled and the contract stays valid. A branch on a code is therefore never more than a guess, and the dependable thing left to react to is the HTTP status — which is precisely what these codes exist to improve on.

**The gap was already 15+ codes wide and growing.** `VALIDATION_FAILED`, `BAD_REQUEST`, `NOT_FOUND`, `FORBIDDEN`, `IDEMPOTENCY_CONFLICT`, `PAYLOAD_TOO_LARGE`, `UNEXPECTED_FAILURE`, `ACCOUNT_VERSION_CONFLICT`, `ACCOUNT_ALREADY_EXISTS`, `CONFIGURATION_<RESOURCE>_VERSION_CONFLICT`, `CONFIGURATION_<RESOURCE>_ALREADY_EXISTS`, `SUPERSEDED_ATTEMPT`, `TRANSACTION_NOT_AWAITING_CORRECTION`, `SHEET_VERSION_CONFLICT`, `TRANSACTION_VERSION_CONFLICT`. Every one of those is a branch a frontend may legitimately want, and none of them is promised.

**"Complete list" was chosen over the tolerant-reader alternative deliberately, and the trade is stated.** Option D — publish the list but require clients to tolerate unknown codes — is a defensible forward-compatibility story, and it was rejected because a tolerant reader is indistinguishable from an open field: once every client is *required* to cope with an unrecognised code, no client can be said to rely on the list at all, and the requirement becomes unenforceable in the only sense that matters. Choosing completeness instead means a **new code is a contract change**, shipped deliberately and never ahead of the list. That is the right direction of travel for a V1 interface whose vocabulary is still settling, and it is the same discipline FR-085's own note asks for: the code is the exception's own "so `ACCOUNT_ALREADY_EXISTS` and `ACCOUNT_VERSION_CONFLICT` stay distinguishable" — a promise about codes that the contract was not making.

**A measurable criterion was added rather than leaving the obligation unobservable.** SC-016 requires 100% of emitted codes to appear in the enumeration and 0 to be absent, and additionally that the enumeration declare nothing the application cannot emit — so the list cannot rot in the other direction either. That last half matters as much as the first: an enumeration that grows to cover codes nothing returns is as wrong as code emitted outside it, and only a two-way check catches it. Recording the requirement without it would have reproduced exactly the "rule with no observable consequence" defect this same session identified in FR-087's identifier clause.

### Session 2026-10-09

- Q: Should V1 show an audit history at the sheet level, or does audit history stay on each individual transaction? → A: **Audit history stays on the transaction. V1 MUST NOT add a sheet-level audit view; the sheet detail carries no audit tab, and the ordered event history FR-051 defines is reachable per transaction only.**

**The requirement was already transaction-scoped; what was undecided is where the confusion came from.** FR-051 begins *"Formal **transaction** audit"* at sheet submission and enumerates events that are all properties of a transaction — approvals, returns, corrections, revision creation, evidence additions. US6's first scenario has an authorized user view *"a **transaction** timeline or history table"*. The contract declares exactly one audit endpoint, `/transactions/{transactionId}/audit`, and `appinfo/routes.php` registers exactly one, `transaction#audit`. **Nothing in this specification asks for a sheet-level history, so this answer confirms the existing scope rather than granting a new capability** — which is the point worth stating, because the ledger had recorded the opposite in two places.

**A sheet-level history would be a derived aggregate, and that is what makes it a decision rather than an omission.** It would have to union the event streams of every transaction in a sheet into one chronological ordering. That introduces questions the specification has never had to answer: how events from different transactions order relative to each other, what a caller who may read some transactions but not others sees, and how such a view paginates across a growing set. None of that is reachable by adding a binding to an existing endpoint — it is a new surface with new access rules over a stream that is defined per transaction on purpose. FR-052 makes every event retain its actor and its own record identity, so a merged view would carry events belonging to records the reader may not be entitled to see.

**The conflicting claim is corrected rather than left standing, because leaving it would have moved the defect silently.** The ledger had recorded on **T171** that the missing `[data-test="audit-tab"]` and `[data-test="audit-event"]` hooks "need an endpoint, not a binding, and that is T098's scope". That is the inverse of the decided scope: it would have had **T098** build a sheet-level audit surface it was never specified to build, and had **T171**'s Playwright journey assert a sheet-level tab that no requirement requires. With this answer, the `audit-tab` and `audit-event` assertions in `purchaser-submit.spec.ts` are **T171's own spec defect** — they assert a surface V1 does not have — and they are to be corrected to address the transaction's own history, or dropped as out of scope **with that reasoning recorded**. **T098's stated scope is unchanged and correct**: it builds the transaction audit timeline, revision history, and evidence components this answer keeps.

**No new requirement is added, and that is deliberate.** The rule was already FR-051's and the endpoint set already the contract's; stating "there is no sheet-level audit" would add a requirement whose only function is to forbid something nothing currently proposes, while making the V1 boundary findable where a reader looks for it. It is recorded in FR-070 alongside the other deferrals for that reason, and US6's scenario 1 is tightened to say *transaction* explicitly so the boundary is readable from the user story. Verified before asking: `grep -nE "^  /.*audit" contracts/ocs-api.yaml` returns the single `/transactions/{transactionId}/audit`; `appinfo/routes.php` registers `transaction#audit` and no sheet-scoped audit route; `grep -rn "audit-event\|audit-tab" src/` returns **nothing**, so no implementation contradicts this either way; and `src/components/audit/` and `src/views/audit/` do not yet exist, meaning the choice had no sunk implementation cost on either side.

- Q: After a purchaser saves a draft transaction, where should the app take them, and how do they get back to the sheet's Submit button? → A: **Return to the sheet the transaction belongs to. Saving a draft MUST land the purchaser on that sheet's detail view, where the Submit control lives, and the transaction list MUST NOT be a dead end from which the sheet cannot be submitted.**

**The gap was a navigational hole that made a required action unreachable.** Nothing in this specification said where the purchaser is taken after a save. US1's scenario 5 says a draft transaction "can be saved as a draft and **completed later**", and scenario 6 has the purchaser submitting the sheet and receiving validation messages — so both presuppose a path from the saved draft back to the sheet's submission control. FR-022 requires only that the save be *possible*, FR-023 that submission validate completeness, and FR-018 that any user be able to act as a purchaser; **none of them says the two are reachable from one another.** Measured in a signed-in browser: `TransactionFormView` routes to the transactions list after a save, and that view renders **no Submit control, no sheet status, and no route back to the sheet** — its breadcrumb reaches only the sheet *list* and itself. A purchaser who followed the app therefore saved a draft correctly and then had no way to submit the sheet they had just added to, with no error and no explanation.

**Returning to the sheet was chosen because it is where the work already is, and because it needs nothing new.** The sheet detail view already carries the sheet's status, its transaction table, and the Submit control gated by `canSubmit`; a draft saved there makes that control appear immediately, which is the visible confirmation the purchaser needs that the save was recorded. The alternatives were rejected for concrete reasons rather than preference. Keeping the transactions list as the destination and **adding** a Submit control there would put a second submission control on a second surface for one sheet, and the ledger already records that ambiguity as a defect class — `SheetDetailView` once emitted `create-transaction-btn` on two cards, and T171's own reconciliation notes that duplicate hooks were removed so a hook would be unambiguous. Adding only a **back link** to the list leaves the purchaser to reconstruct the journey themselves after every single save, on a flow they may perform several times per sheet.

**The transaction list is retained, and its role is stated rather than implied.** It remains the readable, scannable view of one sheet's transactions — the place to compare rows and open one — and it keeps its own Add control. What changes is that it is no longer the **terminus** of a save: a save returns to the sheet, and reaching the list from there is a deliberate act by the purchaser. That keeps the existing navigation honest rather than removing a surface that serves a real purpose.

**One consequence is stated so the rule cannot be satisfied by a route that happens to work.** FR-069's keyboard floor and FR-071's browser-measured clauses apply to whatever surface carries Submit, so this answer adds no new obligation of its own — but it does mean that **the surface a purchaser is returned to after saving is the surface those requirements are about**, and a test that asserts the save succeeded must reach submission from that same place rather than from a different view reached by an unrelated click. Verified before asking: US1 scenarios 5–6 and 7 as quoted above; FR-018, FR-022, FR-023 and FR-086 read for any navigation clause and found none; `TransactionFormView.vue:212` performs the post-save `router.push({ name: 'transactions' })`, and `router/index.ts:54` makes that view the `transactions` route; and `grep -o 'data-test="…"'` over `TransactionListView.vue` returns exactly four hooks — `transaction-list-view`, `create-transaction-btn`, `transaction-list`, `transaction-loading` — with no submit or sheet-status hook among them. The ledger had recorded this as an open question on **T171** rather than deciding it, which is why it reached clarification.

**Recorded 2026-10-09:** this adds **FR-088**, moving the requirement count from 87 to **88**. `plan.md:93` recorded 87 and has been corrected here, and `tasks.md` T134's traceability range still read FR-001 through FR-087 and has been corrected to FR-001 through FR-088 — both MUST be reconciled in this change per Constitution V, and both were behind the count this answer created. No new success criterion is added: FR-088 is a reachability rule whose observable consequence is that the existing submission scenarios can be exercised from where a save leaves the purchaser, so it is verified by T171's Playwright journeys rather than by a new measurable outcome. **No product code was changed by this answer** — it decides a question the implementation had already answered one way, and the gap between that implementation and this decision is **T171**'s to close.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Capture and Submit Expenses (Priority: P1)

As a purchaser, I can create one or more expense sheets, record expense transactions and their
evidence, and submit a complete sheet for review so that my expenses enter a controlled approval
process.

**Why this priority**: Expense capture and submission create the business records used by every
later workflow stage.

**Independent Test**: Create an open sheet, add transactions and evidence, resolve all submission
validation errors, and submit it. The submitted transactions become available for the first review
stage while the purchaser retains a clear view of their status.

**Acceptance Scenarios**:

1. **Given** a purchaser has only the default active petty-cash account, **When** the purchaser
   creates a sheet, **Then** the sheet uses that account's currency and the purchaser cannot select
   another currency.
2. **Given** a purchaser has multiple active petty-cash accounts in different currencies, **When**
   the purchaser creates a sheet, **Then** only those account currencies are available and the
   selected currency applies to every transaction in the sheet.
3. **Given** a purchaser has no petty-cash account of their own, **When** the purchaser creates a
   sheet, **Then** a default active rial account is provisioned on that attempt and used for the
   sheet, and repeating the attempt provisions nothing further.
4. **Given** a purchaser's only petty-cash account has been deactivated, **When** the purchaser
   creates a sheet, **Then** the creation is refused with a message naming the inactive account
   and stating that an administrator must reactivate or replace it, no replacement account is
   created, and the purchaser cannot proceed until an administrator acts (FR-020).
5. **Given** an open sheet, **When** the purchaser adds a transaction with an expense date and a
   positive amount, **Then** the transaction can be saved as a draft and completed later, and the
   save returns the purchaser to that sheet's detail view where the sheet's Submit control is
   reachable (FR-088).
6. **Given** a sheet has no transactions or any transaction lacks its required cost center,
   expense type, description, or evidence, **When** the purchaser submits the sheet, **Then** the
   submission is rejected with specific validation messages.
7. **Given** every transaction is complete, **When** the purchaser submits the sheet, **Then** the
   sheet is locked against new transactions and its transactions enter formal review even when the
   source petty-cash account has insufficient or negative balance.
8. **Given** a purchaser has an open sheet they have not submitted, **When** the purchaser chooses
   delete on the sheet list or the sheet detail and confirms, **Then** the sheet, its draft transactions,
   their revisions, and their stored evidence files are all removed together, the purchaser no longer sees
   the sheet on either surface, and no business audit event records the deletion because the sheet never
   entered the workflow (FR-086).
8a. **Given** the delete confirmation is open, **When** the purchaser cancels it, or dismisses it, or
   completes the deletion, **Then** focus returns to the control that opened it and the sheet is still
   present if the operation was not completed — reachable and operable by keyboard alone throughout
   (FR-086, FR-071, SC-009).
9. **Given** an open transaction, **When** the purchaser enters an expense date later than today,
   **Then** the transaction saves and submits normally, the balance is consumed at finance approval
   rather than on that date, and the record is reported under the reporting period its expense date
   falls in (FR-023, FR-068).
10. **Given** the same purchaser has one draft transaction open in two browser tabs or on two
    devices, **When** the second tab saves a change to it, **Then** the save is refused with
    `409 Conflict` telling the purchaser to reload, neither edit is silently overwritten, and no
    superseded-attempt audit event is written because the draft carries no official history (FR-025).

---

### User Story 2 - Perform Management Review (Priority: P1)

As a cost center manager or senior manager, I can review only the transactions within my assigned
responsibility, inspect their evidence and history, and approve, reject, or return each transaction
for correction.

**Why this priority**: Management review is the primary business-control path between submission
and financial settlement.

**Independent Test**: Submit transactions for two cost centers, sign in as each assigned manager,
and verify that each manager sees and acts only on the transactions in their scope. Complete both
management stages and verify the recorded decisions and next-stage routing.

**Acceptance Scenarios**:

1. **Given** a transaction is awaiting cost center review, **When** the assigned manager opens it,
   **Then** the manager sees its current details, evidence, history, and permitted decisions.
2. **Given** a cost center manager approves a transaction, **When** the decision is recorded,
   **Then** the transaction becomes eligible for senior-manager review and the decision remains in
   its history.
3. **Given** a manager rejects or returns a transaction without an explanation, **When** the
   manager attempts to submit the decision, **Then** the decision is rejected until an explanation
   is supplied.
4. **Given** the senior manager receives a transaction, **When** the senior manager decides,
   **Then** the decision may differ from the cost center manager's opinion without altering the
   earlier opinion.
5. **Given** all transactions in a sheet have been determined at a management stage, **When** the
   final decision at that stage is recorded, **Then** the sheet and its transactions advance to the
   next applicable stage.

---

### User Story 3 - Complete Financial Review and Settlement (Priority: P1)

As a finance officer, I can review transactions that have completed management review, record the
permitted financial decision, and settle approved expenses against the matching petty-cash account.

**Why this priority**: Financial review completes the workflow and is the only event that converts
an approved expense into actual petty-cash consumption.

**Independent Test**: Route one management-approved and one management-rejected transaction to
finance. Approve the eligible transaction, reject the ineligible transaction, and verify the
resulting balances, movements, final states, and preserved decision history.

**Acceptance Scenarios**:

1. **Given** a transaction has not reached finance, **When** a finance officer views the finance
   work queue, **Then** that transaction is not visible or actionable there.
2. **Given** a senior manager approved a transaction, **When** finance approves it, **Then** one
   consumption movement is recorded against the account matching the sheet currency and the
   account balance decreases by the approved amount.
3. **Given** a senior manager rejected a transaction, **When** finance reviews it, **Then** finance
   may reject it or return it for correction but cannot approve it.
4. **Given** any part of final approval, consumption recording, or balance update fails, **When**
   the operation ends, **Then** none of those effects is finalized and the transaction remains in
   its prior valid state.
5. **Given** every transaction has a final financial outcome, **When** the sheet is finalized,
   **Then** the sheet becomes completed or completed-with-rejections and permits only viewing and
   reporting.

---

### User Story 4 - Correct a Returned Transaction (Priority: P1)

As a purchaser, I can correct a returned transaction without erasing its prior version or decisions
so that the corrected expense can be reviewed again with a complete history.

**Why this priority**: Correction is part of the normal approval workflow and is essential to the
project's audit and historical-integrity rules.

**Independent Test**: Return a submitted transaction, edit it as the purchaser, resubmit it, and
verify that a new active revision enters the first management stage while the prior revision and
all earlier decisions remain viewable.

**Acceptance Scenarios**:

1. **Given** a manager or finance officer returns a transaction with an explanation, **When** the
   purchaser views their work, **Then** the transaction appears as awaiting purchaser correction.
2. **Given** a returned transaction, **When** the purchaser changes its business information and
   resubmits it, **Then** a new revision becomes active and review restarts with the cost center
   manager.
3. **Given** a new revision exists, **When** an authorized user views history, **Then** every prior
   revision, decision, actor, timestamp, and recorded change remains available.

---

### User Story 5 - Administer Business Configuration (Priority: P2)

As a system administrator or authorized finance user, I can maintain the reference data, role
assignments, currencies, petty-cash accounts, and account funding needed to operate the workflow.

**Why this priority**: Operational setup is required before real users can route expenses and
settle them correctly, but test fixtures can support the core workflow independently.

**Independent Test**: Configure currencies, cost centers, managers, expense types, user accounts,
and an account charge; then verify that the resulting choices, permissions, and account balance are
available to the appropriate users.

**Acceptance Scenarios**:

1. **Given** an administrator creates or changes a cost center, **When** managers and validity dates
   are assigned, **Then** those assignments govern future transaction routing without changing
   historical actor records.
2. **Given** an administrator activates or deactivates an expense type or currency, **When** users
   create new records, **Then** only currently valid choices are offered while historical records
   retain their original values.
3. **Given** finance charges a user's currency-specific petty-cash account, **When** the charge is
   confirmed, **Then** a movement is recorded and the account balance increases by that amount.
4. **Given** a sensitive configuration or access assignment changes, **When** the change is saved,
   **Then** the actor, time, explanation, previous value, and new value are recorded in the
   configuration audit history.
5. **Given** a purchaser who has no petty-cash account creates one through sheet provisioning,
   **When** the account comes into existence, **Then** a configuration audit event records its
   creation naming **the purchaser** as the actor, so an administrator can see the account was
   self-provisioned and that no administrator authorised it (FR-017).
6. **Given** an administrator creates a currency-specific petty-cash account for a user, **When** the
   account is saved, **Then** the same account-creation audit event records it naming the
   administrator, so both origins appear in one trail (FR-017).

---

### User Story 6 - Trace Decisions and Evidence (Priority: P2)

As an authorized user, I can inspect the complete business history, transaction revisions, and
supporting evidence within my access scope so that decisions are explainable and auditable.

**Why this priority**: Traceability protects financial integrity and supports review, dispute
resolution, and organizational accountability.

**Independent Test**: Execute submission, approval, return, correction, evidence deactivation, and
final decision events; then verify their ordered history, before-and-after values, actor context,
revision links, and access restrictions.

**Acceptance Scenarios**:

1. **Given** a sheet is submitted, **When** an authorized user views a transaction timeline or
   history table, **Then** submission is the first formal event and every later official event is
   shown in chronological order. **This history is per transaction** — reached on the transaction,
   not aggregated across the sheet, and V1 presents no sheet-level audit view (FR-070).
2. **Given** a submitted transaction changes through correction, **When** history is viewed,
   **Then** the previous and new values and the associated revision are visible.
3. **Given** evidence is removed after submission, **When** authorized users view the transaction,
   **Then** the evidence is marked inactive rather than physically removed and its history remains
   visible.
4. **Given** a historical actor is later disabled or removed from the host user directory, **When**
   history is viewed, **Then** the actor identifier, display name, role at the event, and actions
   remain unchanged.
5. **Given** any user including an administrator attempts to alter or delete a business audit
   record, **When** the action is evaluated, **Then** it is refused.

---

### User Story 7 - Monitor, Search, Report, and Export (Priority: P2)

As a purchaser, manager, finance officer, or administrator, I can see role-relevant work and
status, find records, review reports, and export permitted information for operational follow-up
and analysis.

**Why this priority**: Visibility and reporting make the workflow manageable after core capture and
decisions are available.

**Independent Test**: Populate sheets across users, cost centers, statuses, dates, currencies, and
expense types; verify each role's dashboard, searches, reports, and exports contain exactly the
records and fields allowed for that role.

**Acceptance Scenarios**:

1. **Given** a user has one or more active roles, **When** the dashboard opens, **Then** it presents
   the queues, returned work, balances, counts, and summaries relevant to all of those roles.
2. **Given** an authorized user applies supported sheet, transaction, or audit filters, **When**
   results are returned, **Then** only matching records within the user's access scope are shown.
3. **Given** a user requests a sheet, transaction, report, audit, or evidence export, **When** the
   export is generated, **Then** it contains only information the user may view and preserves links
   among sheets, transactions, revisions, decisions, audit events, and evidence.
4. **Given** a group of records is selected, **When** an export is requested with a supported
   `fileFormat`, **Then** the system produces a complete grouped export in that format, and refuses a
   format it cannot yet produce with an explanation naming the reason.
   The supported `fileFormat` values are `csv`, `xlsx`, `zip` and `pdf`. All four are supported in V1,
   including `pdf`, because an OFL-licensed Persian font is vendored into the repository under an
   app-owned path with its licence text, so a Persian PDF renders identically in every deployment
   rather than depending on a machine-specific font location. A `fileFormat` outside these four is
   still refused with a reason naming what is supported.
   The field is named `fileFormat` and not `format` because the response-envelope format is selected by
   a request parameter of that name, so a request field called `format` would silently change the shape
   of the response instead of describing the export.

---

### User Story 8 - Use the Workflow Across Supported Devices (Priority: P3)

As any user, I can perform my permitted primary tasks through a clear Persian right-to-left
interface on desktop or mobile-sized screens.

**Why this priority**: Usability and device coverage are release requirements, while the underlying
business workflow can be validated independently first.

**Independent Test**: Run the affected role journeys in each supported browser at desktop and
mobile viewport sizes and verify readable RTL presentation, visible current status, and completion
of all primary workflow actions.

**Acceptance Scenarios**:

1. **Given** a user opens the application on a supported desktop or mobile browser, **When** a core
   page is displayed, **Then** content is readable in Persian RTL and adapts without blocking the
   primary task.
2. **Given** an action is not permitted for the current role, scope, stage, or record state,
   **When** the page is displayed, **Then** the action is not offered and cannot be completed by a
   direct request.
3. **Given** a communication failure occurs during an action, **When** the failure is detected,
   **Then** the user receives a clear error, no partial business operation is committed, and the
   user may retry manually.

### Edge Cases

- A purchaser may maintain multiple open sheets at the same time.
- A sheet may contain transactions assigned to different cost centers; each transaction follows
  the managers responsible for its own cost center.
- A sheet with no transactions, a transaction with a non-positive amount, or a transaction missing
  any submission field or required evidence cannot be submitted.
- A sheet can be submitted without available account balance, and an approved consumption may
  result in a negative balance.
- A save that succeeds MUST NOT leave the purchaser somewhere the sheet cannot be submitted from: the
  sheet's submission control is reachable from the surface the save leaves the purchaser on, and no
  error is shown for a journey that has not failed (FR-088). A purchaser who saves a draft and then
  reaches a screen with no way to submit that sheet is the failure this guards, and it is a **navigation**
  fault rather than a validation one — the draft was recorded correctly, so nothing reports a problem
  while the workflow is unreachable.
- A manager's rejection is preserved even when a later authorized decision differs from it.
- A management-rejected transaction still reaches finance, but finance cannot approve it.
- A returned transaction is unresolved until corrected and must restart the full review path.
- Evidence may be added after submission, but removal after submission only deactivates it.
- Long-running work remains in its current stage; V1 performs no automatic reassignment,
  escalation, or status transition.
- A completed or completed-with-rejections sheet is locked against new transactions and edits.
- Discovering an error after final financial approval does not reopen or rewrite the original
  transaction in V1; handling such corrections is future scope.
- Disabling a user does not delete, reassign, or rewrite their historical records or decisions.
- If connectivity fails, the user must retry; V1 does not resume an interrupted operation
  automatically.
- A multi-role user may review their own transaction when the user independently has the required
  review role, responsibility scope, and workflow-stage permission.
- A query or schema change that runs correctly on MariaDB but depends on MariaDB/MySQL-specific SQL,
  functions, types, auto-increment assumptions, `GROUP BY` leniency, or delete-`LIMIT` behavior is a
  defect, not a passing result, and must be reworked so that it behaves identically on every declared
  V1 backend before it can satisfy FR-077.
- A schema element whose name, type, nullability, or default exceeds the limits required by the
  declared V1 backends (MySQL/MariaDB, PostgreSQL, SQLite) must be rejected at review time even when
  the target database accepts it, so that the schema does not become coupled to a single backend.
  Oracle-specific identifier and schema limits are not V1 requirements and MUST NOT be used to
  justify a rename or a migration of an existing table.
- Passing the full test suite against MariaDB alone does not demonstrate FR-077 compliance; the
  cross-database verification defined in the plan is the evidence of record.
- A statement that works on MariaDB and fails on any other declared V1 backend is a defect against
  FR-077, even though MariaDB is the development backend.
- Declaring MySQL/MariaDB compatibility as a single supported backend does not by itself prove
  MySQL compatibility, because MySQL and MariaDB are distinct engines that must each be verified
  independently.
- A migration that only runs because one backend tolerates a permissive default, an implicit
  `NOT NULL`, or an unenforced identifier rule is a defect against FR-077 even when it succeeds.
- A V1 table, index, or constraint whose name is not app-scoped, or that could collide with another
  application's object in the shared database, is a defect against FR-078 and FR-079.
- An application query, repository, or test that references a `pc_` table or index name is a defect
  against FR-078; the development schema is provisional V1 schema, not a preserved prior version.
- Discovering residual prototype tables such as `oc_pc_*` in a shared development database is
  not a V1 defect and MUST NOT cause V1 to attempt any import, migration, rename, or cleanup.
- A rewritten or corrected V1 baseline migration that is skipped because stale migration-state rows
  remain for the `pettycash` application ID is an environment defect to be cleared through the
  approved development reset, never a reason to reintroduce a compatibility path.
- A test run whose requests were throttled or answered by the platform rather than by PettyCash is
  **not evidence of an error-rate figure**, in either direction: the excluded requests inflate the
  denominator while suppressing the successful requests that would have exercised application code, so
  a contended run yields a ratio that looks better than a clean one. Such a run is reported as **not
  measured** once excluded responses exceed 1% of the requests issued, with the excluded count
  published beside the figure (FR-073).
- A brute-force `429` or platform `5xx` appearing during an acceptance run is an
  **environment-induced response**, not an application defect, and MUST NOT be counted toward FR-073's
  error-rate numerator; the run's environment must instead be reported as contended, so that a real
  5xx defect is never hidden behind, or excused by, a throttling failure.
- A public evidence link is consumed without authentication, so a link holder's access is **not**
  narrowed by role or scope (FR-031). An expired, deactivated, or never-existing token MUST all be
  refused identically, because a refusal that distinguishes "you had a link, it lapsed" from "no such
  link" turns the error itself into a confirmation that evidence exists.
- A public evidence link access MUST be audited as viewer `anonymous` with timestamp and IP. An audit
  entry with an empty viewer field is a defect against FR-031, not an acceptable rendering of an
  anonymous visit, because it is indistinguishable from a missing audit record during an access
  investigation.
- PettyCash MUST NOT answer a public evidence link request with its own authentication challenge,
  login form, rate limiter, or CAPTCHA; the source requirements place those in Nextcloud's hands, and
  a second mechanism would be an undeclared authentication system.
- Two decisions reaching the server for the same transaction and stage at the same time is a
  **superseded attempt**, not a last-write-wins overwrite and not a silently ignored request: the
  first recorded decision stands, the later request applies nothing, and the attempt is still audited
  (FR-085). A response that records a superseded attempt as an approval or a rejection is itself a
  defect, because it corrupts the history Principle IV protects.
- A decision that arrives after the active revision changed is refused as a superseded attempt on the
  same terms as one arriving after a decision was recorded. It MUST NOT be applied against the
  revision it was composed for, and the client MUST be told the decision it is reasoning about is no
  longer current rather than being left to infer it from an unchanged response body.
- An account deactivated between sheet creation and finance approval leaves the transaction
  **approved-in-intent but unsettleable as written**, and the finance approval is refused with `422`
  naming the account (FR-040). Refusing is correct even though the sheet then cannot complete: a
  transaction that visibly cannot be settled is recoverable, whereas an account conjured up at approval
  time to make settlement succeed books real expense against a balance nobody funded. The sheet
  remains at the finance stage and MUST NOT be completed, and the refusal MUST NOT be presented as a
  system fault — it is a correct refusal caused by a configuration gap an administrator must close.
- An account deactivated **before** any sheet exists blocks sheet creation with `422` naming it
  (FR-020), rather than being silently replaced by a freshly provisioned one. A purchaser whose only
  account was retired deliberately cannot self-restore the capability: only an administrator's
  reactivation, or a charge of a new account (FR-015), restores it, and both are audited under
  FR-017. The refusal is a correct outcome caused by a configuration gap, not a system fault, and
  MUST say so — a purchaser told only “no active account” is left with neither a way to
  proceed nor a way to learn that a remedy exists.
- An expense dated later than today is **accepted rather than refused** (FR-023): the date states
  when a cost was incurred, the balance is consumed at finance approval rather than on that date, and
  the record is reported under the FR-068 period its date falls in — which may be a later period than
  the one it was settled in. Refusing future dates would force a purchaser to enter a false date in
  order to record a real cost, so a future-dated entry is correct input, not a defect.
- An administrator who requests a **currency's decimal scale change** on a currency that already holds
  amounts is **refused with `422`**, and the message MUST name the currency, its current scale and the
  requested one (FR-023). Every stored amount in that currency would otherwise be reinterpreted — a rial
  balance of 50,000 becoming 500.00 — silently changing the meaning of every balance, sheet total and movement
  with no migration and no way to tell afterwards. **Deactivating the currency instead MUST still work**, and a
  currency that has never been used MUST have its scale set freely; refusing the whole update rather than the
  one field would be a defect, since renaming a live currency is harmless. **A scale change is refused, never
  migrated**: converting historical amounts would be the destructive history rewriting the constitution
  prohibits, and a figure the system silently altered is worse than one it declines to change.
  Corrected 2026-10-08 — this edge case previously recorded the scale change as an *undecided* question and
  paired it with a rule about rejecting over-precise amounts that no value can violate.
- A purchaser who **cancels or dismisses the delete confirmation** leaves the sheet entirely intact — no
  row, revision, or stored file removed, and focus returned to the control that opened the dialog
  (FR-086). Because the cascade deletes stored evidence files and a never-submitted sheet has no audit
  trail to restore from, the confirmation is the only thing standing between a mis-click and irreversible
  loss; a dialog that closes on Escape, on a backdrop click, or on Cancel must leave nothing behind.
- A **delete control shown on a sheet FR-086 refuses** — submitted, completed, or
  completed-with-rejections — is itself a defect against FR-086 and FR-071, not merely untidy: a control
  that is always refused teaches the purchaser that the application does not know its own state. The
  server-side refusal remains the enforcement under FR-009; the absent control is what keeps the interface
  consistent with it.
- A fault on an operation that addresses **no single record** — a list, a dashboard, an export of a
  selection — MUST record **no** affected public identifier, and that omission is **correct rather than a
  gap** (FR-087). Naming one record out of a list would send an operator to a record that was never
  involved, which is the same fabrication that inventing an identifier would be. The route recorded beside
  it is what tells the operator which operation failed.
- A fault on a **record-addressing** operation MUST carry that record's public identifier, even when the
  identifier is also visible in the recorded route (FR-087). The two serve different readers: the route is
  prose for a human scanning a log, and the identifier is a field an operator can filter or correlate on.
  An entry with a record-addressing route and no identifier is a defect against this requirement.
- Two decisions submitted for **different** transactions are **independent**, and one losing its race does
  not affect the other (FR-085). V1 has no bulk decision, so "approve these four transactions" is not an
  operation — a request naming more than one transaction is **refused** rather than applied to each in turn
  (FR-033, FR-070). This matters because a reviewer clearing a queue submits several decisions in sequence,
  and the answer a caller must be able to give is **per transaction**: this one was superseded, the other
  three were recorded. A refused multi-transaction request is not itself a `SUPERSEDED_ATTEMPT`, because
  nothing was attempted against any particular transaction's state.
- An error code the application emits that the contract's `Error.code` enumeration does **not** list is
  a defect against FR-083 — including a **new** code shipped ahead of the list, which is the failure mode
  an open field makes invisible. The reverse is equally a defect: an enumeration entry for a code nothing
  can return is a promise the contract cannot keep, and it sends a client writing a branch that can never
  fire. Both directions are SC-016's, which is why it is stated two-way rather than as a containment check.
- An open, never-submitted sheet **MAY be deleted by its owner**, taking its draft transactions, their
  revisions, and their stored evidence files with it atomically, and **no audit event is written**
  (FR-086) — a sheet that never entered the workflow has no official history to falsify. A sheet owned
  by another user, already submitted, or already completed MUST be refused rather than deleted
  (FR-003, FR-026, FR-044). A deletion that removed the sheet while leaving any transaction,
  revision, or stored evidence file behind is itself a defect against FR-086 and FR-042, because the
  orphaned records would belong to no sheet and no requirement would reclaim them.
- Evidence of **any file type the host file service accepts** is stored and served according to
  FR-060; V1 refuses no type and scans no content itself (FR-074). Refusing a type PettyCash
  considers undesirable is a defect against FR-074, because the content-type policy belongs to the
  platform and not to the application — the delegation is deliberate, so its absence is a regression
  rather than an unfinished feature. Where the platform's antivirus capability is enabled its verdict
  governs access, and where it is not enabled that is an accepted platform-level risk rather than a
  gap in V1.
- A `5xx` is **both** a minimal envelope to the caller and a detailed technical log entry
  (FR-087, FR-083): the response states the class of fault and nothing more, while the log names
  actor, route, command id, affected public id, and exception class. A `5xx` answered with no
  corresponding log entry is a defect against FR-087, and an error body exposing exception detail, a
  stack trace, a file path, or a connection string is a defect against both. A deliberate `4xx` needs
  no log entry — its reason is already in the response (FR-085, FR-040, FR-083) — and a technical log
  entry MUST NOT substitute for the business audit record FR-051 and FR-056 require.
- The same purchaser saving one draft from two tabs or two devices has the **second save refused with
  `409 Conflict`** on FR-085's terms (FR-025), never silently overwritten and never field-merged with
  the first. A refusal is the correct outcome here: a draft carries no official history, so nothing
  needs auditing, but a silently lost amount or description is the failure worth preventing, and a
  merge would fabricate a record combining two users' fields that neither entered. An edit that
  changes nothing is not a conflict. This is deliberately distinct from a superseded *decision*
  (FR-085), which is refused identically but recorded as a `SUPERSEDED_ATTEMPT`, because a decision
  history is at stake there and none is here.

## Requirements *(mandatory)*

### Functional Requirements

#### Roles and Access

- **FR-001**: The system MUST recognize purchaser, cost center manager, senior manager, finance
  officer, and system administrator responsibilities.
- **FR-002**: A user MUST be able to hold multiple roles simultaneously, with effective access
  determined by role, responsibility scope, record ownership, workflow stage, and record state.
  Self-review is permitted when the user independently holds the required review role, responsibility
  scope, and workflow-stage permission (per FR-008); ownership grants purchaser rights only, not
  review rights; all five factors must be satisfied for review actions.
- **FR-003**: Purchasers MUST be limited to their own sheets, transactions, evidence, histories,
  balances, and account movements.
- **FR-004**: Cost center managers MUST be limited to transactions and reports for assigned cost
  centers.
- **FR-005**: Senior managers MUST be limited to transactions and reports for cost centers within
  their assigned responsibility.
- **FR-006**: Finance officers MUST see and act only on transactions that have reached the finance
  stage; the shared-queue rule is stated once in FR-016.
- **FR-007**: System administrators MUST have full administrative visibility but MUST NOT acquire
  financial approval authority solely from the administrator role.
- **FR-008**: A multi-role user MUST be permitted to review or decide their own transaction when
  the user otherwise holds the required role, responsibility scope, and workflow-stage permission.
- **FR-009**: Authorization MUST be applied consistently to viewing, decisions, evidence, audit,
  reports, and exports; hiding an action in the interface MUST NOT be the only restriction.

#### Configuration and Petty-Cash Accounts

- **FR-010**: Administrators MUST be able to create, update, activate, and deactivate cost centers,
  including code, name, description, validity dates, cost center manager, and senior manager.
- **FR-011**: Each cost center MUST have one current cost center manager and one current senior
  manager, and one person MAY manage multiple cost centers.
- **FR-012**: Administrators MUST be able to create, update, activate, and deactivate expense types,
  including whether a type requires specific evidence.
- **FR-013**: Administrators MUST be able to manage supported currencies and currency-specific
  petty-cash accounts for users.
- **FR-014**: Every user MUST have a default active rial petty-cash account **except where an
  administrator has deactivated it**, and MAY have additional active accounts, each with its own
  currency, balance, and movement history. Deactivation is a deliberate, audited act (FR-017) whose
  consequence is that the holder can neither self-restore the account nor create a sheet until an
  administrator acts (FR-020) — a **suspended baseline, not a withdrawn requirement**, since
  the obligation remains live until an administrator's deliberate act lifts it. Clarified
  2026-10-08.
- **FR-015**: Finance MUST be able to charge a selected user petty-cash account and record the
  amount, date, description, currency, and resulting balance as an account movement.
- **FR-016**: V1 finance work MUST use a shared queue in which every finance officer can view and
  act on every transaction that has reached the finance stage.
- **FR-017**: Sensitive changes to assignments, expense types, currencies, **petty-cash accounts**,
  access, and control settings MUST record the actor, time, explanation, previous value, and new
  value in a separate configuration audit history. **Creating a petty-cash account is such a change,
  and MUST be recorded by every path that creates one** — an administrator's deliberate creation
  (FR-013) and FR-020's self-service provisioning alike — as **one shared event type** rather
  than one per origin, with the recorded actor distinguishing them. For a provisioned account that
  actor is the **account holder**, so the row shows both that the account exists and that **no
  administrator authorised it**. The audit row is a **record, not an authorisation**: provisioning
  remains self-service, a purchaser needs no approval or role to obtain an account, and the event
  MUST NOT be presented or exported as evidence of administrator consent. Clarified 2026-10-08.

#### Expense Sheets, Transactions, and Evidence

- **FR-018**: Any authenticated user with application access MUST be able to act as a purchaser and
  create multiple open expense sheets.
- **FR-019**: Each sheet MUST have one purchaser, title, creation date, status, source petty-cash
  account, and currency.
- **FR-020**: A purchaser with one active account MUST receive its currency automatically; a
  purchaser with multiple active accounts MUST choose from only those account currencies; a purchaser
  with **no rial account of their own** has a default active rial account auto-created on first sheet
  creation attempt (idempotent provisioning).
  **A deactivated account does not trigger provisioning, and the two cases MUST NOT be collapsed.**
  The automatic rule covers a purchaser who has *no* rial account record at all. Where such an
  account **exists and is inactive**, the trigger MUST NOT fire and the sheet-creation attempt MUST be
  **refused with `422`**, naming the inactive account and stating that an administrator must
  reactivate or replace it, so that a purchaser's own click cannot reverse a deliberate, audited
  deactivation (FR-017, FR-055). The distinction is whether a record **exists**, not the active flag
  alone. FR-014's default account is a **baseline that an administrator's deactivation suspends**, so
  the purchaser cannot lift that suspension, and restoring the account is an administrator's
  deliberate, audited act. Clarified 2026-10-08.
  **This requirement owns the provisioning rule.** It previously cross-referenced "FR-014/T085" here
  while `spec.md:26` (Clarifications, session 2026-09-22) cited only FR-020, so the same normative
  behaviour had two identifiers and no consistent citation. FR-014 states the *account model* — that
  every user has a default active rial account — but not the *provisioning trigger*; the trigger is
  what this requirement specifies and what T085 implements. FR-014 is therefore left as the account
  model and is not a second owner of provisioning. Recorded 2026-10-01.
- **FR-021**: Every transaction in a sheet MUST inherit the sheet currency and MUST NOT define an
  independent currency.
- **FR-022**: An open transaction MUST be savable with an expense date and positive amount as its
  minimum initial information.
- **FR-023**: Submission MUST require every transaction to have an expense date, positive amount,
  description, exactly one cost center, one active expense type, and all required evidence.
  **The expense date carries no validity window (Clarified 2026-10-08).** Any valid calendar date
  MUST be accepted, **including a date later than today**, because the expense date is a fact the
  purchaser asserts about when a cost was incurred rather than a scheduling promise; V1 MUST NOT
  refuse a future-dated expense and MUST NOT impose a lower bound, so back-dating an already-incurred
  cost remains ordinary. The date does not decide when money moves: consumption is decided and booked
  at final finance approval (FR-040), so an approved future-dated expense decrements its account at
  approval and never before anyone approved it. **What the date governs is period membership** — a
  future-dated expense is reported under the FR-068 Jalali period its date falls in, which may be a
  later period than the one in which it was settled, and that is the intended reading rather than a
  defect.
  **A currency's scale MUST NOT be changed while amounts exist in it (Corrected 2026-10-08).** Amounts are
  stored as whole minor units and are **interpreted** against the currency's configured `minor_unit` (0–4), so a
  stored amount's meaning is inseparable from that scale: raising the scale from 0 to 2 would silently
  reinterpret every stored amount in that currency as hundredths, and lowering it as units. Where **any amount
  exists** in the currency, a request that changes its `minor_unit` MUST be **refused with `422`**, and the
  message MUST name the currency, its current scale, and the requested one. **A change to any other field of
  that currency — its name, or its active state — MUST still succeed**: the rule guards one field and refuses
  the whole request, so refusing the update wholesale would be a defect. The currency may be deactivated
  instead, and a currency that has never been used may have its scale set freely. **V1 MUST NOT migrate or
  rewrite stored amounts to accommodate a scale change**, because reinterpreting historical financial records is
  the destructive history rewriting the constitution prohibits; and V1 MUST NOT round a purchaser's amount, since
  a figure the system silently altered is worse than one it refuses.
  (Corrected 2026-10-08, replacing an unsatisfiable clause this same session had added. The previous wording —
  "an amount carrying more decimal places than that scale allows MUST be refused" — described a state **no value
  can be in**: `amountMinor` is an integer *already expressed in the currency's minor units*
  (`contracts/ocs-api.yaml:1416` declares `type: integer`, `Money::of()` stores an integer and a currency *code*
  and never reads `minor_unit`, and `src/views/transactions/TransactionFormView.vue:294` binds the purchaser
  straight to `amountMinor`), so every represented amount has exactly the scale's precision and there was
  nothing to detect. The clause also displaced the hazard that does exist: `CurrencyService::update()` accepted
  `int $minorUnit` at `lib/Application/Configuration/CurrencyService.php:145` with no guard, so an administrator
  could change a live currency's scale and silently alter the meaning of every balance, sheet total and
  movement in it. Correcting the wording is what makes the guard implementable, and the guard is a Principle II
  matter because it changes the **data meaning** of stored financial records without an approved migration.)
- **FR-024**: A sheet MUST contain at least one transaction before submission.
- **FR-025**: Before submission, the purchaser MUST be able to add, edit, and delete transactions
  and add or remove their evidence. Deleting the draft **sheet** itself is FR-086.
  **Concurrent editing by the owner (Clarified 2026-10-08).** Where a save carries a version that is
  no longer current, the application MUST refuse it with `409 Conflict` on FR-085's terms and the
  client MUST reload before saving again. A pre-submission edit MUST NOT silently overwrite a newer
  one and MUST NOT be field-merged with one, and a refusal is the correct outcome rather than a
  defect. This governs the owner's own pre-submission edits of a transaction and of a sheet's own
  editable fields; it does not govern review decisions, which FR-085 owns, nor a sheet after
  submission, which FR-026 and FR-044 own. An edit that changes no value asserts no competing value and
  is therefore not a stale version. **No `SUPERSEDED_ATTEMPT` event is recorded**, because a draft
  carries no official history an attempt could falsify — transaction audit begins at submission
  (FR-051). (Recorded 2026-10-08: `data-model.md:23` places `lock_version` on seven aggregates "for
  optimistic checks" and `:25-38` states the behaviour only for a decision, while this requirement
  permitted unrestricted draft editing — so a second save silently destroyed the first.)
- **FR-026**: After submission, the purchaser MUST NOT add transactions or edit transaction
  business information except through correction of a returned transaction.
- **FR-027**: Account balance MUST NOT prevent sheet creation or submission, and negative balances
  MUST be permitted after valid approved consumption.
- **FR-028**: A sheet MAY contain transactions for multiple cost centers, while decisions remain at
  transaction level and sheet status is derived from the collective transaction states.
- **FR-029**: Each transaction MUST support multiple evidence files and retain file name, type,
  size, upload time, uploader, active state, related sheet, related transaction, and revision
  traceability.
- **FR-030**: A user who may view a transaction MUST be able to view its evidence; advanced
  evidence-specific restrictions (document classification levels, redaction rules, watermarking,
  access logging beyond audit, time-limited evidence access, evidence-type-specific permissions)
  are explicitly deferred to V2.
- **FR-031**: Public evidence links MAY be created only under administrator control, MUST be
  manageable and deactivatable, and MUST be audited; links have configurable TTL (default 30 days,
  maximum 1 year) and per-access audit logging recorded in configuration audit.
  **Who may consume a link (Clarified 2026-10-07).** A public evidence link is **unauthenticated
  access**: the unguessable token is the whole authority, so anyone who holds it may open the linked
  evidence item without signing in and without holding any role or responsibility scope on the
  transaction. This is a deliberate and bounded exception to the role-scope rule FR-009 otherwise
  applies to every other read of evidence, and it is bounded as follows. **Abuse control is delegated
  to Nextcloud's existing brute-force limiter** and PettyCash MUST NOT introduce a second
  authentication, rate-limiting, or access-control mechanism for these links, because the source
  requirements place authentication and login policy in Nextcloud's hands. **Every access records
  configuration audit with the viewer recorded as `anonymous`** together with timestamp and IP — the
  viewer field MUST NOT be left null or absent, because an anonymous visit has no user identifier and
  an empty field would make the entry unusable for the investigation it exists to serve. The token is
  stored only as a hash and disclosed once at creation. **The administrator's own view right is not
  required to create a link**, and creating one for evidence on a transaction the administrator cannot
  themselves view is permitted and is audited as such. Because TTL and revocation are the only
  remaining controls, **an expired, deactivated or unknown token MUST be refused with the same
  answer**, so that the refusal reveals neither that a link ever existed nor when it lapsed.
  (Recorded 2026-10-07: this requirement previously stated that per-access audit records the "viewer
  UID" while leaving the consumer undefined, which is unsatisfiable for an anonymous visit; and it
  stated no access rule, so the application's only unauthenticated data path had no stated
  authorization model. `contracts/ocs-api.yaml` defines link creation and deactivation but no
  consumption route, and `tasks.md` T095/T166 remain open, so this settles the rule before the route
  and service are built.)

- **FR-086**: An authenticated purchaser MUST be able to delete their **own open expense sheet that
  has never been submitted** (Clarified 2026-10-08). The deletion MUST remove the sheet together with
  its draft transactions, their revisions, and the stored evidence files attached to them, and MUST
  complete **atomically** — wholly done or wholly intact — so that no transaction, revision, or stored
  file survives a sheet that no longer exists. Stored evidence files MUST be deleted from storage
  rather than merely unlinked. Deletion MUST be **refused** for a sheet owned by another user
  (FR-003), for a sheet that has already been submitted (FR-026), and for a completed or
  completed-with-rejections sheet (FR-044), each on the reason those requirements already give.
  **No audit event records the deletion**, because a never-submitted sheet has produced no official
  business event to erase: transaction audit begins at submission (FR-051), and no submission,
  routing, decision, revision, or financial effect can exist beneath a sheet that was never
  submitted. FR-084's replay safety applies to this command as to any other mutating operation.
  **The capability MUST be reachable by the purchaser in the interface (Clarified 2026-10-08).** An open
  sheet MUST offer a delete control on **both the sheet list and the sheet detail**, the control MUST NOT be
  offered on a sheet that FR-086 refuses to delete, and the operation MUST be behind a **confirmation step
  naming the sheet being deleted**. An endpoint a purchaser cannot invoke is not a capability, and a
  confirmation step is required because the cascade removes stored evidence files as well as rows: a single
  mis-click would destroy files that cannot be recovered. Both surfaces fall inside **FR-071's** V1
  accessibility floor and therefore inside **SC-009** as a primary journey of User Story 1 — see the
  focus requirement recorded there.
  (Added 2026-10-08: FR-025 covers deleting an individual draft *transaction* and FR-043/FR-044
  cover completion and locking, so no requirement stated whether a draft *sheet* could be discarded
  at all and a purchaser who abandoned one had no way to remove it from their list; the cascade,
  atomicity, storage-cleanup, and no-audit properties were likewise unstated, and no `DeleteSheet`
  command exists under `lib/`. The interface affordance and its confirmation step were added the same
  day: the first delivery built the endpoint and left the capability unreachable from the interface,
  which is not what an acceptance scenario saying "the purchaser deletes it" describes.)

#### Review Workflow and Financial Effects

- **FR-032**: Submission MUST route each transaction to the manager assigned to its cost center and
  MUST begin the transaction's formal business history.
- **FR-033**: Cost center managers MUST be able to approve, reject, or return transactions for
  correction; rejection and return MUST require an explanation. **Each decision applies to exactly one
  transaction (Clarified 2026-10-08).** V1 MUST NOT provide an operation that decides several transactions
  at once, and a request naming more than one transaction MUST be **refused** rather than applied to each
  in turn; see FR-070, which records the bulk decision among the V1 deferrals. FR-085's first-write-wins
  therefore governs each decision independently and there is no partial-application outcome to define.
- **FR-034**: A cost center manager decision MUST remain an advisory decision visible to the senior
  manager with full details in the review queue, and MUST NOT by itself determine the final management
  outcome; the senior manager may approve, reject, or return independently without being forced to
  acknowledge or respond to the advisory decision.
- **FR-035**: Senior managers MUST be able to approve, reject, or return a transaction for
  correction and MAY decide independently of the cost center manager's opinion.
- **FR-036**: The senior manager decision MUST be the final management decision and MUST remain
  unchanged by finance.
- **FR-037**: Transactions approved or rejected by the senior manager MUST proceed to finance once
  **every active nonterminal revision in the sheet carries a senior decision** — that set is what
  "all transactions required for that sheet-stage transition" means. A senior approval and a senior
  rejection each count as a senior decision, and each routes its transaction to finance, because
  FR-039 forbids finance from approving a senior-rejected transaction, not from seeing it.
  **The sheet's own status is a derivation, not a gate.** Each transaction enters and leaves its
  queue on its own decision and no transaction blocks another; the sheet's status is the **earliest
  stage still outstanding** across its active nonterminal revisions, over the ranking
  `Draft 0, CostCenter 1, Correction 2, Senior 3, Finance 4, Final 5`. `CORRECTION` is therefore a
  genuine sheet status and outranks `SENIOR` and `FINANCE`, so a sheet with one transaction awaiting
  purchaser correction displays `CORRECTION` **even when another transaction is already at finance** —
  the intended reading of FR-028's "derived from the collective transaction states", and not a defect.
  FR-043's completion rule is the only true gate. (Clarified 2026-10-02: this requirement previously
  left "all transactions required for that sheet-stage transition" undefined, while `data-model.md`
  carried a derivation rule naming three states — `AWAITING_COST_CENTER_REVIEW`,
  `AWAITING_SENIOR_REVIEW`, `AWAITING_FINANCE_REVIEW` — that are `TransactionStatus` values and not
  `SheetStatus` values at all, and omitting `CORRECTION`, so the data model and the running code
  disagreed about what a returned transaction does to the sheet. `data-model.md` is corrected.)
- **FR-038**: Finance MUST be able to approve, reject, or return a senior-approved transaction.
- **FR-039**: Finance MUST NOT approve a senior-rejected transaction but MAY reject it or return it
  for correction.
- **FR-040**: Final finance approval MUST record one consumption movement against the petty-cash
  account matching the sheet currency and decrease its balance by the approved amount.
  **When no active account in that currency exists (Clarified 2026-10-07), the approval MUST be
  refused with `422`, naming the missing or inactive account**, and the transaction MUST remain at the
  finance stage with no balance movement, consumption record, workflow transition or final outcome
  recorded. FR-020's idempotent provisioning MUST NOT be extended to settlement: provisioning at
  approval time would book approved expense against a balance that was never funded or approved, so
  the remedy is an administrator's deliberate action — restoring, reactivating, or charging an
  account — which FR-015 and FR-017 already audit. (Recorded 2026-10-07: this requirement stated what
  must happen when the account exists but not what must happen when it does not, while FR-042's
  atomicity left an approval with no satisfiable action and no stated outcome.)
- **FR-041**: Pending or finance-rejected transactions MUST NOT affect the actual petty-cash
  balance.
- **FR-042**: Recording a decision, changing workflow state, creating a revision, and recording
  final financial consumption MUST each complete atomically or leave the prior valid state intact.
  **A command carrying a caller-supplied command id MUST be idempotent: a retry with the same id and
  the same intent returns the original result rather than a conflict**, because a retry is what a
  client sends when it never received the first response. Any value a request **derives** rather than
  receives MUST therefore be deterministic across attempts, or MUST NOT participate in the content
  comparison — a derived default that varies per attempt makes a legitimate retry indistinguishable
  from a genuine duplicate, and neither the client nor the operator can tell which occurred. Clarified
  2026-10-08.
- **FR-043**: A sheet MUST become completed when every transaction has a final financial outcome,
  and MUST become completed-with-rejections when at least one transaction is rejected.
- **FR-044**: Completed and completed-with-rejections sheets MUST be locked for viewing and
  reporting only.
- **FR-045**: V1 MUST NOT automatically escalate, reassign, or advance transactions that remain in
  a workflow stage for an extended period.
- **FR-046**: V1 MUST NOT reopen or rewrite a transaction after final financial approval.

#### Correction, Revision, and Audit

- **FR-047**: Any review stage MUST be able to return a transaction to the purchaser for correction
  with a required explanation.
- **FR-048**: Correcting a submitted transaction MUST create a new revision; the prior revision
  MUST remain unchanged and viewable.
- **FR-049**: A corrected transaction MUST restart review with its cost center manager and follow
  the full management and finance path again.
- **FR-050**: The active revision MUST determine the current transaction information and status,
  while all prior decisions remain linked to their original revision.
- **FR-051**: Formal transaction audit MUST begin with sheet submission and record submissions,
  routing, approvals, rejections, returns, corrections, revision creation, transaction changes,
  evidence additions, and evidence deactivations.
- **FR-052**: Every audit event MUST retain event time, actor identifier, actor display name at the
  time, actor role at the time, workflow stage, event type, decision, explanation, and applicable
  before-and-after values.
- **FR-053**: Business audit records MUST be append-only, non-editable, and non-deletable for every
  role, including administrators.
- **FR-054**: Disabling or removing a user MUST NOT delete or alter their historical identity,
  roles-at-event, decisions, records, or in-progress workflow history.
- **FR-055**: Audit visibility and export MUST follow the same role and scope restrictions as the
  related transaction; configuration audit visibility follows role scope: system administrators see
  all configuration audit events; finance officers see configuration audit for accounts they charge;
  cost center and senior managers see configuration audit for their assigned cost centers. An
  officer's charged accounts are the distinct account subjects of that officer's own **account funding
  and balance-charging** configuration audit events, so the set is derived from the audit trail rather
  than recorded on each movement: such an event MUST name the charging officer and the account, and an
  event type outside funding and charging -- a rename, an activation, a deactivation -- MUST NOT count
  toward the set, because touching an account is not charging it. Settling an approved transaction
  against an account therefore MUST write a configuration audit event naming that account as well as
  recording the decision, so a charge appears in both audit streams; relying on the transaction-audit
  decision alone would leave this scope empty for every officer who had actually charged an account. A finance officer with no funding or
  charging event sees no configuration audit, which is a true zero rather than a widened scope.
- **FR-056**: Transaction audit and configuration audit MUST remain separately identifiable from
  each other and from technical error records.
- **FR-057**: Any configurable audit retention policy MUST preserve the non-deletable historical
  record for a minimum of 7 years; policy may govern archival or availability but MUST NOT rewrite
  business history.

#### Evidence Lifecycle

- **FR-058**: Before submission, purchasers MUST be able to remove evidence from their open
  transactions.
- **FR-059**: After submission, removing evidence MUST mark it inactive rather than physically
  delete it, and its history MUST remain viewable.
- **FR-060**: Authorized users MUST be able to preview supported evidence, download it, and see its
  active state and change history. Preview means the browser renders the evidence in place rather than
  saving it: a response whose stored media type is inline-safe -- images, PDF, and plain text -- MUST
  be sent with that stored media type and an `inline` disposition so it renders. Any other media type
  MUST be sent as `application/octet-stream` with an `attachment` disposition, so it is offered as a
  download and is never rendered in the Nextcloud origin, because an attacker-supplied type served
  inline would execute as script in the viewer's session. A caller MUST be able to request either
  disposition explicitly. (Clarified 2026-09-30: `contracts/ocs-api.yaml` declares
  `application/octet-stream` as the only success content type for `GET /evidence/{evidenceId}/content`,
  which makes this requirement's preview unimplementable, while the same contract declares a
  `disposition` parameter defaulting to `inline` -- a value that cannot render an octet-stream body.
  The contract is therefore the artifact that was wrong and MUST be amended to match this requirement;
  T275 owns that change and owns naming the concrete inline-safe media-type list.)

#### Dashboards, Search, Reports, and Exports

- **FR-061**: The system MUST provide role-aware dashboards showing each user's relevant open
  sheets, returned transactions, review queues, decision counts, finance work, balances, movements,
  and administrative summaries.
- **FR-062**: Sheet summaries MUST show headline approved, rejected, and pending amounts based on
  each transaction's latest effective outcome, and MUST separately show approved totals for the
  cost center manager, senior manager, and finance stages as separate columns (Cost Center Approved | Senior Approved | Finance Approved) alongside the latest effective outcome column.
- **FR-063**: Sheet lists and details MUST show identity, owner, currency, status, transaction count,
  key dates, current and submitted totals, summary amounts, and the sheet's **latest active transaction
  revision number** -- the highest revision number among the active revisions of the sheet's transactions.
  **Amended 2026-10-06 (T385).** The clause previously read *"and the active transaction revision"*, which is
  singular and cannot be rendered as written: `activeRevision` is a member of `TransactionSummary`, so a
  sheet has one per transaction, and a list row carries no transactions at all. The reading chosen here is
  the only non-redundant one -- a *count* of active revisions equals the `transactionCount` this same
  clause already requires, so it would add nothing. Per-transaction `activeRevision` remains rendered on
  the detail surface's transaction table, which is where a reader inspects an individual revision.
- **FR-064**: Users MUST be able to filter sheets by status, date, currency, and creator;
  transactions by status, cost center, expense type, date, and amount; audit by actor, stage,
  event type, and date range; and group exports by period, which is the predefined Jalali period
  defined in FR-068. **`period` is a filter in its own right, not a form of the date or date-range
  filters**, and it is scoped to the export and report endpoints that already accept a date range.
  Each facet resolves to exactly one source, so that "filter by creator"
  has a single meaning: a sheet's **creator** is its owner; a transaction's **cost center** is the one
  recorded on that transaction's own active revision -- not the sheet's, because a sheet may hold
  transactions for several cost centers (FR-028); a transaction's **expense type** and **amount** also
  come from its active revision; and an audit event's **stage** is the stage recorded on the event.
  A filter naming any field outside this set MUST be refused with a 422 naming the accepted filters, and
  MUST NOT be ignored -- an unrecognised filter silently returning the unfiltered report is a wrong
  answer, not an empty one. Because FR-068 makes `period` and an explicit date range mutually exclusive
  within one request, supplying both MUST be refused as FR-068 requires; `period` MUST NOT silently
  narrow a supplied date range, and MUST NOT be merged into one. (Clarified 2026-09-30: this set omitted
  `period` while FR-068 required it, so the specification both required the filter and -- through the
  422 rule below -- forbade it. `period` is now a named member of the accepted set rather than being
  declared a parameterisation of `date`, because FR-068 presents periods and custom date ranges as
  alternatives and makes them mutually exclusive, which a parameterisation and its own host cannot be.)
- **FR-065**: Reports MUST cover sheets, transactions, cost centers, current process status, evidence,
  and history within the requester's access scope. Petty-cash balances and movements MUST be reachable
  as data of the `accounts` report -- one account per row, carrying that account's balance and its
  movements. The report shows a user their own accounts, a finance officer every account they have
  charged in addition to their own, and an administrator every account; a report that showed only the
  caller's own accounts would hide from a finance officer the very accounts FR-015 requires them to
  charge. The reporting API publishes exactly seven report types -- sheets, transactions, cost-centers,
  accounts, process, evidence and audit -- and no requirement may add an eighth.
- **FR-066**: Authorized users MUST be able to export permitted reports, sheets, transactions,
  audit histories, and evidence in a supported `fileFormat` (`csv`, `xlsx`, `zip`, or `pdf`), and a
  format outside that set MUST be refused with a reason naming what is supported rather than answered
  with an unreadable or empty document. All four formats MUST be supported in V1. A Persian-capable
  font MUST be vendored into the repository under an app-owned path together with its licence text, and
  PDF export MUST NOT depend on a font installed on the host or inside a container, so that a Persian
  PDF renders identically in every deployment. (Clarified 2026-09-30: `pdf` was previously conditional
  on "a Persian-capable font being available", which no artifact ever established a location for, so V1
  shipped without PDF by accident rather than by decision and T109 was permanently blocked. Verified
  during that task: `tc-lib-pdf` is installed but `tecnickcom/tcpdf` is not, `vendor/` holds no font
  files at all, and `tc-lib-pdf-font` ships none and documents that they must be generated by the
  consuming project's install hooks. Bidirectional text shaping is not the constraint and was verified
  working through `tc-lib-unicode`'s UAX#9 bidi and Arabic shaping plus `setRTL()`.)
- **FR-082**: Group exports MUST honour the records the caller selected. A selection the system cannot
  satisfy MUST be refused with a reason; it MUST NOT be answered with an export that omits the selected
  records. A filter the report's records cannot answer MUST also be refused, never reported as an empty
  result. (Numbered FR-082 rather than FR-068 by the 2026-09-30 clarification: this rule and the group-export
  coverage rule below were both numbered FR-068. FR-068 is retained for the coverage rule because every
  existing cross-reference -- `tasks.md` and `plan.md`'s Reporting Periods note -- uses FR-068 for it. This
  rule therefore takes the next free identifier and keeps its position among the export requirements.)
- **FR-067**: A complete sheet export MUST preserve the relationships among sheet information,
  transactions, decisions, revisions, audit events, and evidence.
- **FR-068**: Group exports MUST support selected sheets, date ranges, cost center transactions, and
  reporting periods (predefined periods: month, quarter, year; plus custom date range) without
  including records outside the requester's access scope. **The predefined periods are Jalali
  (Solar Hijri) periods, because that is the calendar every date in the interface is already
  rendered in**; a period therefore MUST span Farvardin through Esfand, and a Jalali quarter MUST
  begin at Farvardin rather than at a Gregorian quarter boundary. A predefined period MUST resolve
  to an equivalent Gregorian from/to pair for querying, and MUST NOT alter, re-encode or re-interpret
  any stored expense date; stored dates remain Gregorian calendar dates without a time zone. A Jalali
  year is not a Gregorian year, so a period MUST NOT be identified in the request or the response by
  a Gregorian year number. **Each predefined period denotes the period in progress at the moment of
  the request** — the current Jalali month, quarter or year — running from its first day through its
  last, rather than the previous completed period or a trailing window of the same length. Because a
  period in progress is necessarily incomplete, every response that resolved a predefined period MUST
  state the exact date range it resolved, so that a partial period cannot be mistaken for a complete
  one. A request for a previous or trailing period is expressed with the explicit custom date range
  this requirement already supports, not by a second preset. **Period boundaries MUST be resolved in
  the time zone of the account making the request**, taken from that user's own account settings and
  falling back to the instance's configured default time zone when the account does not state one;
  the application MUST NOT hardcode a single zone. Because the zone belongs to the caller, the range
  a predefined period resolves to is **relative to the requesting user**, and two users in different
  time zones resolving the same period name MAY obtain different date ranges. A response MUST state
  both the resolved range and the time zone it was resolved in, so that a difference between two
  users' exports is explainable from the response rather than appearing as missing records.
  **Period boundaries are inclusive at both ends**: a period covers every expense date from its first
  day through its last day, so no day belonging to the period is excluded and no day outside it is
  included. Expense dates are whole calendar dates carrying no time of day, so a boundary cannot fall
  inside a record and there is no half-open interval to reason about. A predefined period and the
  equivalent explicit custom date range MUST therefore select exactly the same records. A Jalali year
  MUST NOT be treated as a fixed length: it has 365 or 366 days, and a period's record count MUST
  reflect its actual length. **A predefined period and an explicit custom date range are mutually
  exclusive within one request.** A request supplying both MUST be refused with a `422` whose message
  names the conflict and states which of the two to remove, and the application MUST NOT resolve the
  conflict by honouring one and discarding the other, nor by intersecting them. Silently dropping
  either instruction would make it impossible for a caller to tell which was applied, and this
  requirement already refuses rather than ignores elsewhere for the same reason.
- **FR-069**: Group evidence exports MUST identify the sheet, transaction, revision relationship,
  file metadata, and active state for every exported item.
- **FR-070**: Scheduled exports, automatic report delivery, external export interfaces, custom
  organizational templates, and direct integration with other systems MUST remain outside V1.
  **Bulk multi-transaction decisions MUST also remain outside V1 (Clarified 2026-10-08):** approving,
  rejecting, or returning **several transactions in one request** is a V2 capability, and V1's single-
  transaction rule is recorded in FR-033 where a reviewer meets it. Recorded here as well so the boundary
  is findable in the one requirement a reader consults for what V1 does not do.
  **A sheet-level audit history MUST also remain outside V1 (Clarified 2026-10-09):** the ordered event
  history FR-051 defines is reachable per **transaction**, and V1 provides no sheet-level audit tab or
  aggregate view. FR-051 states the per-transaction rule where a reader meets it.

#### Experience and Operational Quality

- **FR-071**: The V1 interface MUST be Persian and right-to-left, with all listed elements (sheet status, transaction status, current stage, prior decisions, history, evidence) visible without horizontal scroll at 390px viewport, status color/icon meeting WCAG AA contrast, text ≥12pt Persian font, and adapt to desktop and mobile-sized screens.
  **Keyboard operability (Clarified 2026-10-07).** In addition, a user MUST be able to complete each **primary journey** — the journeys exercised by User Stories 1–7 — **using the keyboard alone**, without a pointer, and every interactive element reached that way MUST present a **visible focus indicator**. "Visible" means a perceivable change to the focused element; a focus indicator that the active host theme renders as absent is a defect, because a keyboard user is then given no indication of where focus is. This is a usability floor, not a full accessibility conformance claim: **screen-reader semantics — form control label association, error-message association with the offending field, and announced status for mutations — are deferred to V2** under FR-076. (Recorded 2026-10-07: `quickstart.md:229` instructed testers to validate keyboard navigation, visible focus, dialog containment/return, labels, error association and live mutation feedback, but no requirement, task or success criterion covered any of them, so the checklist asserted checks that nothing owned while FR-071 promised only contrast, font size and no horizontal scroll. The two criteria V1 adopts are added here and to SC-009; the three deferred items are struck from `quickstart.md`.)
- **FR-072**: The primary workflow MUST be usable in Chrome 120+, Edge 120+, and Firefox 115+ (ESR).
- **FR-073**: The product MUST support the documented V1 operating scale of up to 100 active users
  and 10,000 expense transactions per year without loss of correctness, with p95 latency <3s for
  routine views, error rate <0.1%, and zero data loss. The three bounds are verified differently and
  MUST NOT be reported interchangeably:
  - **p95 latency <3s** is a V1 **design target** governing how queries are built. Its verification
    at the stated scale is deferred to post-V1 per SC-008 and is **not** a V1 acceptance gate.
  - **Error rate <0.1%** means **5xx responses as a share of all HTTP requests issued during V1
    acceptance**, counted across the automated functional, contract and end-to-end suites rather
    than over a dedicated load run, and recorded as a **V1 release-readiness figure** by T135. A
    client-side 4xx is not an error for this purpose — a request the application correctly refused
    is not a defect — and the 4xx, 409 and 422 responses that contract tests deliberately provoke are
    excluded from both numerator and denominator. **An environment-induced response is a second and
    separate excluded class**: a response caused by the run's surroundings rather than by application
    behaviour — a brute-force `429`, an infrastructure or platform `5xx`, a transport failure — is
    excluded from both numerator and denominator, and the count of excluded requests MUST be published
    beside the figure. **If the excluded share exceeds 1% of the requests the run issued, the figure
    MUST be recorded as not measured rather than reported**, naming environment contamination as the
    reason, on the same principle applied to SC-008 and SC-010. This is because a contended run
    inflates the denominator with responses that never exercised application code while suppressing the
    successful requests that would have exercised it, so leaving them in would make a broken run look
    better than a clean one. The result MUST be recorded whether or not it passes.
  - **Zero data loss** is a correctness invariant established by the atomicity and failure-injection
    suites, not by the latency or error-rate measurement.

  (Clarified 2026-10-02: this requirement previously stated "error rate <0.1%" with no denominator and
  no measurement window, and no task measured it at all, so the bound was owned by nobody and could
  not be claimed at release readiness.)

- **FR-074**: V1 MUST enforce a maximum evidence file size of **100 MB per file as its own ceiling**,
  and MUST refuse an upload above it with a message naming the limit, **regardless of what the host
  file service allows**. The 100 MB figure is PettyCash's own limit and not a mirror of the host's
  configuration; the earlier wording that limits "MUST follow the host file service rather than
  introduce a separate PettyCash limit" is **withdrawn as superseded**, because a deployment whose host
  limit exceeded 100 MB had no stated answer under it. The host file service may still impose a
  *lower* limit of its own, in which case the host rejects first and that rejection MUST be reported
  as a host-limit failure rather than as this requirement's 100 MB refusal, so a test or a support
  report can tell which ceiling stopped the upload. (Clarified 2026-10-02.)
  **Accepted file types and content safety are delegated to Nextcloud (Clarified 2026-10-08).** V1
  MUST NOT impose a file-type allow-list, an extension allow-list, or any content scanning of its own:
  an upload MUST be accepted whenever the host file service accepts it, whatever its type, and where
  the platform's antivirus capability is enabled **its verdict governs access to the file**. This is a
  recorded delegation rather than an omission — Principle III makes Nextcloud authoritative for
  security controls, and FR-031 already delegated abuse control for public evidence links the same
  way — and it surrenders no stored-XSS protection, because FR-060 closes that by serving every
  non-inline-safe media type as `application/octet-stream` with an `attachment` disposition, from the
  file's **stored** media type rather than from anything the uploader asserts. V1 therefore accepts
  and stores an executable or an archive if the host accepts it; a deployment wanting content scanning
  obtains it by enabling the platform's antivirus capability, and one that does not has accepted that
  risk at the platform level.
- **FR-075**: Communication failures MUST show a clear error and require manual retry; V1 MUST NOT
  automatically resume interrupted operations.
- **FR-076**: Independent application backup and restore, active multilingual UI, a standalone
  mobile application, and advanced dashboard shortcuts MUST remain outside V1.
  **Accessibility conformance beyond the V1 floor (Clarified 2026-10-07):** form control label
  association, error-message association with the offending field, and announced (live-region) status
  for mutations MUST remain outside V1, alongside full accessibility conformance auditing. V1's stated
  accessibility floor is FR-071's keyboard operability and visible focus for the primary journeys,
  plus its contrast, font-size and no-horizontal-scroll clauses. These items are deferred, not
  satisfied, and MUST NOT be reported as met in V1 release readiness.
- **FR-083**: Every read endpoint MUST answer an unreadable stored record — a broken reference, an
  incomplete row — with the same status and the same class of explanation, so that a client writes
  one handler rather than one per endpoint. **The error code vocabulary is closed and published
  (Clarified 2026-10-08), generalising beyond read endpoints:** `contracts/ocs-api.yaml` MUST declare
  an **enumeration** of the codes the application emits, and **every code it emits MUST appear in that
  enumeration**. A code the application can emit but the contract does not list is a **defect against
  this requirement**, exactly as an endpoint that answers a data fault with the wrong status is one
  against the first clause — and a client **MAY rely on the list being complete**, so a branch on a
  listed code is a supported way to react rather than a guess. The enumeration's purpose is the same
  as this requirement's first clause: one handler. A closed list is what makes "branch on the code"
  testable, where the same field left open means the only dependable thing a client can react to is the
  HTTP status. **New codes are contract changes**, added to the enumeration deliberately and not
  shipped ahead of it. `GET /sheets`, `GET /sheets/{id}`, `GET /dashboard` and
  the report endpoints MUST all return `422` with a message identifying the condition as a data fault
  and ruling out permissions and filters, and MUST NOT return `500`; `500` remains reserved for
  genuinely unexpected faults. An unreadable record MUST NOT be silently omitted from a result set,
  defaulted to a valid-looking value, or rendered as if healthy: a stored record whose reference does
  not resolve is a fault to be reported, not one to paper over. (Clarified 2026-09-30: the same fault
  had two answers depending on the endpoint — the report endpoints returned `422` because
  `ReportController` translated it, while sheets and dashboard fell through to the default mapper's
  `500` with the message withheld. T313 owns making them consistent and T314 pins the settled
  behaviour with tests, including the administrator and dashboard paths that no test currently
  exercises.)
- **FR-084**: Every mutating operation MUST be replay-safe, so that a client retry, a double-click, or
  a duplicated network request can never apply a second business effect. The client MUST supply a
  `X-PettyCash-Command-ID` identifying the command, and the server MUST record the outcome of an
  applied command keyed by that identifier, so that a repeat of an already-applied command returns
  **the first outcome** rather than producing a second decision, revision, settlement or deletion. The
  recorded outcome MUST be the original response, not a recomputation. A command identifier reused
  with **different content** MUST be refused as a conflict rather than applied, and a mutating
  operation that receives **no** command identifier MUST be refused rather than assigned a
  server-generated one. The reason a server-generated fallback is forbidden is specific rather than
  stylistic: it makes a replay arrive under a different key, so the store has nothing to match and the
  idempotency wrapper silently degrades into "apply twice" — the request is accepted, and the client
  cannot recognise the result as the same command. Replay safety composes with FR-042's atomicity: a
  recorded outcome MUST describe a state that was actually committed, never one that was rolled back.
  (Added 2026-10-02 to close the gap recorded under **Command Result**:
  `contracts/ocs-api.yaml:767-772` marks the header required on every mutating operation and the
  schema, persistence layer and roughly a dozen tasks implement replay, but no requirement stated the
  rule, leaving implemented business behavior untraceable to a requirement. T027, T317 and T318 are the
  delivered implementation; T134's traceability range covers it.)
- **FR-085**: A decision submitted for a transaction whose current state no longer accepts it MUST NOT
  overwrite the decision already recorded. **First write wins.** The later request MUST apply no
  business effect: no decision recorded, no revision created, no workflow transition, no balance
  movement, and no audit event representing the attempted decision as having taken effect; and the
  client MUST be answered `409 Conflict` naming that a decision is already recorded and that it must
  reload to see the current outcome. The attempt MUST still be recorded in audit under the canonical
  event type **`SUPERSEDED_ATTEMPT`** — its own event type, neither an approval nor a rejection —
  naming the transaction, its active revision, the stage, the actor, the time, the decision attempted,
  and the decision already in force, so that a reviewer who acted leaves a trace without that trace
  falsifying the decision history. "No longer accepts it" covers both a decision already recorded against the same active
  revision and stage, and an active revision that changed underneath the in-flight request. This
  requirement complements rather than overlaps FR-084: a **replay** is the same command under the same
  `X-PettyCash-Command-ID` and returns the first outcome, whereas a **superseded attempt** is a
  different command issued against state that had already changed, which no command identifier can
  detect. The canonical event type is `SUPERSEDED_ATTEMPT`, and `contracts/ocs-api.yaml`'s
  `AuditEvent.eventType` enumeration carries it as a member rather than leaving the field open. **This rule
  is evaluated independently per transaction (Clarified 2026-10-08).** Every decision in V1 applies to
  **exactly one transaction** — no operation decides several in one request, and a request naming more than
  one MUST be refused rather than applied to each in turn (FR-033, with the V2 deferral recorded in FR-070) —
  so a decision on one transaction is **unaffected** by a concurrent decision on another and no request
  spans two. Each decision carries its own outcome, its own audit event, its own `SUPERSEDED_ATTEMPT` where
  it loses, and its own FR-084 command identity. (Added 2026-10-07: `data-model.md` carries
  `lock_version` on the mutable aggregates expressly
  "for optimistic checks", but no requirement stated the behaviour, so the mechanism existed with no
  specified outcome while two correct-looking financial events could race. The per-transaction scope was
  added 2026-10-08: first-write-wins answers a reviewer whose single decision lost and says nothing about a
  reviewer holding **two** approvals when a second manager decides the first underneath them, so a bulk
  operation would have needed a partial-application rule invented for it against FR-042's atomicity — all-or-
  nothing across a reviewer's queue, or a bulk write that is not atomic. Neither is obviously right, and V1
  does not need to choose.)
- **FR-087**: Every unexpected fault — any `5xx` response — MUST be recorded through **Nextcloud's
  standard technical logging** (Clarified 2026-10-08), naming the acting user, the request route, the
  `X-PettyCash-Command-ID` where the caller supplied one, the public identifier of the affected sheet,
  transaction, account, or evidence item, and the exception class. **"Where one is known" is defined
  mechanically rather than left to judgement (Clarified 2026-10-08): the identifier MUST be recorded on
  every operation that addresses exactly one record by identifier** — `/sheets/{sheetId}`,
  `/transactions/{transactionId}`, `/evidence/{evidenceId}`, `/evidence-links/{linkId}` and the account
  and configuration routes — **and MUST be omitted on an operation that addresses no single record**, such
  as a list, a dashboard, or a creation. The line is not "is this identifier available to the code" but
  "does this operation name one record": on the first group it always does, so "where known" is always
  satisfied; on the second it never does, so omitting the field is correct rather than a gap. **The response
  MUST NOT carry the fault detail recorded in the log** — not the exception message, a stack trace, a
  file path, or a connection string — and MUST carry only the uniform envelope FR-083 defines. The
  command identifier is required here because it is the join key that ties a fault to FR-084's recorded
  command outcome and to FR-085's superseded attempt. A correctly refused request — any `4xx`, including
  FR-085's `409` and FR-040's and FR-083's `422` — MUST NOT require a technical log entry, because the
  response already states the refusal and its reason to the caller; and technical fault logging MUST
  NOT be recorded in, or substituted for, the business audit streams FR-051 and FR-056 define.
  (Added 2026-10-08: no requirement stated what a fault must record — FR-056 only required the three
  record streams to stay distinguishable and `contracts/workflow.md:127` referred to "sensitive detail"
  without naming it — so an implementation could satisfy every requirement while logging nothing
  diagnosable, and FR-073's `5xx` figure could then be reported as met on numbers nobody could act on.
  **Recorded as known-partial on delivery (2026-10-08):** the identifier channel was built and tested but
  **no call site populated it**, so the identifier appeared on no fault. Under the definition above that is
  a shortfall against this requirement, not a satisfied reading of it, and it is closed by supplying the
  identifier on the record-addressing operations rather than by re-reading this sentence more generously.)
- **FR-088**: Saving a draft transaction MUST return the purchaser to the detail view of the sheet that
  transaction belongs to, where the sheet's status, its transactions, and its Submit control are
  presented together. A surface that lists a sheet's transactions MUST NOT be the destination of a save
  where the sheet cannot be submitted from it. **The purchaser MUST be able to reach the sheet's submission
  control from wherever a save leaves them, without reconstructing the journey from unrelated controls.**
  (Clarified 2026-10-09: no requirement stated where a save leads. US1's scenario 5 says a draft can be
  "completed later" and scenario 6 has the purchaser submitting the sheet, so both presuppose a path
  between the two, but FR-018, FR-022 and FR-023 constrain only that the save be possible and that
  submission validate — not that the two are reachable from one another. Measured: the post-save
  navigation landed on the transactions list, which renders no Submit control, no sheet status, and no
  route back to the sheet, so a correct save left the sheet unsubmittable from where the app put the
  purchaser. The transaction list is retained as a readable view and keeps its own Add control; what this
  requirement forbids is its being the **terminus** of a save.)
- **FR-077**: PettyCash MUST remain database-portable across the declared V1 database backends:
  MySQL/MariaDB, PostgreSQL, and SQLite. The MariaDB development environment is not an
  application-level dependency, and behavior MUST remain equivalent across the declared backends.
  The declared V1 backends are the Nextcloud database types `mysql` (MySQL 8.0/8.4 and MariaDB
  10.6/10.11/11.4/11.8), `pgsql` (PostgreSQL 14/15/16/17/18), and `sqlite` (SQLite 3.24+, declared
  for compatibility verification rather than as a production recommendation), verified against the
  Nextcloud 34 supported-backend documentation. Oracle/`oci` is outside V1 scope and MUST NOT be
  declared as supported unless it is explicitly added later as an Enterprise compatibility target.
  Application persistence and schema change MUST NOT depend on backend-specific SQL, functions,
  types, identifier-length or auto-increment assumptions, `GROUP BY` leniency, row-limiting delete
  behavior, or any other vendor-specific extension, so that equivalent behavior is achievable on
  every declared backend. The declared backend set MUST be declared explicitly rather than left
  implied, and the existing MariaDB development and Docker infrastructure MUST remain unchanged
  while any additional backends used for verification remain independent of it. A passing result on
  MariaDB alone MUST NOT be treated as evidence of cross-database compatibility. The verification
  mechanism, supported-backend declaration, and portability rules are decided in the plan.

#### Schema Ownership and Migration Scope

- **FR-078**: Every V1 persistent table MUST use the logical app-scoped name prefix `pettycash_`.
  The instance database prefix is applied by the Nextcloud database abstraction, and application
  code, migrations, tests, fixtures, and documentation MUST NOT hardcode a specific instance prefix
  such as `oc_`. Tables already present in the development schema under a non-app-scoped `pc_` name
  are provisional V1 development schema rather than legacy data, and MUST be renamed into the
  `pettycash_` namespace; they MUST NOT remain part of the V1 baseline.
- **FR-079**: Every V1 index, unique constraint, foreign key, and check constraint MUST be named
  `pettycash_<table>_<subject>_<kind>`, where kind is one of `idx`, `uniq`, `fk`, or `chk`, so that
  no schema object can collide with another application in the shared database. Identifiers MUST
  remain within the portable identifier limits of the declared V1 backends and MUST be abbreviated
  only when a full descriptive name would exceed those limits.
- **FR-080**: V1 storage MUST be created from scratch on a clean installation. PettyCash MUST
  provide no import, migration, table preservation, or data-compatibility contract for any previous
  PettyCash installation or database. Tables left behind by a discarded prototype in a shared
  development database are unmanaged artifacts that V1 MUST NOT read, write, migrate, import, rename,
  or drop, and their presence MUST NOT be treated as a V1 defect or a trigger for any V1 action.
- **FR-081**: Schema-change acceptance MUST cover clean installation on an empty database and the
  forward application of future V1 migrations in version order. It MUST NOT require, and MUST NOT be
  evidenced by, an upgrade path from any pre-V1 database. Resetting the migration state recorded for
  the `pettycash` application ID is development-environment work only: it requires explicit human
  approval, affects PettyCash only, and MUST NOT delete Docker volumes, the shared Nextcloud
  database, or any unrelated application data.

### Key Entities

- **Petty-Cash Account**: A user's balance and movement history for one currency; receives charges
  and approved expense consumption.
- **Petty-Cash Movement**: A dated charge or approved consumption with amount, currency,
  description, and resulting balance.
- **Expense Sheet**: A purchaser-owned grouping of transactions with one currency, a source
  petty-cash account, aggregate status, key dates, and summary amounts.
- **Expense Transaction**: The smallest review and decision unit, containing the expense date,
  amount, description, cost center, expense type, evidence, status, and active revision.
- **Transaction Revision**: An immutable version created when a submitted transaction is corrected;
  prior revisions and decisions remain available.
- **Cost Center**: An organizational responsibility area with identity, validity, one cost center
  manager, and one senior manager.
- **Expense Type**: A managed category used for classification and reporting, including any
  evidence requirement.
- **Evidence**: A file associated with a transaction and revision, with metadata, uploader,
  active state, and change history.
- **Workflow Decision**: An approve, reject, or return outcome recorded for a transaction at a
  specific stage by an authorized actor, including explanation where required.
- **Business Audit Event**: An immutable record of an official workflow or business-data event,
  including actor context and before-and-after values.
- **Configuration Audit Event**: An immutable record of a sensitive administrative configuration
  or access change.
- **Role Assignment**: A user's role and responsibility scope, including cost center or finance
  responsibility where applicable.
- **Currency**: A supported currency code with its configured minor-unit scale, against which every
  amount in the system is interpreted and displayed. The scale is not only a rendering setting: it
  **determines what every stored amount means**, because amounts are whole minor units read against it.
  That is why FR-023 refuses to change a currency's scale once amounts exist in it rather than treating the
  scale as free configuration — a scale change would reinterpret every balance, total and movement in the
  currency, and V1 has no migration that could make that safe. Corrected 2026-10-08: this entry previously
  described the scale as a "precision rule" rejecting amounts with too many decimal places, which is a state
  no value can be in, since an integer in minor units always carries exactly the scale's precision.
  Referenced by FR-008 and FR-020
  (the default rial account) and by the presentation rules that render an amount at its own scale.
  Added 2026-10-01: this is one of three entity families the schema has always had and this list
  omitted. It listed 12 against `plan.md`'s 15 persistent entity families and the 15 `pettycash_*`
  tables; adding Currency, Public Evidence Link and Command Result brings this list to **15**, so the
  two artifacts and the schema now agree.
- **Public Evidence Link**: A revocable, optionally-expiring share token for one evidence item,
  stored as a hash rather than in the clear, disabled by default, with per-access audit written to
  configuration audit. Required by FR-031 and FR-055/FR-056's configuration-audit scope. Added
  2026-10-01, for the same count discrepancy as Currency. Amended 2026-10-07: the link grants
  **unauthenticated** access — the token is the whole authority, the consumer holds no role or scope,
  and each access records the viewer as `anonymous` with timestamp and IP, which is what FR-031's
  "viewer UID" wording is now required to mean on this path.
- **Command Result**: The recorded outcome of an applied mutating command, keyed by the
  client-generated command identifier, so that a repeat of an already-applied command returns the
  first outcome instead of applying a second business effect. Added 2026-10-01 for the same count
  discrepancy as Currency.
  **The gap this entity had is closed.** On 2026-10-01 this entry recorded that the entity had **no
  owning functional requirement** — `contracts/ocs-api.yaml:767-772` marks `X-PettyCash-Command-ID`
  required on every mutating operation and the schema, persistence layer and roughly a dozen tasks
  implement replay, but no FR stated the rule, so implemented business behavior was traceable to
  tasks but not to a requirement. **FR-084** now states it, and T134's range reads FR-001–FR-084. The
  requirement was added *before* the range was widened, not after: widening first would have produced
  a traceability matrix citing a requirement nothing defines, which is the same defect that range was
  widened twice for previously. Adding FR-084 moved the count from 83 to 84, which is also the change
  `plan.md`'s Scale/Scope now records.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In acceptance testing, 100% of complete sheets enter the first review stage and 100%
  of incomplete or empty sheets are rejected with actionable validation messages.
- **SC-002**: Test users in every role can complete their primary workflow journey without seeing
  or changing any record outside their assigned ownership or responsibility scope.
- **SC-003**: 100% of tested approve, reject, return, correction, and finance outcomes follow the
  permitted state transitions and preserve all earlier decisions.
- **SC-004**: Every tested correction creates exactly one new active revision while all prior
  revisions, decisions, and recorded changes remain viewable to authorized users.
- **SC-005**: Every tested final finance approval creates exactly one matching account-consumption
  movement and balance change; forced failures produce zero partial financial or workflow effects.
- **SC-006**: 100% of official events in the acceptance workflow appear in chronological history
  with the actor, role, stage, time, event, explanation, and applicable before-and-after values.
- **SC-007**: Reports and exports for every role contain all permitted selected records and zero
  records outside that role's access scope across the acceptance dataset.
- **SC-008**: At the V1 operating scale of up to 100 active users and 10,000 annual transactions,
  at least 95% of routine list, detail, history, and evidence views become usable within 3 seconds
  under normal operating conditions. A view counts as **usable** when it has finished loading and is
  showing the rows that user is entitled to see, with no error or empty state standing in for them; it
  is measured end to end as a user sees it, not as an internal timing. **Verification of this
  criterion is deferred to post-V1.** The 3-second target remains the V1 design target and governs
  how queries are built, but the measurement at the stated scale is **not** a V1 acceptance gate and
  V1 release readiness **MUST NOT report this criterion as met**. The criterion MUST be recorded at V1
  release readiness as **not measured**, naming post-V1 verification as the reason, on the same
  principle already applied to SC-010: a criterion that was never measured is neither satisfied nor
  omitted. Where a V1 run does measure latency — for example the optimisation pass T128 performs —
  its result is recorded as **informational** and MUST NOT be presented as satisfying this criterion,
  because a run below the stated scale, or at the stated scale without the acceptance procedure, is
  not the measurement this criterion describes. (Clarified 2026-10-02: the criterion previously stated
  that the measurement "is performed during V1 rather than deferred" and "MUST be reported as met or not
  met", while T304 offered deferral as an equal option, so the criterion and its own verification
  contradicted each other and V1 release readiness had no settled answer to record.)
- **SC-009**: All primary purchaser, manager, senior manager, finance, and administrator journeys
  can be completed in Persian RTL at desktop and mobile viewport sizes in each supported browser.
  **Each primary journey MUST additionally be completable using the keyboard alone**, with a visible
  focus indicator on every interactive element reached, in all three browsers at both viewport sizes,
  under the host's default, dark, and high-contrast themes — a focus indicator that the active theme
  renders as absent does not satisfy this criterion. **A confirmation dialog inside a primary journey
  MUST move focus into itself when it opens and return focus to the control that opened it when it is
  cancelled or completed** (Clarified 2026-10-08, with FR-086's delete control): User Story 1 is a primary
  journey, so a dialog a keyboard user cannot enter, operate, or leave fails "completable using the
  keyboard alone" even though every individual control inside it is focusable. This is not one of the items
  FR-076 defers — it is what this criterion already required of the journey once a dialog became part of
  it. Screen-reader semantics are outside V1 (FR-076)
  and MUST NOT be reported against it. (Added 2026-10-07: keyboard operability had no measurable
  criterion while `quickstart.md` told testers to check it.)
- **SC-010**: In moderated acceptance testing, at least 90% of representative users complete their
  primary role journey on the first attempt without facilitator intervention. The result MUST be
  measured and recorded, and this criterion MUST NOT be reported as met by default. When
  representative users or a facilitator are unavailable, it MUST be recorded explicitly as **not
  measured**, naming the reason, rather than treated as satisfied or omitted from the review. It is
  not a blocking release gate; its purpose in V1 is to make both the figure and its availability
  visible, on the same principle already applied to SC-008. No automated test can produce this number,
  so no task is expected to assert it; the release-readiness review owns recording the outcome,
  whichever way it falls. (Clarified 2026-09-30: this was the only one of the fourteen success criteria
  with neither an automatable proxy nor a task referencing it, so the review would otherwise have had
  nothing to check it against. It is distinct from SC-002, which measures the same journeys under
  fixture accounts and is automated.)
- **SC-011**: 100% of completed and completed-with-rejections sheets reject attempts to add or edit
  business records while remaining fully available for authorized viewing and reporting.
- **SC-012**: Disabling a test user leaves 100% of that user's historical actor identity, roles,
  decisions, revisions, and audit events unchanged and accessible to authorized reviewers.
- **SC-013**: V1 database-portability acceptance succeeds only when the schema/migrations and the
  defined persistence/workflow compatibility suite complete successfully against every declared V1
  database backend, with no backend-specific functional deviation.
- **SC-014**: A clean installation on an empty database creates 100% of V1 tables under the
  `pettycash_` prefix with 0 non-app-scoped PettyCash tables remaining, 100% of V1 indexes, unique
  constraints, foreign keys, and check constraints carry `pettycash_*` names, and applying the full
  V1 migration chain in version order on a clean database succeeds on every declared V1 backend.
- **SC-015**: 100% of unexpected faults (`5xx`) returned by V1 endpoints during acceptance testing
  have a corresponding technical log entry recorded through the platform's standard logging, naming
  the actor, the route, the command identifier where one was supplied, the affected public identifier
  **on every operation that addresses exactly one record by identifier**, and the exception class;
  **0** such responses expose an exception message, stack trace,
  file path, or connection string in the response body; and 100% of deliberate `4xx` refusals state
  their reason in the response itself, without requiring a technical log entry. (Added 2026-10-08:
  observability had no measurable outcome — FR-087 now owns the rule, and without a criterion the
  acceptance run had nothing to check the `5xx` entries FR-073 counts against. "Where known" was made
  mechanical in the same session: a criterion that says "where known" without saying who decides is not
  measurable, and the delivered implementation recorded the identifier on no fault at all.)
- **SC-016**: 100% of the error codes the application emits during acceptance testing appear in
  `contracts/ocs-api.yaml`'s `Error.code` enumeration, with **0** emitted codes absent from it, and **0**
  enumeration entries for a code the application cannot emit. (Added 2026-10-08: FR-083 now requires the
  vocabulary to be closed and published, and without a criterion nothing would notice a new code shipping
  ahead of the contract — which is precisely the "rule with no observable consequence" this same session
  had just identified in FR-087's identifier clause. The check is stated **two-way** on purpose: an
  enumeration that grows to cover codes nothing returns is as wrong as code emitted outside it, and only a
  two-way check catches it.)

## Assumptions

- The requirements set is version 1.1; this feature specification consolidates its currently stated
  rules. Decisions still open are named where they occur rather than resolved silently.
- V1 uses the organization's existing authenticated user directory, file service, and platform
  security controls; account creation, passwords, login policy, and infrastructure events are
  outside PettyCash business scope.
- The confirmed V1 transaction fields are expense date, positive amount, description, cost center,
  expense type, and related evidence. Type-specific custom fields are future scope.
- Finance records its decision and explanation in V1; additional finance-only business fields and
  a separate post-settlement correction process are future scope unless added through clarification.
- V1 permits authorized multi-role users to decide their own transactions; role, responsibility,
  workflow-stage, and audit requirements still apply to every decision.
- All finance officers share one finance-stage work queue in V1; scoped finance assignment is
  future scope.
- Headline sheet totals follow the latest effective transaction outcome, while separate stage
  totals preserve visibility into differing manager, senior-manager, and finance decisions.
- Anyone permitted to view a transaction may view its evidence. More granular restrictions for
  special evidence categories are future scope.
- The dashboard's detailed visual layout and table arrangement will be decided during design; the
  information and actions specified here are required regardless of layout.
- V1 has no automatic escalation, scheduled export, automatic report delivery, standalone mobile
  application, independent backup/restore product, or active multilingual interface.
- The 3-second routine-view target is a planning baseline chosen to make the documented requirement
  for acceptable normal-use performance measurable.
- PettyCash V1 is a fresh application. Any previous PettyCash installation, its database, and any
  development data produced by the discarded prototype are reference material only; no supported
  import, migration, or data-compatibility path from them exists or is planned (FR-080).

## Dependencies

- Approved organizational assignments for cost centers, managers, senior managers, finance
  responsibility, supported currencies, and expense types.
- Availability of the host organization's authenticated users, file storage, technical logging,
  and backup facilities.
- The host platform's file service, its antivirus capability where an administrator has enabled it,
  and its standard technical logging — the three facilities to which FR-074 delegates evidence
  content-type acceptance and content scanning, and on which FR-087 depends for fault
  diagnosability. V1 depends on none of them for a decision the specification could otherwise have
  made itself, and adds no second mechanism alongside any of them.
