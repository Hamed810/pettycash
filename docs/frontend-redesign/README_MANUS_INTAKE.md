# Manus UI/UX Intake Workspace / کارگاه ورود مواد طراحی Manus

**Prepared / تاریخ آماده‌سازی:** 2026-10-19 · **Base commit / commit مبنا:** `main` @ `6006f28400e8962909afab0744460152353c12fe`
**Status / وضعیت:** intake only — no audit performed, no application code touched / فقط سازمان‌دهی — بدون Audit و بدون تغییر کد
**Manifest / فهرست تفصیلی:** [`MANUS_INTAKE_MANIFEST.md`](MANUS_INTAKE_MANIFEST.md)

> **Note / نکته:** the pre-existing governance kit index of this folder is [`README.md`](README.md). It was **not** modified by this intake, so this workspace guide lives in a separate file.

---

## فارسی

### ۱) هدف این پوشه

این زیرپوشه، مواد طراحی و Prototype پروژهٔ بازطراحی فرانت‌اند Pettycash را که از حساب Manus تحویل گرفته شده‌اند، به شکل قابل‌مرور و قابل‌استناد نگه می‌دارد تا Audit فنی بعدی (مقایسهٔ Prototype با Spec Kit و پیاده‌سازی واقعی) روی یک ساختار پایدار انجام شود. این کار **سازمان‌دهی فایل** است، نه طراحی مجدد، نه پیاده‌سازی و نه Audit.

### ۲) نظام اختیار مراجع (مهم)

| موضوع | مرجع معتبر |
|---|---|
| رفتار تجاری، وضعیت‌ها، قواعد دامنه | **Spec Kit موجود** (`specs/`) و قراردادهای مصوب |
| جهت‌گیری بصری (ظاهر، چیدمان، سلسله‌مراتب) | تصاویر مرجع طراحی Manus در `references/` — **به شرط تأیید مالک محصول** |
| Prototype تعاملی | `prototype/manus-purchaser-v1/` — **مرجع پیاده‌سازی، نه کد تولیدی** |
| برنامهٔ واقعی در حال بهره‌برداری | اپلیکیشن Nextcloud/Vue موجود در `src/`، `lib/` و بستهٔ ساخته‌شده |

Prototype یک اپ مستقل و بدون وابستگی است (HTML/CSS/JS خالص + یک static server ساده) و هیچ API، Backend یا مخزن Pettycash را تغییر نمی‌دهد.

### ۳) ساختار پوشه‌ها

```text
docs/frontend-redesign/
├── README.md                      ← بستهٔ حاکمیتی موجود (دست‌نخورده)
├── CHARTER.md  DECISIONS.md  ROADMAP.md  STATUS.md   ← حاکمیتی موجود (دست‌نخورده)
├── README_MANUS_INTAKE.md         ← همین فایل
├── MANUS_INTAKE_MANIFEST.md       ← فهرست کامل ورود مواد + هش‌ها
├── references/                    ← مراجع بصری طراحی
│   ├── DESIGN_REFERENCES.md        ← ثبت وضعیت تأیید هر تصویر (سند Manus)
│   ├── desktop/                    ← ۱۱ تصویر دسکتاپ 1536×1024
│   ├── mobile/                     ← ۶ تصویر موبایل 390×693 + ۳ تصویر تولیدشدهٔ موبایل
│   ├── dialogs/                    ← تصویر دسکتاپ Unsaved Changes
│   └── contact-sheets/             ← Contact sheet موبایل (WebP با پسوند .png)
├── prototype/
│   └── manus-purchaser-v1/         ← سورس کامل و قابل‌اجرای Prototype خریدار (v1.0.0)
├── handoff/
│   ├── manus/                      ← اسناد بستهٔ انتقال Manus (00..08 و متن پرامپت)
│   ├── design-notes/               ← یادداشت‌های اصلاح طراحی و پیش‌نویس تصمیم‌ها
│   └── governance/                 ← Snapshot اسناد حاکمیتی داخل بستهٔ Manus
├── review/
│   └── deepseek/                   ← محل خروجی Audit بعدی (هنوز خالی/شروع‌نشده)
└── _archives/                      ← آرشیوهای قدیمی و مستندات پلتفرم Manus
```

