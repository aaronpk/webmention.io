<?php

declare(strict_types=1);

namespace Webmention\Admin;

use Webmention\Config;
use Webmention\Model\Account;

/**
 * Who may see the admin section.
 *
 * ADMIN_USERS is a comma-separated list of account names, each matched against
 * an account's username or its domain, so `aaronparecki.com` finds the account
 * whichever of the two columns carries it. Unset means nobody: the nav entry
 * never appears and every /admin path is a 404.
 */
final class Admins
{
    /** @param list<string> $names Lowercased, non-empty. */
    private function __construct(private readonly array $names)
    {
    }

    public static function fromConfig(Config $config): self
    {
        return self::of((string) $config->get('ADMIN_USERS', ''));
    }

    public static function of(string $list): self
    {
        $names = [];
        foreach (explode(',', strtolower($list)) as $name) {
            if (($name = trim($name)) !== '') {
                $names[] = $name;
            }
        }

        return new self(array_values(array_unique($names)));
    }

    /** No one, the default wherever ADMIN_USERS has not been wired through. */
    public static function none(): self
    {
        return new self([]);
    }

    public function has(?Account $user): bool
    {
        if ($user === null || $this->names === []) {
            return false;
        }

        foreach ([$user->username, $user->domain] as $name) {
            if (is_string($name) && in_array(strtolower(trim($name)), $this->names, true)) {
                return true;
            }
        }

        return false;
    }

    /** Whether anyone at all is an admin, so the section can be left out entirely. */
    public function any(): bool
    {
        return $this->names !== [];
    }
}
