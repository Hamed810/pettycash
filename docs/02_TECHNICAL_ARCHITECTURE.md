# PettyCash Technical Architecture v0.4.3

## Architecture

Vue 3 Frontend \| OCS API \| Controllers \| Services \| Mappers /
Database / Storage

## Authorization Model

Authorization is centralized.

Access decision:

Capability + Data Scope + Workflow State

Modules must not implement separate permission logic.

## Export Architecture

Export flow:

Request -\> Authorization Service -\> Authorized Data Scope -\>
Generator -\> Audit Record