### ۴) رابطه با Spec Kit و فازهای پروژه

- `ROADMAP.md` این پوشه فاز جاری را **F1 (شناخت و Audit)** اعلام می‌کند؛ F1.1 تمام شده و **F1.2** گام مجاز بعدی است. هیچ کد اپلیکیشنی مجاز نیست.
- Spec Kit (`specs/001-pettycash-v1-core`, `002-admin-dashboard-parity`, `003-app-menu-entry`, `004-in-app-navigation`) و ADRهای `docs/decisions/` مرجع رفتار هستند.
- Prototype Manus **به قراردادهای API متعهد نیست** و خودش این محدودیت را در `KNOWN_GAPS.md` و `DESIGN_TO_DOMAIN_MAPPING.md` ثبت کرده است.

### ۵) محدودیت‌های شناخته‌شده

1. تصاویر `references/` در سطح **بستهٔ Manus** تأیید شده‌اند؛ هیچ سند ثبت‌شده در مخزن Pettycash این تأیید را رسماً تأیید نکرده است (رکورد‌های متناقض در Manifest فهرست شده‌اند).
2. تطابق دقیق «هشت تصویر موبایل» اصلی با فایل‌های موجود در آرشیو نیازمند تأیید مالک محصول است.
3. Prototype فقط نمای خریدار است؛ مدیر/مالی/Admin مدل نشده‌اند.
4. Prototype فارسی/RTL ندارد (طبق تصمیم موقت) و آزمون زنده/سرور واقعی ندارد.
5. وضعیت اجرای مستقل Prototype در این مرحله **آزمایش نشده** است (اجرای `npm install` / `npm start` بخشی از این مأموریت نبود).
6. سه فایل با پسوند `.png` در آرشیو اصلی، در واقع **WebP** هستند.

### ۶) دستور کار Audit فنی بعدی

1. ابتدا [`MANUS_INTAKE_MANIFEST.md`](MANUS_INTAKE_MANIFEST.md) و سپس `handoff/manus/01_PROJECT_CONTRACT_EN.md` و `handoff/manus/05_PROTOTYPE_DELIVERY_ACCEPTANCE.md` را بخوان.
2. وضعیت هر مرجع را از `references/DESIGN_REFERENCES.md` بگیر و با هشدارهای متناقض موجود در Manifest تطبیق بده.
3. Prototype را با Spec Kit و پیاده‌سازی واقعی مقایسه کن: نام‌گذاری دامنه (Expense Type در برابر Category)، مرکز هزینه در سطح تراکنش، چرخهٔ Revision/Correction، قواعد مجوز و اقدام‌ها، و صحت داده‌های نمونه.
4. خروجی Audit را **فقط** زیر `review/deepseek/` بنویس.
5. در این مرحله: بدون تغییر `specs/`، `src/`، `lib/`، `tests/`، `appinfo/`؛ بدون نصب وابستگی؛ بدون اجرای کد Prototype بدون هماهنگی؛ بدون commit.
6. قواعد اجرایی مخزن (مرزهای اجرا، PHP فقط در کانتینر، OCI/Serena) در `AGENTS.md` ریشهٔ مخزن آمده و لازم‌الاجرا است.

---

## English

### 1) Purpose

This subfolder holds the Manus-delivered design and prototype materials for the Pettycash frontend redesign in a reviewable, citable layout so the next technical audit (prototype vs. Spec Kit vs. the real Nextcloud/Vue implementation) runs against a stable structure. This work is **file organization only** — not redesign, not implementation, not the audit itself.

### 2) Authority model (important)

| Subject | Authoritative source |
|---|---|
| Business behavior, statuses, domain rules | **Existing Spec Kit** (`specs/`) and approved contracts |
| Visual direction (look, layout, hierarchy) | Manus design references in `references/` — **subject to product-owner approval** |
| Interactive prototype | `prototype/manus-purchaser-v1/` — **implementation reference, not production code** |
| The running production app | Existing Nextcloud/Vue app in `src/`, `lib/`, and the built bundle |

