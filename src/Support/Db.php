<?php

declare(strict_types=1);

namespace Canpas\Fleet\Support;

/**
 * Thin wrapper over $wpdb: table names, UTC time, transactions, and errors that throw.
 *
 * Conventions:
 *  - Ids are 32-char hex in PHP; queries wrap them in UNHEX(%s) and read them with LOWER(HEX(col)).
 *  - Every failed query throws DbError, so a transaction can never silently continue.
 */
final class Db
{
    public static function wpdb(): \wpdb
    {
        global $wpdb;
        return $wpdb;
    }

    /** Full table name, e.g. Db::t('orders') => wp_cf_orders */
    public static function t(string $name): string
    {
        return self::wpdb()->prefix . 'cf_' . $name;
    }

    public static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    /** Today's date in Manila, for validity windows and period keys. */
    public static function todayManila(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', new \DateTimeZone('Asia/Manila'));
    }

    /** Select expression for a binary id column as lowercase hex. */
    public static function hex(string $column, ?string $alias = null): string
    {
        $alias ??= str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column;
        return "LOWER(HEX({$column})) AS {$alias}";
    }

    /** @param mixed ...$args */
    public static function prepare(string $sql, ...$args): string
    {
        return $args === [] ? $sql : (string) self::wpdb()->prepare($sql, ...$args);
    }

    public static function exec(string $sql): int
    {
        $w = self::wpdb();
        $prev = $w->suppress_errors(true);
        $r = $w->query($sql);
        $w->suppress_errors($prev);
        if ($r === false || $w->last_error !== '') {
            throw new DbError($w->last_error !== '' ? $w->last_error : 'Query failed', $sql);
        }
        return (int) $r;
    }

    /** @return array<string, mixed>|null */
    public static function row(string $sql): ?array
    {
        $w = self::wpdb();
        $prev = $w->suppress_errors(true);
        $r = $w->get_row($sql, ARRAY_A);
        $w->suppress_errors($prev);
        if ($w->last_error !== '') {
            throw new DbError($w->last_error, $sql);
        }
        return $r ?: null;
    }

    /** @return list<array<string, mixed>> */
    public static function rows(string $sql): array
    {
        $w = self::wpdb();
        $prev = $w->suppress_errors(true);
        $r = $w->get_results($sql, ARRAY_A);
        $w->suppress_errors($prev);
        if ($w->last_error !== '') {
            throw new DbError($w->last_error, $sql);
        }
        return $r ?: [];
    }

    public static function value(string $sql): mixed
    {
        $w = self::wpdb();
        $prev = $w->suppress_errors(true);
        $r = $w->get_var($sql);
        $w->suppress_errors($prev);
        if ($w->last_error !== '') {
            throw new DbError($w->last_error, $sql);
        }
        return $r;
    }

    /**
     * INSERT a row. Columns listed in $binary hold hex ids and are written with UNHEX().
     * Null values are written as NULL.
     *
     * @param array<string, mixed> $data
     * @param list<string> $binary
     */
    public static function insert(string $table, array $data, array $binary = []): void
    {
        $cols = [];
        $vals = [];
        $args = [];
        foreach ($data as $col => $value) {
            $cols[] = "`{$col}`";
            if ($value === null) {
                $vals[] = 'NULL';
                continue;
            }
            $vals[] = in_array($col, $binary, true) ? 'UNHEX(%s)' : '%s';
            $args[] = is_bool($value) ? (int) $value : (string) $value;
        }
        $sql = 'INSERT INTO ' . self::t($table) . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')';
        self::exec(self::prepare($sql, ...$args));
    }

    public static function begin(): void
    {
        self::exec('START TRANSACTION');
    }

    public static function commit(): void
    {
        self::exec('COMMIT');
    }

    public static function rollback(): void
    {
        $w = self::wpdb();
        $prev = $w->suppress_errors(true);
        $w->query('ROLLBACK');
        $w->suppress_errors($prev);
    }

    /**
     * Run $fn inside one transaction; roll back on any throwable.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function transaction(callable $fn): mixed
    {
        self::begin();
        try {
            $result = $fn();
            self::commit();
            return $result;
        } catch (\Throwable $e) {
            self::rollback();
            throw $e;
        }
    }
}
