# The correction path was present but inert (ad-hoc, between T069 and T072)

**Status:** complete. **Ad-hoc work — deliberately not given a `tasks.md` ID.** It was undertaken
between T069 and T072 at the maintainer's direction, because T069's RED tests could not be satisfied
without it. The number T320 in this feature belongs to the audit-ordering task appended in Phase 51,
so this report is filed under a name rather than an ID to keep the two from being confused.

## What this is

T069 wrote US4's correction tests RED and found four contract failures. They turned out to be
**five defects, two of them hidden behind the first**. This records all five and the fixes.

## The five

### 1. The correction draft was an orphan

`CreateCorrectionDraft::execute()` created the draft, saved it, returned it — and never attached it
to the transaction. So the transaction still referenced the revision being corrected, and
`resubmitCorrection()`'s `activate($this->activeRevision)` activated **that** one.

Measured: after create-then-resubmit the active revision was `77777777-…` (the fixture's original),
not the created draft. The purchaser's corrections were computed, persisted, and discarded.

Fixed with `ExpenseTransaction::attachWorkingRevision()`, which moves the working pointer and
deliberately does **not** activate — `workflow.md`'s Correction Contract clause 5 puts activation at
resubmission.

### 2. A correction draft could not be edited at all

`PATCH` refused with `422 "Cannot update transactions in a submitted sheet"`. The check was made
against the **sheet**, and a returned transaction's sheet is `CORRECTION` — the wrong fact. FR-026
names exactly one exception to "no editing after submission" (correcting a returned transaction) and
`workflow.md` clause 2 says the owner may "create and edit the correction draft". There was no
implementation.

The rule now reads the **transaction** state. The sheet lookup is kept only because the revision takes
its currency from it. Submitted, in-review, and settled transactions are still refused.

### 3. Editing a correction draft silently ended the correction

Found only after (2) was fixed — the 422 had been masking it. `updateTransaction()` called
`activate()` unconditionally, which sets stage to `COST_CENTER` and status to
`AWAITING_COST_CENTER_REVIEW`. On an ordinary draft that is harmless; on a correction draft it meant
the purchaser's **first edit** routed the expense to a manager before they resubmitted anything.

The correction path now attaches instead of activating. Drafts on an open sheet are unchanged.

### 4. The activated revision was never written

Found only after (1) and (3) were fixed. `resubmitCorrection()` calls `activate()`, which sets
`active` and `activated_at` **in memory**, but only the *transaction* was saved — and its
`active_revision_id` points *at* the revision rather than carrying its state. So the activation was
discarded.

Measured: resubmission answered `200` with `activeRevision.amountMinor: 24680` and
`activeRevision.active: false`.

### 5. `Revision.active` meant "was submitted", not "is current"

Found only after (4) was fixed. `pettycash_transaction_revisions` **has no `active` column** —
`mapRow()` derived it from `activated_at IS NOT NULL`. That answers a different question, and the two
diverge the moment a correction happens: the superseded revision keeps its `activated_at` forever, so
**two** revisions read active after every resubmission.

`TransactionDetailView.vue:82` selects the current revision with `revisions.find(r => r.active)`, and
FR-050 rests on the current revision determining current information. The projection now reads
`pettycash_transactions.active_revision_id`, so "exactly one active" is a property of the data.

### Also fixed: the required command id

`POST /correction` and `POST /resubmit` both fell back to a server-minted `Uuid::random()`. The
contract declares the header required on both (`:523-525`), and unlike the T318 DELETEs these are
non-repeatable commands — so a replay arrived under a *different* key and `IdempotencyService` had
nothing to match. Both now answer **400**, matching `update()`, `ReviewController`, and T317.

And `ReturnsQueueView.vue:131` swallowed nothing: `onMounted` left `fetchReturnsQueue()` floating
while the store rethrows. One `.catch()`, mirroring `TransactionDetailView.vue:97`.

## Evidence the predicate contradicted the model

The evidence check had to widen for resubmission to work at all, and the widening was not a guess.
`data-model.md:248` states: *"Required evidence is validated against active evidence related to the
revision/**transaction**."* The predicate was scoped to the revision only, and its own docblock
described the wider rule the code did not implement. Measured: a complete, correctly-edited
correction was refused with `422 "Transaction missing required evidence"` on a transaction that
plainly had evidence. This is **T072's subject**, reached through the correction path.

## Tests

`CorrectionControllerTest` **12/12** · `TransactionCorrection.spec.ts` **14/14** ·
`scripts/dev/phpunit` **854 tests / 3867 assertions / 1 failure / 0 skipped / 0 incomplete** ·
`npm run test:unit` **228/228** · typecheck clean · lint 0 errors.

The single failure is `PurchaserFlowControllerTest::testGetEvidenceContentReturns200WithStream`
(`application/pdf` vs asserted `application/octet-stream`) — pre-existing, carried since T318,
unrelated, and still needing its own task.

### Two tests changed because the implementation was right and they were wrong

Both were corrected rather than bent to pass, and both say so in their docblocks:

- **`testCreatingADraftDoesNotActivateIt` → `testCreatingADraftDoesNotSubmitIt`.** It asserted the
  previously-active revision stayed active. That is wrong: the draft must become the current revision
  or nothing can reach it. What clause 5 actually governs — no *submission* — is now asserted via the
  stage and status.
- **`testAnIncompleteCorrectionIsRefusedAndActivatesNothing` → `testResubmissionIsRefusedOnceTheTransactionsEvidenceIsDeactivated`.** It drove an empty description and expected a refusal. `assertSubmittable()` checks `=== null`, and `''` is not null, so that premise was never supported. Replaced with the evidence half of clause 4, which is enforced and observable — and which also pins that *deactivated* evidence does not count, which a predicate ignoring `active` would pass.

### Mutations: 5 killed, 1 did not

| Mutation | Result |
|---|---|
| remove `attachWorkingRevision()` from `CreateCorrectionDraft` | **killed** — 2 failures |
| revert `Revision.active` to `activated_at !== null` | **killed** — 2 failures |
| restore the revision-scoped evidence predicate | **killed** — 2 failures |
| restore the `Uuid::random()` command-id fallback | **killed** |
| revert the correction branch in `updateTransaction()` | **initially survived — the tests had a gap** |
| remove `revisionRepository->save()` from `ResubmitCorrection` | **survived — see below** |

**The one that survived first was a genuine hole in my tests, not in the code.** Reverting the
correction branch left the file entirely green, because nothing asserted that an edit leaves the
transaction *in* correction. Added `testACorrectedTransactionResubmitsWithItsChanges`'s mid-edit
status/stage assertions; the mutation now fails.

**The revision save is not covered, and I am not claiming otherwise.** Removing
`revisionRepository->save($transaction->revision())` from `ResubmitCorrection` leaves the suite green,
because the `active` projection now comes from `active_revision_id` rather than from anything on the
revision row. The save exists to persist `activated_at`, which nothing currently reads
(`TransactionRevision::isCompleted()` is hardcoded `return false`). It is kept because the column
exists, `mapRow()` reads it, and leaving it unwritten for corrected revisions is the kind of latent
trap this report has otherwise been about — but it is **unproven code**, and it should gain a test
when something starts reading that column.

## Known limitations

- `ResubmitCorrection.php:135` still references an `$explanation` that is neither a parameter nor
  captured by the closure. `??` suppresses the warning so it yields `''`. Unchanged by this task; no
  artifact states what it should be.
- `activated_at` is written but never read (above).
- The evidence predicate now trusts `transaction_id`, so evidence uploaded against a transaction
  satisfies any of its revisions. That is `data-model.md:248` as written; if it was meant to be
  revision-scoped, the model is what needs amending, not the code.
- `testGetEvidenceContentReturns200WithStream` remains red.

## Next

T072 (`tasks.md:536`) — correction evidence retaining originating revision links, and post-submission
removal deactivating rather than deleting. Part of its subject was reached here through the evidence
predicate; the parts not yet done are the upload-side links.
