# Contracts: Purchaser UI Redesign

**Feature**: `005-purchaser-ui-redesign` | **Date**: 2026-10-19 | **Amended**: 2026-10-24 (findings A–E), 2026-10-25 (UI freeze / OPEN-sheet edit; evidence-status presentation implemented; compact uploader owner-authorized), 2026-10-26 (T075 save-action refinement: single footer `submit-btn`; correction keeps `transaction-save-draft`; shared Create/Edit Sheet modal C-23 authorized; T079 F1–F8 documentation findings resolved and not implemented, T080 blocked on the `BE-CR-001` backend delivery); 2026-10-27 (T079 B1/B2 acceptance corrected: authoritative post-create list with preserved store semantics; read-only/disabled currency visual sequence; T079 implemented, minimally verified, and `DONE_VERIFIED` under explicit owner visual approval; `BE-CR-001` immutable-account policy decided by the owner — documentation only, backend delivery outstanding and `T080` still `BLOCKED_BY_BACKEND`); 2026-10-28 (`BE-CR-001` `T438`–`T441` delivered and verified, `T041` closed; `T080` still not implemented and awaiting its own implementation authorization and owner visual gate); 2026-10-29 (`T080` implemented and minimally verified, then explicitly owner-approved the same day and `DONE_VERIFIED`) | **Implemented**: 2026-10-25 (`G-11`/`G-12`; `T076` owner-approved; `T075` refined, reviewed in `iteration-2`, and owner-approved 2026-10-26); `T079` implemented and owner-approved 2026-10-27; `BE-CR-001` `T438`–`T441` delivered and verified 2026-10-28; `T080` implemented and owner-approved 2026-10-29, `DONE_VERIFIED`

The management baseline changes **no** OCS operation, no OpenAPI schema, no workflow contract, no authorization rule, and no persisted structure. The owner directive 2026-10-23 adds a bounded exception for `BE-CR-004`: the `TransactionSummary.evidenceRequired` field, the conditional submit/resubmit receipt rule, the per-transaction `422` field errors, and a debug-gated fixture command. The 001 contracts (`specs/001-pettycash-v1-core/contracts/ocs-api.yaml`, `contracts/workflow.md`) remain the authoritative API/workflow documents; those specific amendments are recorded there and are not restated as new domain rules here.

The **2026-10-24 findings A–E amendment** adds C-14…C-18 for deletion acknowledgement/replay, the transaction surface and capability matrix, evidence preview, the draft-history boundary, and loading isolation. It was implemented and verified on 2026-10-25 under `G-11`, with 001 `T436` under `G-12`. C-17's draft-history rule is **presentation-only**: 001 `T437` (`ADR-008`, pre-submission revision boundary) remains unapproved/unimplemented under the deferred `G-13`, and no 005 task claims that server boundary. All nine tasks (`T064`–`T072`) are checked with evidence in `tasks.md`.

The **2026-10-25 reconciliation** adds C-21 for evidence-status presentation. C-21 is implemented by `T076` and explicitly owner-approved on 2026-10-25 (`DONE_VERIFIED`); it changes no approved frozen behavior and leaves `T075` independent; `T075` is `DONE_VERIFIED` under the owner's 2026-10-26 approval.

The **2026-10-26 T075 save-action refinement** clarifies C-20. The non-correction form previously rendered two controls that performed the same save. They were not presentation-equivalent: `transaction-save-draft` is a secondary `type="button"` control with no loading indicator, while `submit-btn` is the primary `type="submit"` control with the loading indicator, the existing disabled conditions, Enter-to-save, and the existing save handler. The owner approved keeping `submit-btn` as the single footer save action — `Save draft` in CREATE mode and `Save changes` in EDIT mode for an existing `DRAFT` on an `OPEN` sheet — and requiring `transaction-save-draft` not to render alongside it in those modes. Correction mode is not collapsed: `transaction-save-draft` persists a correction without resubmitting, and `Resubmit` posts the existing correction-resubmission transition, so both remain. The rule applies only to (A) and (B) and does not extend to (C), (D), or (E): the unsaved-changes dialog keeps its Save and Save-and-exit behavior, and the post-create evidence-upload retry is unchanged and must not issue a duplicate transaction save. A browser form submission is a save only; it is not a sheet submission or workflow resubmission, and saving an `OPEN`-sheet `DRAFT` never initiates approval. The exact scope is: (A) normal CREATE displays exactly one primary `submit-btn` labelled `Save draft`; (B) non-correction editing of an existing `DRAFT` whose owning sheet is `OPEN` displays exactly one primary `submit-btn` labelled `Save changes`; in both, `transaction-save-draft` is hidden. (C) Correction keeps distinct `transaction-save-draft` (persist without resubmitting) and `Resubmit`. (D) Submitted or otherwise non-editable transactions preserve their existing footer visibility, labels, and disabled controls. (E) A legacy `DRAFT` on a non-`OPEN` sheet preserves its existing behavior; editable OPEN-sheet behavior is never inferred from `DRAFT` status alone. The refinement does not authorize unrelated full-page or non-`OPEN` editing workflows, introduces no new editing permission, and a legacy host with different behavior preserves it and reports the boundary. It changes no transport, endpoint, status, workflow, authorization, or financial rule; `T075` is `DONE_VERIFIED` (2026-10-26): the refinement is implemented, the approved `iteration-2` review of both changed footers at desktop and 390px mobile is recorded, and the owner visually approved it.

The **2026-10-25 compact-uploader authorization** adds C-22 for the existing evidence uploader's presentation. It authorizes exactly one bounded, element-scoped task (`T077`), implemented on 2026-10-25 and explicitly owner-approved on 2026-10-25 (`DONE_VERIFIED`).

The **2026-10-26 shared Create/Edit Sheet modal authorization** adds C-23 for the existing sheet form's presentation and the create/edit entry points. It authorizes exactly two tasks: `T079` (Create Sheet modal and shared-form presentation) and `T080` (Edit Sheet integration). It changes no OCS operation, schema, authorization, financial rule, or persisted structure. `T079`'s F1–F8 documentation findings are resolved and its B1/B2 acceptance correction is recorded; it is implemented and minimally verified with official-Chrome evidence recorded, and it is `DONE_VERIFIED` under the owner's explicit visual approval of all eight screenshots (2026-10-27) together with the six-artifact same-change completion record. `T080` was additionally **blocked** by the account-identity gap recorded in C-10/C-23.5: at the time of this authorization, `SheetDetail` did not expose `accountId` and the update contract still accepted an account while the approved policy makes it immutable. The owner decided that immutable-account policy on 2026-10-27 (documentation only); the backend projection and behavior did not exist then. A read-only `accountId` projection alone is not sufficient. No `T080` implementation is claimed by this amendment; the later separate 2026-10-29 pass implemented it under the delivered backend, and the owner explicitly approved that implementation the same day (C-23.9).

The contracts below are between the **redesigned purchaser surfaces** and the **existing or amended** server payloads, plus the **management contract** this feature introduces for its own traceability. Every rule is either machine-checked by a named existing guard or explicitly marked as requiring owner evidence.

---

## 1. Presentation contract (UI ↔ existing payloads)

### C-01 — Direction is derived, never declared

1. Every purchaser view root declares `:dir="direction"` from `src/utils/direction.ts`; no purchaser view may contain a literal `dir="rtl"` or `dir="ltr"`.
2. `directionFor()` delegates to Nextcloud's `isRTL()`; a second RTL-language list MUST NOT be maintained.
3. Adding LTR support MUST NOT weaken any existing RTL assertion.

*Guard*: `tests/Frontend/Component/RtlAccessibility.spec.ts`, `tests/Frontend/localeFormatting.spec.ts`.

### C-02 — One formatting path per value kind

1. Dates render through `src/utils/date.ts`; missing renders `—`; unparsable renders the raw value; the same stored value renders identically in a list and a detail.
2. Money renders through `src/utils/money.ts` on minor-unit arithmetic with the payload's currency; no float math, no symbol invention, no conversion.
3. Numbers render through `src/utils/number.ts` with the active locale's digits and grouping.
4. Any new `toLocale*` call site outside these modules is a contract violation.

*Guard*: `tests/Frontend/localeFormatting.spec.ts` (13 tests, verified 2026-10-19).

### C-03 — The calendar preference is display-only and inert

