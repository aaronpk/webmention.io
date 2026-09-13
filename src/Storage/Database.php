<?php

declare(strict_types=1);

namespace Webmention\Storage;

use PDO;
use PDOStatement;
use Webmention\Config;

/**
 * A thin wrapper around PDO.
 *
 * Every timestamp in the database is UTC, written by the application (the old
 * Ruby app ran with TZ=UTC), so the connection is pinned to UTC too.
 */
final class Database
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->exec("SET SESSION sql_mode = '', time_zone = '+00:00'");
    }

    public static function connect(Config $config): self
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config->get('DB_HOST', '127.0.0.1'),
            $config->get('DB_PORT', '3306'),
            $config->get('DB_NAME', 'webmention'),
        );

        return new self(new PDO($dsn, $config->get('DB_USER'), $config->get('DB_PASS'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]));
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @param list<mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);

        foreach (array_values($params) as $i => $value) {
            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_bool($value) => PDO::PARAM_INT,
                is_int($value)  => PDO::PARAM_INT,
                default         => PDO::PARAM_STR,
            };
            $statement->bindValue($i + 1, is_bool($value) ? (int) $value : $value, $type);
        }

        $statement->execute();

        return $statement;
    }

    /**
     * @param  list<mixed> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param  list<mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @param list<mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param  array<string, mixed> $row Column names come from code, never from input.
     * @return int The new row's id.
     */
    public function insert(string $table, array $row): int
    {
        $columns = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', array_keys($row)));
        $sql     = sprintf('INSERT INTO `%s` (%s) VALUES (%s)', $table, $columns, self::placeholders($row));

        $this->run($sql, array_values($row));

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $row Column names come from code, never from input. */
    public function update(string $table, int $id, array $row): void
    {
        if ($row === []) {
            return;
        }

        $sets = implode(', ', array_map(static fn (string $c): string => '`' . $c . '` = ?', array_keys($row)));
        $this->run(sprintf('UPDATE `%s` SET %s WHERE id = ?', $table, $sets), [...array_values($row), $id]);
    }

    /** "?, ?, ?" for an IN list or VALUES clause. */
    public static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, max(1, count($values)), '?'));
    }

    /** The current time as a UTC DATETIME string. */
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
