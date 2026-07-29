<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Default test case. Catalog tests use {@see MySqlTestCase} instead so
 * they pick up the MySQL connection switch.
 *
 * In a mixed-suite test run (some tests on SQLite, some on MySQL),
 * `RefreshDatabaseState::$migrated` is a process-shared static that
 * doesn't differentiate between the SQLite (default) and MySQL
 * (catalog) connections. If a MySQL test ran first and set the flag,
 * the next SQLite test would skip its own migrations and the schema
 * would be missing. Resetting the flag from `setUp()` here ensures
 * each transition between connection targets triggers a fresh
 * migrate:fresh.
 */
abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // Only reset when re-entering the default (non-MySQL)
        // pipeline. The {@see MySqlTestCase} subclass handles the
        // mysql side via its own beforeRefreshingDatabase hook.
        if (! ($this instanceof MySqlTestCase)
            && MySqlTestCase::$lastMigratedConnection === 'mysql'
        ) {
            RefreshDatabaseState::$migrated = false;
            MySqlTestCase::$lastMigratedConnection = 'default';
        }

        parent::setUp();
    }
}
