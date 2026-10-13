# Pettycash Frontend Redesign — Roadmap / نقشه راه بازطراحی فرانت‌اند

**Version / نسخه:** 1.0 · **Date / تاریخ:** 2026-10-11  
**Authoritative scope / مرجع دامنه:** [`CHARTER.md`](CHARTER.md)  
**Overall state / وضعیت کلی:** F0 approved in conversation; F1 next, not yet executed. / فاز F0 در گفتگو تصویب شده؛ F1 مرحله بعد است و هنوز اجرا نشده است.

> **Gate rule / قانون عبور:** Deliverables and product-owner acceptance are required before moving to the next phase. / برای ورود به هر فاز، تحویل خروجی و تأیید مالک محصول ضروری است.

## فارسی

### فازها و خروجی‌ها

| فاز | وضعیت | کار اصلی | تحویل‌دادنی‌ها | شرط خروج |
|---|---|---|---|---|
| **F0 — منشور** | ✅ تصویب در گفتگو؛ ثبت Git معلق | توافق بر اصول، Scope، بودجه، نقش‌ها | همین چهار سند حاکمیتی | تأیید توافق‌ها؛ ثبت فایل‌ها در مخزن |
| **F1 — شناخت و Audit** | ⬜ شروع نشده | تحلیل Pettycash و Budget، مرور کارکرد و تصاویر | Audit مبتنی بر شواهد، نقشه صفحات/نقش‌ها/گردش‌کارها، تحلیل مرجع Budget، فهرست شکاف‌ها و اولویت‌ها | تأیید گزارش و محدوده F2 |
| **F2 — تثبیت زیرساخت UI** | ⬜ شروع نشده | اصلاح باگ‌های بنیادیِ *تأییدشده* مطابق Scope مصوب | اصلاحات محدود، شواهد علت ریشه‌ای، تست‌های مرتبط | تأیید سلامت مبانی نمایش و نبود رگرسیون |
| **F3 — معماری UX** | ⬜ شروع نشده | طراحی مسیرهای کاربر، سلسله‌مراتب، ناوبری و وضعیت‌های UI | User flows، ساختار اطلاعات، page specs و قرارداد رفتار | تأیید UX توسط مالک محصول |
| **F4 — طراحی بصری و Prototype** | ⬜ شروع نشده | بازآفرینی نزدیک زبان بصری Budget برای نیازهای Pettycash | الگوی طراحی، نمونه قابل مشاهده، طراحی دو زبان/دو جهت/دو Theme | تأیید نمونه‌ها پیش از اجرا |
| **F5 — پیاده‌سازی تدریجی** | ⬜ شروع نشده | تغییر در برش‌های کوچک بر اساس طراحی مصوب | کد، گزارش تغییرات، آزمون، تصاویر قبل/بعد | تأیید هر برش طبق Definition of Done |
| **F6 — تأیید نهایی** | ⬜ شروع نشده | سناریوهای کامل، رگرسیون، دسترس‌پذیری، بازبینی و آماده‌سازی انتشار | ماتریس شواهد و گزارش پذیرش نهایی | پذیرش صریح مالک محصول |

### F1 — گام‌های پیشنهادی بدون تغییر کد

