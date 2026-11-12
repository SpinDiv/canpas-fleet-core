<?php

declare(strict_types=1);

namespace Canpas\Fleet\Cli;

use Canpas\Fleet\Migrations\Runner;
use Canpas\Fleet\Seed\DemoSeeder;
use Canpas\Fleet\Support\Db;
use Canpas\Fleet\Support\DomainError;
use WP_CLI;

/**
 * Canpas Fleet maintenance commands: wp canpas <migrate|seed|selftest|status>.
 */
final class Command
{
    /**
     * Apply pending database migrations.
     */
    public function migrate(): void
    {
        foreach (Runner::migrate() as $line) {
            WP_CLI::log($line);
        }
        WP_CLI::success('Migrations complete.');
    }

    /**
     * Install roles and the staging demo company and accounts. Safe to re-run. Staging only.
     */
    public function seed(): void
    {
        $this->run(static fn() => DemoSeeder::run());
    }

    /**
     * Run the rule checks against the real database with throwaway records, then clean up.
     */
    public function selftest(): void
    {
        $results = SelfTest::run();
        $failed = 0;
        foreach ($results as [$ok, $name, $detail]) {
            WP_CLI::log(($ok ? 'PASS ' : 'FAIL ') . $name . ($detail !== '' ? " — {$detail}" : ''));
            $failed += $ok ? 0 : 1;
        }
        $failed === 0
            ? WP_CLI::success(count($results) . ' checks passed.')
            : WP_CLI::error("{$failed} of " . count($results) . ' checks failed.');
    }

    /**
     * Row counts per Canpas table.
     */
    public function status(): void
    {
        $rows = [];
        foreach (Db::rows('SHOW TABLES LIKE \'' . esc_sql(Db::t('')) . '%\'') as $r) {
            $table = (string) array_values($r)[0];
            $rows[] = ['table' => $table, 'rows' => (int) Db::value('SELECT COUNT(*) FROM `' . $table . '`')];
        }
        WP_CLI\Utils\format_items('table', $rows, ['table', 'rows']);
    }

    /** @param callable(): list<string> $fn */
    private function run(callable $fn): void
    {
        try {
            foreach ($fn() as $line) {
                WP_CLI::log($line);
            }
            WP_CLI::success('Done.');
        } catch (DomainError $e) {
            WP_CLI::error("[{$e->reason}] " . $e->getMessage());
        }
    }
}
