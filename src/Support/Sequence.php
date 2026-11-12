<?php

declare(strict_types=1);

namespace Canpas\Fleet\Support;

/**
 * Human-readable reference numbers, e.g. ORD-2611-0057 (prefix, YYMM in Manila time, counter).
 * The counter resets each month. Backed by cf_sequences; the row lock taken by the upsert
 * serializes concurrent callers, so two orders can never get the same number.
 */
final class Sequence
{
    public static function next(string $name): int
    {
        $affected = Db::exec(Db::prepare(
            'INSERT INTO ' . Db::t('sequences') . ' (name, value) VALUES (%s, 1)
             ON DUPLICATE KEY UPDATE value = LAST_INSERT_ID(value + 1)',
            $name
        ));
        // 1 = new row inserted (value 1); 2 = existing row updated (LAST_INSERT_ID holds the new value).
        return $affected === 1 ? 1 : (int) Db::value('SELECT LAST_INSERT_ID()');
    }

    /** ref('ORD') => ORD-2611-0001. Four digits, widening automatically past 9999 in a month. */
    public static function ref(string $prefix, ?\DateTimeImmutable $at = null): string
    {
        $yymm = ($at ?? Db::todayManila())->format('ym');
        $n = self::next($prefix . '-' . $yymm);
        return sprintf('%s-%s-%04d', $prefix, $yymm, $n);
    }
}
