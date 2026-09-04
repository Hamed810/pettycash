# PROJECT PETTY CASH - SECURITY MODEL

## Purpose

Defines security rules for financial data protection.

## Authentication

Authentication is provided by Nextcloud.

PettyCash does not manage passwords.

## Authorization Model

Access decision:

User Role + Project Scope + Workflow State + Capability

## Security Principles

-   Backend authorization is mandatory.
-   Frontend visibility is not security.
-   Financial history must be preserved.
-   Sensitive actions must be audited.

## Evidence Security

Evidence files must only be accessed after permission verification.

## Export Security

Exports are data access operations.

Required flow:

Request -\> Authorization -\> Data Filtering -\> Export -\> Audit

## Audit Requirements

Audit events are required for:

-   Create
-   Update
-   Approval
-   Return
-   Reject
-   Export
-   Administration changes
