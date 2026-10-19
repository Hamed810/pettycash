# Pettycash Frontend Redesign — Decision Log / دفتر تصمیمات

**Version / نسخه:** 1.0 · **Consolidated / تاریخ تنظیم:** 2026-10-11 · **آخرین افزوده / last addition:** 2026-10-19 (D-016…D-019، P-005…P-010)  
**Decision authority / مرجع تصویب:** Product owner / مالک محصول  
**Statuses / وضعیت‌ها:** `Accepted / مصوب` · `Proposed / پیشنهادی` · `Superseded / جایگزین‌شده`

> Accepted items reflect agreements made in conversation. No repository commit or external tool activation is implied. / موارد «مصوب» حاصل توافق در گفتگو هستند؛ به معنی ثبت در Git یا فعال‌شدن ابزار بیرونی نیستند.

## تصمیم‌های مصوب / Accepted decisions

| ID | فارسی — تصمیم و منطق | English — decision and rationale | Status |
|---|---|---|---|
| **D-001** | Budget از `otherworld-dev` مرجع اصلی هویت بصری است. هدف شباهت زیاد در شکل، ساختار، Sidebar، کارت‌ها و فرم‌هاست؛ نه کپی‌کردن قوانین مالی آن. | The otherworld-dev Budget app is the primary design reference. Aim for strong visual/layout similarity without copying its business logic. | **Accepted** |
| **D-002** | سازگاری کامل با Nextcloud و استفاده درست از Design System و کامپوننت‌های موجود الزامی است. | Keep Nextcloud integration and use its design system/components correctly. | **Accepted** |
| **D-003** | دامنه، UI/UX، خطاهای عملکردی مرتبط و کیفیت فنی فرانت‌اند است؛ پروژه صرفاً زیباسازی نیست. | Include UI/UX, existing-flow behavioral defects, and frontend engineering quality—not just cosmetic styling. | **Accepted** |
| **D-004** | **هیچ قابلیت تجاری جدیدی** در بازطراحی اضافه نمی‌شود. موارد Spec Kit که هنوز ساخته نشده‌اند، به‌طور خودکار وارد Scope نمی‌شوند. | **No new business features.** Unimplemented Spec Kit features are not automatically part of this redesign. | **Accepted** |
| **D-005** | منطق مالی، نقش‌ها/مجوزها، قراردادهای API و Backend حفظ می‌شوند؛ هر استثنا نیازمند تصویب مستقل است. | Preserve financial rules, authorization, API contracts, and backend behavior; exceptions require separate approval. | **Accepted** |
| **D-006** | فارسی و انگلیسی از ابتدا پشتیبانی می‌شوند؛ RTL/LTR مطابق زبان و تنظیمات Nextcloud است. | Support Persian and English from day one, with RTL/LTR following Nextcloud user language/direction. | **Accepted** |
| **D-007** | سقف هزینه نقدی پروژه **صفر** است؛ ابزارهای متن‌باز/رایگان/سهمیه رایگان مجازند و وابستگی اجباری به سرویس پولی ممنوع است. | **Zero cash budget**: open-source, free, and free-quota tools only; no mandatory paid-service dependency. | **Accepted** |
| **D-008** | طراحی، Specification و Prototype هر بخش باید قبل از اجرا به تأیید مالک محصول برسد. | Product-owner approval of a slice's design/specification/prototype must precede implementation. | **Accepted** |
| **D-009** | Muse Spark 1.3 Free برای معماری و Audit؛ Manus برای کمک به طراحی/Prototype در سهمیه رایگان؛ Z.ai/Bunny برای اجرا در صورت دسترسی رایگان؛ Playwright برای شواهد آزمون. | Muse Spark 1.3 Free audits/specifies; Manus assists design/prototyping within free quota; Z.ai/Bunny implements if free access permits; Playwright verifies. | **Accepted** |
| **D-010** | نقش‌ها ثابت اما سرویس‌ها قابل جایگزینی‌اند؛ خروجی اصلی باید قابل انتقال و قابل نسخه‌بندی باشد. | Roles persist, vendors/tools can be substituted; durable deliverables must remain portable and versionable. | **Accepted** |
| **D-011** | ترتیب فازهای F0 تا F6 و دروازه‌های تأیید الزام‌آور است؛ بدون Scope مصوب، تغییر کد آغاز نمی‌شود. | Follow F0–F6 with approval gates; do not start source-code changes before approving the correction scope. | **Accepted** |
| **D-012** | آزمایش مرورگر، بررسی بصری، Light/Dark، RTL/LTR و عدم رگرسیون جزء معیار پذیرش‌اند. | Browser/E2E evidence, visual checks, light/dark, RTL/LTR, and regression control are acceptance criteria. | **Accepted** |
| **D-013** | Repository و فایل‌های `CHARTER/ROADMAP/DECISIONS/STATUS` مرجع رسمی ادامه کار هستند؛ تصمیم مهم و تغییر فاز باید ثبت شود. | Git repository and the four governance files are the persistent source of truth; update them at each material decision or phase gate. | **Accepted** |
| **D-014** | تغییرات باید محدود، قابل بازگشت و در شاخه مستقل Git باشند؛ مأمور بدون مجوز دامنه را گسترش نمی‌دهد. | Use small, reversible changes on a dedicated Git branch; agents must not silently expand scope. | **Accepted** |
| **D-015** | الهام از Budget مجاز است؛ کپی مستقیم کد/دارایی‌ها فقط پس از بررسی مجوز مربوطه. | Visual inspiration from Budget is allowed; direct reuse of code/assets requires license review. | **Accepted** |
| **D-016** | بازطراحی فرانت‌اند خریدار با شناسه `005-purchaser-ui-redesign` تحت Spec Kit ثبت می‌شود؛ `spec.md` مرجع الزامات و `tasks.md` دفتر وضعیت رسمی `UI-PUR`/`BE-CR` است و بسته ممیزی DeepSeek فقط شاهد می‌ماند. | The purchaser frontend redesign is registered as `005-purchaser-ui-redesign`; `spec.md` governs its requirements and `tasks.md` is the canonical `UI-PUR`/`BE-CR` status ledger, with the DeepSeek package kept as evidence only. | **Accepted** (2026-10-19) |
| **D-017** | وضعیت Batch A همان‌گونه که در درخت کاری وجود دارد ثبت می‌شود: `UI-PUR-008` و `UI-PUR-004` تأیید فنی شده‌اند و `UI-PUR-001`/`UI-PUR-002` جزئی مانده‌اند؛ «تأیید فنی» هرگز جای «پذیرش بصری مالک محصول» را نمی‌گیرد. | Batch A is recorded exactly as it exists in the working tree: `UI-PUR-008`/`UI-PUR-004` technically verified, `UI-PUR-001`/`UI-PUR-002` partial; "technically verified" never stands in for product-owner visual acceptance. | **Accepted** (2026-10-19) |
| **D-018** | هر شش `BE-CR-001…006` مسدود می‌مانند و هیچ تغییر Backend/قرارداد/مشخصات برای رفع انسداد مجاز نیست؛ کارت‌های وابسته راه‌حل سمت کلاینت نمی‌گیرند. | All six `BE-CR-001…006` stay blocked; no backend/contract/specification edit may unblock them, and dependent cards get no client-side workaround. | **Accepted** (2026-10-19) |
| **D-019** | هیچ نوشتن Git بدون طرح شماره‌دار و تأیید صریح (`APPROVE GIT-PLAN-XXX`) انجام نمی‌شود؛ خروجی build و تغییرات کاربر قبلی از دامنه طرح خارج می‌مانند. | No Git write happens without a numbered plan and explicit approval (`APPROVE GIT-PLAN-XXX`); build output and pre-existing user changes stay out of plan scope. | **Accepted** (2026-10-19) |