1. **F1.1 — Baseline:** ثبت SHA/branch مخزن و تفاوت‌های نسخه محلی با GitHub؛ مرور `AGENTS.md`، Spec Kit و تنظیمات واقعی؛ ثبت وضعیت تست‌ها. هیچ تغییری در این گام مجاز نیست.
2. **F1.2 — Pettycash inventory:** تهیه نقشه صفحات، نقش‌ها، مسیرها، فرم‌ها، کامپوننت‌ها، API interactionها و نقاط بحرانی workflow.
3. **F1.3 — UI foundation audit:** بررسی Localization، Theme/CSS variables، کاربرد کامپوننت‌های Nextcloud، Layout/Navigation، RTL/LTR و حالت‌های Loading/Error/Empty. برای هر ایراد، محل کد، شدت، شواهد و روش بازآزمایی ثبت شود.
4. **F1.4 — Visual/behavioral audit:** بررسی اسکرین‌شات‌ها و در صورت فراهم‌بودن محیط آزمایشی، مرور عملی دسکتاپ/موبایل، Light/Dark و فارسی/انگلیسی. نمایش کلید ترجمه، کادرهای هشدار و کنتراست ضعیف **مشاهدات اولیه** هستند، نه علت‌های اثبات‌شده.
5. **F1.5 — Budget reference study:** تحلیل Screenshot، ساختار صفحات، ناوبری، کارت‌ها، فرم‌ها، جدول‌ها، فاصله‌گذاری و رفتار تعاملی Budget در حد منابع قابل دسترس و رایگان. اگر نصب آزمایشی ممکن بود از آن استفاده شود؛ نصب Budget پیش‌نیاز قطعی پروژه نیست.
6. **F1.6 — Gap matrix:** نگاشت «صفحه/گردش‌کار Pettycash → مشکل → الگوی مناسب Budget → محدودیت Nextcloud → طرح اقدام → روش راستی‌آزمایی». اولویت‌بندی بر اساس شدت، فراوانی، اثر بر کاربر، ریسک و هزینه.
7. **F1.7 — Gate review:** ارائه یافته‌ها با شواهد، موارد حل‌نشده و Scope دقیق F2؛ **توقف برای تأیید مالک محصول** قبل از تغییر کد.

**حداقل خروجی F1:** `audit/pettycash/FRONTEND-AUDIT.md`، `audit/budget/REFERENCE-ANALYSIS.md`، `audit/GAP-MATRIX.md`، نقشه صفحه/گردش‌کار و مجموعه تصاویر پاک‌سازی‌شده. نام فایل‌های زیر Audit پیشنهاد ساختار هستند و تا شروع F1 فایل واقعی محسوب نمی‌شوند.

### F2 تا F6 — ترتیب و قواعد اجرا

- **F2:** فقط موارد بنیادی با علت تأییدشده و Scope مصوب؛ اصلاح مشکل با راه‌حل ریشه‌ای، نه پوشاندن آن با استایل. هر اصلاح با یک آزمون یا شاهد مرورگری همراه باشد.
- **F3:** از گردش‌کارهای موجود و نقش‌های واقعی شروع شود؛ قابلیت جدید ایجاد نشود. برای هر صفحه Layout معنایی، Actions، دسترسی، Validation و وضعیت‌های UI مشخص شود.
- **F4:** از الگوی Budget به‌شکل نزدیک و سازگار با Nextcloud پیروی شود. ابتدا نمونه سه صفحه **Dashboard، فهرست تنخواه‌ها، ایجاد تنخواه**؛ سپس صفحات جزئیات، تراکنش‌ها، بررسی و مدیریت بر اساس نیاز. تأیید بصری لازم است.
- **F5:** کار در branch مستقل و برش‌های کوچک؛ اجرای Build/Lint/Test و Playwright و بررسی Screenshot واقعی متناسب با هر برش؛ توقف روی خطاهای خارج از Scope و درخواست تصمیم.
- **F6:** سنجش مسیرهای اصلی به‌ازای نقش‌ها، RTL/LTR، Light/Dark، Responsive، دسترس‌پذیری، سناریوهای Error/Empty/Loading و هماهنگی با Spec Kit؛ ثبت استثناهای آگاهانه و تأیید نهایی.

### نقاط کنترل ثابت

در پایان هر فاز: (۱) تحویل مستندات/شواهد، (۲) ثبت اشکالات و ریسک‌ها، (۳) تأیید/رد مالک محصول، (۴) به‌روزرسانی `STATUS.md`، (۵) ثبت تصمیم‌های جدید در `DECISIONS.md`. برچسب Done بدون شواهد کافی ممنوع است.

---

## English

### Phases and deliverables

