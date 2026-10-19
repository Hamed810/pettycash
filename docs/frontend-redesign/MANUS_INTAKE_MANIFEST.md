# Manus Intake Manifest / فهرست ورود مواد Manus

**Created / ایجاد:** 2026-10-19 · **Branch / شاخه:** `main` · **Base commit / commit مبنا:** `6006f28400e8962909afab0744460152353c12fe`
**Scope / دامنه:** file organization and provenance only. No Spec Kit audit, no implementation tasks, no application-code change.

---

## خلاصهٔ فارسی

- مأموریت این بود که محتویات آرشیو `docs/Review uploaded pasted content.zip` مرتب، طبقه‌بندی و با هش ثبت شود؛ **هیچ فایلی از اپلیکیشن، Spec Kit یا بستهٔ حاکمیتی موجود تغییر نکرد.**
- **۷۲ فایل** از آرشیو مبدأ سازمان‌دهی شد: ۲۲ فایل سورس Prototype، ۲۳ مرجع بصری و متادیتا (۲۲ تصویر + ۱ سند وضعیت)، ۲۴ سند انتقال/طراحی/حاکمیتی، و ۳ فایل آرشیوی. سه سند راهنما هم تازه نوشته شد؛ ۵ سند حاکمیتی قبلی دست‌نخورده ماند.
- ۹ نسخهٔ تکراری شناسایی شد (۵ مورد در سطح آرشیو مبدأ، ۴ مورد عمدی در سطح مخزن). جزئیات در بخش «Duplicate report».
- هیچ فایل ناخوانا یا خرابی وجود نداشت؛ اما ۴ فایل با پسوند `.png` در واقع WebP هستند.
- مسئلهٔ باز مهم: بستهٔ Manus ادعا می‌کند طراحی‌ها «تأییدشده»اند، ولی هیچ سند ثبت‌شدهٔ مخزن Pettycash این تأیید را تأیید نکرده؛ همچنین ثبت‌های تأیید در خود بستهٔ Manus با یکدیگر ناسازگارند. **این تناقض به‌صورت خودکار حل نشد.**
- Audit فنی شروع **نشده** است؛ خروجی آن باید فقط در `review/deepseek/` نوشته شود.

---

## 1. Source material

| Item | Value |
|---|---|
| Source archive (repository) | `docs/Review uploaded pasted content.zip` |
| Size / SHA-256 | 31,391,301 B / `f19e6c68541ef3b5335b2cab570673a0c7adb29a55376a050364cecd2de9d901` |
| Archive entries | 23 files, no directory entries, no path traversal, no absolute paths (`unzip -l` inspection) |
| Extracted for inspection at | `/tmp/opencode/pc-manus-intake/` (outside the repository) |
| Original archive | **left in place, unmodified**; not copied into `docs/frontend-redesign/` to avoid a 31 MB duplicate |

Nested archives found inside the source archive:

| Nested archive | Size / SHA-256 | Role | Handling |
|---|---|---|---|
| `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip` | 19,457,689 B / `870f8d757eb87fddd627b7053408a52f3100b7cbfd43c3658e1bfee5f2e0cfd3` | Manus account-transfer package: contract, inventory, acceptance test, 11 desktop references, mobile references, governance snapshot, legacy notes, 2 legacy prototype ZIPs | fully extracted into the workspace (see §3) |
| `pettycash-purchaser-prototype-2026-10-19.zip` | 6,959,671 B / `e1e1731fda6d17a0645f9c4118fca172ed31d3e031c3178405eb56d1e336fcb0` | Runnable purchaser prototype source, v1.0.0 | fully extracted into `prototype/manus-purchaser-v1/` |

Neither nested archive was re-copied into `docs/frontend-redesign/`, because all of its content is already extracted there and the originals stay inside the preserved source archive.

---

## 2. What the archive actually contained (content-based classification)

Classification was made from file contents, headers, dimensions, and in-document references — not from filenames alone. Two filename-level deceptions were found and are recorded in §5.

| Content class | Files | Notes |
|---|---|---|
| Desktop design references | 11 | `01…11_*.png`, 1536×1024 PNG |
| Unsaved Changes dialog reference | 1 | `unsaved-changes-en-desktop-v1.png`, 1536×1024 PNG |
| Mobile design references (uploaded) | 6 unique | 390×693 PNG, stored in the archive under a folder named `approved_desktop_references/` |
| Mobile design references (generated in the new Manus account) | 3 | 1536×2304 PNG in prototype assets; plus WebP re-encodings at archive root |
| Contact sheet | 1 | `mobile_contact_sheet.png`, actually WebP 1120×1040; tiles 7 mobile screens + the Unsaved Changes desktop screen |
| Manus prototype source tree | 22 | complete `pettycash-purchaser-prototype-2026-10-19.zip` tree, filed at `prototype/manus-purchaser-v1/` |
| ↳ prototype documentation (subset of the 22) | 7 | `README.md`, `CHANGELOG.md`, `DESIGN_TO_DOMAIN_MAPPING.md`, `KNOWN_GAPS.md`, `docs/EXPORT_MAPPING.md`, `plan.md`, `TODO.md` |
| ↳ prototype app exports (subset of the 22) | 2 | `exports/sheets-list-desktop.png` (1440×1000), `exports/sheets-list-mobile.png` (390×1154) |
| Handoff / transfer documents | 11 | numbered `00`–`08`, the raw prompt paste, the mobile-upload placeholder |
| Design decisions & correction notes | 6 | `DESIGN_REFERENCES.md`, `DESIGN_ISSUES_TO_FIX.md`, `DECISION_ADDENDUM_DRAFT.md`, plus 4 legacy v2 design documents |
| Governance documents (archive copies) | 7 | snapshot shipped inside the Manus package; see §6 |
| Legacy prototype archives | 2 | kept as ZIPs, not extracted into the references tree |
| Manus platform documentation | 1 | `SKILL.md` — the Manus ImageGen skill definition; not Pettycash project material |
| Duplicates / renditions | 18 | 14 flattened prototype copies, 1 repeated mobile PNG, 3 WebP renditions — see §4 |