1. The preference (`pettycash.calendarPreference`) MUST NOT be read by any store, API module, or type that builds a request payload.
2. Changing it MUST re-render every mounted formatter without a reload and without a network request.
3. No visible control to change it is authorized by this feature (`FR-005-004`); the mechanism may exist without a toggle.
4. A mutating negative test (identical payloads under both preferences) is required before the card can be called complete (`T018`, outstanding).

*Guard*: `localeFormatting.spec.ts` for (1)–(2); the live surface and the negative test are **not** covered yet.

### C-04 — The sheet list columns are closed

1. Exactly six columns, in order: Sheet, Status, Transactions, Recorded Amount, Latest Approved Amount, First Submitted (`FR-005-006`).
2. `FR-062`'s stage-approved figures remain present, each with its own label and its own cell; a strict six-column list requires an explicit FR-062 amendment (recorded, not adopted).
3. Recorded Amount = `totals.current`; Latest Approved Amount = `totals.effectiveApproved`; never-submitted → `—`; genuine zero → `0`.
4. No Creator, Owner, Currency, action, or invented period column may be added.
5. Status labels come from the catalogued vocabulary with design-token variants; raw enum identifiers MUST NOT be the intended user-facing label.

*Guard*: `tests/Frontend/Component/PurchaserFlow.spec.ts` (137 tests, verified 2026-10-19); `tests/Frontend/unrenderedColumnFallback.spec.ts`.

### C-05 — One action menu per row, gated by status

1. Exactly one overflow trigger per row; its accessible name contains the sheet title.
2. An `OPEN` row offers the approved actions; every other status offers only what the server permits.
3. A destructive action keeps the existing confirmation and MUST issue no request before confirmation.

*Guard*: `PurchaserFlow.spec.ts`, `tests/Frontend/Component/SheetDeletion.spec.ts` (9 tests, verified 2026-10-19).

### C-06 — OPEN-first ordering is a server capability

1. The client MUST NOT sort only the loaded page and MUST NOT claim the ordering requirement is met.
2. A sort control MUST NOT ship before `BE-CR-002` is approved **and** its intra-OPEN ordering and tie-breaker questions are answered.
3. Until then, `UI-PUR-003` stays `BLOCKED_BY_BACKEND`; no client-side approximation of any kind.

*Guard*: review evidence (`PROPOSED_TASK_BACKLOG.md`, `BLOCKED_ITEMS.md` §2.1); no automated guard can catch a lying page boundary.

### C-07 — The workspace is a dialog over the list, and the route stays real

1. `role="dialog"`, `aria-modal="true"`, accessible name = sheet title, focus enters / is contained / returns to the invoking row.
2. The `sheet-detail` route remains addressable (refresh and deep links keep working — `FR-005-013`).
3. Escape closes unless a dirty inner form's guard decides otherwise.
4. At ≤640px: full-screen layer, no horizontal scroll at 390px, exactly one `[role="dialog"]` element after drilling into a transaction.
5. A transaction surface opened from the workspace obeys the same rule: it may be a workspace layer or the addressable transaction route, but at ≤640px it MUST NOT add a second `[role="dialog"]`, and focus MUST return to the invoking row (C-15).
6. A list refetch, transaction fetch, or mutation MUST NOT replace the workspace body with the full-page “Loading sheet…” state (C-18).

*Guard*: `SheetWorkspace.spec.ts`, `TransactionSurface.spec.ts`, `RtlAccessibility.spec.ts`, `fr071NarrowViewport.spec.ts`.

### C-08 — The History tab is an honest per-transaction aggregation

1. History is assembled from the existing per-transaction audit/revision endpoints; no sheet-wide audit endpoint may be requested or invented.
2. A decision value is read from the event's `after` payload; the literal string `DECISION` MUST NEVER be rendered (`D-10`).
3. The audit feed is paged honestly (bounded page + a further request); a truncated feed MUST NOT be presented as complete.
4. A visible disclaimer states that the view is a per-transaction aggregation, not a sheet-level audit record.

*Guard to be created with the surface.*

### C-09 — Row actions are a subset of the server's capability set

1. The transaction table renders from the server payload; no action or permission rule may be invented client-side.
2. The capability set comes from `GET /transactions/{id}` (`TransactionDetail.allowedActions`). `TransactionSummary` carries none and MUST NOT be used to decide an action (C-15).
3. Edit and Delete require the capability to be exactly `true` **and** the owning sheet's current status to be `OPEN`.
4. Correct requires `canCorrect === true` and the transaction's `AWAITING_PURCHASER_CORRECTION` state. It MUST NOT require `OPEN`, because a returned transaction lives in a `CORRECTION` sheet.
5. Review capabilities (`canApprove`, `canReject`, `canReturn`) are not purchaser actions and MUST NOT be rendered in the purchaser transaction surface.
6. Sheet state and transaction state are read independently; neither is inferred from the other. A refused action (`409`/`422`/`404`) triggers a refetch before another action, and a stale action disappears.
7. No purchaser edit/delete affordance is offered for a submitted transaction; no Correct affordance is offered outside a returned transaction.
8. The two published-but-unreachable transaction statuses must never be rendered (`D-07`).

*Guard*: `TransactionSurface.spec.ts` (new), `SheetWorkspace.spec.ts`, `PurchaserFlow.spec.ts`, `TransactionCorrection.spec.ts`.

### C-10 — Inline sheet edit never guesses the account

1. `accounts[0]`-style substitution is prohibited (`GAP-011`: silent repointing).
2. The account is immutable after creation; there is no account-changing half. The owner decided the immutable-account policy on 2026-10-27; 001 `FR-019`/`FR-021` are the normative source, and this contract records only the purchaser-surface obligations: an `OPEN` sheet's title alone may change; the account and currency are displayed read-only and preserved; a title-only PATCH omits `accountId`; any supplied `accountId` is refused before persistence with `fieldErrors.accountId`; an inactive stored account does not block the title-only update; corrupted references are reported, not repaired; and the public `accountId` is declared on `SheetDetail` only. The prior replacement policy and its feasibility conflict are withdrawn, and no second-account fixture or replacement UI branch exists. `T438`–`T441` are all delivered and verified (2026-10-28), and `T041` is `DONE_VERIFIED`; `T039`/`T080` await their own separate implementation authorization. A title-only PATCH remains the only contractually correct update.

*Guard*: review evidence; the blocked card carries the criterion.

### C-11 — Transaction authoring correctness

1. Cost-center preselect uses the most recently saved transaction's `activeRevision.createdAt` only if the center is still active; display order, array order, and expense date MUST NOT be used; an existing transaction in edit mode is never overwritten (`REC-05`).
2. The create route MUST allow the purchaser to select receipts before the first save. Saving accepts the FR-022 minimum or every optional draft field and never requires a receipt. Staged receipts are uploaded only after the transaction exists, through the existing evidence endpoint; an upload failure leaves the saved transaction in place, names the failed receipt, and allows retry. The edit route keeps the uploader for DRAFT/awaiting-correction; an uploader with an empty transaction ID fails closed (`FR-005-022/036`, `FR-089/090`).
3. A DRAFT offers exactly one delete affordance with a confirmation; cancel issues no request; confirm sends one `DELETE` with the current `expectedVersion` and one `X-PettyCash-Command-ID` (FR-025). A bodyless `204` is success (C-14). While the request is in flight the confirm control is busy and a second activation issues no second request. A retry after an unknown outcome reuses the same command id and `expectedVersion`; a same-id replay is success and a different-id `404` is already deleted/not found (C-14, C-15).
4. The two editable forms guard unsaved changes on cancel, Escape, in-app navigation, browser back, and tab close/reload; a pristine form does not prompt; Escape during the guard maps to Stay.
5. A 409 refetches, shows stored values, explains the conflict, and allows a retry with the fresh version; no field merge ever occurs.
6. Returned transactions use exactly one canonical correction path (the PATCH flow, `REC-04`); `expectedVersion` is non-zero; the dead correction wrapper that omits it is removed or repaired; the prior revision remains and resubmission restarts review at Cost Center.
7. Evidence attached to a draft MUST NOT block an authorized deletion; the server cascade removes the draft evidence rows and stored files. Evidence deletion remains effect-idempotent and header-free (C-14).
8. Delete and edit affordances opened from the transaction surface are gated by C-09; a delete is not offered from a non-OPEN sheet even if a stale payload says `canDelete`.

*Guard*: existing `PurchaserFlow.spec.ts`, `TransactionCorrection.spec.ts`, `http.spec.ts`; new cases added per card; draft-delete replay is pinned by 001 `T436`'s contract/integration tests.

### C-12 — Readiness states only server-enforced preconditions

