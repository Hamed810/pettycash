# PettyCash Product Requirements v0.4.3

## Purpose

Project PettyCash is a native Nextcloud application for controlled
project expense management.

## Core Workflow

Purchaser -\> Manager 1 -\> Manager 2 -\> Accountant

## Main Features

-   Project based expense management
-   Cost Lists
-   Transaction entry
-   Evidence management
-   Approval workflow
-   Reporting and export

## Roles

### Purchaser

Creates Cost Lists, enters expenses, uploads evidence, exports own
authorized data.

### Manager 1

Reviews assigned project expenses and approves, rejects, or returns
items.

### Manager 2

Performs second-level approval.

### Accountant

Processes final approved data and reports.

### Administrator

Manages configuration and full access.

## Export Rule

Every role may export, but only data inside their authorization scope.

Export does not bypass permissions.