The prototype is a standalone, dependency-free app (plain HTML/CSS/JS plus a small static server). It changes no Pettycash API, backend, or repository file.

### 3) Directory structure

```text
docs/frontend-redesign/
├── README.md                      ← existing governance kit index (untouched)
├── CHARTER.md  DECISIONS.md  ROADMAP.md  STATUS.md   ← existing governance (untouched)
├── README_MANUS_INTAKE.md         ← this file
├── MANUS_INTAKE_MANIFEST.md       ← full intake inventory, hashes, duplicate report
├── references/                    ← visual design references
│   ├── DESIGN_REFERENCES.md        ← per-image status registry (Manus authored)
│   ├── desktop/                    ← 11 desktop references, 1536×1024
│   ├── mobile/                     ← 6 uploaded mobile references (390×693) + 3 generated mobile refs
│   ├── dialogs/                    ← Unsaved Changes desktop reference
│   └── contact-sheets/             ← mobile contact sheet (WebP carrying a .png extension)
├── prototype/
│   └── manus-purchaser-v1/         ← complete, runnable purchaser prototype source (v1.0.0)
├── handoff/
│   ├── manus/                      ← Manus transfer package docs (00..08 plus the raw prompt paste)
│   ├── design-notes/               ← design correction notes and decision addendum draft
│   └── governance/                 ← governance snapshot shipped inside the Manus package
├── review/
│   └── deepseek/                   ← where the next audit output goes (not started)
└── _archives/                      ← legacy archives and Manus platform documentation
```

### 4) Relationship to Spec Kit and project phases

- This folder's `ROADMAP.md` declares the current phase as **F1 (discovery & audit)**; F1.1 is complete and **F1.2** is the next authorized step. No application code is authorized.
- Spec Kit (`specs/001-pettycash-v1-core`, `002-admin-dashboard-parity`, `003-app-menu-entry`, `004-in-app-navigation`) plus the ADRs in `docs/decisions/` govern behavior.
- The Manus prototype is **not bound to the API contracts**; it states that boundary itself in `KNOWN_GAPS.md` and `DESIGN_TO_DOMAIN_MAPPING.md`.

### 5) Known limitations

1. The `references/` images are approved at the **Manus package** level; no repository-committed Pettycash document formally records that approval (conflicting records are listed in the manifest).
2. Whether the archive's files match the owner's original "eight mobile images" exactly still needs product-owner confirmation.
3. The prototype models the purchaser view only; manager, finance, and admin views are not modeled.
4. The prototype has no Persian/RTL screens (per the temporary English-only decision) and no live API or backend.
5. The prototype was **not executed** during this intake (`npm install` / `npm start` were out of scope).
6. Three files in the source archive carry a `.png` extension but are actually **WebP**.

### 6) Instructions for the next technical audit

1. Read [`MANUS_INTAKE_MANIFEST.md`](MANUS_INTAKE_MANIFEST.md) first, then `handoff/manus/01_PROJECT_CONTRACT_EN.md` and `handoff/manus/05_PROTOTYPE_DELIVERY_ACCEPTANCE.md`.
2. Take each reference's status from `references/DESIGN_REFERENCES.md` and reconcile it with the conflicting-record list in the manifest.
3. Compare the prototype against the Spec Kit and the real implementation: domain naming (`Expense Type` vs `Category`), transaction-level Cost Center, the revision/correction lifecycle, permission-driven actions, and fixture-data correctness.
4. Write audit output **only** under `review/deepseek/`.
5. At this stage: do not modify `specs/`, `src/`, `lib/`, `tests/`, or `appinfo/`; do not install dependencies; do not run prototype code without coordination; do not commit.
6. Repository execution rules (execution boundaries, PHP container-only, OCI/Serena routing) live in the root `AGENTS.md` and remain binding.

---

**Related / مرتبط:** [`MANUS_INTAKE_MANIFEST.md`](MANUS_INTAKE_MANIFEST.md) · [`README.md`](README.md) · [`STATUS.md`](STATUS.md) · [`ROADMAP.md`](ROADMAP.md) · [`CHARTER.md`](CHARTER.md) · [`DECISIONS.md`](DECISIONS.md)
