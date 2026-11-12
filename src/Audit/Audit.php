<?php

declare(strict_types=1);

namespace Canpas\Fleet\Audit;

use Canpas\Fleet\Support\Db;
use Canpas\Fleet\Support\Uuid;

/**
 * Append-only audit trail of every staff and customer action that changes data.
 * Records ids and codes, never names, ID numbers or document contents.
 * Call it inside the same transaction as the change it records, so both commit or neither does.
 */
final class Audit
{
    /** @param array<string, scalar|null> $meta Ids, codes, amounts and counts only. */
    public static function log(?string $companyId, int $actorUserId, string $action, string $objectType, ?string $objectId, array $meta = []): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? (defined('WP_CLI') && WP_CLI ? 'cli' : 'unknown');
        Db::insert('audit_log', [
            'id' => Uuid::v7(),
            'company_id' => $companyId,
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'ip_hash' => hash('sha256', $ip . wp_salt('auth')),
            'meta_json' => $meta === [] ? null : wp_json_encode($meta),
            'created_at' => Db::now(),
        ], ['id', 'company_id', 'object_id']);
    }
}
