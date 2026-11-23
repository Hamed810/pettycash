# Feature Specification: Finance Manager Account Management

**Feature Branch**: `008-finance-manager-account-management` (logical feature directory; no branch operation is authorized by this specification)

**Created**: 2026-11-15 | **Requirements corrected**: 2026-11-16

**Status**: Draft — requirements corrected 2026-11-16 to restore the approved six-column ledger, distinguish read-only inspection from the authorized recharge write, and make individual posting, historical detail, holder scope, ledger date, pagination semantics, and the narrowly scoped read-only Finance evidence access in completed history explicit. Nothing in this specification authorizes planning, task generation, contract work, implementation, or tests.

**Input**: Owner requirements for a Finance Manager module covering a holder directory, holder accounts, a unified account ledger, account recharge, and completed Cost Sheet history, with English-first localization and Persian support, while preserving existing financial and authorization rules.

---

## Source of Truth and Scope

1. `.specify/memory/constitution.md` governs delivery, verification, and visual evidence.
2. `specs/001-pettycash-v1-core/spec.md` and its approved contracts govern domain, authorization, financial, lifecycle, evidence, currency, revision, and workflow behavior. This feature does not restate, redefine, or weaken those rules.
3. This specification governs the Finance Manager module's information architecture, interaction, read-only and authorized-write classification, presentation, and acceptance.
4. Existing purchaser-owned account behavior and authorization semantics remain unchanged.
5. The module consists of five surfaces:
   - holder directory — read-only;
   - holder account inspection — read-only;
   - unified account ledger — read-only;
   - completed Cost Sheet history — read-only;
   - account recharge — an explicitly authorized financial write operation.

No other financial write capability is granted by this feature. Approving, rejecting, returning, or editing a transaction or decision is not part of this module.

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Find a petty-cash holder and inspect that holder's accounts (Priority: P1)

A Finance Manager opens the module, sees a directory restricted to users who actually own at least one petty-cash account, selects a holder, and inspects the holder's accounts: identifier, currency, current balance, status, and activity. Every part of this journey is read-only.

**Why this priority**: Without reliable holder discovery and account inspection, no later ledger, recharge, or history journey can begin.

**Independent Test**: With a data set containing both account-owning and non-account-owning users, a Finance Manager can find every account-owning user, confirm that non-owning users are absent, open an account, and confirm that no mutation control is offered.

**Acceptance Scenarios**:

1. **Given** a user owns at least one petty-cash account, **When** the Finance Manager opens the holder directory, **Then** that user appears once as a holder with identity, number of accounts, active-account count, and account status.
2. **Given** a user owns no petty-cash account, **When** the Finance Manager uses the holder directory or its search, **Then** that user is not discoverable through the module.
3. **Given** the Finance Manager selects a holder, **When** the holder's accounts are displayed, **Then** each account shows its identifier, currency, current balance, active or inactive status, and account activity.
4. **Given** a user without Finance authorization, **When** that user attempts to reach another user's holder record or accounts through the module, **Then** access is denied and no holder data is shown.
5. **Given** a purchaser viewing their own accounts, **When** the feature is available, **Then** the purchaser's own account behavior is unchanged.

---

### User Story 2 - Review a holder account's unified ledger (Priority: P2)

A Finance Manager opens the unified ledger for a selected holder account and reviews the account's chronological financial history in the approved six-column structure. Each row shows the authoritative resulting balance for that financial movement, and paging through the ledger never changes the meaning of a historical balance.

**Why this priority**: The ledger is the accounting record that explains how the current balance came to be.

**Independent Test**: With an account containing recharges, expense postings, and at least two pages of movements, a Finance Manager can open the ledger, verify the six columns, verify that every row shows its own authoritative resulting balance, and verify that loading another page leaves previously displayed balances unchanged.

**Acceptance Scenarios**:

