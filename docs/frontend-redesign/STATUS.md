# Pettycash Frontend Redesign — Live Status / وضعیت جاری پروژه

**As of / تاریخ وضعیت:** 2026-10-19  
**Charter / منشور:** v1.0 + الحاقیه اجرایی ۷ (Spec Kit) / v1.0 + execution addendum 7 (Spec Kit)  
**Current phase / فاز جاری:** **F1/F2 materially advanced — the audit and Batch A are recorded by `GIT-PLAN-001-R1`; Batch B not started**  
**Code changes / تغییرات کد:** **Batch A recorded by `GIT-PLAN-001-R1` (C5 `fae7bce`); no backend change; no Batch B card; no push**  
**Canonical ledger / دفتر وضعیت رسمی:** [`specs/005-purchaser-ui-redesign/tasks.md`](../../specs/005-purchaser-ui-redesign/tasks.md)  
**Repository sync / ثبت در مخزن:** **C1 `7e9fe77`, C2 `f8bf1d3`, C3 `7307e41`, C4 `62dcd59`, C5 `fae7bce`, C6 `8869fc6`; this status update is C7; no push**

> این سند جای وضعیت تاریخ ۲۰۲۶-۱۰-۱۱ را می‌گیرد. متن پیشین از طریق تاریخچه Git قابل بازیابی است. هیچ اصلی از منشور تغییر نکرده و هیچ فازی مصوب اعلام نشده است. / This file replaces the 2026-10-11 status; the previous text is recoverable from Git history. No charter principle changed and no phase is declared approved.

## فارسی — خلاصه قابل استفاده برای ادامه گفتگو

**هدف:** بازطراحی UI/UX خریدار در Pettycash با شباهت نزدیک به زبان بصری Budget، بدون قابلیت تجاری جدید و بدون تغییر Backend/API مگر با تصویب مستقل.

**اصول ثابت (بدون تغییر):** بودجه صفر؛ فارسی و انگلیسی از ابتدا؛ RTL/LTR، Dark/Light، Responsive، دسترس‌پذیری؛ طراحی مصوب پیش از اجرا؛ ابزارها قابل جایگزینی؛ مالک محصول تأییدکننده نهایی.

**آنچه واقعاً انجام شده است (با شاهد):**

1. **ممیزی مستند:** بسته ممیزی زیر `docs/frontend-redesign/review/deepseek/` (شامل Audit، Gap Matrix، Source Map، ۱۶ کارت `UI-PUR` و ۶ درخواست `BE-CR`، بازتنظیم تصمیم‌های مالک و فهرست مسدودها) تولید شده و در درخت کاری موجود است.
2. **Batch A recorded in C5 (`fae7bce`):** `UI-PUR-008` and `UI-PUR-004` are technically verified; `UI-PUR-001` and `UI-PUR-002` are partial. Evidence: `review/bunny/BATCH_A_EXECUTION_REPORT.md`, a re-executed 4-file/168-test run, and the R1 pre-commit 7-file/241-test run on 2026-10-19.
3. **ثبت تحت Spec Kit:** `specs/005-purchaser-ui-redesign/` (مشخصات، طرح، تحقیق، مدل داده، قرارداد، quickstart، چک‌لیست و دفتر وظایف) ایجاد شد و `.specify/feature.json` به آن اشاره می‌کند.
4. **حاکمیت هم‌راستا شد:** این فایل، `CHARTER.md` (الحاقیه ۷)، `DECISIONS.md` (D-016…D-019 و P-005…P-010) و `ROADMAP.md` فقط با شواهد به‌روز شدند.

**Not done:** product-owner visual acceptance of Batch A; the live calendar-change surface (AC2); the mutating negative test (AC5); an English catalog (`REC-01`); creation-date filter labels (`REC-06`); every Batch B card; all six `BE-CR`s.

**وضعیت کارت‌ها:** ۲ کارت `DONE_VERIFIED` فنی (`UI-PUR-004`، `UI-PUR-008`)، ۲ کارت `PARTIAL` (`UI-PUR-001`، `UI-PUR-002`)، ۱۳ کارت `READY_FOR_OWNER_APPROVAL` (تأییدنشده)، ۳ کارت `BLOCKED_BY_BACKEND` (`UI-PUR-003`، `007`، `014`) و شش `BE-CR` در وضعیت `BLOCKED_PENDING_OWNER_APPROVAL`. جزئیات و دروازه هر کارت در دفتر وضعیت رسمی است.

