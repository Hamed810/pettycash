# Pettycash Frontend Redesign — Project Charter / منشور پروژه بازطراحی فرانت‌اند Pettycash

**Version / نسخه:** 1.0 (+ الحاقیه اجرایی ۷، ۲۰۲۶-۱۰-۱۹ — بدون تغییر اصول)  
**Date / تاریخ:** 2026-10-11 · **آخرین الحاقیه / last addendum:** 2026-10-19  
**Approval / تصویب:** Approved by the product owner in conversation; repository commit pending. / مورد تأیید مالک محصول در گفتگو؛ ثبت در مخزن هنوز انجام نشده است.  
**Scope / دامنه:** Pettycash frontend within Nextcloud / فرانت‌اند Pettycash در محیط Nextcloud

> **Normative rule / قاعده اصلی:** This charter governs planning and implementation. Any material change requires explicit product-owner approval and an entry in `DECISIONS.md`. / این منشور مبنای برنامه‌ریزی و اجراست. هر تغییر اساسی به تأیید صریح مالک محصول و ثبت در `DECISIONS.md` نیاز دارد.

---

## فارسی

### ۱. چشم‌انداز و معیار موفقیت

Pettycash باید به یک نرم‌افزار مالی حرفه‌ای، خوانا، قابل اعتماد و سازگار با Nextcloud تبدیل شود. پروژه فقط زیباترکردن ظاهر نیست؛ **UI، UX، صحت رفتار رابط، دسترس‌پذیری و کیفیت فنی** در محدوده آن قرار دارند.