---

## 3. Organized inventory

Every row below was copied with `cp -p` (timestamps preserved). Copy operations refuse to overwrite an existing destination; no destination file was replaced.

**72 files** were organized from the source archive; **3 files** are newly authored intake documents. Total in this listing: **75 files**, **37,064,165 bytes**.

| Destination | Bytes | SHA-256 | Original path inside source archive |
|---|---|---|---|
| `docs/frontend-redesign/MANUS_INTAKE_MANIFEST.md` | (this file) | — (self-referential) | (authored by this intake) |
| `docs/frontend-redesign/README_MANUS_INTAKE.md` | 12,159 | `adb96e9ce72ab101ae79b66eaa1411954faa03b87b9e9c8882423c3efb937eec` | (pre-existing / authored by this intake) |
| `docs/frontend-redesign/_archives/legacy-prototypes/pettycash-sheets-modal-prototype-v2.zip` | 1,036,506 | `0fd4b8bb8d91cd47eb959c3d7064f84a23a14acaa0bc33655a6e4c3cc7983233` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!legacy_prototypes/pettycash-sheets-modal-prototype-v2.zip |
| `docs/frontend-redesign/_archives/legacy-prototypes/pettycash-ui-prototype-original-manus.zip` | 1,625,467 | `86317076bcb58983d35ded6c02cf7f6f6bb84efe28d03276dde9f32bfe3c823f` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!legacy_prototypes/pettycash-ui-prototype-original-manus.zip |
| `docs/frontend-redesign/_archives/manus-platform/SKILL.md` | 19,920 | `bdbf86931215b8e4a51ba893edba6468d7327246c10b07e18337a31a58a183c6` | `Review uploaded pasted content.zip`!SKILL.md |
| `docs/frontend-redesign/handoff/design-notes/DECISION_ADDENDUM_DRAFT.md` | 5,202 | `6e4f5aabbf149f1d9862720afea49afa3dd690162768ced93de3b15195fc29dc` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!legacy_reference_notes/DECISION_ADDENDUM_DRAFT.md |
| `docs/frontend-redesign/handoff/design-notes/DESIGN_ISSUES_TO_FIX.md` | 3,489 | `5318364704311c51fe8a9d8094e1e8761b456b540a673800f7438f039775566d` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!legacy_reference_notes/DESIGN_ISSUES_TO_FIX.md |
| `docs/frontend-redesign/handoff/design-notes/legacy-prototype-v2/DESIGN-V2.md` | 3,275 | `0929bf5922a17a5d299bfb9040f03169a9d281918db8ed8940bd9f906f7ffcdc` | `pettycash-sheets-modal-prototype-v2.zip`!pettycash-sheets-modal-v2/DESIGN-V2.md |
| `docs/frontend-redesign/handoff/design-notes/legacy-prototype-v2/design-components-tokens.md` | 3,341 | `76be6de50b3813ef266a6b806591581e56f0c79536d121c65b044d8a4687f1f7` | `pettycash-sheets-modal-prototype-v2.zip`!pettycash-sheets-modal-v2/docs/design-components-tokens.md |
| `docs/frontend-redesign/handoff/design-notes/legacy-prototype-v2/design-rules.md` | 2,668 | `c2f8f6e0fc5cc9d401180d316d3988d51050a483b3144a534afbb303ba0acb88` | `pettycash-sheets-modal-prototype-v2.zip`!pettycash-sheets-modal-v2/docs/design-rules.md |
| `docs/frontend-redesign/handoff/design-notes/legacy-prototype-v2/sheets-list-purchaser-rules.md` | 1,379 | `14ab7e573d626f72b6fb4096cdea03920a73f0b64813ede492f9602b1bf2806c` | `pettycash-sheets-modal-prototype-v2.zip`!pettycash-sheets-modal-v2/docs/sheets-list-purchaser-rules.md |
| `docs/frontend-redesign/handoff/governance/manus-handoff-snapshot/CHARTER.md` | 14,977 | `f946fbd6ab82470988d216d2de6b87a3b123c60c3804ed21fd8b418ea0e9fe26` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!governance_snapshot/CHARTER.md |
| `docs/frontend-redesign/handoff/governance/manus-handoff-snapshot/DECISIONS.md` | 8,403 | `20e7799838b2e5b6bccbe2bac277c292c6982953005ad1258073ab43a2f9052b` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!governance_snapshot/DECISIONS.md |
| `docs/frontend-redesign/handoff/governance/manus-handoff-snapshot/FAST_TRACK_ADDENDUM.md` | 6,902 | `8608c24b6e5442c8f2c7be8f27dcc9f0d77a355cd489a2e5359ec4900d8d910a` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!governance_snapshot/FAST_TRACK_ADDENDUM.md |
| `docs/frontend-redesign/handoff/governance/manus-handoff-snapshot/FRONTEND_TASK_BACKLOG.md` | 7,619 | `1b2752da5f61a3619ae29730373c6981e23eef696c5eae747d1bd7eaf8bb5087` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!governance_snapshot/FRONTEND_TASK_BACKLOG.md |
| `docs/frontend-redesign/handoff/governance/manus-handoff-snapshot/README.md` | 3,367 | `a93be849f90c09fc96e3495c4710d6ef703692b4f821e1cf570acc5bfc783f41` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!governance_snapshot/README.md |
| `docs/frontend-redesign/handoff/governance/manus-handoff-snapshot/ROADMAP.md` | 12,041 | `0d46d390fc8586f474d7f5055de185f0594dee955675f2974207a1c0c69238f2` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!governance_snapshot/ROADMAP.md |
| `docs/frontend-redesign/handoff/governance/manus-handoff-snapshot/STATUS.md` | 7,370 | `a0c0f2968c254d3f5d2d64f58bd2d935de86a767d901b0bab125fd87fcb38b4e` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!governance_snapshot/STATUS.md |
| `docs/frontend-redesign/handoff/manus/00_START_HERE_FA.md` | 3,365 | `8e744ff3354ca6b2c3f36b14505d9475d16fa69a69b2c905d55447fd62c515b1` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!00_START_HERE_FA.md |
| `docs/frontend-redesign/handoff/manus/01_PROJECT_CONTRACT_EN.md` | 6,871 | `14d2d02a0e2d73fe79098e042efc7a453117b36908b52b4522c976a7fa569554` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!01_PROJECT_CONTRACT_EN.md |
| `docs/frontend-redesign/handoff/manus/02_DOMAIN_RECOVERY_SUMMARY_EN.md` | 3,158 | `e3a878d65482f1e7b4a73b1a1ca835f44da1e2d43a7720949a9f7825a69613fa` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!02_DOMAIN_RECOVERY_SUMMARY_EN.md |
| `docs/frontend-redesign/handoff/manus/03_QA_AND_KNOWN_MOCKUP_ERRORS.md` | 2,165 | `b4c41b339c35e8721a94d9d6929de9e1a74ac090ab1c2de8f3b58b41f3058b71` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!03_QA_AND_KNOWN_MOCKUP_ERRORS.md |
| `docs/frontend-redesign/handoff/manus/04_DESIGN_INVENTORY_AND_MISSING_ASSETS.md` | 2,368 | `101179632ba5a58734fe270189b5f371dde3ed049cd34ca7111a8db7243895e3` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!04_DESIGN_INVENTORY_AND_MISSING_ASSETS.md |
| `docs/frontend-redesign/handoff/manus/05_PROTOTYPE_DELIVERY_ACCEPTANCE.md` | 3,019 | `998b15ee3045dac66e9153fdbc4f8fbbee2c9ec5d83575f03b9180e761e251ef` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!05_PROTOTYPE_DELIVERY_ACCEPTANCE.md |
| `docs/frontend-redesign/handoff/manus/06_TRANSFER_CHECKLIST_FA.md` | 1,529 | `6f1b1f2d9e3d9bdd6be22267997a815200f789368b50c360a8ba887092e4b584` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!06_TRANSFER_CHECKLIST_FA.md |
| `docs/frontend-redesign/handoff/manus/07_PASTE_TO_NEW_MANUS.md` | 5,776 | `418424d7ca9badab68a03743f8ea414fb777d588dd6047dc9392da5601928c56` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!07_PASTE_TO_NEW_MANUS.md |
| `docs/frontend-redesign/handoff/manus/08_FILE_MANIFEST.json` | 6,356 | `cec422d53816432e7d7776dbfb516d2d161402a86f8f21052a69e1fceca3d4e0` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!08_FILE_MANIFEST.json |
| `docs/frontend-redesign/handoff/manus/latest_mobile_upload_separately/PLACE_NEW_MANUS_MOBILE_PNGS_HERE.txt` | 232 | `ac3c267ed346846b764257416e86d057740f6b3a6f2f7622cac073585f6f0bc9` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!latest_mobile_upload_separately/PLACE_NEW_MANUS_MOBILE_PNGS_HERE.txt |
| `docs/frontend-redesign/handoff/manus/pasted_content.txt` | 5,682 | `b8ae58ce711df5b01d51c826d37a9ad98e1518b8478fd951684ad9ea6d9d1db5` | `Review uploaded pasted content.zip`!pasted_content.txt |
| `docs/frontend-redesign/prototype/manus-purchaser-v1/CHANGELOG.md` | 811 | `2c03481bf356aaafdbb2d5b9adf0ff66e2ea99a3f8af391859048b60f09c71d9` | `pettycash-purchaser-prototype-2026-10-19.zip`!CHANGELOG.md |
| `docs/frontend-redesign/prototype/manus-purchaser-v1/DESIGN_TO_DOMAIN_MAPPING.md` | 3,290 | `04ca19ae2a7af8e50d4fd8c64c4a446b6ea6e32e46dd19d04feda9ee4842d0d3` | `pettycash-purchaser-prototype-2026-10-19.zip`!DESIGN_TO_DOMAIN_MAPPING.md |
| `docs/frontend-redesign/prototype/manus-purchaser-v1/KNOWN_GAPS.md` | 1,551 | `da9e1592ce1fcfacb1798978f7d93a1533d7c57ae382779a92501a9acf40842e` | `pettycash-purchaser-prototype-2026-10-19.zip`!KNOWN_GAPS.md |
| `docs/frontend-redesign/prototype/manus-purchaser-v1/README.md` | 3,150 | `13a774322149cafba379c098804ca3b642000b4f30f3859fde8103f65b893ac5` | `pettycash-purchaser-prototype-2026-10-19.zip`!README.md |
| `docs/frontend-redesign/prototype/manus-purchaser-v1/TODO.md` | 1,932 | `0334f8ff91c07bc0fa557d9735614423b9a1e347b3ad13a48e858d4dc642f6a8` | `pettycash-purchaser-prototype-2026-10-19.zip`!TODO.md |
| `docs/frontend-redesign/prototype/manus-purchaser-v1/app.config.ts` | 135 | `dbfcaf88cd571f14ddc83ad19a7a6139ce771c0dd6216a695b7978b77f7d4db6` | `pettycash-purchaser-prototype-2026-10-19.zip`!app.config.ts |
| `docs/frontend-redesign/prototype/manus-purchaser-v1/app.js` | 45,008 | `150f3b7585062d6a0f0b8dad158a5017be9a5cce02db8d07488e46816bada790` | `pettycash-purchaser-prototype-2026-10-19.zip`!app.js |
| `docs/frontend-redesign/prototype/manus-purchaser-v1/assets/generated-mobile/generated_mobile_edit_draft.png` | 2,142,745 | `8ee431a4c422d7fb6b669e30f4cafb1ca113bc8fb71ae01034c861d025d7ecc1` | `pettycash-purchaser-prototype-2026-10-19.zip`!assets/generated-mobile/generated_mobile_edit_draft.png |
| `docs/frontend-redesign/prototype/manus-purchaser-v1/assets/generated-mobile/generated_mobile_revisions_history.png` | 2,201,480 | `29d7095259803ee1c5be083412320c179931054394628b68bb928e74aec6d995` | `pettycash-purchaser-prototype-2026-10-19.zip`!assets/generated-mobile/generated_mobile_revisions_history.png |
| `docs/frontend-redesign/prototype/manus-purchaser-v1/assets/generated-mobile/generated_mobile_view_transaction.png` | 2,149,472 | `8e50cfc4e69457709ffda5a42c7c348d8193d5d80d8221c2b8c4629c48710b2c` | `pettycash-purchaser-prototype-2026-10-19.zip`!assets/generated-mobile/generated_mobile_view_transaction.png |
| `docs/frontend-redesign/prototype/manus-purchaser-v1/assets/generated-mobile/unsaved-changes-en-desktop-v1.png` | 1,550,722 | `fc9f2d99dce6d8fa8772564674566c0a66cb1b7f729960253fdf6acab9f8ccc2` | `pettycash-purchaser-prototype-2026-10-19.zip`!assets/generated-mobile/unsaved-changes-en-desktop-v1.png |
| `docs/frontend-redesign/prototype/manus-purchaser-v1/assets/pettycash-mark.svg` | 348 | `d4951553efefa819b4c9fe7926266158d1d1d383872234d2330d0aebd552454e` | `pettycash-purchaser-prototype-2026-10-19.zip`!assets/pettycash-mark.svg |
| `docs/frontend-redesign/prototype/manus-purchaser-v1/docs/EXPORT_MAPPING.md` | 1,343 | `c458763237d23105d9a908d0cbc628ad54f2867d4de8876fb00abc867c2d0cbc` | `pettycash-purchaser-prototype-2026-10-19.zip`!docs/EXPORT_MAPPING.md |
| `docs/frontend-redesign/prototype/manus-purchaser-v1/exports/sheets-list-desktop.png` | 101,540 | `4ba8f6e2203aea61f94bed042108cc84a27bd2b691c6aedf4f21d4cba0c19b56` | `pettycash-purchaser-prototype-2026-10-19.zip`!exports/sheets-list-desktop.png |
| `docs/frontend-redesign/prototype/manus-purchaser-v1/exports/sheets-list-mobile.png` | 68,824 | `82dfb4f63bc4c46640cc54cf552c6905c4c0946ee3de7080ed0ab7cd53533087` | `pettycash-purchaser-prototype-2026-10-19.zip`!exports/sheets-list-mobile.png |
| `docs/frontend-redesign/prototype/manus-purchaser-v1/index.html` | 594 | `16324cfeee15cdce7b602ef1b0dba5fea0fadc6c598d03ccacf63a863eec4aa9` | `pettycash-purchaser-prototype-2026-10-19.zip`!index.html |
| `docs/frontend-redesign/prototype/manus-purchaser-v1/package-lock.json` | 272 | `c2e68303b34738606fffe09e3bffca843d6ff3c76cbf24682106f8891ec4b1ef` | `pettycash-purchaser-prototype-2026-10-19.zip`!package-lock.json |
| `docs/frontend-redesign/prototype/manus-purchaser-v1/package.json` | 342 | `e811932de6766f32c8a4252012bae6720c14861be9a35b1586b8d66dc002aa94` | `pettycash-purchaser-prototype-2026-10-19.zip`!package.json |
| `docs/frontend-redesign/prototype/manus-purchaser-v1/plan.md` | 3,747 | `0e25502351c9b17eafaf9e91c07b0b712b3f623c20ff7d2cac0bb9701091bf9c` | `pettycash-purchaser-prototype-2026-10-19.zip`!plan.md |
| `docs/frontend-redesign/prototype/manus-purchaser-v1/public/manus-routes.json` | 281 | `209b651d46160d5344bf3f9294bfcbfd284704cf3f2dc990ffbd14de7cbfc666` | `pettycash-purchaser-prototype-2026-10-19.zip`!public/manus-routes.json |
| `docs/frontend-redesign/prototype/manus-purchaser-v1/server.js` | 1,482 | `c7d49516181bcdde59fafdf87f705f3925b9d1fbd4a4e34b68b31c947a81c96b` | `pettycash-purchaser-prototype-2026-10-19.zip`!server.js |
| `docs/frontend-redesign/prototype/manus-purchaser-v1/styles.css` | 18,959 | `469c5bb413822543f18044addaacc5dfa5cb195714924b809defcec8fe2f91aa` | `pettycash-purchaser-prototype-2026-10-19.zip`!styles.css |
| `docs/frontend-redesign/references/DESIGN_REFERENCES.md` | 2,831 | `72d7dfd0dbfb5ddbd130ad8d9d58159b83c1185f7945ec50a6b7edb6ea9a303e` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!legacy_reference_notes/DESIGN_REFERENCES.md |
| `docs/frontend-redesign/references/contact-sheets/mobile_contact_sheet.png` | 717,064 | `4b777dbeee374eb3b467ae60f038dfd809d96f27eb964bbd40f06be372272ad5` | `Review uploaded pasted content.zip`!mobile_contact_sheet.png |
| `docs/frontend-redesign/references/desktop/01_sheets_list_desktop_EN_approved.png` | 1,261,383 | `98b510ebd76d2d33ec282f4d89103447033ee99c44776e1edb77549c99a705da` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!approved_desktop_references/01_sheets_list_desktop_EN_approved.png |
| `docs/frontend-redesign/references/desktop/02_sheet_detail_open_EN_reference.png` | 1,395,871 | `7596831907f79a7ce506ff86eaf904955c6b2a4de3ddefa34182d9b75b3b903e` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!approved_desktop_references/02_sheet_detail_open_EN_reference.png |
| `docs/frontend-redesign/references/desktop/03_sheet_detail_submitted_cc_EN_reference.png` | 1,451,295 | `e3c7f276a6ded848b6f938fca07455cf402574f002858be5ba7fcbdac0bd486e` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!approved_desktop_references/03_sheet_detail_submitted_cc_EN_reference.png |
| `docs/frontend-redesign/references/desktop/04_sheet_detail_completed_workflow_EN_reference.png` | 1,467,431 | `0f5846e52663583416b6dde6d00a31472e74cb1c9ab845cd38bff6acd5d3e0e4` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!approved_desktop_references/04_sheet_detail_completed_workflow_EN_reference.png |
| `docs/frontend-redesign/references/desktop/05_sheet_approvals_tab_EN_reference.png` | 1,516,690 | `3bfa4b80180f08841d2db9a28a1fb848918f7eab9c8bb3eb05b5f1a0d865e764` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!approved_desktop_references/05_sheet_approvals_tab_EN_reference.png |
| `docs/frontend-redesign/references/desktop/06_sheet_overview_tab_EN_reference.png` | 1,429,031 | `bdd65302742f73155df344dd77c92937ab25e61a41c1482534390e297dd8ba79` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!approved_desktop_references/06_sheet_overview_tab_EN_reference.png |
| `docs/frontend-redesign/references/desktop/07_sheet_history_tab_EN_reference.png` | 1,513,502 | `6cf399ce1033cd5d548e62941e34e387dcba34fd5ee26bebbd421b8f26e71cf1` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!approved_desktop_references/07_sheet_history_tab_EN_reference.png |
| `docs/frontend-redesign/references/desktop/08_transaction_create_EN_reference.png` | 1,341,091 | `37db9e9ef22f7eb4cd4f11d9c11b29243158e144e4ce7d6c906ec0f322f549d3` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!approved_desktop_references/08_transaction_create_EN_reference.png |
| `docs/frontend-redesign/references/desktop/09_transaction_correction_EN_reference.png` | 1,368,679 | `d9dec46ea57eed02e58260457e507945a3bd6fa858d8b20fe11e89427ab9cecb` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!approved_desktop_references/09_transaction_correction_EN_reference.png |
| `docs/frontend-redesign/references/desktop/10_transaction_read_only_EN_reference.png` | 1,393,013 | `a435feb8402623d8fd0f2190310603ffcfa13c692a725a2f50931178d184e97d` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!approved_desktop_references/10_transaction_read_only_EN_reference.png |
| `docs/frontend-redesign/references/desktop/11_transaction_read_only_revisions_EN_reference.png` | 1,412,874 | `2f38d97688464560720a1302bfe34e389de866bb7a6275a855f79137fbb2d9e7` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!approved_desktop_references/11_transaction_read_only_revisions_EN_reference.png |
| `docs/frontend-redesign/references/dialogs/unsaved-changes-en-desktop-v1.png` | 1,550,722 | `fc9f2d99dce6d8fa8772564674566c0a66cb1b7f729960253fdf6acab9f8ccc2` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!approved_desktop_references/unsaved-changes-en-desktop-v1.png |
| `docs/frontend-redesign/references/mobile/generated_mobile_edit_draft.png` | 2,142,745 | `8ee431a4c422d7fb6b669e30f4cafb1ca113bc8fb71ae01034c861d025d7ecc1` | `pettycash-purchaser-prototype-2026-10-19.zip`!assets/generated-mobile/generated_mobile_edit_draft.png |
| `docs/frontend-redesign/references/mobile/generated_mobile_revisions_history.png` | 2,201,480 | `29d7095259803ee1c5be083412320c179931054394628b68bb928e74aec6d995` | `pettycash-purchaser-prototype-2026-10-19.zip`!assets/generated-mobile/generated_mobile_revisions_history.png |
| `docs/frontend-redesign/references/mobile/generated_mobile_view_transaction.png` | 2,149,472 | `8e50cfc4e69457709ffda5a42c7c348d8193d5d80d8221c2b8c4629c48710b2c` | `pettycash-purchaser-prototype-2026-10-19.zip`!assets/generated-mobile/generated_mobile_view_transaction.png |
| `docs/frontend-redesign/references/mobile/sheet-detail-completed-with-rejections-purchaser-mobile-en-v1.png` | 305,440 | `3d31f0e0fbbbf16ed10b01c475a359f84ac804e0baf4f5b566bb88140dc50271` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!approved_desktop_references/sheet-detail-completed-with-rejections-purchaser-mobile-en-v1.png |
| `docs/frontend-redesign/references/mobile/sheet-detail-cost-center-review-purchaser-mobile-en-v1.png` | 288,570 | `e8f911f2531b59d90dacc78699a8f344205593ad0f15e089f86a9e8de7fd247a` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!approved_desktop_references/sheet-detail-cost-center-review-purchaser-mobile-en-v1.png |
| `docs/frontend-redesign/references/mobile/sheet-detail-open-purchaser-mobile-en-v1.png` | 282,593 | `c0fa6c830715750ca1007c72f90befd01f533ec1313c041eb73b8293b4c0ee2f` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!approved_desktop_references/sheet-detail-open-purchaser-mobile-en-v1.png |
| `docs/frontend-redesign/references/mobile/sheets-list-purchaser-mobile-en-v1.png` | 234,761 | `8a3f6316efe28b6e37b908665f3124bdbbdfbbbc7ff2f1cac9bd672a7df93b3a` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!approved_desktop_references/sheets-list-purchaser-mobile-en-v1.png |
| `docs/frontend-redesign/references/mobile/transaction-add-purchaser-mobile-en-v1.png` | 228,296 | `766d1fe64a94d2b3db80b2166491f121842561da249f0e3c2a5d7d694b80db21` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!approved_desktop_references/transaction-add-purchaser-mobile-en-v1.png |
| `docs/frontend-redesign/references/mobile/transaction-correction-purchaser-mobile-en-v1.png` | 274,288 | `3e6809ac70443e1386a7cc312528cde210ca6c398d56df48b2e586806ad53033` | `PETTYCASH_MANUS_NEW_ACCOUNT_HANDOFF_2026-10-19.zip`!approved_desktop_references/transaction-correction-purchaser-mobile-en-v1.png |
| `docs/frontend-redesign/review/deepseek/README.md` | 1,924 | `11b60b4f9520ea2da5307b10dca8a80dca5a21109ce67e4dcb5580f9d029fa8d` | (pre-existing / authored by this intake) |