1. Blockers: description, cost center, expense type, at least one transaction (`FR-005-027`), plus a missing required receipt when the server-derived `evidenceRequired` is true and active `evidenceCount` is zero.
2. A transaction whose `evidenceRequired` is false is never shown as receipt-blocked. A deactivated receipt does not count; `evidenceCount` is the active count.
3. The client MUST NOT infer the receipt rule from expense type names, local configuration, or a second copy of the rule; the values come from the transaction payload (`FR-092`).
4. The Submit control remains reachable; the server's `422` remains the backstop. Every `transactions.<transactionId>.evidence` field error is mapped to the named transaction in one summary, with focus moved to the summary or first named transaction (`FR-005-037`).
5. A submitted sheet renders no readiness surface; a zero-transaction sheet shows the FR-024 blocker and no Submit affordance.

*Guard to be created with the surface; T057–T058 and the browser evidence in T061.*

### C-13 — Development-only fixture data

1. The 25/30/35/40-transaction sheets are created only by the debug-gated fixture command/script in `T060`; they are not migration or startup data.
2. The fixture baseline is **25/30/35/40 transactions, 130 transactions and 66 receipts in total**. The fixture set is idempotent in its own namespace and uses application services where a service path exists; it does not write raw financial rows that bypass domain invariants.
3. The command refuses to run unless Nextcloud debug/development mode is enabled; no production user or normal console path may create the fixtures.
4. Fixture data is verification evidence, not a product feature, and is removed by its own cleanup path at the end of the acceptance run.
5. A mutating browser run MUST reseed or restore the fixture namespace **before and after** the run. The 41/31 transaction counts observed after T061 are residue from the run's own created transactions, not a product count and not a new fixture expectation. Product code and product counts MUST NOT be changed to match residue (C-18, `T071`).

*Guard*: `DevSeedSheetsCommandTest` asserts the debug refusal, namespace cleanup, idempotent reseed, and baseline shape; `T071` asserts the pre/post browser-run restoration.

### C-14 — Bodyless success and replay-safe deletion

1. A `204 No Content` from `DELETE /transactions/{id}` or `DELETE /sheets/{id}` is a **success with no body**. The client MUST NOT require an OCS envelope, synthesize one, or surface `pettycash.error.malformedResponse` for it.
2. A data-returning response with no valid OCS envelope MUST still be rejected. In particular, a `200` HTML login page or reverse-proxy page is not success.
3. Only an operation that declares a bodyless success may accept `204`; a data-returning call MUST NOT silently receive `undefined` as its payload.
4. The transaction delete carries one `X-PettyCash-Command-ID` per delete intent and the current `expectedVersion`. A retry after an unknown outcome reuses both. Under 001 `T436`, the same id and payload returns the original `204` after the row is gone; a different id after success returns `404`.
5. `expectedVersion` remains a concurrency guard, not a replay guard. A stale version still returns `409`; a non-`DRAFT` transaction or non-`OPEN` sheet still returns `422`.
6. Evidence attached to a draft does not block an authorized deletion; the cascade removes its draft evidence rows and files. Evidence deletion remains effect-idempotent and header-free.
7. A delete confirmation issues exactly one request while in flight. A `404` after a confirmed success is presented as already deleted/not found, not as a second success or an unhandled failure.
8. The client-side handling is 005 `T064`. The replay-gated server contract and backend are 001 `T436`; both are complete, and `T065` consumed `T436`'s evidence before closing.

*Guard*: `tests/Frontend/http.spec.ts` (204 success and malformed-200 rejection), `PurchaserFlow.spec.ts`/`SheetDeletion.spec.ts` (one request per confirmation), and 001 `T436`'s contract/integration tests (replay matrix).

### C-15 — Transaction surface and capability matrix

1. A transaction row action opens a surface backed by `GET /transactions/{id}` (`TransactionDetail`); the list row summary is not a detail payload and is not a capability source.
2. The surface reads `allowedActions` from that detail. It MUST NOT derive an action from `TransactionSummary`, a local status check, a role name, or a prior view's cached capability.
3. The action matrix is:

   | Action | Capability | Additional server state | Notes |
   |---|---|---|---|
   | Edit | `canEdit === true` | owning sheet `OPEN` | DRAFT only, by the server projection |
   | Delete | `canDelete === true` | owning sheet `OPEN` | DRAFT only; C-14 governs the command |
   | Correct | `canCorrect === true` | transaction `AWAITING_PURCHASER_CORRECTION` | sheet is `CORRECTION`; do **not** require `OPEN` |
   | Approve / Reject / Return | review surfaces only | review stage and policy | not a purchaser action |

4. Sheet state and transaction state are fetched/read independently. The UI MUST NOT infer transaction permission from sheet status or sheet permission from transaction status.
5. A `409`, `422`, or `404` from an action triggers a transaction and sheet refetch before another action. A stale action MUST disappear after the refetch; the UI MUST NOT keep an action visible after the server has refused it.
6. A `404` detail load renders a not-found state with a way back/refresh; a network or malformed response offers retry. The workspace is not blanked and no write is issued while opening the surface.
7. The surface renders the returned active revision, status, version, evidence, and revisions without inventing values, and obeys C-07's one-dialog and 390px rules.

*Guard*: `tests/Frontend/Component/TransactionSurface.spec.ts` covers the matrix, 404, stale-refetch, and no-write-on-open; `PurchaserFlow.spec.ts`, `SheetWorkspace.spec.ts`, and `TransactionCorrection.spec.ts` keep the existing journeys green; `tests/E2E/t072-transaction-surface-evidence.mjs` covers the live matrix.

### C-16 — Evidence preview respects the inline-safe policy

1. Active evidence offers preview to an authorized viewer through `GET /evidence/{id}/content?disposition=inline`.
2. The preview renders only for the server's inline-safe set: `application/pdf`, `image/png`, `image/jpeg`, `image/gif`, `image/webp`, `image/bmp`, `text/plain` (parameters dropped, case-insensitive).
3. `image/svg+xml` is **not** inline-safe and is download-only. HTML, unknown, empty, and every other type are download-only. No iframe, object, image, or blob URL may render them in the Nextcloud origin.
4. `text/plain` renders as text. No `v-html`, `innerHTML`, or `dangerouslySetInnerHTML` may be used for evidence.
5. Non-inline-safe types offer download via `disposition=attachment` (or the existing download path) and MUST NOT show a broken preview.
6. A preview failure (`403`, `404`, network, unreadable body) shows an error, keeps download available, and allows retry/close.
7. The preview is a dialog with an accessible name equal to the file name, focus entry/return, Escape close, no horizontal scroll at 390px, and no second `[role="dialog"]`.
8. Object URLs are revoked on close/unmount. Preview never triggers a download or an upload. Inactive evidence keeps its metadata/history but offers no content preview unless the existing download policy explicitly permits it.
9. The client applies the server's closed set; it does not rely on browser content sniffing and does not add a type to the inline-safe set.

*Guard*: `tests/Frontend/Component/EvidencePreview.spec.ts` covers inline-safe, SVG/HTML refusal, download fallback, error, object-URL revocation, keyboard, and 390px; `RtlAccessibility.spec.ts` remains the RTL baseline; `T072` covers the live preview.

### C-17 — Draft history is not official history

1. A transaction whose status is `DRAFT` has never been submitted. The surface MUST NOT present a revision selector, revision tabs, a prior-revision list, or draft-stage audit events as official history. It shows one working-draft state without inventing a revision number.
2. The workspace History aggregation omits a DRAFT transaction's events from official history.
3. For a submitted transaction, official history begins with the submission. Legacy events whose `stage` is `DRAFT` and whose type is `REVISION_CREATED` or `TRANSACTION_CHANGED` are filtered from the official view.
4. A correction revision that has not been resubmitted is labelled as the working correction, not as a prior official revision. After resubmission it enters the official history.
5. Stored revisions and audit events are never deleted, rewritten, renumbered, or backfilled (001 FR-053). The filter is presentation-only.
6. "Never submitted" is derived from the explicit transaction status, not from the revision count or the presence of a DRAFT-stage event.
7. The server boundary is proposed in `ADR-008` and gated as 001 `T437`. The presentation change (`T069`) is complete and does **not** claim the boundary is implemented while `T437` is open. `ADR-008` is Proposed — not approved, not implemented.

*Guard*: `tests/Frontend/Component/DraftHistory.spec.ts` (DRAFT revision surface and legacy-event filter), `SheetWorkspace.spec.ts` (history filter), and the append-only assertions in 001's existing audit/revision suites. No migration or repository code changed.