### D-020 — R1 checkpoint scope (Accepted 2026-10-19)

- **Status:** Accepted. The product owner approved `GIT-PLAN-001-R1` on 2026-10-19.
- **Decision:** The R1 checkpoint records the Spec Kit 005 migration, governance reconciliation, audit evidence, inspected design handoff, Batch A source/tests, and generated build output in six content commits plus one post-success status commit. It stays on `feat/pettycash-purchaser-ui-batch-a`, uses exact file allowlists, and does not push, merge, switch branches, or start Batch B.
- **Relationship to D-019:** D-019's approval requirement and its exclusion of pre-existing user changes remain in force. D-020 supersedes only D-019's exclusion of build output for this checkpoint; C6 records it after a repeat build is byte-identical.
- **Record rule:** Commit SHAs and outcomes are recorded in C7 only after C1–C6 succeed.

## موضوعات باز و پیشنهادها / Open items and proposals

| ID | فارسی — موضوع نیازمند تصمیم | English — decision still required | Status / gate |
|---|---|---|---|
| **P-001** | ابزار نگهداری طرح نهایی: Penpot، Figma Starter یا Prototype محلی؟ هیچ‌یک شرط آغاز F1 نیست. | Choose a design artifact home (Penpot, Figma Starter, or local prototype). Not a blocker for F1. | **Proposed — before F4** |
| **P-002** | برای بررسی عملی Budget آیا نصب نسخه آزمایشی در Nextcloud ممکن و مفید است؟ جایگزین خواندن مخزن/تصاویر هم پذیرفتنی است. | Decide whether a Budget test installation is useful/available; code/screenshots are an acceptable fallback. | **Proposed — during F1** |
| **P-003** | پس از Audit، محدوده دقیق اصلاحات اولیه F2 باید جداگانه تأیید شود. | Approve the exact F2 remediation backlog after F1 evidence is reviewed. | **Open — F1 gate** |
| **P-004** | برای هر برش F5، طرح و معیار پذیرش باید جداگانه تصویب شوند. | Approve design and acceptance criteria for each F5 implementation slice. | **Open — per slice** |

