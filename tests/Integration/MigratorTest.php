<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use Webmention\Storage\Migrator;
use Webmention\Tests\Support\IntegrationTestCase;

/**
 * tools/migrate: applying database/migrations through PDO and remembering it.
 */
final class MigratorTest extends IntegrationTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/webmention-migrations-' . getmypid();
        mkdir($this->dir);
        $this->db->pdo()->exec('DROP TABLE IF EXISTS migrator_test');
        (new Migrator($this->db, $this->dir))->status(); // creates the tracking table on a fresh test database
        $this->db->pdo()->exec('DELETE FROM ' . Migrator::TABLE . " WHERE name LIKE '2099-%'");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        $this->db->pdo()->exec('DROP TABLE IF EXISTS migrator_test');
        $this->db->pdo()->exec('DELETE FROM ' . Migrator::TABLE . " WHERE name LIKE '2099-%'");

        parent::tearDown();
    }

    public function testAppliesPendingSqlInOrderAndRemembersIt(): void
    {
        file_put_contents($this->dir . '/2099-01-02-add-column.sql', "-- a comment; with a semicolon\nALTER TABLE migrator_test ADD COLUMN note varchar(20) DEFAULT NULL;\nALTER TABLE migrator_test ADD INDEX note (note);\n");
        file_put_contents($this->dir . '/2099-01-01-create.sql', "CREATE TABLE migrator_test (\n  id int NOT NULL,\n  PRIMARY KEY (id)\n) ENGINE=InnoDB;\n");
        file_put_contents($this->dir . '/2099-01-03-data.php', "<?php // run by hand\n");
        file_put_contents($this->dir . '/README.md', "not a migration\n");

        $migrator = new Migrator($this->db, $this->dir);

        self::assertSame(
            [['2099-01-01-create.sql', 'sql', null], ['2099-01-02-add-column.sql', 'sql', null], ['2099-01-03-data.php', 'php', null]],
            array_map(static fn (array $m): array => [$m['name'], $m['kind'], $m['applied_at']], $migrator->status()),
        );

        $applied = $migrator->apply();
        self::assertSame([['2099-01-01-create.sql', 1, 1, false], ['2099-01-02-add-column.sql', 2, 2, false]], array_map(static fn (array $a): array => [$a['name'], $a['statements'], $a['ran'], $a['by_hand']], $applied));
        self::assertSame(['id', 'note'], array_column($this->db->all('SHOW COLUMNS FROM migrator_test'), 'Field'));

        $status = $migrator->status();
        self::assertNotNull($status[0]['applied_at']);
        self::assertNotNull($status[1]['applied_at']);
        self::assertNull($status[2]['applied_at'], 'php migrations are never run from here');

        self::assertSame([], $migrator->apply(), 'nothing left');

        $migrator->mark('2099-01-03-data.php');
        self::assertNotNull($migrator->status()[2]['applied_at']);
        $migrator->mark('2099-01-03-data.php'); // twice is fine

        $this->expectException(\InvalidArgumentException::class);
        $migrator->mark('2099-09-09-nope.sql');
    }

    public function testAFileAppliedByHandIsRecordedWithoutRunningTheRestOfIt(): void
    {
        // The table and column already exist, and a row was marked by the hand-run UPDATE, then changed since.
        $this->db->pdo()->exec('CREATE TABLE migrator_test (id int NOT NULL, note varchar(20) DEFAULT NULL, PRIMARY KEY (id))');
        $this->db->pdo()->exec("INSERT INTO migrator_test (id, note) VALUES (1, 'changed since')");
        file_put_contents($this->dir . '/2099-01-01-create.sql', "CREATE TABLE migrator_test (id int NOT NULL, PRIMARY KEY (id));\n");
        file_put_contents($this->dir . '/2099-01-02-column-and-data.sql', "ALTER TABLE migrator_test ADD COLUMN note varchar(20) DEFAULT NULL;\nUPDATE migrator_test SET note = 'initial';\n");
        file_put_contents($this->dir . '/2099-01-03-new.sql', "ALTER TABLE migrator_test ADD COLUMN extra int DEFAULT 0;\n");

        $applied = (new Migrator($this->db, $this->dir))->apply();

        self::assertSame(
            [['2099-01-01-create.sql', 0, true], ['2099-01-02-column-and-data.sql', 0, true], ['2099-01-03-new.sql', 1, false]],
            array_map(static fn (array $a): array => [$a['name'], $a['ran'], $a['by_hand']], $applied),
        );
        self::assertSame('changed since', $this->db->value('SELECT note FROM migrator_test WHERE id = 1'), 'the data statement after the DDL did not run again');
        self::assertSame(['id', 'note', 'extra'], array_column($this->db->all('SHOW COLUMNS FROM migrator_test'), 'Field'), 'the genuinely new file ran');
    }

    public function testTheFirstSchemaStatementDecidesAndLaterOnesAlreadyThereAreSkipped(): void
    {
        // A data statement ahead of the DDL (the blocklists dedupe) runs; the first ALTER is what is judged.
        $this->db->pdo()->exec('CREATE TABLE migrator_test (id int NOT NULL, b int, PRIMARY KEY (id))');
        $this->db->pdo()->exec('INSERT INTO migrator_test (id) VALUES (1), (2)');
        file_put_contents($this->dir . '/2099-01-01-create.sql', "CREATE TABLE migrator_test (id int NOT NULL, PRIMARY KEY (id));\n");
        file_put_contents($this->dir . '/2099-01-02-dedupe-then-index.sql', "DELETE FROM migrator_test WHERE id = 2;\nALTER TABLE migrator_test ADD COLUMN a int;\nALTER TABLE migrator_test ADD COLUMN b int;\n");
        $migrator = new Migrator($this->db, $this->dir);
        $migrator->baseline('2099-01-01-create.sql');

        $applied = $migrator->apply();

        self::assertSame([['2099-01-02-dedupe-then-index.sql', 3, 2, false]], array_map(static fn (array $a): array => [$a['name'], $a['statements'], $a['ran'], $a['by_hand']], $applied));
        self::assertSame(['id', 'b', 'a'], array_column($this->db->all('SHOW COLUMNS FROM migrator_test'), 'Field'), 'a was added, b was already there');
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM migrator_test'), 'the data statement ran');
    }

    public function testBaselineRecordsUpToANameWithoutRunning(): void
    {
        file_put_contents($this->dir . '/2099-01-01-create.sql', "CREATE TABLE migrator_test (id int NOT NULL, PRIMARY KEY (id));\n");
        file_put_contents($this->dir . '/2099-01-02-data.php', "<?php\n");
        file_put_contents($this->dir . '/2099-01-03-later.sql', "CREATE TABLE migrator_test (id int NOT NULL, PRIMARY KEY (id));\n");
        $migrator = new Migrator($this->db, $this->dir);

        self::assertSame(['2099-01-01-create.sql', '2099-01-02-data.php'], $migrator->baseline('2099-01-02-data.php'));
        self::assertSame([], $this->db->all("SHOW TABLES LIKE 'migrator_test'"), 'nothing ran');
        self::assertSame(['2099-01-03-later.sql'], array_column($migrator->apply(), 'name'));

        $this->expectException(\InvalidArgumentException::class);
        $migrator->baseline('2099-09-09-nope.sql');
    }

    public function testARealFailureStopsAndNamesTheStatement(): void
    {
        file_put_contents($this->dir . '/2099-01-01-create.sql', "CREATE TABLE migrator_test (id int NOT NULL, PRIMARY KEY (id));\n");
        file_put_contents($this->dir . '/2099-01-02-bad.sql', "ALTER TABLE migrator_test ADD COLUMN ok int;\nALTER TABLE no_such_table ADD COLUMN x int;\n");
        file_put_contents($this->dir . '/2099-01-03-after.sql', "ALTER TABLE migrator_test ADD COLUMN later int;\n");
        $migrator = new Migrator($this->db, $this->dir);

        try {
            $migrator->apply();
            self::fail('expected the bad migration to throw');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('2099-01-02-bad.sql failed at: ALTER TABLE no_such_table', $e->getMessage());
        }

        $status = array_column($migrator->status(), 'applied_at', 'name');
        self::assertNotNull($status['2099-01-01-create.sql'], 'the one before was recorded');
        self::assertNull($status['2099-01-02-bad.sql']);
        self::assertNull($status['2099-01-03-after.sql'], 'nothing after it ran');
        self::assertSame(['id', 'ok'], array_column($this->db->all('SHOW COLUMNS FROM migrator_test'), 'Field'));

        // Fixed: running again finishes, the statement that had run counting as already there.
        file_put_contents($this->dir . '/2099-01-02-bad.sql', "ALTER TABLE migrator_test ADD COLUMN ok int;\n");
        self::assertCount(2, $migrator->apply());
        self::assertSame(['id', 'ok', 'later'], array_column($this->db->all('SHOW COLUMNS FROM migrator_test'), 'Field'));
    }

    public function testTheRealMigrationsSplitIntoTheirStatements(): void
    {
        $total = 0;
        foreach ((new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->files() as $name) {
            if (str_ends_with($name, '.sql')) {
                $statements = Migrator::statements((string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/' . $name));
                self::assertNotSame([], $statements, $name);
                foreach ($statements as $sql) {
                    self::assertMatchesRegularExpression('/^(ALTER|CREATE|UPDATE|DELETE)\b/i', $sql, "$name: $sql");
                }
                // Every file has a schema statement: it is what tells a hand-applied file from a pending one.
                self::assertNotSame([], preg_grep('/^(ALTER|CREATE)\b/i', $statements), "$name has DDL");
                $total += count($statements);
            }
        }
        self::assertSame(16, $total);
    }
}
