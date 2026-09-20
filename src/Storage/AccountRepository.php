<?php

declare(strict_types=1);

namespace Webmention\Storage;

use Webmention\Model\Account;

final class AccountRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function find(int $id): ?Account
    {
        $row = $this->db->one('SELECT * FROM accounts WHERE id = ?', [$id]);

        return $row === null ? null : Account::fromRow($row);
    }

    public function findByDomain(string $domain): ?Account
    {
        $row = $this->db->one('SELECT * FROM accounts WHERE domain = ? ORDER BY id LIMIT 1', [$domain]);

        return $row === null ? null : Account::fromRow($row);
    }

    /**
     * The name in a webmention endpoint URL. Almost every account is named after
     * its domain, but a few early accounts have a different username, and both
     * forms have been handed out.
     */
    public function findByName(string $name): ?Account
    {
        $row = $this->db->one(
            'SELECT * FROM accounts WHERE domain = ? OR username = ? ORDER BY id LIMIT 1',
            [$name, $name],
        );

        return $row === null ? null : Account::fromRow($row);
    }

    public function findByToken(string $token): ?Account
    {
        if ($token === '') {
            return null;
        }

        $row = $this->db->one('SELECT * FROM accounts WHERE token = ? ORDER BY id LIMIT 1', [$token]);

        return $row === null ? null : Account::fromRow($row);
    }

    public function create(string $domain): Account
    {
        $now = Database::now();
        $id  = $this->db->insert('accounts', [
            'username'   => $domain,
            'domain'     => $domain,
            'created_at' => $now,
            'updated_at' => $now,
            'last_login' => $now,
        ]);

        return $this->find($id) ?? throw new \RuntimeException('Account vanished after insert.');
    }

    public function recordLogin(int $id): void
    {
        $now = Database::now();
        $this->db->update('accounts', $id, ['last_login' => $now, 'updated_at' => $now]);
    }

    /** Replace the API token with a fresh random one, and return it. */
    public function regenerateToken(int $id): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        $this->db->update('accounts', $id, ['token' => $token, 'updated_at' => Database::now()]);

        return $token;
    }

    public function hasVerifiedLinks(int $id): bool
    {
        return $this->db->value(
            'SELECT 1 FROM links WHERE account_id = ? AND verified = 1 AND deleted = 0 LIMIT 1',
            [$id],
        ) !== null;
    }

    /**
     * Accounts matching a typed fragment of a domain, username or email, or an
     * exact id, for the admin search. The Account model carries only what the
     * app needs, so this returns rows: the admin page shows the email address
     * and the last sign-in too.
     *
     * A scan of a few thousand rows; there is no index for a LIKE like this
     * and none is worth adding.
     *
     * @return list<array<string, mixed>>
     */
    public function searchRows(string $query, int $limit): array
    {
        $like  = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($query)) . '%';
        $id    = ctype_digit(trim($query)) ? (int) trim($query) : 0;

        return $this->db->all(
            'SELECT a.id, a.username, a.domain, a.email, a.created_at, a.last_login,
                    (SELECT COUNT(*) FROM sites s WHERE s.account_id = a.id) AS sites
                FROM accounts a
                WHERE a.id = ? OR a.domain LIKE ? OR a.username LIKE ? OR a.email LIKE ?
                ORDER BY a.last_login IS NULL, a.last_login DESC, a.id DESC LIMIT ?',
            [$id, $like, $like, $like, max(1, $limit)],
        );
    }

    /** The most recently active accounts, shown before anything is searched for. @return list<array<string, mixed>> */
    public function recentRows(int $limit): array
    {
        return $this->db->all(
            'SELECT a.id, a.username, a.domain, a.email, a.created_at, a.last_login,
                    (SELECT COUNT(*) FROM sites s WHERE s.account_id = a.id) AS sites
                FROM accounts a
                ORDER BY a.last_login IS NULL, a.last_login DESC, a.id DESC LIMIT ?',
            [max(1, $limit)],
        );
    }

    /** The whole row, for the admin account page. @return array<string, mixed>|null */
    public function row(int $id): ?array
    {
        return $this->db->one('SELECT * FROM accounts WHERE id = ?', [$id]);
    }

    /**
     * Accounts created per calendar month, for the admin activity page.
     *
     * @return array<string, int> 'YYYY-MM' => count
     */
    public function createdPerMonth(): array
    {
        $out = [];
        foreach ($this->db->all("SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS n FROM accounts WHERE created_at IS NOT NULL GROUP BY month") as $row) {
            $out[(string) $row['month']] = (int) $row['n'];
        }

        return $out;
    }

    public function countCreatedSince(string $since): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM accounts WHERE created_at >= ?', [$since]);
    }

    public function countAll(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM accounts');
    }
}