### C-18 — Loading is per resource; a refetch does not replace loaded data

1. The sheet list, sheet detail, transaction detail, evidence, history, and each mutation have independent loading/error state. One shared boolean is prohibited.
2. The sheet workspace shows the full-page “loading sheet” state only when no sheet for the current id is loaded and a detail request is in flight. A list refetch, transaction fetch, evidence fetch, history fetch, or mutation MUST NOT trigger it.
3. A mutation exposes its busy state on the initiating control or region and does not unmount loaded data.
4. Concurrent requests are resolved by request identity/token or an equivalent mechanism. A late response from a superseded request cannot overwrite newer data or clear another request's loading state.
5. A failed background refetch keeps the last loaded data and shows the error. The list shows its own loading state independently.
6. The 390px and RTL layout remains stable while loading; no horizontal scroll is introduced by a loader.
7. The mutating browser procedure reseeds the fixture namespace before and after the run. The 25/30/35/40 baseline (130 transactions / 66 receipts) is restored; the 41/31 residue is not a product expectation (C-13).

*Guard*: `tests/Frontend/Component/LoadingIsolation.spec.ts` simulates a list refetch and a mutation during a loaded detail, asserts the full-page loader is absent, and resolves a late superseded response; `SheetWorkspace.spec.ts`/`PurchaserFlow.spec.ts` keep the existing surfaces green; `T071` asserts the fixture restoration; `T072` captures a loaded detail during a list refetch.

### C-19 — Owner-approved surfaces are frozen

1. The Sheets List at `/apps/pettycash#/sheets` is frozen as approved: layout, table structure, column proportions, row density, pagination presentation, navigation, responsive behavior, RTL/LTR presentation, and approved visual styling.
2. The sheet transaction workspace is frozen as approved: overall page layout, existing tabs and tab organization, transaction-table appearance, existing sheet-level information, approved visual styling, and existing non-target interactions.
3. A change to any frozen aspect requires a separately authorized Spec Kit task. It MUST NOT be made under a freeze task, a refactor, a test fix, or an unrelated task.
4. The freeze is scope protection, not a security or correctness exemption. It MUST NOT be used to preserve a security defect, bypass server enforcement, or refuse an explicitly authorized defect fix.
5. `T074` records the baseline; it changes no application behavior and introduces no new status vocabulary. Completed tasks remain completed and are not renumbered or re-opened.
6. Three element-scoped, explicitly owner-authorized exception **groups** exist (four tasks): `T075` (C-20, the `OPEN`-sheet eye-icon interaction and its save/delete/prefill behavior), `T077` (C-22, the evidence-uploader presentation inside the existing transaction editing form), and `T079`/`T080` (C-23, the Create/Edit Sheet modal interaction and presentation plus the shared sheet-form modal infrastructure). None is a general exception; none authorizes any other frozen aspect, and the existence of one does not broaden another.

*Guard*: source review of `T074`; `T075` is limited to C-20, `T077` is limited to C-22, and `T079`/`T080` are limited to C-23. The existing list/workspace regression guards stay in service but are not re-baselined by this contract.

### C-20 — OPEN-sheet eye icon opens the edit form directly

1. For an `OPEN` sheet, the transaction row eye icon opens the **editable** transaction experience directly when `canEdit === true`, the transaction is `DRAFT`, and the owning sheet is `OPEN`. It MUST NOT render the read-only transaction surface first and MUST NOT navigate to a full page.
2. The editable experience reuses the existing `TransactionFormView` and the existing `TransactionSurface` modal. A second competing form is prohibited.
3. The form pre-fills the transaction's persisted `activeRevision` values: expense date, amount, description, cost center, and expense type. Previously supported field behavior is retained; no new financial field or business meaning is introduced.
4. Active evidence is shown through the existing evidence API and `EvidenceList`. The user can view, preview only the server's inline-safe types, download, add when authorized, and remove when authorized. Preview reuses `EvidencePreview` and its safe content policy (C-16); the user does not leave the edit experience. For a never-submitted `DRAFT` on an `OPEN` sheet, C-21 governs the lifecycle presentation.
5. Editing, deleting, and evidence add/remove are available for an authorized `DRAFT` on an `OPEN` sheet. The existing authentication, ownership, `allowedActions`, `expectedVersion`, command-id/replay, confirmation, and evidence-cascade safeguards remain in force. No permission is broadened.
6. Save validates only the existing draft minimum (expense date and positive amount), persists through the existing update path, preserves `DRAFT`, preserves and re-associates existing evidence, applies additions/removals through the existing APIs, refreshes the transaction and owning sheet, and returns to the same sheet workspace. Save MUST NOT submit the transaction or sheet, start approval, or require a receipt; the receipt rule remains at submission/resubmission.
7. **One primary footer save action.** In normal CREATE mode and in non-correction editing of an existing `DRAFT` on an `OPEN` sheet, the non-correction form MUST expose exactly one primary save action in the editable transaction-form footer: the existing `submit-btn` control, labelled **`Save draft`** in CREATE mode and **`Save changes`** in EDIT mode for an existing `DRAFT` on an `OPEN` sheet. `submit-btn` MUST retain `type="submit"`, `variant="primary"`, its existing loading indicator, its existing disabled conditions, Enter-to-save, and the existing save handler. The secondary `transaction-save-draft` control MUST NOT be rendered alongside it in normal CREATE or non-correction OPEN/DRAFT EDIT mode. The rule applies only to (A) and (B); it does not extend to (C), (D), or (E). It does not remove or change the unsaved-changes confirmation dialog, which keeps its Save and Save-and-exit behavior. The post-create evidence-upload retry keeps its existing flow; it is not a transaction-save surface and must not issue a duplicate transaction `POST` or `PATCH`. Both labels MUST exist in all four existing locale catalogs (`l10n/en.json`, `l10n/en.js`, `l10n/fa.json`, `l10n/fa.js`) with key parity, and the CREATE-mode label MUST describe a save rather than a submission or approval. This localization scope is limited to those two `T075` save-label keys and does not close `REC-01` or authorize any other English-catalog work. Visual acceptance for this rule MUST use `artifacts/visual-review/t075/iteration-2/` in official Google Chrome (`channel: 'chrome'`) at desktop and 390px mobile viewports with Persian RTL verification, showing both (A) and (B) with exactly one visible primary action and zero visible `transaction-save-draft` controls, without overwriting `iteration-1`. The exact scope is: (A) normal CREATE MUST expose exactly one primary `submit-btn` labelled `Save draft`; (B) non-correction editing of an existing `DRAFT` whose owning sheet is `OPEN` MUST expose exactly one primary `submit-btn` labelled `Save changes`; in both cases `transaction-save-draft` MUST be hidden. (C) Correction MUST preserve distinct `transaction-save-draft` (`Save draft`; persist without resubmitting) and `Resubmit`, and neither capability may be removed by this refinement. (D) Submitted or otherwise non-editable transactions MUST preserve their existing footer visibility, labels, and disabled controls. (E) A legacy `DRAFT` on a non-`OPEN` sheet MUST preserve its existing behavior; editable OPEN-sheet behavior MUST NOT be inferred from `DRAFT` status alone. A browser form submission here is a save only: it MUST NOT be treated as a sheet submission or workflow resubmission, and saving an `OPEN`-sheet `DRAFT` MUST NOT initiate approval. This refinement does not authorize changes to unrelated full-page or non-`OPEN` editing workflows, introduces no new editing permission, and a legacy host that requires different behavior MUST preserve its existing behavior and report the boundary.
8. A failed upload is reported without claiming success or discarding the saved transaction. The post-create upload-retry flow is preserved; retrying evidence upload MUST NOT issue a duplicate transaction `POST` or `PATCH`. A conflict is handled by the existing refetch/explanation path, not by merging.
9. For every non-`OPEN` sheet, the current inspection behavior is unchanged: submitted/review/approved/completed sheets remain read-only, correction-stage inspection keeps its permission-aware correction path, and a legacy `DRAFT` on a non-`OPEN` sheet preserves its existing behavior. Submitted or otherwise non-editable transactions preserve their existing footer visibility, labels, and disabled controls; editable OPEN-sheet behavior MUST NOT be inferred from `DRAFT` status alone. No unauthorized edit, delete, evidence, or new editing permission is exposed.
10. The edit experience keeps one-dialog semantics at 390px: the workspace stays mounted, the edit surface is the active dialog, and an evidence preview follows the existing inert/nested-dialog rule rather than adding unnecessary dialogs.
11. Server confirmation that an ordinary draft edit returns and persists `DRAFT` is a separately authorized backend defect fix recorded in `.specify/bugs/open-sheet-draft-status/` (`TransactionService::updateTransaction()` attaches a working revision instead of activating review). It is supporting evidence only: `T075` is frontend-only, its browser runner does not save/delete/upload, its component guard mocks transport, and this contract does not re-implement or claim that backend fix. The bug's manual browser walkthrough was not repeated, so its test result remains `partial`.

