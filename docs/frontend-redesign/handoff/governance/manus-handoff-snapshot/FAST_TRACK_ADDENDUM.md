# Pettycash Frontend — Fast-Track Delivery Addendum / الحاقیه تحویل سریع فرانت‌اند

**Date / تاریخ:** 2026-10-15  
**Status / وضعیت:** Proposed documentation update based on the product owner's latest direction / پیش‌نویس ثبت تغییر رویکرد براساس دستور تازه مالک محصول  
**Applies alongside / همراه با:** `CHARTER.md`, `ROADMAP.md`, `DECISIONS.md`, `STATUS.md` v1.0. This document does **not** silently replace accepted decisions / این سند مصوبات قبلی را خودکار باطل نمی‌کند.

## فارسی

### وضعیت جدید اعلام‌شده توسط مالک محصول
- وضعیت Git تمیز شده و فایل‌های نهایی بارگذاری شده‌اند. این اطلاعات **اظهار مالک محصول** است؛ عامل اجرا قبل از تغییرات، branch و commit مورد کار را ثبت می‌کند.
- فازهای توسعه برنامه به پایان رسیده‌اند؛ این ادعا به معنای بدون‌اشکال‌بودن برنامه یا تأیید آزمون‌ها نیست.
- **هدف جاری:** نهایی‌کردن سریع تجربه و ظاهر Frontend و تحویل مجموعه‌ای از تسک‌های شفاف برای عامل هوش مصنوعی.
- رفع عمومی عیب‌های کل برنامه فعلاً هدف نیست؛ تنها نقص‌هایی بررسی می‌شوند که پیاده‌سازی یا استفاده از طراحی تأییدشده را مسدود کنند.
- بازنویسی کل برنامه و افزودن قابلیت تجاری جدید ممنوع است.
- تغییر Backend فقط در صورت ضرورتِ مستند برای تجربه Frontend مجاز به پیشنهاد است و اجرای آن نیازمند تأیید جداگانهٔ تغییر دقیق قرارداد/رفتار است.

### مسیر سریع اجرا (به‌جای Audit طولانی و پشت‌سرهم)
1. **Rapid discovery & reference lock:** یک گذر کوتاه روی **نسخه جاری** صفحات و قراردادهای Frontend، به‌علاوهٔ الگوهای منتخب Budget؛ ثبت شکاف‌های مستقیم طراحی، نه عیب‌یابی جامع.
2. **Design freeze:** ابتدا سه صفحه Dashboard، Sheets List و Create Sheet با الهام نزدیک از Budget؛ تعیین الگوهای مشترک، UI states و نسخه‌های فارسی/انگلیسی. تأیید بصری مالک محصول.
3. **Task-pack freeze:** تبدیل طرح تأییدشده به تسک‌های مستقل همراه با ورودی، فایل‌های محتمل، محدودیت، معیار پذیرش، تست و وابستگی؛ هر تسک کوچک و قابل بازگشت باشد.
4. **AI implementation:** پیاده‌سازی گام‌به‌گام با Muse به‌عنوان معمار/Reviewer و Bunny یا عامل رایگان مناسب به‌عنوان مجری؛ Playwright برای شواهد مرورگری.
5. **Focused release verification:** فقط مسیرهای کلیدی کاربران و تناسب بصری و چندزبانهٔ رابط، بدون بازکردن پروژهٔ عمومی رفع عیب.

### قواعد ثابت
- Nextcloud-native، Budget به‌عنوان مرجع بصری، بودجه نقدی صفر، فارسی/انگلیسی و RTL/LTR از ابتدا، Light/Dark، Responsive و Accessibility.
- حفظ رفتار مالی، دسترسی‌ها، API و داده‌ها مگر تغییر دقیقاً تصویب شود.
- تا تأیید طرح و Scope، هیچ بازنویسی در کد تولیدی آغاز نشود؛ طراحی بخش‌ها به‌صورت برش‌های کوتاه قابل تصویب است.
- هیچ Agent نمی‌تواند از روی فهرست تخمینی تسک‌ها ادعای وجود قطعی فایل یا باگ داشته باشد: مسیرها باید با HEAD فعلی بررسی شوند.
- باگ‌های موجود که مانع کار طراحی نیستند در **separate defect backlog** ثبت می‌شوند، نه الزاماً در این پروژه حل.
- طرح‌ها و تسک‌ها باید در Git به شکل قابل انتقال ذخیره شوند؛ اعتبار رایگان Manus یا Z.ai نباید پیش‌نیاز باشد.

### نقطه کنترل
پس از طراحی سه صفحه مرجع و پیش از اولین پیاده‌سازی، مالک محصول ظاهر و معیارهای پذیرش را تأیید می‌کند. تغییر Backend با ticket مستقل، اثر روی قرارداد، ریسک و تأیید جداگانه انجام می‌شود.

## English

### Updated owner direction
- The owner reports Git is now clean, final files are uploaded, and all development phases are finished. These are **owner-reported states**; the implementation agent should pin the current branch/commit. Development completion is not defect-free acceptance.
- **Immediate priority:** finalize frontend look and UX quickly, then hand off clear, bounded AI implementation tickets.
- Avoid a broad bug-fixing campaign. Capture unrelated existing defects separately; address only blockers of an approved frontend experience.
- Do not rebuild the application or add business features.
- Permit **proposed** backend changes only where a concrete frontend requirement cannot be met otherwise; require explicit separate approval before changing API contracts, business logic, or authorization.

### Fast-track sequence
1. **Rapid discovery + Budget reference:** short assessment of current frontend screens and contracts; focus on design-impacting facts.
2. **Approve visual direction:** first prototype Dashboard, Sheets List, and Create Sheet; include shared design patterns, UI states, FA/EN and theme behavior.
3. **Freeze actionable tickets:** independent, testable and reversible tasks with scope, dependencies, acceptance criteria and evidence.
4. **Execute via AI:** Muse as architect/reviewer; Bunny or available free-tier implementer; Playwright as verification.
5. **Focused frontend acceptance:** visual fidelity, critical current workflows, localization, responsive, themes and accessibility; defer unrelated backend bugs.

### Unchanged constraints
Budget-like visual language; Nextcloud integration; zero cash spend; bilingual FA/EN, RTL/LTR; light/dark; responsive and accessible UI; preserve existing financial rules/API unless approved separately; no new business features. Final source paths and root causes must be checked against the current clean HEAD, not historical reports.

### Approval gate
Approve the three-page reference design and corresponding acceptance criteria before production edits. Any backend adjustment requires its own change ticket and sign-off.
