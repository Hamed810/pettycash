# Pettycash Frontend Redesign — Decision Log / دفتر تصمیمات

**Version / نسخه:** 1.0 · **Consolidated / تاریخ تنظیم:** 2026-10-11  
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

## موضوعات باز و پیشنهادها / Open items and proposals

| ID | فارسی — موضوع نیازمند تصمیم | English — decision still required | Status / gate |
|---|---|---|---|
| **P-001** | ابزار نگهداری طرح نهایی: Penpot، Figma Starter یا Prototype محلی؟ هیچ‌یک شرط آغاز F1 نیست. | Choose a design artifact home (Penpot, Figma Starter, or local prototype). Not a blocker for F1. | **Proposed — before F4** |
| **P-002** | برای بررسی عملی Budget آیا نصب نسخه آزمایشی در Nextcloud ممکن و مفید است؟ جایگزین خواندن مخزن/تصاویر هم پذیرفتنی است. | Decide whether a Budget test installation is useful/available; code/screenshots are an acceptable fallback. | **Proposed — during F1** |
| **P-003** | پس از Audit، محدوده دقیق اصلاحات اولیه F2 باید جداگانه تأیید شود. | Approve the exact F2 remediation backlog after F1 evidence is reviewed. | **Open — F1 gate** |
| **P-004** | برای هر برش F5، طرح و معیار پذیرش باید جداگانه تصویب شوند. | Approve design and acceptance criteria for each F5 implementation slice. | **Open — per slice** |

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
