# Pettycash Frontend Redesign / بازطراحی فرانت‌اند Pettycash

**Bilingual project governance kit / بسته دوزبانه اسناد راهبری پروژه** · **2026-10-11**

## فارسی

این پوشه برای حفظ توافق‌ها در طول گفتگوهای طولانی، تغییر مدل‌ها و جابه‌جایی ابزارها تهیه شده است. **مبنای رسمی تصمیمات، فایل‌های ثبت‌شده در Git است؛ نه حافظه چت.**

| فایل | کاربرد |
|---|---|
| [`CHARTER.md`](CHARTER.md) | منشور نسخه 1.0: هدف، دامنه، اصول، نقش‌ها، اختیارات و معیار پذیرش |
| [`ROADMAP.md`](ROADMAP.md) | فازهای F0 تا F6، تحویل‌دادنی‌ها، ترتیب کار و دروازه‌های تأیید |
| [`DECISIONS.md`](DECISIONS.md) | تصمیم‌های قطعی و مسائل باز؛ روش کنترل تغییرات |
| [`STATUS.md`](STATUS.md) | وضعیت فعلی و اقدام بعد؛ نقطه شروع هر چت یا Agent جدید |

**روش استفاده:** اول `STATUS.md` را بخوان، سپس `CHARTER.md` و `DECISIONS.md` را به‌عنوان محدودیت‌های لازم‌الاجرا بررسی کن و فعالیت مجاز بعدی را از `ROADMAP.md` بگیر. پس از هر مرحله فایل وضعیت را به‌روز کن. هیچ Agent نباید این اسناد را بدون تأیید مالک محصول دور بزند.

**نحوه افزودن به مخزن (از ریشه Repository):** محتویات این پوشه را به مسیر `docs/frontend-redesign/` منتقل کن، سپس وضعیت Git را بررسی و فقط فایل‌های مستندات را commit کن. این بسته هیچ کد اپلیکیشن را تغییر نمی‌دهد.

**یادآوری مهم:** F0 در گفتگو تصویب شده، اما تا زمان commit این اسناد، ادعا نکن که در Git ثبت شده‌اند. F1 هنوز شروع نشده و هیچ مأموریت پیاده‌سازی مجاز نشده است.

## English

This folder preserves decisions across long chats, model swaps, and changing tool availability. **Committed repository documents—not chat memory—are the durable source of truth.**

| File | Purpose |
|---|---|
| [`CHARTER.md`](CHARTER.md) | v1.0 project charter: goals, boundaries, operating rules, roles, acceptance criteria |
| [`ROADMAP.md`](ROADMAP.md) | F0–F6 phases, deliverables, work sequencing, and approval gates |
| [`DECISIONS.md`](DECISIONS.md) | Accepted decisions, open proposals, and change-control process |
| [`STATUS.md`](STATUS.md) | Current state, next exact action, and handoff for new chat/agent sessions |

**How to use:** read `STATUS.md` first; honor `CHARTER.md` and `DECISIONS.md`; take the next allowed step from `ROADMAP.md`. Update status at every significant handoff. Agents must not bypass these rules without product-owner approval.

**Add to repository:** copy this directory into `docs/frontend-redesign/` at the Pettycash repository root, review Git status, and commit only the intended documentation files. This kit makes no application-code changes.

**State caveat:** F0 was approved in conversation, not yet committed. F1 has not started, and implementation is not authorized.
