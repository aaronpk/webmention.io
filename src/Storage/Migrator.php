<?php

declare(strict_types=1);

namespace Webmention\Storage;

use PDOException;

/**
 * Applies the files in database/migrations in name order and remembers
 * which ones it has applied, in schema_migrations (additive: nothing else
 * reads it).
 *
 * A .sql file is run one statement at a time. Every migration here is
 * additive, so "already exists" from the database is informative: when a
 * file's first schema statement (ALTER, CREATE) finds its column, index or
 * table already there, the file was applied by hand before this tool
 * existed, and the rest of it is skipped rather than repeated (some files
 * follow their DDL with a data statement that must not run twice). A later
 * schema statement finding its change already there is skipped on its own.
 * Anything else is an error that stops the run. A data statement placed
 * before a file's first DDL (the blocklists dedupe) runs every time, so it
 * has to be idempotent.
 *
 * baseline() records files as applied without running them, for bringing a
 * hand-migrated database under the tool explicitly.
 *
 * A .php file is a data migration with its own dry run and --apply; it is
 * listed but never run from here. Once run by hand, mark() records it.
 */
final class Migrator
{
    public const TABLE = 'schema_migrations';

    /** MySQL errors meaning the additive change is already there. */
    private const ALREADY_THERE = [
        1050, // table already exists
        1060, // duplicate column name
        1061, // duplicate key name
        1091, // can't drop; check that it exists (a DROP INDEX IF EXISTS style file)
    ];

    public function __construct(
        private readonly Database $db,
        private readonly string $directory,
    ) {
    }

    /**
     * Every migration, in order, with its state.
     *
     * @return list<array{name: string, kind: 'sql'|'php', applied_at: ?string}>
     */
    public function status(): array
    {
        $this->ensureTable();

        $applied = [];
        foreach ($this->db->all('SELECT name, applied_at FROM ' . self::TABLE) as $row) {
            $applied[(string) $row['name']] = (string) $row['applied_at'];
        }

        $out = [];
        foreach ($this->files() as $name) {
            $out[] = [
                'name'       => $name,
                'kind'       => str_ends_with($name, '.php') ? 'php' : 'sql',
                'applied_at' => $applied[$name] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Apply every pending .sql migration in order. Stops at the first one
     * that fails and rethrows, after recording the ones before it.
     *
     * @return list<array{name: string, statements: int, ran: int, by_hand: bool}> What was applied.
     */
    public function apply(): array
    {
        $done = [];
        foreach ($this->status() as $migration) {
            if ($migration['applied_at'] !== null || $migration['kind'] !== 'sql') {
                continue;
            }
            $done[] = $this->applyFile($migration['name']);
        }

        return $done;
    }

    /** @return array{name: string, statements: int, ran: int, by_hand: bool} */
    private function applyFile(string $name): array
    {
        $statements = self::statements((string) file_get_contents($this->directory . '/' . $name));
        $ran        = 0;
        $ddlRan     = 0;
        $byHand     = false;

        foreach ($statements as $sql) {
            $isDdl = preg_match('/^(ALTER|CREATE|DROP)\b/i', $sql) === 1;
            try {
                $this->db->pdo()->exec($sql);
                $ran++;
                $ddlRan += $isDdl ? 1 : 0;
            } catch (PDOException $e) {
                if (!in_array((int) ($e->errorInfo[1] ?? 0), self::ALREADY_THERE, true)) {
                    throw new \RuntimeException("$name failed at: " . self::excerpt($sql) . "\n" . $e->getMessage(), 0, $e);
                }
                if ($isDdl && $ddlRan === 0) {
                    $byHand = true; // applied before this tool existed: leave the rest alone

                    break;
                }
                // A later change that is already there is skipped on its own.
            }
        }

        $this->mark($name);

        return ['name' => $name, 'statements' => count($statements), 'ran' => $ran, 'by_hand' => $byHand];
    }

    /**
     * Record every migration up to and including $through as applied,
     * without running anything.
     *
     * @return list<string> The names recorded.
     */
    public function baseline(string $through): array
    {
        $files = $this->files();
        if (!in_array($through, $files, true)) {
            throw new \InvalidArgumentException("No migration named $through.");
        }
        $done = [];
        foreach ($files as $name) {
            $this->mark($name);
            $done[] = $name;
            if ($name === $through) {
                break;
            }
        }

        return $done;
    }

    /** Record a migration as applied without running it (a .php one run by hand). */
    public function mark(string $name): void
    {
        if (!in_array($name, $this->files(), true)) {
            throw new \InvalidArgumentException("No migration named $name.");
        }
        $this->ensureTable();
        $this->db->run('INSERT IGNORE INTO ' . self::TABLE . ' (name, applied_at) VALUES (?, ?)', [$name, Database::now()]);
    }

    /** @return list<string> File names, oldest first. */
    public function files(): array
    {
        $names = [];
        foreach (scandir($this->directory) ?: [] as $entry) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}-.+\.(sql|php)$/', $entry) === 1) {
                $names[] = $entry;
            }
        }
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * The statements in a .sql file: comment lines dropped, split on the
     * semicolons that end them. Good for the DDL kept here; not a general
     * SQL parser.
     *
     * @return list<string>
     */
    public static function statements(string $sql): array
    {
        $lines = array_filter(explode("\n", $sql), static fn (string $line): bool => preg_match('/^\s*--/', $line) !== 1);
        $out   = [];
        foreach (explode(";\n", implode("\n", $lines) . "\n") as $chunk) {
            $chunk = trim($chunk, " \t\r\n;");
            if ($chunk !== '') {
                $out[] = $chunk;
            }
        }

        return $out;
    }

    private function ensureTable(): void
    {
        $this->db->pdo()->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
            name varchar(255) NOT NULL,
            applied_at datetime NOT NULL,
            PRIMARY KEY (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    private static function excerpt(string $sql): string
    {
        $one = trim((string) preg_replace('/\s+/', ' ', $sql));

        return mb_strlen($one) > 120 ? mb_substr($one, 0, 117) . '…' : $one;
    }
}