---

## 4. Duplicate report

Detection used SHA-256 for byte identity and, where byte identity could not settle the question, a pixel-level comparison through the repository's existing Playwright/Chromium installation on the host.

### 4.1 Duplicates in the source archive (not duplicated into the workspace)

| # | Duplicate | Evidence | Action taken |
|---|---|---|---|
| 1 | 14 flattened files at archive root (`app.js`, `styles.css`, `server.js`, `index.html`, `package.json`, `app.config.ts`, `manus-routes.json`, `pettycash-mark.svg`, `plan.md`, `TODO.md`, `Changelog.md`, `Known Gaps.md`, `Export Mapping.md`, `Design to Domain Mapping.md`, `Pettycash Purchaser Prototype.md`) | byte-identical to the same members of `pettycash-purchaser-prototype-2026-10-19.zip` | canonical copies taken from the prototype ZIP; root copies not duplicated |
| 2 | `transaction-correction-purchaser-mobile-en-v1 (1).png` | byte-identical **and** pixel-identical to `transaction-correction-purchaser-mobile-en-v1.png` | single canonical copy placed in `references/mobile/` |
| 3 | `generated_mobile_edit_draft.png`, `generated_mobile_view_transaction.png`, `generated_mobile_revisions_history.png` at archive root | WebP-encoded (RIFF/VP8X) despite the `.png` extension; pixel-identical to the prototype's PNG assets of the same name (verified with Playwright/Chromium canvas comparison) | canonical PNG copies placed in `references/mobile/`; WebP renditions not duplicated |
| 4 | `unsaved-changes-en-desktop-v1.png` in the handoff references **and** in the prototype assets | byte-identical (`fc9f2d99dce6d8fa…`) | one canonical copy in `references/dialogs/`; prototype keeps its own copy for self-containment |
| 5 | `pasted_content.txt` vs `handoff/manus/07_PASTE_TO_NEW_MANUS.md` | same prompt, different form (raw paste vs. formatted markdown; the paste begins with a truncated `ETTYCASH`) | **both preserved** as distinct artifacts |