*Guard*: focused frontend tests for the OPEN eye-click branch, the non-OPEN unchanged branch, persisted-data prefill, evidence list/preview, save/DRAFT behavior, delete visibility, and the save-action contract (exactly one primary footer action on `submit-btn`, `Save draft` in normal CREATE, `Save changes` in non-correction `DRAFT`/`OPEN` EDIT, no visible `transaction-save-draft` alongside it, distinct `transaction-save-draft`/`Resubmit` in correction mode, existing disabled/loading states and Enter-to-save, the unchanged unsaved-changes dialog, and the upload retry with no duplicate `POST`/`PATCH`); the existing submitted/non-editable `transaction-save-draft` disabled-state assertion at `tests/Frontend/Component/PurchaserFlow.spec.ts:2125` remains a disabled assertion, with negative/regression assertions for the submitted/non-editable footer, a legacy `DRAFT` on a non-`OPEN` sheet, correction's Save draft plus `Resubmit`, and both one-button cases; one official-Chrome interaction smoke test; a new `artifacts/visual-review/t075/iteration-2/` visual review that evidences both the CREATE (`Save draft`) and OPEN/DRAFT EDIT (`Save changes`) footers in official Google Chrome (`channel: 'chrome'`) at desktop and 390px mobile viewports with Persian RTL verification, each showing exactly one visible primary action and zero visible `transaction-save-draft` controls, and does not overwrite `iteration-1`.

### C-21 — Evidence lifecycle presentation follows transaction state, not sheet status alone

1. Ordinary evidence presentation for a **never-submitted** transaction on an `OPEN` sheet MUST NOT render `Active`/`Inactive` lifecycle badges or deactivation metadata. The ordinary attachment list shows only currently active attachments; after a confirmed authorized removal, the removed item is absent from that list.
2. Never-submitted status is derived from `transaction.status === 'DRAFT'` **and** the owning sheet's `OPEN` state. `OPEN` alone MUST NOT hide lifecycle presentation; a non-`DRAFT` transaction on an `OPEN` sheet keeps the 001 `FR-059`/`FR-060` evidence-state semantics, with C-16 still governing preview.
3. Authorized evidence additions and removals remain available (001 `FR-058`; C-11, C-20). This presentation rule changes no stored evidence, server lifecycle, evidence cascade, audit row, or authorization rule.
4. For correction workflows and every submitted transaction, inactive evidence keeps its metadata, deactivation history, and meaningful `Active`/`Inactive` presentation. Audit visibility and authorization remain unchanged (001 `FR-059`, `FR-060`).
5. No stored evidence is deleted, rewritten, renumbered, or backfilled by this rule. A draft removal follows the existing server behavior; C-21 does not redefine it.
6. `T076` implements this rule; implementation, minimal verification, and explicit owner visual approval are complete (2026-10-25), so the task is `DONE_VERIFIED`. It does not change any approved behavior or frozen surface.

*Guard*: `tests/Frontend/Component/EvidenceStatusPresentation.spec.ts` (created with `T076`, 9 cases); existing `EvidencePreview.spec.ts`, `TransactionSurface.spec.ts`, `OpenSheetTransactionEdit.spec.ts`, and `AuditEvidence.spec.ts` stay green.

### C-22 — The evidence uploader is compact, readable, and behavior-preserving

1. **Authorization and scope.** The product owner explicitly authorized, on 2026-10-25, one bounded presentation change: the existing evidence uploader **inside the existing transaction editing form only**. The approved scope is the uploader's presentation, the selected filename and size, the upload/remove controls, the responsive layout, and preservation of existing upload behavior. The implementation uses an optional `compact` boolean prop on the shared uploader, defaulting to `false`; compact mode is activated only from `TransactionFormView.vue`. `TransactionDetailView.vue` is not modified and its uploader retains the existing appearance and behavior. It is a second element-scoped exception to C-19 (alongside C-20), not a general exception.
2. The uploader presents a **compact, visually clear control** instead of an oversized upload area. It keeps the existing select/upload affordances and states the existing 100 MB limit. The existing shared uploader and upload path are reused; no second uploader or duplicated upload logic is introduced.
3. A selected file shows its **filename**, its **size**, and the **remove** and **upload** actions.
4. **Long filename at 390px.** A representative long filename MUST NOT wrap character by character, MUST NOT cause horizontal overflow, and the upload/remove controls MUST remain operable. The full filename MUST remain accessible on desktop and mobile. If the name is visually truncated, a usable way to inspect the full name MUST be provided that is operable by keyboard and touch, not by hover alone. Existing supported accessibility interactions MUST be preserved.
5. **Behavior preservation.** File selection, upload through the existing endpoint, the existing 100 MB validation and its stated refusal message, removal of a selected file, progress, and error handling MUST be preserved. The `compact` prop changes presentation only; upload logic, API, authorization, validation, persistence, and error handling remain single-source and unchanged. No upload request is issued without a selected file, a draft transaction, and a non-empty transaction ID; the empty-ID fail-closed behavior is unchanged. The existing locked-after-submission state and save-first state remain; attachment permissions, authorization, and lifecycle behavior are unchanged, and correct handling of a successful upload is retained.
6. **Presentation only.** This rule MUST NOT change the upload endpoint or transport, request shape, authorization, evidence persistence, stored evidence, the Sheets List, sheet transaction workspace layout, existing tabs, the transaction table, other transaction-form fields, the evidence-list lifecycle, or preview/download. It MUST NOT alter `T074`'s frozen list/workspace shell, `T075` eye-icon/save/delete/prefill behavior, or `T076` evidence-status presentation. The frozen workspace shell remains unchanged. `TransactionFormView.vue` is the only host that activates the opt-in prop; the uploader rendered by `TransactionDetailView.vue` retains its existing appearance and behavior, and `TransactionDetailView.vue` MUST NOT require modification.
7. **Browser evidence.** Acceptance evidence is captured in **official Google Chrome** through Playwright using `{ browserName: 'chromium', channel: 'chrome' }` at desktop and a 390px mobile viewport, **including Persian RTL (`fa`)**. It comprises one desktop screenshot and one 390px screenshot showing the selected filename, size, and operable controls, plus a representative long filename demonstrating C-22.4. Firefox, Edge, WebKit, bundled Chromium, and a comprehensive browser matrix are not part of this gate. Records live under `artifacts/visual-review/t077/<iteration>/`; the Chrome version is recorded with the record, and explicit owner visual approval is required before closure.
8. **Verification split.** Focused verification uses the new `EvidenceUploaderCompact.spec.ts` plus the existing `PurchaserFlow.spec.ts` uploader/flow assertions. It covers selection, the 100 MB refusal, removal, progress/failure, existing-endpoint compatibility, and successful-upload handling. Existing stable `data-test` hooks used by `PurchaserFlow.spec.ts` (`drop-zone`, `select-file-btn`, `upload-progress`) are preserved unless that spec is updated in the same task. Browser-only layout and long-filename assertions belong to the Chrome gate; jsdom results are **not** mobile-layout proof. A component mock is **not** backend-persistence proof: prefer existing integration evidence plus one safely isolated live upload against a disposable fixture with an explicitly verified cleanup that does not mutate production or valuable fixture evidence; reuse the existing upload-helper pattern in `tests/E2E/purchaser-submit.spec.ts` or `tests/E2E/audit-evidence.spec.ts` rather than inventing a new upload path, and record the request/response and cleanup. If safe live verification is unavailable, report the gap rather than claiming full verification. The focused spec MUST also assert the default `compact=false` presentation and that the `TransactionDetailView` uploader retains its existing appearance and behavior; `TransactionDetailView.vue` is not modified, and this is an explicit regression boundary. No live upload is performed by the documentation authorization itself.
9. **T075 relationship.** `T075` is `DONE_VERIFIED` under the owner's 2026-10-26 visual approval, with its original implementation and historical evidence preserved. `T077` changes only the uploader presentation within the `T075` editing experience; the opt-in prop does not alter the detail-view uploader or T075's non-uploader evidence. T077 screenshots supersede older T075 screenshots only for the uploader's visual presentation, and that presentation is not re-reviewed under T075 while the rest of T075's evidence remains in scope. Reviewing T077 neither closed nor reopened `T075`.
10. The separate mobile preview-header defect is a tracked follow-up; it is not fixed, accepted, or included by `T077`.

