# Pettycash Frontend Redesign — Live Status / وضعیت جاری پروژه

**As of / تاریخ وضعیت:** 2026-10-20  
**Charter / منشور:** v1.0 + الحاقیه اجرایی ۷ (Spec Kit) / v1.0 + execution addendum 7 (Spec Kit)  
**Current phase / فاز جاری:** **F1/F2 materially advanced — Batch A is recorded; the Batch B frontend (`T029–T038`, `T022`) is implemented, verified, and recorded by `GIT-PLAN-002-R1` (Batch B C1–C4); owner visual acceptance remains pending; no push**  
**Code changes / تغییرات کد:** **Batch A recorded by `GIT-PLAN-001-R1` (C5 `fae7bce`); Batch B frontend committed by `GIT-PLAN-002-R1` (Batch B C1 `8ccadea`, C2 `c16a0c3`, C3 `6ff72cc`, C4 `4a28aea`); C5 records this status; no backend change; no push**  
**Canonical ledger / دفتر وضعیت رسمی:** [`specs/005-purchaser-ui-redesign/tasks.md`](../../specs/005-purchaser-ui-redesign/tasks.md)  
**Repository sync / ثبت در مخزن:** **Batch A: C1 `7e9fe77`, C2 `f8bf1d3`, C3 `7307e41`, C4 `62dcd59`, C5 `fae7bce`, C6 `8869fc6` (the 2026-10-19 status was C7); Batch B: C1 `8ccadea`, C2 `c16a0c3`, C3 `6ff72cc`, C4 `4a28aea`; Batch B C5 is this status record; no push**

> این سند جای وضعیت تاریخ ۲۰۲۶-۱۰-۱۱ را می‌گیرد. متن پیشین از طریق تاریخچه Git قابل بازیابی است. هیچ اصلی از منشور تغییر نکرده و هیچ فازی مصوب اعلام نشده است. / This file replaces the 2026-10-11 status; the previous text is recoverable from Git history. No charter principle changed and no phase is declared approved.

## فارسی — خلاصه قابل استفاده برای ادامه گفتگو

**هدف:** بازطراحی UI/UX خریدار در Pettycash با شباهت نزدیک به زبان بصری Budget، بدون قابلیت تجاری جدید و بدون تغییر Backend/API مگر با تصویب مستقل.

**اصول ثابت (بدون تغییر):** بودجه صفر؛ فارسی و انگلیسی از ابتدا؛ RTL/LTR، Dark/Light، Responsive، دسترس‌پذیری؛ طراحی مصوب پیش از اجرا؛ ابزارها قابل جایگزینی؛ مالک محصول تأییدکننده نهایی.

**آنچه واقعاً انجام شده است (با شاهد):**

1. **ممیزی مستند:** بسته ممیزی زیر `docs/frontend-redesign/review/deepseek/` (شامل Audit، Gap Matrix، Source Map، ۱۶ کارت `UI-PUR` و ۶ درخواست `BE-CR`، بازتنظیم تصمیم‌های مالک و فهرست مسدودها) تولید شده و در درخت کاری موجود است.
2. **Batch A recorded in C5 (`fae7bce`):** `UI-PUR-008` and `UI-PUR-004` are technically verified; `UI-PUR-001` and `UI-PUR-002` are partial. Evidence: `review/bunny/BATCH_A_EXECUTION_REPORT.md`, a re-executed 4-file/168-test run, and the R1 pre-commit 7-file/241-test run on 2026-10-19.
3. **ثبت تحت Spec Kit:** `specs/005-purchaser-ui-redesign/` (مشخصات، طرح، تحقیق، مدل داده، قرارداد، quickstart، چک‌لیست و دفتر وظایف) ایجاد شد و `.specify/feature.json` به آن اشاره می‌کند.
4. **حاکمیت هم‌راستا شد:** این فایل، `CHARTER.md` (الحاقیه ۷)، `DECISIONS.md` (D-016…D-019 و P-005…P-010) و `ROADMAP.md` فقط با شواهد به‌روز شدند.
5. **Batch B frontend (2026-10-20):** با دستور مالک، `T029–T038` و `T022` پیاده‌سازی و راستی‌آزمایی شدند: `npm run check` سبز (۵۲۴/۵۲۴ در اجرای دوم؛ ۹ فایل/۲۲۲ تست هدفمند)؛ فارسی RTL زنده (پوشش دیالوگ، ۴ تب، ۳ مرحله تأیید، ۳ رویداد تاریخچه، ۳۹۰×۸۴۴ بدون خطا/سرریز) و انگلیسی LTR (`dir=ltr`، بدون سرریز، کلیدهای خام چون `REC-01` باز است)؛ استقلال تقویم تأیید شد (فارسی+میلادی در برابر فارسی+جلالی). چک‌پوینت Git تحت `GIT-PLAN-002-R1` اجرا شد (C1–C4؛ C5 همین ثبت وضعیت)؛ push انجام نشد.