### 4.2 Duplicates retained intentionally inside the workspace (4)

| Files | Reason retained |
|---|---|
| `references/mobile/generated_mobile_*.png` (3) **=** `prototype/manus-purchaser-v1/assets/generated-mobile/*` | `references/` must be a complete design-reference registry; the prototype must stay self-contained and runnable. Documented here so the audit does not read it as an error. |
| `references/dialogs/unsaved-changes-en-desktop-v1.png` **=** `prototype/manus-purchaser-v1/assets/generated-mobile/unsaved-changes-en-desktop-v1.png` | same reasoning |
| `handoff/governance/manus-handoff-snapshot/CHARTER.md` **=** `CHARTER.md` (workspace root) | the snapshot is preserved as a complete frozen unit; see §6 |
| `handoff/governance/manus-handoff-snapshot/DECISIONS.md` **=** `DECISIONS.md` (workspace root) | same reasoning |

---

## 5. Missing, unreadable, or misrepresented files

| Item | Finding |
|---|---|
| Unreadable / corrupt files | **none** — all 23 archive entries extracted and inspected |
| Files with wrong extension | 4 files carry `.png` but are WebP: `generated_mobile_edit_draft.png`, `generated_mobile_view_transaction.png`, `generated_mobile_revisions_history.png`, `mobile_contact_sheet.png` (all at the source-archive root) |
| Files stored in a misleading folder | the 6 uploaded mobile references and 1 desktop dialog reference were shipped inside `approved_desktop_references/`, whose name implies desktop-only content. They were re-filed by actual dimensions (390×693 → `references/mobile/`; 1536×1024 → `references/dialogs/`). |
| Declared-missing items, now resolved | `handoff/manus/04_DESIGN_INVENTORY_AND_MISSING_ASSETS.md` lists items as "NOT present": (a) the eight latest mobile images — **found** in the archive as 7 unique mobile screens (390×693) plus the Unsaved Changes desktop reference; (b) any newer runnable prototype — **found** (`pettycash-purchaser-prototype-2026-10-19.zip`, v1.0.0, dated 2026-10-19, newer than both legacy archives); (c) Manus documentation delivered with the mobile images — **partially found** (`DESIGN_TO_DOMAIN_MAPPING.md`, `KNOWN_GAPS.md`, `docs/EXPORT_MAPPING.md`, `CHANGELOG.md`, plus the 3 generated mobile references); (d) a separate approved Unsaved Changes screenshot — **found** |
| Still open | whether the 7 mobile screens found here are the same eight images the owner uploaded to the old Manus account. `handoff/manus/04_DESIGN_INVENTORY_AND_MISSING_ASSETS.md` itself says "No need to assume precisely which eight images cover which screens until they have been opened and identified". The contact sheet counts exactly 8 tiles (7 mobile + 1 desktop). **Product-owner confirmation required.** |

