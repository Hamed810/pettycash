<?php

declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
    ],
    'ocs' => [
        // Session routes
        ['name' => 'session#me', 'url' => '/api/v1/me', 'verb' => 'GET'],
        ['name' => 'dashboard#index', 'url' => '/api/v1/dashboard', 'verb' => 'GET'],

        // Sheet routes
        ['name' => 'sheet#create', 'url' => '/api/v1/sheets', 'verb' => 'POST'],
        ['name' => 'sheet#index', 'url' => '/api/v1/sheets', 'verb' => 'GET'],
        ['name' => 'sheet#show', 'url' => '/api/v1/sheets/{sheetId}', 'verb' => 'GET'],
        ['name' => 'sheet#update', 'url' => '/api/v1/sheets/{sheetId}', 'verb' => 'PATCH'],
        // T417 / FR-086: owner deletion of a never-submitted sheet. Declared `DELETE` here, absent from
        // `routes.php` until now — which is why the requirement was unimplementable through any layer.
        ['name' => 'sheet#destroy', 'url' => '/api/v1/sheets/{sheetId}', 'verb' => 'DELETE'],
        ['name' => 'sheet#submit', 'url' => '/api/v1/sheets/{sheetId}/submit', 'verb' => 'POST'],

        // Transaction routes
        ['name' => 'transaction#create', 'url' => '/api/v1/sheets/{sheetId}/transactions', 'verb' => 'POST'],
        ['name' => 'transaction#show', 'url' => '/api/v1/transactions/{transactionId}', 'verb' => 'GET'],
        ['name' => 'transaction#update', 'url' => '/api/v1/transactions/{transactionId}', 'verb' => 'PATCH'],
        ['name' => 'transaction#destroy', 'url' => '/api/v1/transactions/{transactionId}', 'verb' => 'DELETE'],
        ['name' => 'transaction#correction', 'url' => '/api/v1/transactions/{transactionId}/correction', 'verb' => 'POST'],
        ['name' => 'transaction#resubmit', 'url' => '/api/v1/transactions/{transactionId}/resubmit', 'verb' => 'POST'],
        ['name' => 'audit#revisions', 'url' => '/api/v1/transactions/{transactionId}/revisions', 'verb' => 'GET'],
        ['name' => 'audit#timeline', 'url' => '/api/v1/transactions/{transactionId}/audit', 'verb' => 'GET'],

        // Evidence routes
        ['name' => 'evidence#upload', 'url' => '/api/v1/transactions/{transactionId}/evidence', 'verb' => 'POST'],
        ['name' => 'evidence#destroy', 'url' => '/api/v1/evidence/{evidenceId}', 'verb' => 'DELETE'],
        ['name' => 'evidence#content', 'url' => '/api/v1/evidence/{evidenceId}/content', 'verb' => 'GET'],
        ['name' => 'evidence#links', 'url' => '/api/v1/evidence/{evidenceId}/links', 'verb' => 'POST'],
        ['name' => 'evidence#deactivateLink', 'url' => '/api/v1/evidence-links/{linkId}/deactivate', 'verb' => 'POST'],

        // FR-031: the only unauthenticated data path in V1. Its action carries `#[PublicPage]` and
        // deliberately nothing else — no `#[NoAdminRequired]`, no application-level rate limiter, no
        // CAPTCHA. See `PublicEvidenceController::show()` for why each of those would be wrong, and for
        // why abuse control stays with Nextcloud's brute-force limiter rather than being reimplemented.
        //
        // Declared without an `X-PettyCash-Command-ID` requirement, matching the contract: consumption
        // mutates nothing business-side, so it is idempotent by the token and a command identifier would
        // imply a replay store it has no use for.
        ['name' => 'publicEvidence#show', 'url' => '/api/v1/public/evidence/{token}', 'verb' => 'GET'],

        // Workflow queue routes
        ['name' => 'review#index', 'url' => '/api/v1/queues/{stage}', 'verb' => 'GET'],
        ['name' => 'review#returns', 'url' => '/api/v1/returns', 'verb' => 'GET'],
        ['name' => 'review#decide', 'url' => '/api/v1/transactions/{transactionId}/decisions', 'verb' => 'POST'],

        // Configuration routes
        ['name' => 'configuration#currenciesIndex', 'url' => '/api/v1/configuration/currencies', 'verb' => 'GET'],
        ['name' => 'configuration#currencyCreate', 'url' => '/api/v1/configuration/currencies', 'verb' => 'POST'],
        ['name' => 'configuration#currencyUpdate', 'url' => '/api/v1/configuration/currencies/{currencyId}', 'verb' => 'PATCH'],
        ['name' => 'configuration#costCentersIndex', 'url' => '/api/v1/configuration/cost-centers', 'verb' => 'GET'],
        ['name' => 'configuration#costCenterCreate', 'url' => '/api/v1/configuration/cost-centers', 'verb' => 'POST'],
        ['name' => 'configuration#costCenterUpdate', 'url' => '/api/v1/configuration/cost-centers/{costCenterId}', 'verb' => 'PATCH'],
        ['name' => 'configuration#expenseTypesIndex', 'url' => '/api/v1/configuration/expense-types', 'verb' => 'GET'],
        ['name' => 'configuration#expenseTypeCreate', 'url' => '/api/v1/configuration/expense-types', 'verb' => 'POST'],
        ['name' => 'configuration#expenseTypeUpdate', 'url' => '/api/v1/configuration/expense-types/{expenseTypeId}', 'verb' => 'PATCH'],
        ['name' => 'configuration#financeAssignmentsIndex', 'url' => '/api/v1/configuration/finance-assignments', 'verb' => 'GET'],
        ['name' => 'configuration#financeAssignmentCreate', 'url' => '/api/v1/configuration/finance-assignments', 'verb' => 'POST'],

        // Account routes
        ['name' => 'account#index', 'url' => '/api/v1/accounts', 'verb' => 'GET'],
        ['name' => 'account#create', 'url' => '/api/v1/accounts', 'verb' => 'POST'],
        ['name' => 'account#charge', 'url' => '/api/v1/accounts/{accountId}/charges', 'verb' => 'POST'],
        // T409 / FR-013: administrator deactivation and reactivation of an account. Declared `PATCH`
        // here; absent until now, which is why no artifact could deactivate an account except through
        // owner deletion.
        ['name' => 'account#update', 'url' => '/api/v1/accounts/{accountId}', 'verb' => 'PATCH'],
        ['name' => 'account#movements', 'url' => '/api/v1/accounts/{accountId}/movements', 'verb' => 'GET'],

        // Report routes
        ['name' => 'report#show', 'url' => '/api/v1/reports/{reportType}', 'verb' => 'GET'],

        // Export routes
        ['name' => 'export#create', 'url' => '/api/v1/exports', 'verb' => 'POST'],
    ]
];