*Guard*: `tests/Frontend/Component/EvidenceUploaderCompact.spec.ts` (created with `T077`, 8 focused tests) plus the existing `PurchaserFlow.spec.ts` uploader/flow assertions, the `TransactionDetailView` uploader regression assertion (default `compact=false`, existing appearance/behavior; source file unchanged), the official-Chrome desktop/390px `fa`-RTL long-filename evidence, and the focused upload verification with a disposable fixture and verified cleanup. All of these exist and passed on 2026-10-25; `T077` is `DONE_VERIFIED` under explicit owner visual approval.

### C-23 — Create/Edit Sheet uses a shared Nextcloud-native modal

1. **Authorization and scope.** The product owner explicitly authorized, on 2026-10-26, one bounded interaction/presentation change: the Create Sheet and Edit Sheet experiences move from full-page forms to a shared Nextcloud-native modal over the mounted Sheets List. It authorizes exactly `T079` (Create Sheet modal and shared-form presentation) and `T080` (Edit Sheet integration). It is the third element-scoped exception to C-19, not a general exception. It does not authorize the Sheets List table, columns, filters, pagination, row density, navigation, the sheet transaction workspace, tabs, transaction table, transaction editing, evidence list/uploader/preview/download, or unrelated Batch B work. The 2026-10-26 analysis reconciliation resolves findings F1–F8 and the 2026-10-27 B1/B2 acceptance correction in the specification, plan, task ledger, this contract, `D-025`, and the completion report; it starts no implementation. The separate 2026-10-27 pass implemented `T079`, and the separate 2026-10-29 pass implemented `T080`.
2. **One form, one save path.** The existing single `SheetFormView` form implementation is retained and made modal-ready; a second form, duplicated validation, a new endpoint, or a new store action is prohibited. Create continues through `createNewSheet`/`POST /sheets`; the existing `createNewSheet` action and its store mutation semantics are preserved, and `src/stores/sheets.ts` is not modified. Edit continues through `updateExistingSheet`/`PATCH /sheets/{id}` with `expectedVersion`. `mutationLoading`, the existing error/conflict handling, and `useUnsavedChangesGuard` are preserved. No financial, authorization, ownership, currency, or persistence rule changes.
3. **Create Sheet and dismissal.** `/sheets/create` (`sheet-create`) remains addressable and renders the Sheets List with the modal open. The list stays mounted behind the modal. Modal visibility MUST derive from the route, so the address and the visible modal cannot diverge, and exactly one `[role="dialog"]` is mounted for the whole dismissal/confirmation flow. The dialog heading and the primary Create Sheet action both use the existing localized `sheets.create` label, giving the dialog a non-empty accessible name; the sheet-name field is labelled with the dedicated `sheets.name` key (`Sheet name` / `نام تنخواه`). The modal presents the account selector, the selected account's balance where the existing account payload supports it, the derived currency, and Cancel. Validation is unchanged: title required; account required only when accounts have loaded and at least one exists; a zero-account purchaser remains server-provisioned; a single account remains fixed; the balance is informational and never blocks submission. When no selectable account exists, the modal MUST NOT synthesize an account, render an unexplained empty/disabled selector, or show `undefined` text, an invalid balance, or a currency not derived from a real account; it shows the neutral `sheets.noAccounts` state, omits the balance/currency rows, keeps the title field and create action available, and omits `accountId` from the request so server provisioning and validation remain authoritative. Cancel, Escape, and the dialog close affordance all follow one controlled close path: a pristine form closes directly; a dirty form presents the existing unsaved-changes decision inside the same dialog, with no second `[role="dialog"]`, without modifying the shared `UnsavedChangesDialog.vue`, and without globally changing the shared guard composable's semantics. Using the guard's existing Save/Discard/Stay semantics, Stay restores the entered values and focus, Discard closes without creating, and Save retains save-and-leave behavior. After a successful create, the modal closes and the purchaser remains on the Sheets List with the active filters and pagination preserved where possible. The authoritative list is refetched through the existing fetch mechanism, and the final visible list reflects the server response rather than the existing `createNewSheet` action's temporary insertion; the action and store mutation semantics are preserved and `src/stores/sheets.ts` is not modified. A polite `sheets.createSuccess` notification is shown, the new sheet is not opened automatically, a sheet that does not match the active filter is not forced into the filtered view, and the frozen Sheets List visual presentation remains unchanged (only its authoritative data is refetched). Focused verification covers at least one filtered and one paginated post-create state and proves the final list reflects the refetched server response, not the temporary insertion.
4. **Edit Sheet.** `/sheets/:id/edit` (`sheet-edit`) remains addressable and renders the Sheets List/workspace with the shared edit modal open. The modal pre-fills the persisted title, displays the authoritative account and derived currency read-only, shows the supported balance, and exposes Cancel plus the primary action whose visible text is `Save changes` / `ذخیره تغییرات` (an existing catalog value is reused; no new English-catalog key is authorized). It respects the existing edit permissions and `OPEN`-only restriction; `SheetController::update()` and `SheetService::updateSheet()` remain authoritative. It preserves the existing `409` refetch-without-merge path, sends only `title` and `expectedVersion`, and does not submit the sheet or start approval. Saving or cancelling returns to the originating sheet workspace when the edit was opened from it, and to the list otherwise. The provenance mechanism, exact return target, non-`OPEN`/unauthorized deep-link behavior, and inactive-account display are implemented (2026-10-29) and covered by the recorded focused and official-Chrome evidence. `T039` remains the inline/workspace title edit; `T080` is the shared modal edit; neither is unblocked by `T079`. While the edit modal is open, `SheetDetailView` MUST NOT also mount a second dialog.
5. **Account-identity and immutable-update gate.** At the time of this contract, `SheetDetail` did not declare `accountId`, and the update path accepted an account while the approved policy makes it immutable. `T080` MUST NOT substitute `accounts[0]`, guess an account, send an empty value, send `accountId`, or silently change backend behavior. The owner decided the immutable-account policy on 2026-10-27; 001 `FR-019`/`FR-021` are the normative source, and this contract records only the payload/UI gate: `SheetDetail`-only `accountId`; `SheetSummary` and the list response unchanged; title-only omission preserves the current account (active or inactive) and currency; any supplied `accountId` is refused; and corrupted references are surfaced as data faults, not repaired. The `SheetDetail.accountId` projection (`T439`) and the immutable-update behavior (`T440`) are delivered, and `T441` closed their verification (all `DONE_VERIFIED`, 2026-10-28). There is no replacement path and no second-account fixture. `T080` is **`DONE_VERIFIED`** (implemented and minimally verified 2026-10-29, then explicitly owner-approved the same day); it no longer carries the `BLOCKED_BY_BACKEND` label because the backend dependency, implementation authorization, and owner visual gate are satisfied. The approval records that the seven desktop screenshots were visually reviewed and that the three mobile screenshots were not individually inspected but are supported by the recorded official-Chrome 119/119 evidence. A narrow read-only `accountId` projection on `SheetDetail` alone was **not sufficient** to unblock `T080`. This contract authorizes no backend implementation; the frontend implementation exists and is verified by the recorded tests and the owner's recorded approval.
6. **Presentation, feedback, and accessibility.** The modal MUST use `@nextcloud/vue` components and existing project utilities; no Vuetify, second UI framework, or new focus-trap dependency. Its normal state MUST present a neutral/professional surface with no warning-style background, note card, or unnecessary warning/note container, clear hierarchy and alignment, compact spacing, responsive desktop and 390px layouts with no horizontal scroll, correct Persian RTL, keyboard-operable controls, initial focus on the sheet-name field, focus containment, focus return to the invoking Create Sheet button or to the Sheets List heading for direct `/sheets/create` entry, and safe dismissal without accidental data loss. Legitimate validation, account-load, mutation, and conflict feedback MUST remain visible and accessible; those states are exempt from the normal-state warning/note prohibition. `T079` visual acceptance MUST explicitly check: no warning/note/yellow container in the normal state; a non-empty accessible dialog name; the `sheets.name` field label; with a fixture of at least two selectable accounts, opening and inspecting the account selector shows valid, readable account labels and option values with no `undefined`; selecting an account shows the balance and derived currency, and the currency control remains read-only or disabled with no `undefined` label, without requiring the account and currency selectors to be open simultaneously; visible sheet-name, account, balance, and currency fields when an account exists; the neutral no-account state when none exists; exactly one Cancel action and one primary Create Sheet action using the existing localized label; no horizontal scroll or clipped controls at 390px with correct RTL alignment; initial focus and focus return, including direct `/sheets/create` entry; and keyboard reachability with a visible focus ring. A passing jsdom test is not mobile-layout or RTL proof.
7. **`NcSelect` label correctness and currency binding.** `NcSelect`'s `label` prop is the **option key**, not the visible field label; `inputLabel` is the visible field label. The account selector currently passes the translated field name as the option key while options are `{ label: formattedBalance, value: accountId }`, so option text renders `undefined`; the option key MUST address the option's own `label`. The currency selector's equivalent misuse and missing `reduce` MUST be corrected without changing the currency value path. The derived currency value is a getter-only, display-only computed, so the currency `NcSelect` and the readonly currency `NcTextField` MUST be bound with `:model-value="currency"`; no writable computed, setter, or domain-rule change is permitted. This is a frontend presentation fix, not a backend or payload defect.
8. **Localization, visual gates, and stop points.** The `T079` implementation is authorized to add exactly two narrow keys, `sheets.name` (`Sheet name` / `نام تنخواه`) and `sheets.createSuccess` (`Sheet created` / `برگه ایجاد شد`), to `l10n/en.json`, `l10n/en.js`, `l10n/fa.json`, and `l10n/fa.js` with key parity. This is not a general English-catalog authorization and does not close `REC-01`. `T079` requires official Google Chrome (`channel: 'chrome'`) at desktop and 390px mobile viewports in Persian RTL, using a multi-account fixture and the account-selector sequence in §6, with evidence under `artifacts/visual-review/t079/<iteration>/`, and MUST stop for explicit owner approval. Its closure also requires a six-artifact same-change completion record in `spec.md`, `plan.md`, `tasks.md`, this contract, `D-025` in `docs/frontend-redesign/DECISIONS.md`, and `docs/reports/005-purchaser-ui-redesign.md`, with actual commands/results, changed paths, evidence, and `git status`, and the owner approval recorded verbatim; T079 is not marked accepted before that approval. `T080` started only after that approval, the `BE-CR-001` backend delivery (001 `T438`–`T441`, all delivered and verified 2026-10-28), and a separate implementation authorization; its immutable-account policy was decided on 2026-10-27 and no replacement decision is required. It requires official Chrome at desktop with a populated existing sheet and at 390px mobile in Persian RTL, with evidence under `artifacts/visual-review/t080/<iteration>/`, followed by another explicit owner approval; that evidence was produced under `artifacts/visual-review/t080/iteration-1/` and the owner explicitly approved the implementation on 2026-10-29, recording that the seven desktop screenshots were visually reviewed and that the three mobile screenshots were not individually inspected but are supported by the recorded official-Chrome 119/119 evidence. No comprehensive suite, full build, or aggregate gate is required before either visual gate; focused safety checks for validation, save, `409`, the controlled dismissal state machine, post-create refetch in filtered and paginated states, and route addressability remain required.
9. **Implementation status (2026-10-27; `T080` 2026-10-29).** `T079` is implemented and minimally verified against this contract and is `DONE_VERIFIED` under the owner's explicit visual approval of all eight screenshots on 2026-10-27 (verbatim: “Approve all eight”). Its official Chrome 156.0.8078.47 review at desktop (1440x900) and 390px (390x844) in Persian RTL recorded 37 passing checks and eight screenshots under `artifacts/visual-review/t079/iteration-1/`, with 0 writes and 0 console/page errors. The six-artifact same-change completion record is complete. The `BE-CR-001` backend delivery (`T438`–`T441`) is also implemented and verified (2026-10-28). `T080` is implemented and minimally verified (2026-10-29) and is `DONE_VERIFIED` under the owner's explicit approval of that implementation the same day (verbatim: “The product owner explicitly approves the current T080 implementation.”). The approval records that the seven desktop screenshots were visually reviewed; the three mobile screenshots were not individually inspected and are supported by the recorded official-Chrome 119/119 evidence, which the owner accepted. `/sheets/:id/edit` renders the list with the shared modal, the authoritative `SheetDetail` supplies the title/account/stored currency, account and currency are read-only, the PATCH body is `title` + `expectedVersion` only, workspace-origin edits return to the same workspace, list/direct entries return to the list, and exactly one `[role="dialog"]` exists. Focused `SheetFormDialog.spec.ts` passed 22/22, the `SheetFormDialog.spec.ts` + `PurchaserFlow.spec.ts` batch passed 180/180, `npm run typecheck` and `npm run build` passed, scoped ESLint reported 0 errors (36 pre-existing formatting warnings), and scoped Stylelint was clean. The official-Chrome runner recorded 119/119 checks and 10 screenshots (7 desktop 1440x900, 3 mobile 390x844) in Persian RTL under `artifacts/visual-review/t080/iteration-1/`, with exactly two deliberate title-only PATCHes (`expectedVersion`-guarded, no `accountId`, HTTP 200), 0 console/page errors, and restored fixture, language, and first-run-wizard state. The `T080` implementation touched `src/components/sheets/SheetFormDialog.vue`, `src/views/sheets/SheetFormView.vue`, `src/views/sheets/SheetListView.vue`, `src/views/sheets/SheetDetailView.vue`, `src/router/index.ts`, `tests/Frontend/Component/SheetFormDialog.spec.ts`, `tests/Frontend/Component/PurchaserFlow.spec.ts`, and the Git-ignored `artifacts/visual-review/t080/iteration-1/`. `T079`'s implementation touched only `src/components/sheets/SheetFormDialog.vue` (new), the dialog-mode/guard/zero-account/`NcSelect` corrections in `src/views/sheets/SheetFormView.vue`, the route-derived modal host plus success/refetch/focus-return behavior in `src/views/sheets/SheetListView.vue`, the `/sheets/create` route host in `src/router/index.ts`, `tests/Frontend/Component/SheetFormDialog.spec.ts` (new), the two authorized keys in the four `l10n` files, and the Git-ignored evidence directory. `src/stores/sheets.ts` was not modified, and no backend, API, authorization, financial, or persistence file changed for either task.