---

## 6. Governance documents found in the archive

`handoff/manus/` and `handoff/governance/manus-handoff-snapshot/` were kept strictly separate from the workspace's own governance files. None of the existing files (`CHARTER.md`, `DECISIONS.md`, `ROADMAP.md`, `STATUS.md`, `README.md`) were modified, and no conflicting decision was merged automatically.

| Snapshot file | Relationship to the repository copy |
|---|---|
| `handoff/governance/manus-handoff-snapshot/CHARTER.md` | byte-identical to `docs/frontend-redesign/CHARTER.md` — pure duplicate |
| `handoff/governance/manus-handoff-snapshot/DECISIONS.md` | byte-identical to `docs/frontend-redesign/DECISIONS.md` — pure duplicate |
| `handoff/governance/manus-handoff-snapshot/STATUS.md` | **differs** from `docs/frontend-redesign/STATUS.md` — older snapshot; the repository file is authoritative |
| `handoff/governance/manus-handoff-snapshot/ROADMAP.md` | **differs** from `docs/frontend-redesign/ROADMAP.md` — older snapshot; the repository file is authoritative |
| `handoff/governance/manus-handoff-snapshot/README.md` | **differs** from `docs/frontend-redesign/README.md` — older snapshot; the repository file is authoritative |
| `handoff/governance/manus-handoff-snapshot/FRONTEND_TASK_BACKLOG.md` | **exists only in the archive**; not present anywhere in the repository |
| `handoff/governance/manus-handoff-snapshot/FAST_TRACK_ADDENDUM.md` | **exists only in the archive**; not present anywhere in the repository |