### اقدام دقیق بعدی

1. **تصمیم مالک محصول** درباره `REC-01` (منبع کاتالوگ انگلیسی) — دروازه هر پذیرش بصری انگلیسی.
2. **تأیید یا رد کارت‌به‌کارت** کارت‌های `READY_FOR_OWNER_APPROVAL` و سطح زنده تقویم (`P-008`).
3. **پاسخ پرسش‌های باز `BE-CR-001/002/003`** و تعیین دامنه `BE-CR-004/005/006` (`P-007`، `P-009`).
4. **`GIT-PLAN-001-R1` executed** — C1–C6 are recorded; C7 records this status; no push.
5. Batch B تنها پس از تأیید کارت‌به‌کارت آغاز شود.

**Blockers / موانع:** `BE-CR-001/002/003` (کارت‌های وابسته)، `REC-01` (پذیرش انگلیسی)، سطح کاربری تقویم (AC2)، آزمون منفی AC5، و پذیرش بصری Batch A.

**Next gate / دروازه بعد:** decisions `P-005…P-009`; `GIT-PLAN-002` for push/PR.

## English — restart handoff

**Goal:** Redesign the purchaser's Pettycash UI/UX toward Budget's visual language, without new business features and without backend/API change unless separately approved.

**Fixed constraints (unchanged):** zero cash budget; Persian and English from day one; RTL/LTR, light/dark, responsive, accessibility; approved design before implementation; replaceable tooling; the product owner approves.

**Done, with evidence:**

1. **Audit package:** `docs/frontend-redesign/review/deepseek/` (audit, gap matrix, source map, 16 `UI-PUR` cards, 6 `BE-CR` requests, the owner-decision reconciliation, and the blocked-items register) exists in the working tree.
2. **Batch A recorded in C5 (`fae7bce`):** `UI-PUR-008` and `UI-PUR-004` are technically verified; `UI-PUR-001` and `UI-PUR-002` are partial. Evidence: `review/bunny/BATCH_A_EXECUTION_REPORT.md`, a re-executed 4-file/168-test run, and the R1 pre-commit 7-file/241-test run on 2026-10-19.
3. **Spec Kit registration:** `specs/005-purchaser-ui-redesign/` (spec, plan, research, data model, contract, quickstart, checklist, task ledger) created; `.specify/feature.json` points at it.
4. **Governance reconciled:** this file, `CHARTER.md` (addendum 7), `DECISIONS.md` (D-016…D-019, P-005…P-010), and `ROADMAP.md` updated with evidence only.

**Not done:** product-owner visual acceptance of Batch A; the live calendar-change surface (AC2); the mutating negative test (AC5); an English catalog (`REC-01`); creation-date filter labels (`REC-06`); every Batch B card; all six `BE-CR`s.

**Card states:** 2 technically `DONE_VERIFIED` (`UI-PUR-004`, `UI-PUR-008`), 2 `PARTIAL` (`UI-PUR-001`, `UI-PUR-002`), 13 `READY_FOR_OWNER_APPROVAL` (not approved), 3 `BLOCKED_BY_BACKEND` (`UI-PUR-003`, `007`, `014`), and six `BE-CR`s `BLOCKED_PENDING_OWNER_APPROVAL`. Per-card gates live in the canonical ledger.

### Exact next actions

1. **Owner decision** on `REC-01` (English catalog sourcing) — it gates every English visual acceptance.
2. **Approve or reject** each `READY_FOR_OWNER_APPROVAL` card individually, plus the live calendar surface (`P-008`).
3. **Answer the open `BE-CR-001/002/003` questions** and scope `BE-CR-004/005/006` (`P-007`, `P-009`).
4. **`GIT-PLAN-001-R1` executed** — C1–C6 are recorded; C7 records this status; no push.
5. Start Batch B only after card-by-card approval.

**Blockers:** `BE-CR-001/002/003` (dependent cards), `REC-01` (English acceptance), the calendar surface (AC2), the AC5 negative test, and Batch A visual acceptance.

**Next gate:** decisions `P-005…P-009`; `GIT-PLAN-002` for push/PR.

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