*Guard*: focused `SheetFormDialog.spec.ts` (new) plus the existing sheet-form and route assertions in `PurchaserFlow.spec.ts`/`ApplicationShell.spec.ts`; exactly one dialog and one form across the whole dismissal/confirmation flow; the `sheets.create` heading/action and non-empty accessible name; the `sheets.name` field label; the multi-account account-selector sequence with no `undefined` labels and a read-only/disabled currency control; preserved validation; route-synchronized visibility; the pristine/dirty close state machine with Stay/Discard/Save and no second dialog; post-create refetch in filtered and paginated states with filter/page preservation, the final list matching the server response rather than the temporary insertion, and the polite success notification; `T079` official-Chrome desktop + 390px `fa`-RTL evidence, six-artifact same-change completion record, and owner approval before acceptance; `T080` official-Chrome populated-desktop + 390px `fa`-RTL evidence and owner approval after the `BE-CR-001` backend delivery and separate implementation authorization, all satisfied before the 2026-10-29 implementation, with the owner's explicit approval recorded on 2026-10-29. Neither task may close on jsdom-only evidence.

---

## 2. Stack contract

1. `@nextcloud/vue` 9.x and existing project utilities remain the approved stack.
2. No new UI framework, Vuetify component, icon set, focus-trap library, or other dependency (`FR-005-029`).
3. All colours come from existing Nextcloud theme variables; no hardcoded hex, local radius constant, or new `font-family` declaration.
4. `prefers-reduced-motion: reduce` disables animation; no information is conveyed only by motion.
5. Invalid controls expose `aria-invalid="true"` and a resolving `aria-describedby`.

*Guards*: `tests/Frontend/Component/NextcloudVueContract.spec.ts`, `tests/Frontend/typography.spec.ts`, `tests/Frontend/keyboard-accessibility.spec.ts`, `tests/Frontend/fr071NarrowViewport.spec.ts`.

---

## 3. Management contract (this feature's own ledger)

