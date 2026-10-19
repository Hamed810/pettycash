# Prototype scope and acceptance test — Purchaser-only

When visual coverage has been verified and owner accepts any last missing PNG:

## Mandatory deliverables
1. One **full runnable editable source package** (not a screenshot-only PDF or view-only preview), all HTML/CSS/JS (or appropriate original framework code), asset references, fixtures/mock data, package configuration, self-contained README/start steps and reproducible dependencies where practical.
2. Link/HTML preview that loads on desktop and 390px mobile, supports English LTR, light and dark when approved references cover them, global independent Gregorian/Jalali calendar toggle. The selected calendar persists across pages in the prototype.
3. Route/state index or demo controls that let owner explore **OPEN**, **mixed submitted review**, **correction**, **completed approved**, and **completed with rejections**; sample datasets internally consistent and clearly synthetic.
4. Purchaser Sheets List working local search/filter/sort/pagination (mock dataset okay). OPEN first irrespective of First Submitted ascending/descending; row clicks open modal while maintaining list state. No invented real server integration claims.
5. Sheet Detail modal: correct title/ID/account, statuses, per-stage summaries, transaction table scroll with sticky header at 30+ rows, status-aware controls. Open sheet all transactions DRAFT; no Add after submission.
6. Clickable transaction rows navigate within same modal workspace to readable details or authorized edit/correction. One `+` in fixed table header when allowed. No nested modal stack.
7. New transaction Cost Center prefilled from last created and saved transaction in same Sheet if valid, user can override; no prefill based on currently sorted row.
8. Transaction save individually (simulate persistence in-memory/local state and label as MOCK), distinct Submit/Resubmit actions, evidence attach follow-up after entity creation, no invented unsupported fields.
9. Unsaved Changes guard works from X, Cancel, internal Back, Escape, and browser navigation. Save errors keep modal open. Reversible demo action should not pretend to call live API.
10. Approvals/Overview/History tabs with per-transaction decision timeline, revision differences and cost-center aggregates; correct actor/stage and historical preservation.
11. Mobile responsive with accessible controls and legible cards, fixed/sticky navigation and scroll areas. Keyboard focus, focus trap/return, Escape-close handling, reduced-motion preference.
12. Export/attach selected updated PNG screenshots of prototype views, changelog, implementation notes, and a list of any intentionally mocked/unsupported interactions.

## Hard boundaries
- No edits to Pettycash Git repository.
- No backend endpoint creation, no new business feature, no admin/manager/finance role UI in this task.
- No Persian UI screen generation now; bilingual goal survives but remains later phase.
- **Stop and get owner sign-off before claiming final delivery.**