`handoff/manus/04_DESIGN_INVENTORY_AND_MISSING_ASSETS.md` states that this snapshot is historical and that "some decisions have changed since those were written", and that the new contract "does NOT overwrite repository governance". That statement matches the handling above.

---

## 7. Reference set and recorded approval status

**Read this before labelling anything "approved".** The statuses below are what the Manus-authored documents say. No document committed to the Pettycash repository records approval of any Manus screenshot, and the Manus documents disagree with each other (see §8). Nothing here should be treated as an approval record by itself.

| File (`references/…`) | Screen | Status as recorded in `references/DESIGN_REFERENCES.md` (Manus) |
|---|---|---|
| `desktop/01_sheets_list_desktop_EN_approved.png` | Purchaser Sheets List | "Owner FINAL visual"; date examples may be inaccurate |
| `desktop/02_sheet_detail_open_EN_reference.png` | Sheet Detail — OPEN | "Accepted visual direction" |
| `desktop/03_sheet_detail_submitted_cc_EN_reference.png` | Sheet Detail — submitted / Cost Center review | "Accepted visual direction" |
| `desktop/04_sheet_detail_completed_workflow_EN_reference.png` | Sheet Detail — completed + stage summaries | "Styling reference / **data NOT approved**" |
| `desktop/05_sheet_approvals_tab_EN_reference.png` | Approvals tab | "Visually accepted, semantics need correction" |
| `desktop/06_sheet_overview_tab_EN_reference.png` | Overview tab | "Visually accepted, semantics need correction" |
| `desktop/07_sheet_history_tab_EN_reference.png` | History tab | "Visually accepted, semantics need correction" |
| `desktop/08_transaction_create_EN_reference.png` | Add Transaction | "Visually accepted, source semantics incorrect" |
| `desktop/09_transaction_correction_EN_reference.png` | Returned transaction correction | "Visually accepted, source semantics need correction" |
| `desktop/10_transaction_read_only_EN_reference.png` | View Transaction (read-only) | "Visually accepted" |
| `desktop/11_transaction_read_only_revisions_EN_reference.png` | View Transaction with revisions | "Visually accepted" |
| `dialogs/unsaved-changes-en-desktop-v1.png` | Unsaved Changes guard | **conflicting** — see §8, item 1 |
| `mobile/sheets-list-purchaser-mobile-en-v1.png` | Sheets List, 390px | no per-file status recorded in `DESIGN_REFERENCES.md` |
| `mobile/sheet-detail-open-purchaser-mobile-en-v1.png` | Sheet Detail OPEN, 390px | no per-file status recorded |
| `mobile/sheet-detail-cost-center-review-purchaser-mobile-en-v1.png` | Sheet Detail / Cost Center review, 390px | no per-file status recorded |
| `mobile/sheet-detail-completed-with-rejections-purchaser-mobile-en-v1.png` | Sheet Detail completed with rejections, 390px | no per-file status recorded |
| `mobile/transaction-add-purchaser-mobile-en-v1.png` | Add Transaction, 390px | no per-file status recorded |
| `mobile/transaction-correction-purchaser-mobile-en-v1.png` | Correct returned transaction, 390px | no per-file status recorded |
| `mobile/generated_mobile_edit_draft.png` | Edit Draft, 390px | generated in the new Manus account; see `prototype/manus-purchaser-v1/docs/EXPORT_MAPPING.md` |
| `mobile/generated_mobile_view_transaction.png` | View Transaction, 390px | as above |
| `mobile/generated_mobile_revisions_history.png` | Revisions / History, 390px | as above |
| `contact-sheets/mobile_contact_sheet.png` | Contact sheet of the 7 mobile screens + Unsaved Changes | contact sheet only; carries no approval semantics |

