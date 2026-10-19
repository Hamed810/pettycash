# Known Gaps

This is a purchaser-only prototype, not production code. The following are intentionally not modeled:

- No Nextcloud/Pettycash repository edits, backend endpoints, database, authentication or live API calls.
- No manager, Cost Center manager, Senior, Finance or Administrator UI. Their actor labels appear only as synthetic audit examples.
- No real file picker, upload transport, MIME validation, size policy or evidence storage. The evidence action is a reversible local mock action after the transaction exists.
- No real server-side pagination or `BE-CR-01` change. List pagination is local over the complete fixture set.
- No API-level optimistic concurrency (`expectedVersion`, command-id), 409/422 response handling, or network retry.
- No production-grade Jalali date picker. The switch demonstrates independent preference and uses validated fixture pairs; free-form form date edits are illustrative text inputs.
- No real permission evaluation. Controls are driven by the synthetic fixture status and purchaser-only rules.
- No Sheet metadata edit form. The prototype demonstrates the authorized delete overflow action and OPEN-only submit/add behavior, but intentionally does not invent a generic Edit Sheet action.
- No production audit endpoint. History is assembled from per-transaction fixture audit data only.
- No bilingual Persian/RTL screens in this phase. The semantic structure is kept simple for later localization.
- Visual output is illustrative and may require owner review before any production implementation.
