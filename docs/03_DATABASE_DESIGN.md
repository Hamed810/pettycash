# PettyCash Database Design v0.4.3

## Tables

-   pcash_project
-   pcash_member
-   pcash_list
-   pcash_txn
-   pcash_revision
-   pcash_action
-   pcash_attach
-   pcash_audit
-   pcash_currency
-   pcash_category
-   pcash_vehicle

## Rules

Never manually add Nextcloud prefix in migrations.

Correct: pcash_currency

Incorrect: oc_pcash_currency

Financial history must not be physically deleted.
