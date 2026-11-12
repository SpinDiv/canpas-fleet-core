<?php

declare(strict_types=1);

namespace Canpas\Fleet\Migrations;

use Canpas\Fleet\Support\Db;
use Canpas\Fleet\Support\DbError;

/**
 * Applies numbered migration files from migrations/ once each, in order.
 * A migration file returns: ['version' => '0001_core', 'statements' => fn(string $p, string $opts): list<string>,
 *                            'optional' => false]
 * Optional migrations (e.g. triggers that need extra privileges) are recorded as skipped on failure.
 */
final class Runner
{
    /** @return list<string> lines describing what happened */
    public static function migrate(): array
    {
        $w = Db::wpdb();
        $p = $w->prefix . 'cf_';
        $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
        Db::exec("CREATE TABLE IF NOT EXISTS {$p}migrations (
            version VARCHAR(64) NOT NULL PRIMARY KEY,
            status VARCHAR(16) NOT NULL DEFAULT 'applied',
            note VARCHAR(255) NULL,
            applied_at DATETIME(6) NOT NULL
        ) {$opts}");

        $done = array_column(Db::rows("SELECT version FROM {$p}migrations"), 'version');
        $files = glob(CANPAS_FLEET_DIR . 'migrations/*.php') ?: [];
        sort($files);
        $log = [];
        foreach ($files as $file) {
            /** @var array{version:string, statements:callable, optional?:bool} $m */
            $m = require $file;
            if (in_array($m['version'], $done, true)) {
                continue;
            }
            $status = 'applied';
            $note = null;
            try {
                foreach (($m['statements'])($p, $opts) as $sql) {
                    Db::exec($sql);
                }
            } catch (DbError $e) {
                if (empty($m['optional'])) {
                    throw new \RuntimeException("Migration {$m['version']} failed: " . $e->getMessage());
                }
                $status = 'skipped';
                $note = substr($e->getMessage(), 0, 250);
            }
            Db::insert('migrations', [
                'version' => $m['version'],
                'status' => $status,
                'note' => $note,
                'applied_at' => Db::now(),
            ]);
            $log[] = "{$m['version']}: {$status}" . ($note ? " ({$note})" : '');
        }
        return $log === [] ? ['Nothing to migrate.'] : $log;
    }
}
