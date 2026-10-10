<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Nextcloud;

use OCA\PettyCash\AppInfo\Application;
use OCA\PettyCash\Application\Port\RetentionPolicyStore;
use OCP\IAppConfig;

/**
 * Nextcloud application configuration as the retention policy store.
 *
 * Constitution III keeps configuration on supported Nextcloud services, so this is `IAppConfig`
 * rather than a table. The value is stored as one JSON document under a single key: the record has
 * three fields that must agree with each other, and storing them separately would let a partial write
 * leave a policy whose `updatedAt` no longer describes its `retentionYears`.
 *
 * `load()` is total. A missing key, an unparsable value, or a value of the wrong shape all return
 * null, because a corrupt control setting must not be able to take down the read path that compliance
 * verification and every audit query depend on. The service then applies the FR-057 default and
 * reports the discrepancy instead of propagating a parse error.
 */
final class NextcloudRetentionPolicyStore implements RetentionPolicyStore
{
    private const CONFIG_KEY = 'audit_retention_policy';

    public function __construct(private readonly IAppConfig $appConfig) {}

    public function load(): ?array
    {
        if (!$this->appConfig->hasKey(Application::APP_ID, self::CONFIG_KEY)) {
            return null;
        }

        $raw = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY);
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $years = $decoded['retentionYears'] ?? null;
        if (!is_int($years)) {
            return null;
        }

        return [
            'retentionYears' => $years,
            'updatedAt' => isset($decoded['updatedAt']) && is_string($decoded['updatedAt'])
                ? $decoded['updatedAt']
                : '',
            'updatedBy' => isset($decoded['updatedBy']) && is_string($decoded['updatedBy'])
                ? $decoded['updatedBy']
                : '',
        ];
    }

    public function save(array $record): void
    {
        $this->appConfig->setValueString(
            Application::APP_ID,
            self::CONFIG_KEY,
            json_encode([
                'retentionYears' => $record['retentionYears'],
                'updatedAt' => $record['updatedAt'],
                'updatedBy' => $record['updatedBy'],
            ], JSON_THROW_ON_ERROR)
        );
    }
}