1. **Given** an account has financial movements, **When** the Finance Manager opens its ledger, **Then** the ledger presents exactly six user-visible columns in this order: Date, Description, Approved, Recharge, Expense, Balance.
2. **Given** a ledger row arises from a Cost Sheet, **When** the Approved cell is displayed, **Then** it shows the Cost Sheet's approved transaction count relative to its total transaction count (for example, `5 of 10`).
3. **Given** a ledger row is a recharge, **When** the Approved cell is displayed, **Then** the Approved value is shown as not applicable.
4. **Given** any ledger row, **When** its Balance is displayed, **Then** the value is the authoritative resulting balance after that financial movement, not a value derived by summing only the currently loaded page.
5. **Given** a financial movement whose authoritative posting timestamp differs from its Cost Sheet creation date or its transaction expense date, **When** the ledger Date is displayed, **Then** it shows the movement's authoritative posting timestamp and does not substitute either other date.
6. **Given** a negative account balance, **When** the balance is displayed, **Then** it is presented as an amount owed to the petty-cash holder rather than as an error, a zero, or an unexplained negative amount.
7. **Given** the Finance Manager loads another ledger page, **When** the new page is displayed, **Then** previously displayed historical balances are not recalculated from partial client data and retain their original meaning.
8. **Given** the ledger is displayed, **When** the Finance Manager inspects it, **Then** no action that mutates a movement, decision, or historical record is available.

---

### User Story 3 - Recharge a holder account (Priority: P3)

A Finance Manager selects an eligible holder account, starts a recharge, enters a positive amount, reviews the holder, destination account, account currency, recharge amount, and current balance, explicitly confirms, submits the financial operation, and sees success and the resulting balance only after the authoritative server result confirms success.

**Why this priority**: Recharge is the one financial write operation this module grants, and it must remain deliberate, confirmed, and auditable.

**Independent Test**: With an active holder account, a Finance Manager can complete a recharge only after explicit confirmation; an inactive account cannot be recharged; a duplicated submission produces one financial effect; and a failed or unknown result is not shown as success.

**Acceptance Scenarios**:

1. **Given** an eligible active holder account, **When** the Finance Manager starts Recharge, **Then** the flow is: select account, start recharge, enter a positive amount, review holder, destination account, account currency, recharge amount, and current balance, explicitly confirm, submit the financial operation, wait for the authoritative server result, and only then show success and the resulting balance.
2. **Given** the review step, **When** the account currency is displayed, **Then** it is the destination account's currency and cannot be independently changed.
3. **Given** a recharge amount, **When** the Finance Manager submits, **Then** a non-positive or invalid amount cannot produce a financial effect.
4. **Given** an inactive or otherwise ineligible account, **When** the Finance Manager attempts a recharge, **Then** the recharge cannot be completed and no financial effect occurs.
5. **Given** the Finance Manager confirms a recharge, **When** the submission is repeated accidentally or retried, **Then** no second financial effect occurs and the existing replay-safe behavior is preserved.
6. **Given** the authoritative result is a failure or is unknown, **When** the interface responds, **Then** it does not present the recharge as confirmed success and does not show a resulting balance as though the operation succeeded.
7. **Given** a completed recharge, **When** the financial history is reviewed, **Then** the recharge is auditable.
8. **Given** any recharge, **When** it is processed, **Then** the existing underlying financial write semantics are unchanged.

---

### User Story 4 - Review completed Cost Sheet history (Priority: P4)

A Finance Manager opens the completed Cost Sheet history, selects a completed Cost Sheet, and reviews its final transaction outcomes, posting information, and supporting detail. The entire history view is read-only and every transaction in it has a terminal Finance Manager decision.

**Why this priority**: Completed Cost Sheet history explains the approved expense postings that appear in the account ledger.

**Independent Test**: With a completed Cost Sheet and a Cost Sheet still awaiting a Finance decision, a Finance Manager can open the completed history, verify that only the completed sheet appears, verify every required detail field, and confirm that no decision or historical mutation action exists.

**Acceptance Scenarios**:

1. **Given** Cost Sheets exist in several lifecycle states, **When** the Finance Manager opens completed Cost Sheet history, **Then** only Cost Sheets whose every transaction has a terminal Finance Manager decision and which have reached their final completed lifecycle state are listed.
2. **Given** the completed history list, **When** a completed Cost Sheet is displayed, **Then** it shows its reference, title, purchaser, total transaction count, approved transaction count, rejected transaction count, and actual financially posted amount.
3. **Given** the Finance Manager opens a completed Cost Sheet, **When** its detail is displayed, **Then** it provides, where the authoritative record holds it: Cost Sheet reference, Cost Sheet title, purchaser, total transaction count, approved transaction count, rejected transaction count, actual financially posted amount, every transaction, transaction reference, description, date, amount and currency, Cost Center decision, Senior Manager decision, final Finance Manager decision, supporting evidence, and financial posting information.
4. **Given** any transaction in the completed historical view, **When** its Finance state is displayed, **Then** it is a terminal Finance Manager decision and never a pending Finance-review state.
5. **Given** the completed history detail, **When** the Finance Manager inspects it, **Then** the view provides no Approve, Reject, Return for Correction, decision-editing, or other historical mutation action.
6. **Given** a Cost Sheet with multiple financial postings, **When** its completed history and the related ledger are reviewed, **Then** each authoritative posting remains traceable and no artificial consolidation makes a historical balance inaccurate.

