# Quickstart: In-App Navigation

**Feature**: `004-in-app-navigation` | **Date**: 2026-10-09

## Validate the fix end-to-end (manual, ~5 minutes)

Prerequisites: Lab running (`/home/ubuntu/projects/nextcloud-lab/bin/lab pettycash up`, `http://localhost:8081`), one administrator + one non-admin user, Vite dev or built app served via Nextcloud.

1. **Admin path**
   - Sign in as administrator → open Petty Cash (`/apps/pettycash/`).
   - Expect: visible navigation with Dashboard, Sheets, Queues, Accounts, Administration.
   - Click Administration → Configuration: expect the four reference-data sections (currencies, cost centers incl. senior-manager field, expense types, finance assignments).
   - Click back to Dashboard via navigation (no address-bar edit).
2. **Reviewer path**
   - Sign in with a manager/finance role → dashboard shows the applicable queue link(s); click through to queue → detail → back to queue → back to dashboard.
3. **Non-admin path**
   - Sign in as plain purchaser → no Administration entry visible; typing `#/admin/configuration` still loads the shell but server answers 403 on data (unchanged enforcement).
4. **Layout**
   - Repeat step 1 at 390px width and in RTL: navigation usable, no horizontal scroll, keyboard Tab reaches every entry.

## Automated validation

```bash
npm test -- tests/Frontend/Component/Navigation.spec.ts
npm test -- tests/Frontend/routeNameIntegrity.spec.ts
npm run lint
```

Expected: all green; `routeNameIntegrity` proves no invented route names; `NextcloudVueContract` proves no Vuetify drift.
