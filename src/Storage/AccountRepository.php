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
}
