# PettyCash API Reference v0.4.3

## API Principles

All APIs use the same authorization layer.

Future export APIs:

GET /api/v1/lists/{uuid}/export/csv

GET /api/v1/lists/{uuid}/export/evidence

The API returns only authorized data.

Unauthorized export attempts return forbidden.