### افزوده‌های ۲۰۲۶-۱۰-۱۹ / Added 2026-10-19

| ID | فارسی — موضوع نیازمند تصمیم | English — decision still required | Status / gate |
|---|---|---|---|
| **P-005** | منبع کاتالوگ انگلیسی (`REC-01`): ساخت `l10n/en.*` یا اکتفا به مراجع ایستا برای بازبینی انگلیسی؟ تا این تصمیم، هیچ پذیرش بصری انگلیسی قابل‌سنجش نیست و کاتالوگ ناقص/دستی ممنوع است. | English catalog sourcing (`REC-01`): author `l10n/en.*`, or review English against static references only? Until then no English visual acceptance is verifiable and a partial/stub catalog is prohibited. | **Open — blocks English acceptance** |
| **P-006** | تصویب کارت‌به‌کارت Scope «Batch B» (۱۳ کارت `READY_FOR_OWNER_APPROVAL`). تصویب یک صف یا طرح، تصویب کارت نیست. | Approve Batch B scope card by card (13 `READY_FOR_OWNER_APPROVAL` cards). Approving a queue or a plan is not approving a card. | **Open — before any Batch B work** |
| **P-007** | پاسخ به پرسش‌های باز `BE-CR-001/002/003`: قاعده ارز هنگام تغییر حساب؛ خوانش مجوز؛ ترتیب داخل گروه OPEN و tie-breaker؛ معنای `REC-03` و شکل payload. | Answer the open `BE-CR-001/002/003` questions: currency rule on account change; permission reading; intra-OPEN ordering and tie-breaker; `REC-03` semantics and payload shape. | **Open — required before those approvals** |
| **P-008** | آیا سطح کاربری تغییر تقویم (`UI-PUR-001` AC2) مجاز است؟ امروز فقط سازوکار پیاده شده و هیچ toggle نمایانی مجاز نبوده است. | Is a user-facing calendar-change surface authorized (`UI-PUR-001` AC2)? Only the mechanism exists today; no visible toggle was authorized. | **Open — owner** |
| **P-009** | دامنه `BE-CR-004/005/006`: آیا این سه تغییر انطباق با مشخصات اصلاً در دامنه این بازطراحی هستند؟ پاسخ `BE-CR-004` دروازه لایه شواهد `UI-PUR-016` است. | Scope of `BE-CR-004/005/006`: are these three spec-compliance changes in this redesign's scope at all? The `BE-CR-004` answer gates `UI-PUR-016`'s evidence tier. | **Open — owner** |
| **P-010** | دو گزینه ثبت‌شده: اصلاح FR-062 برای فهرست اکیداً شش‌ستونه؛ و پذیرش `POST /transactions/{id}/correction` به‌جای مسیر PATCH. هیچ‌کدام اتخاذ نشده‌اند. | Two recorded alternatives: an FR-062 amendment for a strict six-column list; adopting `POST /transactions/{id}/correction` instead of the PATCH flow. Neither is adopted. | **Recorded — not adopted** |

## روش تغییر تصمیم / Decision change control

**فارسی:** برای تغییر یک تصمیم مصوب، ابتدا دلیل، گزینه‌ها، آثار بر Scope/هزینه/سازگاری/تست و نام تصمیم قبلی ثبت شود. سپس مالک محصول آن را صریحاً تأیید کند. رکورد قبلی حذف نشود؛ وضعیت آن `Superseded` و شناسه تصمیم جایگزین اضافه شود. بدون چنین روندی هیچ Agent حق تعبیر مجدد مصوبات را ندارد.

**English:** To change an accepted decision, record the rationale, alternatives, effects on scope/cost/compatibility/testing, and the prior decision ID. Obtain explicit product-owner approval. Preserve the old entry as `Superseded` with a replacement reference; no agent may silently reinterpret prior approvals.

### الگوی رکورد جدید / New decision template

```markdown
### D-016 — <Short title / عنوان کوتاه>
- Status / وضعیت: Proposed | Accepted | Superseded
- Date / تاریخ: YYYY-MM-DD
- Context (FA) / زمینه: ...
- Context (EN): ...
- Decision (FA) / تصمیم: ...
- Decision (EN): ...
- Consequences (FA) / پیامدها: ...
- Consequences (EN): ...
- Approved by / تأییدکننده: Product owner / مالک محصول
- Supersedes / جایگزین: None / ندارد
```

---

**Related / مرتبط:** [`CHARTER.md`](CHARTER.md) · [`ROADMAP.md`](ROADMAP.md) · [`STATUS.md`](STATUS.md)
