# PettyCash Permission Matrix v0.4.3

## Formula

Permission = Capability + Scope + Workflow State

## Export Permissions

  -----------------------------------------------------------------------
  Role              CSV               Evidence Package  Reports
  ----------------- ----------------- ----------------- -----------------
  Purchaser         Own data          Own data          Own lists

  Manager 1         Assigned projects Assigned projects Assigned projects

  Manager 2         Assigned projects Assigned projects Assigned projects

  Accountant        Authorized        Authorized        Financial reports
                    projects          projects          

  Admin             All               All               All
  -----------------------------------------------------------------------

## Rule

If a user cannot view data, they cannot export it.
