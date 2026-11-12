<?php

declare(strict_types=1);

namespace Canpas\Fleet\Domain;

use Canpas\Fleet\Audit\Audit;
use Canpas\Fleet\Support\Db;
use Canpas\Fleet\Support\DomainError;
use Canpas\Fleet\Support\Uuid;
use Canpas\Fleet\Tenancy\Actor;

/**
 * Stock ledger for quantity-tracked items (formwork, scaffolding, tools).
 * Stock on hand is always the sum of movements; nobody types a total over it.
 */
final class Stock
{
    public const REASONS = [
        'purchase' => 'Bought / received',
        'opening' => 'Opening count',
        'adjustment' => 'Count correction',
        'damaged' => 'Damaged, out of use',
        'repaired' => 'Repaired, back in use',
        'write_off' => 'Lost / written off',
    ];

    public static function onHand(string $itemId): int
    {
        return (int) Db::value(Db::prepare('SELECT COALESCE(SUM(qty), 0) FROM ' . Db::t('stock_movements') . ' WHERE item_id = UNHEX(%s)', $itemId));
    }

    /** @return array<string,int> item id => on hand */
    public static function onHandAll(): array
    {
        $out = [];
        foreach (Db::rows('SELECT ' . Db::hex('item_id') . ', SUM(qty) AS n FROM ' . Db::t('stock_movements') . ' GROUP BY item_id') as $r) {
            $out[$r['item_id']] = (int) $r['n'];
        }
        return $out;
    }

    public static function adjust(Actor $actor, string $itemId, int $qty, string $reason, string $note = ''): void
    {
        $actor->require('cf_manage_stock');
        $itemId = Uuid::assertHex($itemId, 'item');
        if (!isset(self::REASONS[$reason])) {
            throw new DomainError('invalid_input', 'Choose a reason.', ['field' => 'reason']);
        }
        if ($qty === 0 || abs($qty) > 1000000) {
            throw new DomainError('invalid_input', 'Enter a quantity, e.g. 120 to add or -5 to remove.', ['field' => 'qty']);
        }
        $positive = ['purchase', 'opening', 'repaired'];
        $negative = ['damaged', 'write_off'];
        if ((in_array($reason, $positive, true) && $qty < 0) || (in_array($reason, $negative, true) && $qty > 0)) {
            throw new DomainError('invalid_input', 'The quantity sign does not match the reason (add for received or repaired, remove for damaged or lost).', ['field' => 'qty']);
        }
        Db::transaction(static function () use ($actor, $itemId, $qty, $reason, $note): void {
            $tracking = Db::value(Db::prepare('SELECT tracking FROM ' . Db::t('equipment_items') . ' WHERE id = UNHEX(%s) FOR UPDATE', $itemId));
            if ($tracking === null) {
                throw new DomainError('not_found', 'Item not found.');
            }
            if ($tracking !== 'qty') {
                throw new DomainError('not_quantity', 'Heavy equipment is counted by serial unit, not by quantity.');
            }
            if (self::onHand($itemId) + $qty < 0) {
                throw new DomainError('negative_stock', 'This would leave less than zero in stock.', ['field' => 'qty']);
            }
            Db::insert('stock_movements', [
                'id' => Uuid::v7(), 'item_id' => $itemId, 'qty' => $qty, 'reason' => $reason,
                'note' => $note !== '' ? mb_substr(sanitize_text_field($note), 0, 255) : null,
                'user_id' => $actor->userId, 'created_at' => Db::now(),
            ], ['id', 'item_id']);
            Audit::log(null, $actor->userId, 'stock.adjust', 'equipment_item', $itemId, ['qty' => $qty, 'reason' => $reason]);
        });
    }
}