**مرجع بصری اصلی، اپلیکیشن [Nextcloud Budget](https://github.com/otherworld-dev/Budget) است.** هدف، **شباهت زیاد در زبان بصری و ساختار صفحات** (ناوبری کناری، کارت‌ها، تراکم و سلسله‌مراتب اطلاعات، الگوهای فرم/جدول، فواصل و ریتم طراحی) است؛ نه جایگزینی گردش‌کار و منطق Pettycash با Budget.

پروژه زمانی موفق است که صفحات و گردش‌کارهای مصوب، از نظر بصری با طرح تأییدشده مطابقت داشته باشند و با شواهد مرورگری و آزمون‌های مرتبط بدون رگرسیون تأیید شوند؛ صرف پاس‌شدن build کافی نیست.

### ۲. دامنه مجاز و غیرمجاز

**مجاز:** تحلیل وضعیت موجود، ترمیم زیرساخت نمایش/ترجمه/Theme، اصلاح باگ‌های UI، بهبود ناوبری و فرم‌ها، بهینه‌سازی گردش‌کارهای *موجود*، هماهنگ‌سازی کامپوننت‌های Nextcloud، بازآرایی ساختار بصری، دسترس‌پذیری، پشتیبانی دوزبانه و Responsive، تست و مستندسازی.

**غیرمجاز بدون تصویب جداگانه:** افزودن قابلیت تجاری جدید؛ تغییر قرارداد API، قوانین مالی، مجوزهای دسترسی یا رفتار Backend؛ بازنویسی گسترده و بی‌دلیل؛ ورود کتابخانه UI موازی؛ استفاده از سرویس پولی به‌عنوان پیش‌نیاز؛ انتقال کد/دارایی‌های Budget بدون بررسی مجوز.

قابلیت‌های تعریف‌شده در Spec Kit که هنوز پیاده نشده‌اند، صرفاً به دلیل وجود در Specification به‌طور خودکار جزو دامنه این بازطراحی نیستند. نیاز به Backend یا توسعه دامنه باید به‌عنوان درخواست تغییر مستقل ثبت و تأیید شود.

### ۳. اصول اجرایی الزام‌آور

1. **شناخت پیش از تغییر:** بررسی Repository، `AGENTS.md`، Spec Kit، کد محلی و رفتار واقعی در مرورگر قبل از طراحی و اجرا.
2. **شواهد پیش از ادعا:** تفکیک «مشاهده‌شده»، «تأییدشده در سورس»، «فرضیه» و «تأییدشده در مرورگر». هیچ فرضیه‌ای نباید به‌عنوان علت قطعی گزارش شود.
3. **Design-first:** هر بخش باید ابتدا Specification و طرح قابل مشاهده داشته باشد؛ تأیید مالک محصول پیش از پیاده‌سازی الزامی است.
4. **Nextcloud-native integration:** حفظ Vue/معماری موجود در حد امکان؛ رعایت نسخه واقعی `@nextcloud/vue`، Theme، جهت صفحه، اصول دسترس‌پذیری و قوانین Repository. کتابخانه رقیب بدون تأیید اضافه نشود.
5. **دو زبان از ابتدا:** فارسی و انگلیسی، با RTL/LTR متناسب با زبان/تنظیمات کاربر در Nextcloud؛ اعداد، تاریخ، مبلغ، واحد پول، فرم و جدول باید در هر دو حالت درست و بدون ابهام باشند.
6. **اصلاح علت، نه پوشاندن علامت:** برای خطاهای ترجمه، کنتراست یا semantics کامپوننت‌ها، اصلاح ریشه‌ای بر workaround ظاهری اولویت دارد.
7. **عدم رگرسیون:** حفظ قراردادهای API و رفتار تجاری؛ آزمون سناریوهای مهم در مرورگر واقعی، در کنار lint/build/unit/E2E متناسب با تغییر.
8. **تغییر محدود و قابل بازگشت:** کار در branch جداگانه، به‌صورت برش‌های کوچک و با گزارش فایل‌های تغییرکرده، آزمون‌ها و ریسک‌ها؛ بدون تغییر پنهانی خارج از Scope.
9. **هزینه صفر و عدم قفل‌شدن به فروشنده:** فقط ابزار رایگان، متن‌باز یا سهمیه رایگان. خروجی‌های اصلی باید در فایل‌های قابل انتقال و قابل نگهداری در Git باقی بمانند.
10. **مرجع رسمی پایدار:** `CHARTER.md`، `ROADMAP.md`، `DECISIONS.md` و `STATUS.md` مرجع هماهنگی هستند، نه حافظه چت یا حافظه یک مدل.
11. **امنیت داده‌ها:** به سرویس‌های بیرونی فقط داده ساختگی/پاک‌سازی‌شده و اطلاعات غیرمحرمانه داده شود؛ هیچ کلید، توکن، داده مالی واقعی یا اطلاعات شخصی محرمانه ارسال نشود.
12. **پایبندی به مجوزها:** الهام بصری از Budget مجاز است؛ استفاده مجدد از کد، تصاویر، آیکون‌ها یا استایل‌های متعلق به آن قبل از بررسی مجوز و الزامات توزیع ممنوع است.

### ۴. نقش‌ها و اختیارات

| نقش | مسئولیت | مرز اختیار |
|---|---|---|
| مالک محصول (کاربر) | تعیین اولویت و تأیید نهایی Scope/Design/Release | تصمیم نهایی با اوست |
| ChatGPT (مشاور/بازبین) | برنامه‌ریزی، بررسی تناقض‌ها و کیفیت شواهد | حق تصویب مستقل تغییرات اساسی ندارد |
| Muse Spark 1.3 Free (معمار) | Audit، تحلیل محدودیت‌ها، تهیه Specification اجرایی | تغییر کد فقط با مأموریت صریح |
| Manus (طراح UI/UX پیشنهادی و مورد توافق) | تحلیل Budget و ساخت Prototype با سهمیه رایگان | مالک معماری/کد تولید نیست؛ خروجی باید قابل انتقال باشد |
| Z.ai / Bunny (مجری مشروط) | پیاده‌سازی بر اساس طرح مصوب در صورت امکان استفاده رایگان | بدون بازتعریف Scope یا طراحی مستقلِ اساسی |
| Playwright و ابزارهای تست | سنجش رفتار و ارائه Screenshot/شواهد | ابزار تأیید فنی؛ جایگزین تأیید مالک محصول نیست |

اگر سهمیه یا دسترسی ابزارها تغییر کرد، **نقش‌ها ثابت می‌مانند و ابزار قابل جایگزینی است**. Penpot/Figma/Prototype محلی می‌توانند محل نگهداری طرح باشند؛ انتخاب ابزار نگهداری هنوز مصوب نشده است.

### ۵. توالی مصوب و دروازه‌های تأیید

`F0 منشور → F1 تحلیل Pettycash و Budget → F2 تثبیت زیرساخت → F3 معماری UX → F4 طراحی بصری و Prototype → F5 پیاده‌سازی تدریجی → F6 تأیید نهایی`

ورود به فاز بعدی به خروجی مستند و تأیید صریح دروازه فاز نیاز دارد. شروع **F2 یا هر تغییر کد** قبل از پذیرش Scope اصلاحات توسط مالک محصول مجاز نیست. جزئیات در `ROADMAP.md` است.

### ۶. معیار پذیرش عمومی هر برش کاری

برای اعلام اتمام یک برش: (۱) مسئله و Scope ثبت شده، (۲) طراحی/مشخصات مصوب موجود است، (۳) تغییرات فقط در محدوده مجاز بوده‌اند، (۴) آزمون‌های مناسب اجرا و نتایج ثبت شده‌اند، (۵) حالت‌های Dark/Light، فارسی/انگلیسی، RTL/LTR، Responsive و Loading/Error/Empty متناسب با آن بخش بررسی شده‌اند، (۶) شواهد Screenshot یا تست مرورگر موجود است، (۷) هیچ رگرسیون شناخته‌شده حل‌نشده بدون پذیرش رسمی باقی نمانده و (۸) `STATUS.md` و در صورت لزوم `DECISIONS.md` به‌روز شده‌اند.

### ۷. الحاقیه اجرایی (۲۰۲۶-۱۰-۱۹) — ثبت بازطراحی تحت Spec Kit

این بند **اصل جدیدی اضافه نمی‌کند و هیچ‌یک از اصول ۱ تا ۱۲ یا دروازه‌های فاز را تغییر نمی‌دهد**؛ فقط تکلیف مدیریتی و مسیر شواهد را ثبت می‌کند.

1. **مالکیت Spec Kit:** بازطراحی فرانت‌اند خریدار با شناسه `005-purchaser-ui-redesign` تحت `specs/005-purchaser-ui-redesign/` ثبت شده است. `spec.md` مرجع الزامات این قلمرو و `tasks.md` **دفتر وضعیت رسمی** کارهای UI-PUR/BE-CR است.
2. **بسته ممیزی به‌عنوان شاهد:** `docs/frontend-redesign/review/deepseek/**` و گزارش Batch A ذیل `review/bunny/**` بسته **شواهد** باقی می‌مانند، نه منبع دوم الزامات و نه منبع موازی وضعیت. کارت‌ها با شناسه‌های اصلی خود (`UI-PUR-001…016`، `BE-CR-001…006`) حفظ شده‌اند.
3. **تأیید جزء‌به‌جزء:** تأیید `spec.md`، `plan.md` یا `tasks.md` به‌معنای تأیید هیچ کارت، هیچ Batch و هیچ `BE-CR` نیست. کارت‌های `READY_FOR_OWNER_APPROVAL` تأییدنشده‌اند.
4. **عدم دور زدن موانع:** هیچ تغییر Backend/قرارداد/مشخصات برای رفع انسداد مجاز نیست؛ سه کارت وابسته (`UI-PUR-003`، `007`، `014`) و لایه شواهد `UI-PUR-016` تا تأیید صریح مالک مسدود می‌مانند (`BE-CR-001…006`).
5. **تفکیک سطوح شواهد:** «اجراشده در همین گذر»، «ثبت‌شده در گزارش»، «تأییدشده در سورس» و «هنوز به‌دست‌نیامده» هرگز یکی نمی‌شوند؛ «تأیید فنی» جای «پذیرش بصری مالک محصول» را نمی‌گیرد.
6. **حاصل‌کار Git مجوزدار:** هیچ نوشتن Git بخشی از این الحاقیه نیست؛ هر عملیات Git نیازمند طرح شماره‌دار و تأیید صریح مالک است.
7. **به‌روزرسانی مستندات حاکمیتی فقط با شواهد:** هر ادعای بدون شاهد به‌صورت `Proposed`/`Open`/`Blocked` ثبت می‌شود، نه `Accepted`.

---

## English

### 1. Vision and success criteria

Transform Pettycash into a professional, legible, reliable financial application that feels integrated with Nextcloud. This is **not a CSS-only facelift**: visual design, user experience, correct UI behavior, accessibility, and maintainability all matter.

The **primary visual reference is [Nextcloud Budget](https://github.com/otherworld-dev/Budget)**. Aim for **strong similarity in visual language and page structure**—sidebar, cards, information hierarchy, form/table patterns, spacing, and overall rhythm—while preserving Pettycash-specific workflows and business rules.

Success requires product-owner-approved designs, evidence of browser behavior, and appropriate regression checks. A passing build alone does not establish completion.

### 2. In and out of scope

**In scope:** source and visual audits; fixes to UI foundations (localization, theming, component semantics); existing-workflow usability and behavior fixes; accessible bilingual responsive layouts; testing and documentation.

**Out of scope without separate approval:** new business features; API contract, financial-rule, authorization, or backend behavior changes; unjustified rewrites; competing UI frameworks; mandatory paid services; unreviewed copying of Budget source code or assets.

Existing Spec Kit requirements that have not yet been implemented are **not automatically in scope**. Backend work or feature expansion must be raised and approved as a separate change request.

### 3. Binding operating principles

1. **Discover before changing:** inspect the repository, `AGENTS.md`, Spec Kit, local changes, and actual browser behavior.
2. **Evidence over assumptions:** label observations, confirmed source findings, hypotheses, and browser-verified facts distinctly.
3. **Design before implementation:** each slice needs a specification and reviewable visual artifact approved by the product owner.
4. **Nextcloud integration:** retain the existing architecture where practical; respect the installed `@nextcloud/vue` version, theme conventions, accessibility, and repository rules. No competing UI framework without approval.
5. **Bilingual by design:** Persian and English with RTL/LTR driven by the user's Nextcloud language/direction; handle dates, numbers, amounts, currencies, forms, and tables correctly.
6. **Fix root causes:** do not conceal localization, contrast, or component misuse defects with cosmetic overrides.
7. **Prevent regressions:** retain APIs and financial behavior; use browser-based checks plus appropriate static/unit/E2E testing.
8. **Small and reversible changes:** use a dedicated Git branch, constrained slices, transparent reports, and no silent scope expansion.
9. **Zero budget, portable artifacts:** only open-source, free-tier, or free-quota tooling; keep durable artifacts in Git-friendly formats.
10. **Repository as source of truth:** these four governance files supersede the transient context of a chat or agent session.
11. **Protect sensitive data:** share only synthetic or sanitized data with third-party tools; never expose secrets or real private financial data.
12. **Respect licensing:** visual inspiration from Budget is fine; copying code, styles, icons, or other assets requires prior license review.

### 4. Roles and authority

| Role | Responsibility | Boundary |
|---|---|---|
| Product owner (user) | Sets priorities; approves scope, design, and release | Final decision maker |
| ChatGPT (consultant/reviewer) | Plans, checks consistency, reviews evidence | Cannot approve material changes unilaterally |
| Muse Spark 1.3 Free (architect) | Audits, constraints, executable specifications | No code changes unless explicitly assigned |
| Manus (agreed design aid) | Budget analysis and UI/UX prototypes within free quota | Not the architecture or production-code owner; exportable outputs required |
| Z.ai / Bunny (conditional implementer) | Implements approved slices when free access permits | No independent feature expansion or major design decisions |
| Playwright and test tooling | Browser behavior and screenshot evidence | Technical evidence, not product-owner sign-off |

Roles survive tool substitutions. Penpot, Figma, or a local prototype are **candidate** artifact repositories, not yet approved mandatory dependencies.

### 5. Approved phases and approval gates

`F0 Charter → F1 Pettycash + Budget audit → F2 UI foundations → F3 UX architecture → F4 Visual design & prototypes → F5 Incremental implementation → F6 Final verification`

Each phase must produce reviewed outputs before its gate opens. **F2 or any source-code change is forbidden before the product owner approves its correction scope.** See `ROADMAP.md` for details.

### 6. Definition of Done for an implementation slice

A slice is complete only when (1) problem and scope are documented, (2) approved specs/design exist, (3) changes stay within scope, (4) relevant tests were run and results recorded, (5) applicable light/dark, Persian/English, RTL/LTR, responsive, and loading/error/empty states were checked, (6) browser or screenshot evidence exists, (7) unresolved known regressions have explicit disposition, and (8) `STATUS.md` and, when necessary, `DECISIONS.md` are updated.

### 7. Execution addendum (2026-10-19) — the redesign registered under Spec Kit

This clause **adds no principle and changes none of principles 1–12 or any phase gate**; it records the management assignment and the evidence path only.

1. **Spec Kit ownership:** the purchaser frontend redesign is registered as `005-purchaser-ui-redesign` under `specs/005-purchaser-ui-redesign/`. `spec.md` is the requirement authority for this scope and `tasks.md` is the **canonical status ledger** for the `UI-PUR`/`BE-CR` work.
2. **The audit package is evidence:** `docs/frontend-redesign/review/deepseek/**` and the Batch A report under `review/bunny/**` remain the **evidence** package — not a second requirement source and not a parallel status source. Cards keep their original IDs (`UI-PUR-001…016`, `BE-CR-001…006`).
3. **Card-by-card approval:** approving `spec.md`, `plan.md`, or `tasks.md` approves no card, no batch, and no `BE-CR`. `READY_FOR_OWNER_APPROVAL` cards are not approved.
4. **No bypassing a block:** no backend, contract, or specification edit may unblock an item; the three dependent cards (`UI-PUR-003`, `UI-PUR-007`, `UI-PUR-014`) stay blocked until the owner explicitly approves the matching `BE-CR`. **Recorded 2026-10-23:** the owner approved `BE-CR-004` for the receipt/creation scope only, so the evidence tier of `UI-PUR-016` is unblocked for that scope; `BE-CR-001`, `002`, `003`, `005`, and `006` remain blocked.
5. **Evidence tiers stay separate:** "re-executed in this pass", "report-recorded", "source-verified", and "not yet obtained" are never collapsed; "technically verified" never stands in for product-owner visual acceptance.
6. **Git output is licensed:** no Git write is part of this addendum; every Git operation requires a numbered plan and explicit owner approval.
7. **Governance documents change only on evidence:** any claim without evidence is recorded as `Proposed`/`Open`/`Blocked`, never `Accepted`.

---

**Related / مرتبط:** [`ROADMAP.md`](ROADMAP.md) · [`DECISIONS.md`](DECISIONS.md) · [`STATUS.md`](STATUS.md)
