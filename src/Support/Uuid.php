<?php

declare(strict_types=1);

namespace Canpas\Fleet\Support;

/**
 * UUIDv7 ids. Stored as BINARY(16); passed around in PHP and SQL as 32-char lowercase hex
 * and converted with UNHEX()/HEX() inside queries, so raw bytes never go through escaping.
 */
final class Uuid
{
    /** New UUIDv7 as 32-char lowercase hex. */
    public static function v7(): string
    {
        $ms = (int) floor(microtime(true) * 1000);
        $bytes = substr(pack('J', $ms), 2, 6) . random_bytes(10);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return bin2hex($bytes);
    }

    /** Random v4-style UUID string with dashes, used for idempotency keys. */
    public static function v4String(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
    }

    public static function isHex(string $id): bool
    {
        return (bool) preg_match('/^[0-9a-f]{32}$/', $id);
    }

    public static function assertHex(string $id, string $label = 'id'): string
    {
        $id = strtolower($id);
        if (!self::isHex($id)) {
            throw new DomainError('invalid_id', "Invalid {$label}.");
        }
        return $id;
    }

    public static function isUuidString(string $s): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s);
    }
}