---

### User Story 5 - Use the module in English or Persian (Priority: P5)

A Finance Manager uses the module in English or Persian. All user-facing strings are localized, Persian renders right-to-left, and the module remains inside the existing Nextcloud application shell and navigation.

**Why this priority**: The module must be usable by the approved user population without regressing the established application experience.

**Independent Test**: Switching the user language between English and Persian renders every module string in the selected language, preserves correct LTR/RTL layout, and keeps the existing Nextcloud shell and navigation intact.

**Acceptance Scenarios**:

1. **Given** the user's language is English, **When** the module is displayed, **Then** all user-facing text is English and the layout is left-to-right.
2. **Given** the user's language is Persian, **When** the module is displayed, **Then** all user-facing text is Persian and the layout is right-to-left.
3. **Given** the module is open, **When** the user navigates, **Then** the existing Nextcloud application shell and navigation behavior are preserved.

---

### Edge Cases

- A holder directory with no holders, a holder with no active accounts, an account with no ledger movements, and an empty completed Cost Sheet history each present an explicit empty state rather than a blank or misleading surface.
- A slow or failed data load presents an explicit loading or error state and never a blank surface or fabricated data.
- An unauthorized user attempting cross-user holder or account access is denied without disclosing holder identity or account data.
- An inactive or otherwise ineligible account cannot be recharged.
- The destination account currency is fixed; no currency selection and no currency conversion are offered during recharge.
- A recharge submission that is duplicated, retried, or replayed produces at most one financial effect.
- A recharge timeout or unknown result is presented as an unknown or failed outcome, never as success.
- A negative balance is presented as an amount owed to the petty-cash holder.
- A Cost Sheet whose transactions were posted at different times, with unrelated financial movements between those postings, is still represented accurately in both the ledger and its completed history.
- A completed Cost Sheet with rejected transactions (a completion with rejections under existing lifecycle rules) is represented accurately, including a zero or partial approved count and its actual posted amount.
- A Cost Sheet containing a transaction still awaiting a Finance Manager decision is not presented as completed history.
- A financial movement's authoritative posting timestamp differs from the associated transaction's expense date; the ledger Date follows the posting timestamp and any other date is labelled distinctly in detail views.
- Ledger pagination crosses a page boundary; historical balances on both pages retain their authoritative values.

---

## Requirements *(mandatory)*

### Functional Requirements

#### Authorization and Access Boundaries

- **FR-008-001**: The Finance Manager module MUST provide read-only access to the holder directory, holder account inspection, the unified account ledger, and completed Cost Sheet history.
- **FR-008-002**: Account recharge MUST be an explicitly authorized financial write operation of this module. The module MUST NOT be presented or operated as entirely read-only.
- **FR-008-003**: No financial decision action — including Approve, Reject, Return for Correction, or decision editing — is granted by this module.
- **FR-008-004**: Existing purchaser-owned account access and behavior MUST remain unchanged. This feature MUST NOT reduce, redirect, or replace them.
- **FR-008-005**: Finance Manager cross-user access MUST be role-based and additive to existing authorization. A user without the required Finance authorization MUST NOT use this cross-user access.
- **FR-008-006**: Cross-user access MUST be limited to users who actually own at least one petty-cash account. The holder directory MUST NOT enumerate the unrestricted Nextcloud user directory, and the module MUST NOT require or provide general Nextcloud user search.
- **FR-008-007**: Data visible through the module MUST follow existing role and responsibility scope. Existing purchaser privacy boundaries MUST be preserved.

#### Holder Directory and Account Inspection

- **FR-008-008**: The holder directory MUST be read-only and MUST list only users who own at least one petty-cash account, each once.
- **FR-008-009**: Each holder entry MUST show the holder identity, number of accounts, active-account count, and account status.
- **FR-008-010**: Selecting a holder MUST display that holder's accounts, including account identifier, currency, current balance, active or inactive status, and account activity.
- **FR-008-011**: Holder account inspection MUST be read-only and MUST NOT expose any account or balance mutation control.

#### Unified Account Ledger