**Not done:** product-owner visual acceptance of Batch A and Batch B (`T051`); the live calendar-change surface (AC2/`T017`); the mutating negative test (AC5/`T018`); an English catalog and English visual acceptance (`REC-01`/`T023`); the backend-blocked cards (`T028`/`T039`/`T040`/`T044`); all six `BE-CR`s.

**وضعیت کارت‌ها:** ۱۱ کارت `DONE_VERIFIED` (`UI-PUR-004`/`008` و Batch B شامل `005`، `006`، `009`–`013`، `015`، و `016` در سطح سرور)، ۲ کارت `PARTIAL` (`UI-PUR-001`، `UI-PUR-002`)، ۳ کارت `BLOCKED_BY_BACKEND` (`UI-PUR-003`، `007`، `014`) و شش `BE-CR` در وضعیت `BLOCKED_PENDING_OWNER_APPROVAL`. پذیرش بصری مالک (`T051`) برای کارت‌های تکمیل‌شده باز است. جزئیات و دروازه هر کارت در دفتر وضعیت رسمی است.

### اقدام دقیق بعدی

1. **پذیرش بصری مالک (`T051`)** بر پایه اسکرین‌شات‌ها و شواهد زنده؛ این پذیرش را نمی‌توان خودکار اعلام کرد.
2. **تصمیم مالک محصول** درباره `REC-01`/`P-005`، سطح زنده تقویم (`P-008`) و `T017`/`T018`.
3. **طرح Git اجرا شد** (`GIT-PLAN-002-R1`): Batch B C1–C4 ثبت شدند و C5 همین وضعیت را ثبت می‌کند؛ `js/`/`css/` تولیدشده و ده مرجع طراحی شامل شدند؛ push انجام نشد.
4. **پاسخ پرسش‌های باز `BE-CR-001/002/003`** و تعیین دامنه `BE-CR-004/005/006` (`P-007`، `P-009`).
5. Push/merge مجاز نیست؛ `GIT-PLAN-003` برای push/PR تهیه نشده است.

**Blockers / موانع:** `BE-CR-001/002/003` (کارت‌های وابسته)، `REC-01` (پذیرش انگلیسی)، سطح کاربری تقویم (AC2/`T017`)، آزمون منفی AC5/`T018`، و پذیرش بصری Batch A/Batch B.

**Next gate / دروازه بعد:** `T051` visual acceptance; decisions `P-005`/`P-008`; `BE-CR` answers; `GIT-PLAN-003` for push/PR (not prepared).

## English — restart handoff

**Goal:** Redesign the purchaser's Pettycash UI/UX toward Budget's visual language, without new business features and without backend/API change unless separately approved.

**Fixed constraints (unchanged):** zero cash budget; Persian and English from day one; RTL/LTR, light/dark, responsive, accessibility; approved design before implementation; replaceable tooling; the product owner approves.

**Done, with evidence:**

1. **Audit package:** `docs/frontend-redesign/review/deepseek/` (audit, gap matrix, source map, 16 `UI-PUR` cards, 6 `BE-CR` requests, the owner-decision reconciliation, and the blocked-items register) exists in the working tree.
2. **Batch A recorded in C5 (`fae7bce`):** `UI-PUR-008` and `UI-PUR-004` are technically verified; `UI-PUR-001` and `UI-PUR-002` are partial. Evidence: `review/bunny/BATCH_A_EXECUTION_REPORT.md`, a re-executed 4-file/168-test run, and the R1 pre-commit 7-file/241-test run on 2026-10-19.
3. **Spec Kit registration:** `specs/005-purchaser-ui-redesign/` (spec, plan, research, data model, contract, quickstart, checklist, task ledger) created; `.specify/feature.json` points at it.
4. **Governance reconciled:** this file, `CHARTER.md` (addendum 7), `DECISIONS.md` (D-016…D-019, P-005…P-010), and `ROADMAP.md` updated with evidence only.
5. **Batch B frontend (2026-10-20):** under the owner directive, `T029–T038` and `T022` are implemented and verified: `npm run check` passed (524/524 on rerun; targeted 9 files/222 tests); live Persian RTL (overlay, 4 tabs, 3 approval stages, 3 history events, 390×844, no errors/overflow) and English LTR (`dir=ltr`, no overflow, raw keys because `REC-01` is open); calendar independence confirmed (Persian+Gregorian vs Persian+Jalali). The Git checkpoint was executed under `GIT-PLAN-002-R1` (C1–C4; C5 records this status); no push.

