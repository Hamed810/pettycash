# PettyCash Migration Rules v0.4.3

## Permanent Rules

-   Migration versions always increase.
-   Never reuse migration versions.
-   Test every migration with occ upgrade.

Database names:

Correct: pcash_currency

Incorrect: oc_pcash_currency

Use Nextcloud database abstraction.
