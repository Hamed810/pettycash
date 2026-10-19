# Pettycash design components and tokens

## Design intent

English LTR is the master composition. Persian RTL mirrors reading order, action alignment, and inline content while retaining the familiar Nextcloud left navigation rail. The system stays quiet and operational: green for active/approved work, amber for review, red for correction/error, and neutral surfaces for completed or informational content.

## Core tokens

| Token | Light | Dark | Use |
| --- | --- | --- | --- |
| `--bg` | `#f5f6f7` | `#171b1c` | page canvas |
| `--surface` | `#ffffff` | `#222829` | cards, panels, form surfaces |
| `--surface-2` | `#f8faf9` | `#1d2324` | subtle field and header fill |
| `--surface-3` | `#eef3f1` | `#2a3333` | neutral chips and count badges |
| `--text` | `#20282a` | `#eef5f2` | primary text |
| `--muted` | `#6c777a` | `#a9b6b2` | secondary text and metadata |
| `--border` | `#dfe5e3` | `#394543` | dividers and card edges |
| `--accent` | `#087f6b` | `#55c4a7` | primary action and active state |
| `--accent-soft` | `#dff4ed` | `#183c35` | active/approved background |
| `--warning` | `#a66c12` | `#e3b45c` | review/pending state |
| `--danger` | `#b54343` | `#ef8a8a` | correction/error state |
| `--radius` | `14px` | `14px` | cards and panels |
| `--radius-sm` | `9px` | `9px` | fields, sidebar items |

## Components

**App shell** uses a 246px navigation rail, compact top bar, and a fluid content area capped at 1320px. No custom locale/theme switcher, workspace switcher, or prototype control strip appears in final exports; those are Nextcloud-owned concerns.

**Dashboard** contains role-sensitive existing-workflow buckets only: Open sheets, Awaiting review, Needs correction, and Completed. Quick links point to existing sheet browsing and create-sheet actions. No charts, invented balance totals, or new workflows are shown.

**Sheets List** contains the informative creator scope plus the supported status, currency, date-from, and date-to filters. The dense desktop table retains creator, currency, status, transaction count, active revision, current/submitted totals, latest effective outcome, stage-approved totals, key dates, and row actions. Mobile rows reflow into labelled stacked cards rather than dropping financial information.

**Create/Edit Sheet** keeps title, account, inherited currency, account balance, cancel, and create/update actions. Account branches are explicit: zero accounts show the valid default-provisioning path without a balance; one account is auto-selected; many accounts require a choice before showing the selected account balance and inherited currency.

**Status chip** uses a dot plus text and never relies on color alone. **Money and identifiers** use `dir="ltr"`/`bdi` isolation so Persian layouts do not reorder or clip currency, IDs, or dates.

## Review URLs

- English Dashboard light: `/?page=dashboard&lang=en&theme=light`
- English Sheets dark: `/?page=sheets&lang=en&theme=dark`
- English Create light, many accounts: `/?page=create&lang=en&theme=light&accounts=many`
- English Edit light, one account: `/?page=create&mode=edit&lang=en&theme=light&accounts=one`
- Persian Dashboard light: `/?page=dashboard&lang=fa&theme=light`
- Persian Sheets dark: `/?page=sheets&lang=fa&theme=dark`
- Persian Create zero accounts: `/?page=create&lang=fa&theme=light&accounts=zero`
