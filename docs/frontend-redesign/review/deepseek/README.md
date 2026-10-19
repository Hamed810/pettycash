# DeepSeek technical audit — output area / محل خروجی Audit فنی

**Status / وضعیت:** not started / شروع نشده — waiting for product-owner approval of the intake / منتظر تأیید مالک محصول روی مرحلهٔ سازمان‌دهی

This directory is reserved for the next technical audit: comparing the Manus purchaser prototype and design references against the existing Spec Kit and the real Nextcloud/Vue implementation.

این پوشه برای Audit فنی بعدی نگه داشته شده است: مقایسهٔ Prototype و مراجع طراحی Manus با Spec Kit موجود و پیاده‌سازی واقعی Nextcloud/Vue.

## Rules for this directory / قواعد این پوشه

- Read-only outside this directory: do not modify `specs/`, `src/`, `lib/`, `tests/`, `appinfo/`, API contracts, or application configuration.
- No dependency installation, no `npm install`, no production build.
- No Git commit.
- State facts with evidence; separate observation, hypothesis, source-confirmed, and runtime-confirmed findings.
- Do not treat the prototype as production code, and do not treat the design images as a behavioral contract.
- Do not relabel any reference image as "approved" unless a product-owner confirmation exists; see `../MANUS_INTAKE_MANIFEST.md` §7 and §8.

## Suggested inputs / ورودی‌های پیشنهادی

- `../README_MANUS_INTAKE.md` and `../MANUS_INTAKE_MANIFEST.md`
- `../handoff/manus/01_PROJECT_CONTRACT_EN.md`, `02_DOMAIN_RECOVERY_SUMMARY_EN.md`, `03_QA_AND_KNOWN_MOCKUP_ERRORS.md`, `05_PROTOTYPE_DELIVERY_ACCEPTANCE.md`
- `../references/DESIGN_REFERENCES.md`
- `../prototype/manus-purchaser-v1/` (`README.md`, `DESIGN_TO_DOMAIN_MAPPING.md`, `KNOWN_GAPS.md`, `docs/EXPORT_MAPPING.md`, `app.js`)
- Repository: `AGENTS.md`, `specs/`, `docs/decisions/`, `docs/frontend-redesign/CHARTER.md`, `DECISIONS.md`, `ROADMAP.md`, `STATUS.md`