**Not done:** product-owner visual acceptance of Batch A and Batch B (`T051`); the live calendar-change surface (AC2/`T017`); the mutating negative test (AC5/`T018`); an English catalog and English visual acceptance (`REC-01`/`T023`); the backend-blocked cards (`T028`/`T039`/`T040`/`T044`); all six `BE-CR`s.

**Card states:** 11 technically `DONE_VERIFIED` (`UI-PUR-004`/`008` plus Batch B `005`, `006`, `009`–`013`, `015`, and `016` server tier), 2 `PARTIAL` (`UI-PUR-001`, `UI-PUR-002`), 3 `BLOCKED_BY_BACKEND` (`UI-PUR-003`, `007`, `014`), and six `BE-CR`s `BLOCKED_PENDING_OWNER_APPROVAL`. Owner visual acceptance (`T051`) is open for the completed visual cards. Per-card gates live in the canonical ledger.

### Exact next actions

1. **Owner visual acceptance (`T051`)** based on the screenshots and live evidence; it cannot be self-certified.
2. **Owner decision** on `REC-01`/`P-005`, the live calendar surface (`P-008`), and `T017`/`T018`.
3. **Git plan executed** (`GIT-PLAN-002-R1`): Batch B C1–C4 are committed and C5 records this status; the generated `js/`/`css/` and the ten desktop design references are included; no push.
4. **Answer the open `BE-CR-001/002/003` questions** and scope `BE-CR-004/005/006` (`P-007`, `P-009`).
5. Push/merge is unauthorized; `GIT-PLAN-003` for push/PR is not prepared.

**Blockers:** `BE-CR-001/002/003` (dependent cards), `REC-01` (English acceptance), the calendar surface (AC2/`T017`), the AC5/`T018` negative test, and Batch A/Batch B visual acceptance.

**Next gate:** `T051` visual acceptance; decisions `P-005`/`P-008`; `BE-CR` answers; `GIT-PLAN-003` for push/PR (not prepared).

---

## چرخه به‌روزرسانی / Status update protocol

**فارسی:** بعد از هر جلسه مؤثر یا تحویل یک Task، تاریخ، فاز، موارد تکمیل‌شده با شواهد، موارد باز، خطرها، اقدام بعد و لینک PR/commit (در صورت وجود) را اصلاح کن. هیچ نتیجه‌ای را بدون مدرک Done نزن. قبل از تغییر اصول به `CHARTER.md` و `DECISIONS.md` رجوع کن. سطوح شواهد را تفکیک کن: اجراشده در همین گذر، ثبت‌شده در گزارش، تأییدشده در سورس، و هنوز به‌دست‌نیامده.

**English:** After each meaningful session or task delivery, update date, phase, evidence-backed completions, pending work, risks, the next action, and PR/commit references if available. Never mark work complete without evidence. Consult `CHARTER.md` and `DECISIONS.md` before changing principles. Keep the evidence tiers separate: re-executed, report-recorded, source-verified, not yet obtained.

### قالب گزارش کوتاه / Mini-update template

```text
Date / تاریخ:
Phase / فاز:
Baseline branch & commit / شاخه و commit مبنا:
Completed + evidence / انجام‌شده و شواهد:
In progress / در حال انجام:
Blocked / موانع:
Decisions needed / تصمیم‌های مورد نیاز:
Next exact action / اقدام دقیق بعدی:
Owner approval / تأیید مالک محصول:
```

---

**Related / مرتبط:** [`CHARTER.md`](CHARTER.md) · [`ROADMAP.md`](ROADMAP.md) · [`DECISIONS.md`](DECISIONS.md) · [`specs/005-purchaser-ui-redesign/tasks.md`](../../specs/005-purchaser-ui-redesign/tasks.md)
