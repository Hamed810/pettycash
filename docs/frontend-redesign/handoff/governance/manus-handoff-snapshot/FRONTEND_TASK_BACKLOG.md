# Pettycash Frontend — Draft AI Task Backlog / بک‌لاگ پیشنهادی تسک‌های هوش مصنوعی

**Status / وضعیت:** Planning candidates, **not confirmed code defects** / تسک‌های پیشنهادی؛ نقص‌های کد هنوز روی آخرین نسخه راستی‌آزمایی نشده‌اند.  
**Reference / مرجع:** Nextcloud Budget visual language; Pettycash existing workflows only.  
**Baseline prerequisite:** Agent records clean Git status, branch, HEAD and current route/view inventory before finalizing file-level scope.  
**Priorities:** P0 essential for visual baseline; P1 core journeys; P2 final consistency.  
**Rule:** Do not execute an implementation ticket until its page design and acceptance criteria have owner approval.

| ID | Priority | عنوان / Task | Scope / محدوده | Acceptance / معیار پذیرش (minimum) | Dependency |
|---|---|---|---|---|---|
| UI-00 | P0 | قفل‌کردن مبنای فعلی / Pin latest baseline | Read-only current routes/views, Git SHA, Nextcloud Vue version, key existing workflows. No bug campaign. | Repository version and screen inventory recorded; any stale earlier report clearly labeled. | None |
| UI-01 | P0 | راهنمای بصری Budget / Budget design reference | Sidebar, header, cards, forms, tables, spacing, states, typography; screenshots + rationale. No source/code copying. | Reference board identifies patterns to keep/adapt/reject and their Nextcloud implications. | UI-00 |
| UI-02 | P0 | سه Prototype مرجع / Three reference prototypes | Dashboard, Sheets List, Create Sheet in a Budget-like style, with existing Pettycash actions. | Reviewable desktop+mobile; FA RTL and EN LTR; light/dark; owner-approved. No speculative business features. | UI-01 |
| UI-03 | P0 | قرارداد Design System / UI system contract | Shared layout, sidebar, page header, surface, table, form, button, spacing, tokens and UI-state patterns. | Reusable guidelines mapped to installed `@nextcloud/vue` APIs; avoid competing framework and hardcoded theme colors. | UI-02 |
| UI-04 | P0 | زیرساخت RTL/i18n/theme / Localization & theme | Translation catalog loading/fallback, bidirectionality, CSS theme compatibility, accessible contrast. | No raw translation keys in chosen test screens; two locales/two themes usable; root-cause fixes only. | UI-03 |
| UI-05 | P0 | پوسته و ناوبری / App shell and navigation | Sidebar/app layout, active states, page header, breadcrumbs, responsive nav. | Navigation remains role-aware; keyboard/back behavior works; no page overflow at target mobile width. | UI-03 |
| UI-06 | P1 | داشبورد / Dashboard | Existing information and permitted actions only; Budget-inspired cards and hierarchy. | Accurate data; working actions; loading/error/empty; FA/EN and themes; screenshot approved. | UI-04, UI-05 |
| UI-07 | P1 | فهرست تنخواه / Sheets list | Headers, quick action, filters, table/list density, row actions, states. | Current filters/actions still function; mobile presentation readable; screenshot approved. | UI-04, UI-05 |
| UI-08 | P1 | ایجاد/ویرایش تنخواه / Sheet form | Layout, labels, validation, amounts/currency/account selection consistent with existing API. | No broken validation/navigation; supported create/edit scenarios; bilingual layouts approved. | UI-04, UI-05 |
| UI-09 | P1 | جزئیات تنخواه / Sheet detail | Financial summary, transaction section, permitted edit/submit actions, status/review timeline where already implemented. | Current workflow actions discoverable; role/status restrictions unchanged; evidence from browser. | UI-07, UI-08 |
| UI-10 | P1 | صفحات تراکنش و اسناد / Transactions & evidence | Transaction list/detail/create/edit, file/evidence UI using existing capabilities. | Existing forms, uploads, edit/navigation remain usable; failures explained; no new data model. | UI-09 |
| UI-11 | P1 | صف‌ها و صفحات بررسی / Review workflows | Role-specific queues, review detail, approve/return interactions already implemented. | Visibility and permissions preserved; review journey verified for existing roles. | UI-09, UI-10 |
| UI-12 | P1 | حساب، تأمین اعتبار، تنظیمات و گزارش / Other screens | Accounts, Funding, Configuration, Reports, Audit (only if present in current HEAD). | Each existing page uses shared patterns; no omitted functional actions; role gating preserved. | UI-05, UI-10 |
| UI-13 | P2 | تثبیت تجربه مشترک / UX polish | Unified loading, error, empty, success, confirmations, keyboard, responsive and theme checks across screens. | FA/EN RTL/LTR; light/dark; mobile no horizontal clipping; accessible focus/labels. | UI-06..UI-12 |
| UI-14 | P2 | پذیرش نهایی فرانت / Frontend acceptance | Focused Playwright smoke flows, before/after screenshots, build/typecheck/unit as appropriate. | Test evidence and known issues list, no newly introduced regressions, owner visual approval. | UI-13 |
| BE-CR | Conditional | درخواست تغییر محدود Backend / Backend change request | Only when a proven UI requirement cannot be satisfied within current backend/API. Separate ticket with precise endpoint, delta, contract/test impact. | Owner explicitly approves the exact change before implementation; no generic backend refactor. | Any affected UI ticket |

## Task card template / الگوی هر تسک اجرایی

```markdown
### UI-XX — <Persian title / English title>
- Status / وضعیت: Proposed | Approved | In Progress | Verified
- Source baseline / نسخه مبنا: <branch + SHA>
- Outcome / نتیجه: <user-visible end state>
- Screens / صفحات: <route(s) verified from current code>
- Allowed source scope / محدوده فایل‌ها: <actual paths verified from HEAD>
- Design reference / مرجع طرح: <approved mockup, screenshot or prototype>
- Preserve / موارد ثابت: <existing business behavior, permission, endpoints>
- Acceptance / پذیرش: <checkable behavior + FA/EN, RTL/LTR, themes, responsive>
- Tests / آزمون‌ها: <commands, browser journeys and screenshot cases>
- Dependencies / وابستگی‌ها: <ticket IDs>
- Backend impact / اثر Backend: None | BE-CR requested (NOT preapproved)
- Out of scope / خارج از محدوده: unrelated bugs, business features, backend redesign
- Completion evidence / شواهد: <commit/PR, checks, screenshots, remaining risks>
```

## Delivery rules / قواعد تحویل

**فارسی:** UI-00 تا UI-03 برنامه‌ریزی و طراحی هستند، نه اجازه تغییر کد تولیدی. برای سرعت، بعد از تأیید UI-02/UI-03 می‌توان UI-04/UI-05 و سپس برش‌های صفحه را به عامل واگذار کرد. پیش از واگذاری هر UI-06 تا UI-12 مسیرهای واقعی و فایل‌های آن از نسخه جاری استخراج و در کارت تسک ثبت شوند. «تمام شدن فازهای توسعه» به معنی اجازه افزودن قابلیت‌های جدید نیست. عیب‌های نامرتبط در backlog مستقل ثبت شوند.

**English:** UI-00..UI-03 are planning/design tickets, not blanket implementation approval. After design approval, implement UI-04/UI-05, then page slices. Verify actual paths and routes against the current repository before assigning UI-06..UI-12. Keep unrelated defects in a separate bug backlog. Do not treat previous development phase completion as feature-expansion authorization.