---

## 8. Organization decisions requiring review

1. **Conflicting approval records for Unsaved Changes.** `references/DESIGN_REFERENCES.md` lists "Unsaved Changes V3" under "**Not yet approved**", while `handoff/manus/00_START_HERE_FA.md` says the desktop designs **and** Unsaved Changes "قبلاً پذیرفته شده‌اند" (were already accepted) and `handoff/manus/01_PROJECT_CONTRACT_EN.md` says the unsaved-change guard is "APPROVED visual and behavior". No automatic reconciliation was performed.
2. **Approval status of the 390px mobile screens is unrecorded.** No Manus document in the archive states a per-screen approval status for the 6 uploaded mobile references, and the repository records no approval at all. They are filed as references, not as approved designs.
3. **Workspace README naming.** The requested file `docs/frontend-redesign/README.md` already existed as the bilingual governance-kit index. To avoid destroying committed governance content, the workspace guide was written as `docs/frontend-redesign/README_MANUS_INTAKE.md` and the existing `README.md` was left byte-identical. If the owner wants a single README, that is a governance change and should be done deliberately.
4. **Governance snapshot placement.** The archive snapshot was moved under `handoff/governance/manus-handoff-snapshot/` rather than merged, per the "do not merge conflicting decisions automatically" rule. Two of its files are exact duplicates of repository files; the other five are older or archive-only.
5. **`DESIGN_REFERENCES.md` placement.** It was placed in `references/` (next to the images it describes) rather than with the other legacy notes, because its text already refers to "all files in `references/`". Original path in the archive: `legacy_reference_notes/DESIGN_REFERENCES.md`.
6. **Legacy prototype ZIPs were not extracted into the references tree.** `handoff/manus/04_DESIGN_INVENTORY_AND_MISSING_ASSETS.md` marks both as "**old** and not a final UI". They are preserved whole in `_archives/legacy-prototypes/`. However, the four design-decision documents inside `pettycash-sheets-modal-prototype-v2.zip` were extracted to `handoff/design-notes/legacy-prototype-v2/`, because they contain token/colour and layout decisions that the audit may need to cite. An inventory of both legacy archives is in §9.
7. **`SKILL.md` is not Pettycash material.** It is the Manus "ImageGen" skill definition, i.e. toolchain documentation showing how the Manus images were produced. It was preserved under `_archives/manus-platform/` rather than discarded, and it must not be read as project policy.
8. **The three WebP renditions were not copied.** They are pixel-identical to the prototype's PNG assets and remain available inside the preserved source archive.
9. **Japanese/Chinese or other stray content:** none found.
10. **No dependency was installed and no prototype code was executed.** A host-side Playwright/Chromium session was used only to compare pixel content of image files; it made no network request and touched no repository file.

