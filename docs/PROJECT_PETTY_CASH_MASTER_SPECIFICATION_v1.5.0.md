# PROJECT PETTY CASH

# MASTER SPECIFICATION

## Product Requirements, Technical Specification & Development Governance

**Version:** 1.5.0\
**Status:** Current Development Baseline

------------------------------------------------------------------------

## Document Purpose

This document is the authoritative reference for Project Petty Cash.

It defines:

-   Product requirements
-   Business workflows
-   Technical architecture
-   User roles
-   Permission rules
-   Database principles
-   API architecture
-   UI structure
-   Migration rules
-   Git and release governance

The project baseline is:

    Git Version
    =
    Nextcloud App Version
    =
    Documentation Version

    v1.5.0

------------------------------------------------------------------------

# 1. Product Vision

Project Petty Cash is a Nextcloud application for controlled project
expense management.

The system provides:

-   Project based expense tracking
-   Multi-level approval workflow
-   Evidence management
-   Audit history
-   Controlled financial access
-   Export capability based on authorization scope

------------------------------------------------------------------------

# 2. Implemented Features

## Core Platform

Implemented:

-   Nextcloud application foundation
-   Vue 3 frontend
-   TypeScript frontend services
-   OCS API backend
-   Service layer architecture
-   Database entity and mapper pattern

## Master Data

Implemented:

-   Currency management
-   Expense categories
-   Projects
-   Project members
-   Vehicles

## Expense Workflow

Implemented:

-   Cost Lists
-   Transactions
-   Validation rules
-   Attachments foundation
-   Revision handling foundation

## Approval Workflow

Implemented:

-   Manager 1 approval
-   Manager 2 approval
-   Approve action
-   Return action
-   Reject action
-   Approval history foundation

## Administration

Implemented:

-   System settings
-   Master data administration

------------------------------------------------------------------------

# 3. Planned Features

Approved future modules:

-   Accountant approval workflow
-   Accountant return workflow
-   CSV export
-   Evidence package export
-   Reporting engine
-   OCR integration
-   Notifications
-   Advanced analytics

Planned features must not be represented as completed functionality.

------------------------------------------------------------------------

# 4. Roles and Responsibilities

## Administrator

Responsible for:

-   System configuration
-   Full administration
-   Master data

## Project Owner

Responsible for:

-   Project ownership
-   Project oversight
-   Project visibility

## Purchaser

Responsible for:

-   Creating Cost Lists
-   Entering expenses
-   Uploading evidence
-   Correcting returned items

Restrictions:

-   Cannot approve own expenses
-   Cannot access unrelated projects

## Manager 1

Responsible for first approval stage.

Actions:

-   Review
-   Approve
-   Return
-   Reject

## Manager 2

Responsible for second approval stage.

Actions:

-   Review
-   Approve
-   Return
-   Reject

## Accountant

Responsible for financial review.

Actions:

-   Approve
-   Return
-   Prepare accounting completion

------------------------------------------------------------------------

# 5. Permission Model

Authorization rule:

    Permission =
    Capability
    +
    Project Scope
    +
    Workflow State

Export follows the same authorization rules.

Every user may export:

-   CSV
-   Evidence packages
-   Reports

Only within their authorized scope.

------------------------------------------------------------------------

# 6. Workflow Model

Current workflow:

    OPEN

      |
      v

    MANAGER1_REVIEW

      |
      v

    MANAGER2_REVIEW

      |
      v

    ACCOUNTING

      |
      v

    COMPLETED

Returned records move back for correction while preserving history.

------------------------------------------------------------------------

# 7. Database Rules

Main entities:

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
-   pcash_ocr

Important rule:

Database records are accessed through:

    Controller
     -> Service
     -> Mapper
     -> Database

------------------------------------------------------------------------

# 8. Nextcloud Migration Rules

Permanent rules:

## Database Prefix

Never use:

    oc_pcash_currency

Use:

    pcash_currency

Nextcloud automatically applies the database prefix.

## Migration Rules

Every migration must:

-   Have a unique version
-   Never reuse old migration versions
-   Pass syntax validation
-   Pass occ upgrade
-   Verify oc_migrations

------------------------------------------------------------------------

# 9. Repository Structure

Recommended:

    pettycash/

    appinfo/
    lib/
    src/
    docs/
    tests/
    README.md
    CHANGELOG.md

Documentation belongs inside Git.

------------------------------------------------------------------------

# 10. Release Governance

Release synchronization:

    Git Tag
    =
    Nextcloud Version
    =
    Documentation Version

Release checklist:

-   Code completed
-   Documentation updated
-   Changelog updated
-   Version updated
-   Migration tested
-   Git tag created

Example:

    v1.5.0

------------------------------------------------------------------------

# 11. Decision Log

## Central Authorization

All permission decisions are centralized.

Reason:

Avoid inconsistent security rules.

## Export Authorization

Export uses the same access rules as viewing.

Reason:

Export is data access.

## Documentation Governance

Documentation follows Git versioning.

Reason:

Maintain traceability.

## Migration Prefix Handling

Never manually add Nextcloud database prefix.

Reason:

Nextcloud manages prefixes automatically.

------------------------------------------------------------------------

# Version History

  Version   Description
  --------- --------------------------------
  1.0       Initial specification
  1.1       Database foundation
  1.2       Cost List workflow
  1.3       Approval workflow
  1.4       Administration and master data
  1.5.0     Master specification baseline
