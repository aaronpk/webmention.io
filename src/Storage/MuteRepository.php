<?php

declare(strict_types=1);

namespace Webmention\Storage;

use Webmention\Model\Mute;

final class MuteRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<Mute> Oldest first. */
    public function forAccount(int $accountId): array
    {
        return array_map(Mute::fromRow(...), $this->db->all('SELECT * FROM mutes WHERE account_id = ? ORDER BY id', [$accountId]));
    }

    public function find(int $accountId, int $id): ?Mute
    {
        $row = $this->db->one('SELECT * FROM mutes WHERE id = ? AND account_id = ?', [$id, $accountId]);

        return $row === null ? null : Mute::fromRow($row);
    }

    /** Add a rule; adding the same one twice returns the existing rule. */
    public function add(int $accountId, string $kind, string $pattern): Mute
    {
        $row = $this->db->one('SELECT * FROM mutes WHERE account_id = ? AND kind = ? AND pattern = ?', [$accountId, $kind, $pattern]);
        if ($row !== null) {
            return Mute::fromRow($row);
        }

        $id = $this->db->insert('mutes', [
            'account_id' => $accountId,
            'kind'       => $kind,
            'pattern'    => $pattern,
            'created_at' => Database::now(),
        ]);

        return $this->find($accountId, $id) ?? throw new \RuntimeException('Mute vanished after insert.');
    }

    public function remove(int $accountId, int $id): void
    {
        $this->db->run('DELETE FROM mutes WHERE id = ? AND account_id = ?', [$id, $accountId]);
    }

    /**
     * The SQL predicate selecting links rows a rule covers, for hiding and
     * restoring in bulk. Must agree with Mute::matches().
     *
     * @return array{string, list<string>}
     */
    public static function predicate(Mute $rule): array
    {
        $column = $rule->kind === 'author' ? 'links.author_url' : 'links.href';

        if ($rule->isPrefix()) {
            return ["LOWER($column) LIKE ? ESCAPE '\\\\'", [strtolower(addcslashes($rule->pattern, '\\%_')) . '%']];
        }

        // The host part of the URL: between the second and third slash.
        $host = "LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX($column, '/', 3), '/', -1))";

        return ["($host = ? OR $host LIKE ? ESCAPE '\\\\')", [$rule->pattern, '%.' . addcslashes($rule->pattern, '\\%_')]];
    }
}