---

## 9. Preserved archives not extracted into the workspace

| Archive (`_archives/legacy-prototypes/`) | Contents (top level) |
|---|---|
| `pettycash-ui-prototype-original-manus.zip` (1,625,467 B) | `index.html`, `styles.css`, `app.js`, `package.json`, `README.md`, `TODO.md`, `CHANGELOG.md`, `manus-routes.json`, `public/`, `assets/`, `docs/` (3 design documents), `exports/` (15 PNG exports: dashboard/sheets/create in EN and FA, desktop and 390px mobile, light and dark) |
| `pettycash-sheets-modal-prototype-v2.zip` (1,036,506 B) | `pettycash-sheets-modal-v2/` with `index.html`, `app.js`, `styles.css`, `package.json`, `README.md`, `README-V2.txt`, `CHANGELOG.md`, `DESIGN-V2.md`, `TODO.md`, `manus-routes.json`, `assets/pettycash-mark.svg`, `docs/` (3 design documents), `exports-v2/` (10 PNG exports incl. unsaved-confirm light/dark) |

Both are reachable without extraction through the preserved source archive as well.

---

## 10. Verification record

| Check | Result |
|---|---|
| All source materials accounted for | yes — every one of the 23 archive entries is either organized, or explicitly recorded as a duplicate/rendition in §4 and §5 |
| Prototype source complete | yes — 22 files, extracted from the prototype ZIP, README/`package.json`/`server.js`/`app.js`/`styles.css`/`index.html`/assets/exports/docs all present |
| Desktop and mobile images separated correctly | yes — by measured dimensions (1536×1024 desktop, 390×693 mobile), not by archive folder name |
| Application source unchanged | yes — `git status` shows no change under `src/`, `lib/`, `tests/`, `appinfo/` |
| Spec Kit unchanged | yes — no change under `specs/` |
| No existing file overwritten | yes — every copy target was pre-checked; the 5 pre-existing files in `docs/frontend-redesign/` are byte-identical and unmodified |
| Original archive still available | yes — `docs/Review uploaded pasted content.zip`, unmodified, SHA-256 `f19e6c68…d901` |
| Git status | only new untracked directories under `docs/frontend-redesign/`; the pre-existing modifications (`AGENTS.md`, `.claude/`, `CLAUDE.md`, the source ZIP) were already present before this task and were left untouched |
| Commit created | no |
| Dependencies installed | no |
| Prototype executed | no |