1. **Card identity**: every imported card keeps its original ID; no renumbering, no merging, no silent drop.
2. **Single canonical status**: `tasks.md` is the canonical execution ledger; the audit package is evidence, not a parallel status source. When they disagree, `tasks.md` plus the evidence link wins, and the disagreement is reported.
3. **Status vocabulary** is closed to the six values in `research.md` Decision 4.
4. **Gate explicitness**: any non-`DONE_VERIFIED` status names the exact condition that changes it.
5. **No backend by stealth, with recorded and bounded exceptions**: a change to `lib/`, `appinfo/`, `specs/001-*`, the OpenAPI contract, or the workflow contract is out of this feature except for:
   - the **approved** 2026-10-23 `BE-CR-004` allowlist: receipt enforcement in submit/resubmit, `TransactionSummary.evidenceRequired`, the per-transaction `422` field errors, and the debug-gated fixture command; and
   - the **approved and implemented** 2026-10-25 001 `T436` change: `FR-025` draft-deletion acknowledgement/replay and the corresponding `ocs-api.yaml` text. Its contract and integration tests are green.
   - the **unapproved** 2026-10-24/25 001 `FR-051` pre-submission revision/audit boundary and `ADR-008` remain out of scope and are **not implemented**; `G-13` is deferred. A 005 task may not claim that server half while `T437` is open.
   Every other backend change is out of scope regardless of how it is justified.
6. **No approval by association**: approving this contract, the plan, or the task ledger approves no card, no batch, no `BE-CR`, and no further gate. `G-11` and `G-12` were separately approved on 2026-10-25; `G-13` remains deferred, so `T437` and `ADR-008` are not approved by this contract or by the completed `T064`–`T072` work.
7. **Git boundary**: no Git write is part of this feature; the Git plan is a separate, explicitly approved operation set (`FR`/`Out of Scope`, `GIT-PLAN-001-R1`).
8. **Planning is not implementation**: a checked requirements-quality item, a recorded planning decision, or an amended contract clause is not evidence that the behavior exists. The six status values and the evidence tiers still decide completion (`FR-005-034`).

---

## 4. Regression guards that must stay green

| Guard | Property |
|---|---|
| `tests/Frontend/l10nCatalog.spec.ts` | Persian catalog completeness; `fa.js`/`fa.json` key-for-key equality (362 keys) |
| `tests/Frontend/Component/RtlAccessibility.spec.ts` | RTL direction and structure |
| `tests/Frontend/fr071NarrowViewport.spec.ts` | 390px floor; repository-wide ban on `overflow-x: auto\|scroll` |
| `tests/Frontend/Component/NextcloudVueContract.spec.ts` | No Vuetify / no `v-*` |
| `tests/Frontend/routeNameIntegrity.spec.ts` | No invented route names |
| `tests/Frontend/keyboard-accessibility.spec.ts` | Keyboard reachability |
| `tests/Frontend/typography.spec.ts` | Typography contract |
| `tests/Frontend/Component/PurchaserFlow.spec.ts` | Purchaser journeys incl. list/actions |
| `tests/Frontend/Component/SheetDeletion.spec.ts` | Delete confirmation behaviour |
| `tests/Frontend/http.spec.ts` | OCS envelope handling; bodyless 204; malformed-200 rejection |
| `tests/Frontend/localeFormatting.spec.ts` | Date/number/money/direction formatting |

**Guards added by the 2026-10-24 amendment and green on 2026-10-25**:

| Guard | Property | Task |
|---|---|---|
| `tests/Frontend/Component/TransactionSurface.spec.ts` | Detail loading, 404, capability matrix, stale refetch, no write on open | T066–T067 |
| `tests/Frontend/Component/EvidencePreview.spec.ts` | Inline-safe set, SVG/HTML refusal, download fallback, error, object-URL revocation, keyboard | T068 |
| `tests/Frontend/Component/DraftHistory.spec.ts` | No draft revision/history surfaces; submitted history retained; correction-draft labelling | T069 |
| `tests/Frontend/Component/LoadingIsolation.spec.ts` | Per-resource loaders, late superseded response, list refetch during detail | T070 |
| `tests/Frontend/Composables/DraftDeletion.spec.ts` | One command id per intent, single-flight, 404/409/422 outcomes | T065 |
| `tests/E2E/t061-receipt-slice-evidence.mjs` (updated) | Pre/post fixture reseed and baseline 25/30/35/40 | T071 |
| `tests/E2E/t072-transaction-surface-evidence.mjs` | Four-configuration browser evidence for delete/replay, surface matrix, preview, history, loading | T072 |

**Guard added by the 2026-10-25 owner amendment**:

| Guard | Property | Task |
|---|---|---|
| `tests/Frontend/Component/OpenSheetTransactionEdit.spec.ts` | OPEN eye icon opens the embedded edit form directly with persisted data; non-OPEN keeps inspection; evidence list/preview; save/delete behavior; exactly one primary `Save changes` action on `submit-btn` in non-correction EDIT with `transaction-save-draft` absent | T075 |
| `tests/Frontend/Component/PurchaserFlow.spec.ts` (updated by the 2026-10-26 refinement) | CREATE form exposes one primary `Save draft` action on `submit-btn`; non-correction EDIT exposes one primary `Save changes` on `submit-btn`; correction keeps distinct `transaction-save-draft` (save without resubmit) and `Resubmit`; submitted/non-editable and legacy `DRAFT`-on-non-`OPEN` footers preserve their existing behavior; the existing `transaction-save-draft` disabled-state assertion at 2125 remains a disabled assertion; saving still uses the existing draft minimum and update/create path | T075 |

**Selector compatibility authorized by the 2026-10-26 refinement** (`submit-btn` is the stable non-correction hook; correction keeps `transaction-save-draft`):

| File / runner | Non-correction references | Correction references | Required action |
|---|---|---|---|
| `tests/Frontend/Component/PurchaserFlow.spec.ts` | `transaction-save-draft` at 1974, 1997, 2001, 2003, 2102; existing disabled assertion at 2125 | none | Move non-submitted-state action assertions to `submit-btn`; keep 2125 as the existing `transaction-save-draft` disabled-state assertion and do not convert it to an absence assertion; keep existing `submit-btn` assertions at 2124 and 3226; add negative/regression assertions for submitted/non-editable, legacy `DRAFT`/non-`OPEN`, correction Save draft + `Resubmit`, and both one-button cases |
| `tests/Frontend/Component/OpenSheetTransactionEdit.spec.ts` | `transaction-save-draft` at 406, 408 | none | Move to `submit-btn` and assert `transaction-save-draft` is absent in non-correction EDIT |
| `tests/E2E/management-review.spec.ts` | 47 | none | Move to `submit-btn` |
| `tests/E2E/purchaser-submit.spec.ts` | 241, 255, 288, 312, 356, 385 | none | Move to `submit-btn` |
| `tests/E2E/finance-settlement.spec.ts` | 51, 276 | none | Move to `submit-btn` |
| `tests/E2E/audit-evidence.spec.ts` | 303 | 389 | Move 303 to `submit-btn`; keep 389 on `transaction-save-draft` |
| `tests/E2E/transaction-correction.spec.ts` | 48 | 257 | Move 48 to `submit-btn`; keep 257 on `transaction-save-draft` |
| `tests/E2E/t061-receipt-slice-evidence.mjs` | already `submit-btn` | none | No selector change |
| `artifacts/visual-review/t075/iteration-1/run.mjs` | 196 | none | Historical only; do not rewrite; the new `iteration-2` runner uses `submit-btn` |

**Guard added by the 2026-10-25 evidence-status implementation**:

| Guard | Property | Task |
|---|---|---|
| `tests/Frontend/Component/EvidenceStatusPresentation.spec.ts` (9 cases) | Never-submitted `DRAFT` on `OPEN` hides lifecycle badges/deactivation metadata and inactive items from the ordinary list; correction/submitted keeps badge/history; `OPEN` alone does not hide them; embedded form and direct detail route carry the same rule; a removed attachment leaves the visible list | T076 |

**Guard owner-authorized for the 2026-10-25 compact-uploader task (not yet created)**:

| Guard | Property | Task |
|---|---|---|
| `tests/Frontend/Component/EvidenceUploaderCompact.spec.ts` | Compact control; filename/size/remove/upload shown; selection, upload, 100 MB refusal, removal, progress, and errors preserved; locked/save-first states preserved; no transport or persistence change. Long-filename, 390px, and overflow assertions are owned by the official-Chrome `fa`-RTL gate, not jsdom. | T077 (`DONE_VERIFIED`, owner-authorized and owner-approved 2026-10-25) |