- **FR-008-012**: The unified account ledger MUST provide a read-only, chronological accounting-style history for one selected holder account, integrating recharges and expense postings.
- **FR-008-013**: The ledger MUST present exactly six user-visible columns, in this order: **Date**, **Description**, **Approved**, **Recharge**, **Expense**, **Balance**.
- **FR-008-014**: For the accounting ledger, **Date** MUST mean the authoritative persisted timestamp of the financial account movement. A Cost Sheet creation date, a transaction expense date, or a prototype or mockup date MUST NOT be substituted for that timestamp. Other dates MAY appear in detail views only when they are clearly labelled as something other than the accounting posting timestamp.
- **FR-008-015**: **Description** MUST identify the financial movement. Where the movement arises from a Cost Sheet, the description MUST identify the associated Cost Sheet reference or title where available. A recharge description MUST identify the recharge and any description recorded with it.
- **FR-008-016**: For a ledger entry associated with a Cost Sheet, the **Approved** column MUST display the approved transaction count relative to the total transaction count (for example, `5 of 10`). The Approved column MUST NOT be replaced by a generic movement-type column.
- **FR-008-017**: For a recharge entry, the **Approved** value MUST be presented as not applicable.
- **FR-008-018**: **Recharge** and **Expense** MUST remain separate monetary columns. A row MUST present a monetary value only in the applicable column, and MUST NOT present a misleading amount in the non-applicable column.
- **FR-008-019**: **Balance** MUST display the authoritative resulting balance associated with that financial movement.
- **FR-008-020**: The ledger MUST NOT add a separate rejected-count column.
- **FR-008-021**: Every authoritative financial movement MUST be preserved and displayed with its own timestamp and resulting historical balance. The feature MUST NOT collapse multiple authoritative movements into one visually convenient Cost Sheet row if doing so would destroy historical balance accuracy.
- **FR-008-022**: A negative account balance MUST be presented as an amount owed to the petty-cash holder, using the approved localized wording, and MUST NOT be presented as an error, a zero, or an unexplained negative number.
- **FR-008-023**: The frontend MUST NOT derive authoritative balances by summing only the currently loaded page. Pagination MUST NOT change or obscure the meaning of historical balances, and loading another page MUST NOT cause previously displayed historical balances to be recalculated from partial client data.
- **FR-008-024**: Sufficient server-provided context MUST exist for a paginated chronological presentation so that each displayed balance remains meaningful independently of the loaded page.
- **FR-008-025**: The ledger MUST be read-only and MUST NOT offer any action that mutates a movement, decision, or historical record.

#### Individual Posting and Completed Cost Sheet Lifecycle

- **FR-008-026**: A final Finance approval of an eligible transaction MAY create its financial consumption posting immediately, according to existing financial rules.
- **FR-008-027**: Cost Sheet completion is a separate lifecycle state. A Cost Sheet becomes completed only after every transaction belonging to it has a terminal Finance Manager outcome.
- **FR-008-028**: Transactions belonging to one Cost Sheet MAY be financially posted at different times, and unrelated financial movements MAY occur between postings belonging to the same Cost Sheet.
- **FR-008-029**: Every authoritative movement and its resulting historical balance MUST be preserved.
- **FR-008-030**: This feature MUST NOT require or introduce deferred settlement, batch settlement, retroactive settlement, or artificial consolidation of movements.
- **FR-008-031**: Completed Cost Sheet history MUST be a read-only historical projection over the authoritative underlying data. It MUST NOT redefine the lifecycle, posting, or decision rules that produced that data, and it MUST NOT collapse multiple authoritative movements into one historical row if doing so would destroy historical balance accuracy.

#### Completed Cost Sheet History

