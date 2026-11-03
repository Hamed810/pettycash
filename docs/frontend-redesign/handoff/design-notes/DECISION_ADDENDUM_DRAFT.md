# Conversation Decisions Addendum (DRAFT for repository merge)

**Scope:** Decisions *after* `DECISIONS.md` dated 2026-10-11. These are a conversation-level checkpoint, **not yet written into Git**. Owner should merge using project's append-only change-control procedure, assign official IDs, and reconcile prior D-006 (bilingual final product) with the **temporary English-only Manus design sequence** (not a rescission of bilingual support).

> **Superseded 2026-10-27:** `U-14`'s "title/account" half is withdrawn. The sheet's source account and currency are **immutable after creation**; only the title may change while the sheet is `OPEN`. Normative policy: 001 `FR-019`/`FR-021`; decision record `D-026`. The `U-14` row is retained as history.

| Candidate ID | Approved direction (English) | خلاصه فارسی | Status |
|---|---|---|---|
| U-01 | Design PNG before any UI code; approved PNG then editable/runnable prototype, then Bunny implementation. | اول PNG، بعد تصویب، بعد Prototype، بعد کد اصلی | Approved in conversation |
| U-02 | Manus temporarily designs only English LTR; final product remains EN/FA RTL/LTR. | توقف موقت طراحی فارسی فقط در Manus | Approved |
| U-03 | Manus exports both PNGs and complete source prototype at prototype phase. | تحویل PNG و سورس قابل اجرا/ویرایش | Approved |
| U-04 | Purchaser Sheets List has compact core columns including Recorded/Latest Approved/First Submitted, no Creator/Actions column for own-only list; row-click modal. | لیست شیت خریدار خلاصه، قابل کلیک، بدون اکشن‌های تکراری | Approved |
| U-05 | First Submitted is first submission date; OPEN sheets no date and always first in both sort directions; global pagination-safe sort requires BE-CR-01 specification. | مرتب‌سازی تاریخ اولین ارسال، Open همیشه اول | Approved; backend contract ticket only |
| U-06 | Title above ID, no decorative two-digit ID badge/date header/chevron; hover subtle lift + shadow (~150–200ms). | عنوان بالای شناسه، انیمیشن ظریف Hover | Approved |
| U-07 | Global Gregorian/Jalali calendar preference independent of UI language, with persistent selection and accurate display conversion. | تقویم سراسری مستقل از زبان محیط | Approved UX requirement; persistence mechanism to be checked |
| U-08 | Sheet Detail remains a modal over Sheets list, mobile full-screen; closing restores list state; dirty guard is mandatory. | جزئیات در Modal، حفظ وضعیت فهرست، هشدار خروج بدون ذخیره | Approved |
| U-09 | OPEN = never submitted; all transactions DRAFT. Submission enters staged per-transaction workflow. | Open یعنی ارسال‌نشده و تمام تراکنش‌ها Draft | Approved/domain-confirmed |
| U-10 | Sheet cost center does not exist; each Tx has Cost Center; use Expense Type, not Category. | مرکز هزینه مختص تراکنش، Expense Type به‌جای Category | Approved/domain-confirmed |
| U-11 | New transaction defaults Cost Center from last created & saved transaction of same sheet; user may change; invalid/none yields blank. | پیش‌فرض مرکز هزینه از آخرین تراکنش ایجاد و ذخیره‌شده همان شیت | Approved new frontend behavior |
| U-12 | Only one Add Transaction plus button in fixed transaction list header; never displayed on non-OPEN sheet. | یک دکمه افزودن در هدر ثابت جدول فقط وقتی مجاز | Approved |
| U-13 | No global Save in Sheet Detail; transaction saves independently; Submit Sheet is separate. | ذخیره مجزای تراکنش‌ها، عدم وجود Save سراسری شیت | Approved/domain-confirmed |
| U-14 | Sheet metadata (title/account) edit only while OPEN, through contextual action; delete sheet only OPEN owner via overflow and confirm. | ویرایش موضعی Title/Account فقط Open، حذف در سه‌نقطه | Approved/domain-confirmed |
| U-15 | Per-stage workflow stats under each stage: approved/rejected/pending counts + amounts; no isolated Total card, no returned line in stage cards; returns shown in transaction-level history. | خلاصه آماری جداگانه زیر هر مرحله بدون Total و ردیف Returned | Approved visual instruction |
| U-16 | Read-only transaction detail shows decision history and revisions; corrections use returned transaction revision and resubmit. | جزئیات تراکنش و تاریخچهٔ Revision، اصلاح از مسیر برگشت | Approved visual/domain direction |
| U-17 | Role-specific Manager/Finance/Admin screens intentionally deferred; current purchaser design cannot be copied blindly into review queues. | فعلاً فقط نمای خریدار؛ سایر نقش‌ها بعداً | Approved |
| U-18 | The next target is Unsaved Changes desktop PNG, followed by purchaser mobile PNGs; implement UI only after owner approval. | قدم بعد هشدار ذخیره‌نشدن و سپس موبایل | Agreed next action |

**Risks / reconciliation:** Sample PNGs sometimes invent fields, filetype/size rules, stale action icons, incorrect Jalali dates, and inconsistent financial/status fixtures. These are **NOT** accepted business decisions. `CHARTER.md`, approved Specs, contracts, and authorized backend behavior prevail. Do not edit application code when merging this addendum.