| Phase | Status | Main work | Deliverables | Exit gate |
|---|---|---|---|---|
| **F0 — Charter** | ✅ Approved in chat; Git commit pending | Agree on principles, scope, budget, roles | Four governance documents | Approval and repository record |
| **F1 — Discovery & audit** | ⬜ Not started | Study Pettycash and Budget, browser evidence | Evidence-based audit, page/role/flow inventory, Budget study, prioritized gaps | Product-owner acceptance of audit and F2 scope |
| **F2 — UI foundation stabilization** | ⬜ Not started | Fix confirmed foundational defects in approved scope | Bounded fixes, root-cause evidence, relevant tests | Foundation checks pass, no unaccepted regressions |
| **F3 — UX architecture** | ⬜ Not started | User journeys, information hierarchy, navigation, UI states | User flows, information architecture, page/behavior specifications | UX approval |
| **F4 — Visual design & prototypes** | ⬜ Not started | Closely adapt Budget's visual language to Pettycash | Design patterns, reviewable prototypes, bilingual/bidirectional/two-theme variants | Explicit visual approval before coding |
| **F5 — Incremental implementation** | ⬜ Not started | Implement approved slices | Code, change reports, tests, before/after screenshots | Definition of Done + slice approval |
| **F6 — Final verification** | ⬜ Not started | End-to-end, regression, accessibility, release readiness | Evidence matrix and acceptance report | Explicit product-owner sign-off |

### F1 — proposed read-only work packages

1. **F1.1 — Establish a baseline:** record branch/commit and local-vs-GitHub differences; inspect `AGENTS.md`, Spec Kit, actual configuration, and current test evidence. No modifications.
2. **F1.2 — Inventory Pettycash:** map views, roles, routes, forms, components, API interactions, and critical user journeys.
3. **F1.3 — Audit UI foundations:** localization, theme/CSS variables, Nextcloud component semantics, layout/navigation, RTL/LTR, and loading/error/empty states. Each defect needs severity, code location, evidence, and verification method.
4. **F1.4 — Audit actual behavior:** study screenshots and, if a test environment is available, inspect desktop/mobile, light/dark and Persian/English behavior. Literal translation keys, warning-style containers, and low contrast are **initial observations**, not verified root causes.
5. **F1.5 — Study Budget:** analyze available screenshots and navigation, cards, forms, tables, spacing, interaction patterns. A free test installation may help but is **not a mandatory dependency**.
6. **F1.6 — Build a gap matrix:** map “Pettycash page/flow → issue → relevant Budget pattern → Nextcloud constraint → proposal → verification”; prioritize by severity, frequency, user impact, risk, and effort.
7. **F1.7 — Review gate:** present evidence, open questions, and the exact F2 fix scope; **stop for product-owner approval** before code changes.

**Minimum F1 outputs:** `audit/pettycash/FRONTEND-AUDIT.md`, `audit/budget/REFERENCE-ANALYSIS.md`, `audit/GAP-MATRIX.md`, page/flow inventory, and sanitized screenshots. These are future proposed paths, not claims that audit artifacts already exist.

### F2–F6 — implementation order and constraints

- **F2:** repair only verified foundational problems within an approved scope; use root-cause fixes rather than CSS camouflage, with tests/browser evidence.
- **F3:** derive UX from existing roles and journeys, without adding business features. Specify page hierarchy, actions, authorization, validation, and UI states.
- **F4:** closely follow the Budget reference while remaining compatible with Nextcloud. Prototype **Dashboard, Sheet List, and Create Sheet** first, then details, transactions, review, and administration as needed. Obtain visual approval.
- **F5:** work on a dedicated branch in small slices; run relevant build/lint/tests, Playwright, and real browser screenshot review; escalate out-of-scope needs before acting.
- **F6:** verify core journeys by role, RTL/LTR, light/dark, responsive layouts, accessibility, loading/error/empty cases, and Spec Kit alignment; document exceptions and obtain final sign-off.

### Recurring control point

At each gate: (1) deliver evidence, (2) record issues and risks, (3) obtain explicit accept/reject decision, (4) update `STATUS.md`, and (5) record new decisions in `DECISIONS.md`. Never mark Done based on agent self-report alone.

---

**Related / مرتبط:** [`CHARTER.md`](CHARTER.md) · [`DECISIONS.md`](DECISIONS.md) · [`STATUS.md`](STATUS.md)