- **FR-008-032**: The completed Cost Sheet history list MUST be read-only and MUST contain only Cost Sheets that have reached their final completed lifecycle state under existing rules, including a completion with rejections where the existing lifecycle defines that as final.
- **FR-008-033**: Each listed completed Cost Sheet MUST show its reference, title, purchaser, total transaction count, approved transaction count, rejected transaction count, and actual financially posted amount.
- **FR-008-034**: Opening a completed Cost Sheet MUST provide, where the authoritative record holds it: Cost Sheet reference, Cost Sheet title, purchaser, total transaction count, approved transaction count, rejected transaction count, actual financially posted amount, every transaction, transaction reference, description, date, amount and currency, Cost Center decision, Senior Manager decision, final Finance Manager decision, supporting evidence, and financial posting information including the associated posting timestamp, posted amount and currency, and resulting account balance.
- **FR-008-035**: The **actual financially posted amount** MUST be derived from the financial postings actually associated with the Cost Sheet, not from a projection that assumes every approved transaction posted.
- **FR-008-036**: Every transaction presented in the completed historical view MUST already have a terminal Finance Manager decision. A pending Finance-review state MUST NOT appear in this view.
- **FR-008-037**: Supporting evidence in the completed historical view MUST be readable by an authorized Finance Manager through the existing evidence content delivery path. This access MUST require an effective Finance Manager authorization; MUST be limited to evidence belonging to a transaction in a Cost Sheet that has reached a terminal completed lifecycle state (`COMPLETED` or `COMPLETED_WITH_REJECTIONS`) and that the Finance completed-history operation is authorized to expose; MUST be read-only and MUST NOT permit evidence upload, replacement, deletion, or any other mutation; MUST NOT grant access to evidence outside that completed-history scope; MUST preserve existing purchaser, Cost Center Manager, and Senior Manager evidence permissions unchanged; and MUST NOT introduce a parallel evidence storage or download mechanism.
- **FR-008-038**: The completed historical view MUST be strictly read-only and MUST NOT provide Approve, Reject, Return for Correction, decision editing, or any other historical mutation action.

#### Account Recharge

- **FR-008-039**: Account recharge MUST follow this user flow:
  1. Select an eligible holder account.
  2. Start Recharge.
  3. Enter a positive amount.
  4. Review the holder, destination account, account currency, recharge amount, and current balance.
  5. Explicitly confirm.
  6. Submit the financial operation.
  7. Wait for the authoritative server result.
  8. Show success and the resulting balance only after confirmed server success.
- **FR-008-040**: The recharge amount MUST be positive. A non-positive or invalid amount MUST NOT produce a financial effect.
- **FR-008-041**: The account currency MUST be the destination account's currency and MUST NOT be independently changed. No currency conversion is introduced.
- **FR-008-042**: An inactive or otherwise ineligible account MUST NOT be recharged.
- **FR-008-043**: The interface and operation MUST prevent accidental duplicate submission. Existing idempotent and replay-safe financial behavior MUST be preserved, so a retried or repeated submission produces no second financial effect.
- **FR-008-044**: A server failure or unknown-result state MUST NOT be presented as confirmed success, and a resulting balance MUST NOT be shown as though the operation succeeded.
- **FR-008-045**: Every recharge MUST remain auditable under existing audit rules.
- **FR-008-046**: Recharge MUST NOT redefine the underlying financial write semantics.

#### Localization, Presentation, and Application Shell

- **FR-008-047**: English MUST be the primary language, and all user-facing module strings MUST be localizable into Persian.
- **FR-008-048**: The module MUST render correctly in English left-to-right and Persian right-to-left.
- **FR-008-049**: The existing Nextcloud application shell, navigation, and established page behavior MUST be preserved.
- **FR-008-050**: Every surface MUST provide explicit loading, error, and empty states, and MUST remain usable at the approved desktop priority and responsive breakpoints.
- **FR-008-051**: User-facing financial values and balances MUST come from authoritative application data; the interface MUST NOT fabricate, infer, or locally substitute a financial value.

### Key Entities

- **Petty-Cash Holder**: A user who owns at least one petty-cash account, and therefore appears in the Finance Manager holder directory.
- **Petty-Cash Account**: A holder's balance and movement history for one currency, with an identifier, currency, status, current balance, and account activity.
- **Petty-Cash Movement**: An authoritative recharge or approved expense posting with a description, amount, currency, authoritative posting timestamp, and resulting balance.
- **Unified Account Ledger**: The chronological read-only projection of a holder account's financial movements using the approved six-column structure.
- **Completed Cost Sheet History Entry**: The read-only historical projection of a finally completed Cost Sheet, including counts, actual posted amount, transactions, stage decisions, available evidence, and financial posting information.
- **Recharge Request**: A deliberate, explicitly confirmed financial write against one eligible holder account, denominated in that account's currency, producing one auditable movement and resulting balance on success.

