<?php
declare(strict_types=1);
namespace OCA\PettyCash\Domain\Shared\Enum;
enum AuditEventType: string { case Submitted = 'SUBMITTED'; case Routed = 'ROUTED'; case Decided = 'DECIDED'; case Returned = 'RETURNED'; case Corrected = 'CORRECTED'; case EvidenceAdded = 'EVIDENCE_ADDED'; case EvidenceDeactivated = 'EVIDENCE_DEACTIVATED'; case Settled = 'SETTLED'; }
