# Pettycash Frontend Redesign — Live Status / وضعیت جاری پروژه

**As of / تاریخ وضعیت:** 2026-10-11  
**Charter / منشور:** v1.0 — approved in conversation / مصوب در گفتگو  
**Current phase / فاز جاری:** **F0 approved → F1 ready, not started** / **F0 تصویب شده ← F1 آماده شروع، ولی شروع نشده**  
**Code changes / تغییرات کد:** **None authorized or performed by this documentation package / هیچ تغییری مجاز یا توسط این بسته انجام نشده**  
**Repository sync / ثبت در مخزن:** **Pending user commit / در انتظار کپی و ثبت توسط کاربر**

## فارسی — خلاصه قابل استفاده برای ادامه گفتگو

**هدف:** بازطراحی کامل UI/UX و اصلاح اشکالات مرتبط Pettycash در Nextcloud، با شباهت زیاد به ظاهر اپلیکیشن Budget، بدون قابلیت تجاری جدید و بدون تغییر Backend/API مگر پس از تصویب مستقل.

**اصول ثابت:** بودجه صفر؛ فارسی و انگلیسی از ابتدا؛ RTL/LTR، Dark/Light، Responsive، دسترس‌پذیری و Playwright؛ طراحی مصوب قبل از اجرای هر برش؛ Muse معمار، Manus طراح در سهمیه رایگان، Bunny مجری مشروط، مالک محصول تأییدکننده.

**آنچه انجام شده:** توافق بر اهداف و فازها در گفتگو، انتخاب Budget به‌عنوان مرجع اصلی، مشاهده سه تصویر از Dashboard / Sheets List / Create Sheet، تنظیم بسته دوزبانه منشور/نقشه راه/تصمیمات/وضعیت.

**آنچه هنوز انجام نشده:** ثبت در GitHub؛ Audit کامل و قابل استناد روی نسخه مشخص سورس؛ تأیید علت ریشه‌ای مشکلات مشاهده‌شده؛ تحلیل جامع Budget؛ طراحی نهایی و Prototype؛ هرگونه اصلاح یا پیاده‌سازی؛ اجرای آزمون جدید.

**مشاهدات اولیه از تصاویر (نه یافته ریشه‌ایِ تأییدشده):** نمایش کلیدهای ترجمه به‌جای متن؛ کادرهای زردِ شبیه هشدار پیرامون محتوای معمول؛ کنتراست ضعیف بعضی کارت‌های Dashboard؛ مشکلات چیدمان و سلسله‌مراتب بصری. برای هر کدام شواهد سورس/مرورگر در F1 لازم است.

### اقدام دقیق بعدی

1. این فایل‌ها را در `docs/frontend-redesign/` مخزن Pettycash قرار بده و در Git ثبت کن.
2. **F1.1 فقط خواندنی** را آغاز کن: `AGENTS.md` و Spec Kit و وضعیت Git/تفاوت محلی را بررسی و commit/branch مبنا را ثبت کن.
3. F1 را با Audit مستقل Pettycash و مطالعه Budget ادامه بده؛ یافته‌ها را بر اساس «مشاهده/فرضیه/تأیید سورس/تأیید مرورگر» دسته‌بندی کن.
4. قبل از **F2 یا هر تغییر کد**، گزارش F1 و Scope اصلاحات پیشنهادی را برای تأیید مالک محصول ارائه کن.
5. پس از هر تصمیم/پیشرفت، این فایل و در صورت لزوم `DECISIONS.md` را به‌روز کن.

**Blockers / موانع:** مانع قطعی برای آغاز Audit فقط‌خواندنی ثبت نشده است. دسترسی به محیط مرورگر، وضعیت ابزار رایگان، انتخاب ابزار نگهداری طرح و امکان نصب Budget باید طی F1 روشن شوند؛ نباید بی‌دلیل از ابتدا پیش‌نیاز فرض شوند.

**Next gate / دروازه بعد:** پذیرش خروجی F1 و تصویب Scope دقیق F2 توسط مالک محصول.

## English — restart handoff

**Goal:** Comprehensive Pettycash frontend UX/UI and related behavior repair inside Nextcloud, with strong visual/layout similarity to Budget. No new business features; backend and API changes need separate approval.

**Fixed constraints:** zero cash budget; Persian and English from day one; RTL/LTR, light/dark, responsive, accessibility and Playwright; approved design before implementation. Muse is the architect, Manus the free-quota design aid, Bunny the conditional implementer; the product owner approves.

**Done:** goals/phases agreed in chat; Budget selected as the primary reference; three UI screenshots supplied (Dashboard, Sheet List, Create Sheet); bilingual charter, roadmap, decision log, and status drafted.

**Not done:** repository commit, source-version-specific full audit, root-cause confirmation, comprehensive Budget study, final visual design/prototype, any app-code implementation, or new test execution.

**Initial visual observations, not verified root causes:** literal translation keys, warning-looking yellow content wrappers, low contrast on parts of Dashboard, weak layout/hierarchy. Require source and browser evidence in F1.

### Exact next actions

1. Copy these governance files to Pettycash `docs/frontend-redesign/` and commit them.
2. Begin **read-only F1.1**: inspect `AGENTS.md`, Spec Kit, Git state, local-vs-remote differences; record baseline branch/commit.
3. Audit Pettycash and study Budget; label observations, hypotheses, source-confirmed and browser-confirmed facts separately.
4. **Stop before F2 or any app code changes** and present F1 evidence plus proposed F2 scope for product-owner approval.
5. Update this status and `DECISIONS.md` at each gate or material decision.

**Blockers:** none confirmed for starting read-only discovery. Browser access, free-tier tool limits, design-artifact tool selection, and availability of a Budget test install should be assessed during F1, not assumed as mandatory prerequisites.

**Next gate:** product-owner acceptance of F1 findings and explicit approval of F2 remediation scope.

---

## چرخه به‌روزرسانی / Status update protocol

**فارسی:** بعد از هر جلسه مؤثر یا تحویل یک Task، تاریخ، فاز، موارد تکمیل‌شده با شواهد، موارد باز، خطرها، اقدام بعد و لینک PR/commit (در صورت وجود) را اصلاح کن. هیچ نتیجه‌ای را بدون مدرک Done نزن. قبل از تغییر اصول به `CHARTER.md` و `DECISIONS.md` رجوع کن.

**English:** After each meaningful session or task delivery, update date, phase, evidence-backed completions, pending work, risks, the next action, and PR/commit references if available. Never mark work complete without evidence. Consult `CHARTER.md` and `DECISIONS.md` before changing principles.

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

**Related / مرتبط:** [`CHARTER.md`](CHARTER.md) · [`ROADMAP.md`](ROADMAP.md) · [`DECISIONS.md`](DECISIONS.md)