---

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-008-001**: A Finance Manager can discover a user who owns a petty-cash account and open that holder's account list in one session, and no user who owns no petty-cash account is discoverable through the module.
- **SC-008-002**: A Finance Manager can inspect a holder's account identifier, currency, current balance, status, and activity, and no mutation control appears on the holder or account inspection surfaces.
- **SC-008-003**: A user without Finance authorization cannot use the Finance cross-user access; the attempt is denied and no holder or account data is disclosed.
- **SC-008-004**: A purchaser's own account behavior is verified unchanged by a regression check.
- **SC-008-005**: A Finance Manager can open an account ledger for a selected holder account.
- **SC-008-006**: The ledger presents exactly the six approved columns, in order: Date, Description, Approved, Recharge, Expense, Balance.
- **SC-008-007**: Every financial movement row shows its authoritative resulting balance, including rows after the first page, and previously displayed balances do not change when another page is loaded.
- **SC-008-008**: For a Cost Sheet-related ledger entry, the Approved cell shows the approved transaction count relative to the total transaction count (for example, `5 of 10`); a recharge row shows Approved as not applicable; and no separate rejected-count column is present.
- **SC-008-009**: A recharge requires explicit confirmation and confirmed server success; failure or an unknown result is never presented as success, and a repeated or retried submission produces at most one financial effect.
- **SC-008-010**: A negative balance is clearly presented as an amount owed to the petty-cash holder in both English and Persian.
- **SC-008-011**: Completed Cost Sheet history contains only Cost Sheets whose every transaction has a terminal Finance Manager decision; no pending Finance-review transaction appears.
- **SC-008-012**: Completed Cost Sheet history exposes no Approve, Reject, Return for Correction, decision-editing, or other historical mutation action.
- **SC-008-013**: A completed Cost Sheet detail identifies every transaction with its reference, description, date, amount and currency, Cost Center decision, Senior Manager decision, final Finance Manager decision, available supporting evidence (readable through the existing evidence content path by an authorized Finance Manager under the read-only completed-history rule), and financial posting information.
- **SC-008-014**: English and Persian user-facing strings are all localizable, and the module renders correctly in English LTR and Persian RTL.
- **SC-008-015**: The existing Nextcloud application-shell behavior is preserved.
- **SC-008-016**: A Cost Sheet with multiple authoritative postings retains each posting's own timestamp and resulting balance in the ledger, with no artificial consolidation that would make a historical balance inaccurate.
- **SC-008-017**: An inactive or otherwise ineligible account cannot be recharged, and a completed recharge is auditable.
- **SC-008-018**: Every ledger row's Date equals the authoritative posting timestamp of that financial movement; no Cost Sheet creation date, transaction expense date, or prototype or mockup date is substituted.
- **SC-008-019**: Each module surface provides explicit loading, error, and empty states and remains usable at the approved responsive breakpoints in both English and Persian.
- **SC-008-020**: Every displayed financial value and balance matches authoritative application data; no value is fabricated, inferred, or locally substituted.

---

## Dependencies

- The existing Finance role authorization and cross-user responsibility model.
- The existing petty-cash account ownership, status, balance, movement, and audit rules.
- The existing Cost Sheet and transaction lifecycle, stage-decision history, evidence visibility, and financial posting workflow.
- The existing English/Persian localization mechanism and Nextcloud application shell.

## Assumptions

- The Finance Manager role and its base authorization already exist and are not redefined here.
- A holder is defined by account ownership; the module has no need to browse the general Nextcloud user population.
- The six-column ledger structure and its Approved-cell meaning are already approved product decisions.
- Completed Cost Sheets are read-only by existing rule, and the historical view only projects that rule.
- Desktop is the priority surface; the module must still behave correctly at approved responsive breakpoints.
- The module does not change financial decision semantics, posting semantics, currency rules, or audit rules.

## Out of Scope

- Changes to existing Finance decision semantics, account lifecycle, posting rules, revision handling, or audit rules.
- Deferred settlement, batch settlement, retroactive settlement, or artificial consolidation of financial movements.
- Currency conversion or independent recharge-currency selection.
- General Nextcloud user search or enumeration of non-holder users.
- Purchaser-owned account redesign or changes to purchaser workflows.
- Mobile-specific redesign.
- Any mutation of historical decisions, transactions, or movements.
- Planning, task generation, contract changes, implementation, and tests, which require separate authorization.

## Localization Requirements

- Primary language: English.
- Required localization: Persian.
- Every user-facing label, message, status, empty state, error state, and financial caption must be localizable.
- Persian presentation must be right-to-left; English presentation must be left-to-right.
- The negative-balance explanation must be localized and must retain the same meaning in both languages. The approved English label is `Amount Owed to Petty Cash Holder`; the approved Persian label is `طلب تنخواه‌دار`.

## Versioning

- This is version 1 of the specification.
- Requirements corrected 2026-11-16. Future changes require explicit authorization and must preserve the approved feature boundary and existing financial and authorization rules.
