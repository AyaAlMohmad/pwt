<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

/**
 * Test case for suites that must run against MySQL (the dedicated
 * `pwt_testing` database).
 *
 * Why this exists:
 *   The catalog module's read queries rely on MySQL `JSON_EXTRACT`
 *   and `JSON_UNQUOTE` functions, which SQLite does not implement.
 *   Per CLAUDE.md § 10, suites that exercise MySQL-specific
 *   behaviour run against the `pwt_testing` database; everything
 *   else stays on the SQLite in-memory pipeline for speed.
 *
 * How it works:
 *   We `use RefreshDatabase` here AND override
 *   `beforeRefreshingDatabase()` so the connection switch wins
 *   over Pest's runtime-injected trait method (PHP gives priority
 *   to a class-declared method over a trait-imported one when
 *   both exist on the same class — by `use`-ing RefreshDatabase
 *   here we keep that resolution under our control instead of
 *   leaving it to the dynamically-generated Pest test class).
 *
 *   Pest test files opt in via `tests/Pest.php` — there is no
 *   per-file boilerplate.
 */
abstract class MySqlTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Tracks which connection last ran migrate:fresh inside this
     * PHP process. The default value is `null` (none).
     *
     * `RefreshDatabaseState::$migrated` is a process-static flag
     * shared by every test, so it does not differentiate between
     * the SQLite (default) and MySQL (catalog) connections. When
     * a suite switches connection target we reset the flag so the
     * new connection's migrations actually run.
     */
    public static ?string $lastMigratedConnection = null;

    /**
     * Hook invoked by `RefreshDatabase::refreshDatabase()` BEFORE
     * it inspects the `database.default` config. Swap the
     * connection to MySQL `pwt_testing` so the migrate:fresh and
     * transaction work happens there.
     */
    protected function beforeRefreshingDatabase()
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => env('DB_TEST_DATABASE', 'pwt_testing'),
        ]);

        // Purge any cached connection so the next lookup picks up
        // the overridden database name. The `db` singleton itself
        // stays in the container — purging only clears its
        // internal connection cache, which is what we need.
        $db = $this->app->make('db');
        $db->purge('mysql');
        $db->purge();

        // If a previous test ran migrate:fresh against a different
        // connection (e.g. the SQLite in-memory default),
        // `RefreshDatabaseState::$migrated` is still `true` —
        // but the migration ran on the wrong connection. Reset the
        // flag whenever the connection target changes so
        // `refreshTestDatabase()` re-runs migrations on the new one.
        if (static::$lastMigratedConnection !== 'mysql') {
            RefreshDatabaseState::$migrated = false;
            static::$lastMigratedConnection = 'mysql';
        }
    }
}
