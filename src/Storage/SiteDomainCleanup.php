<?php

declare(strict_types=1);

namespace Webmention\Storage;

use Throwable;
use Webmention\Controllers\SettingsController;

/**
 * Puts a bare hostname back in sites.domain.
 *
 * The Ruby app let two other shapes in. Its add-a-site form wrote the field
 * straight to the column with no validation at all (controllers/controller.rb:75),
 * so rows hold "https://alexsci.com/blog/" and "HTTPS://cathydutton.co.uk".
 * And controllers/auth.rb:75 turned an IndieAuth profile URL into an account
 * name with `.gsub(/\//, '_')`, so a path could still be one segment of
 * "/:username/webmention"; controllers/controller.rb:62 then copied that into
 * the first site's domain, leaving "www.samnir.com_zh_".
 *
 * Neither shape can ever match: everything that reads the column compares it
 * against a target's host, and SiteVerifier fetches "https://$domain/". So
 * these rows receive nothing, and 36 accounts have never had a webmention
 * because of it.
 *
 * Accounts are deliberately left alone. The underscore form is their
 * identity, matched by exact string in the endpoint route, and
 * AuthController::domainFor() still produces it so those sign-ins keep
 * finding the same account.
 */
final class SiteDomainCleanup
{
    public const RENAME = 'rename';
    public const FOLD   = 'fold';
    public const STUCK  = 'stuck';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Every row that is not already a bare hostname, and what to do with it.
     *
     * @return array{rename: list<array<string, mixed>>, fold: list<array<string, mixed>>, stuck: list<array<string, mixed>>}
     */
    public function plan(): array
    {
        $rows = $this->db->all(
            'SELECT s.*, a.username FROM sites s JOIN accounts a ON a.id = s.account_id ORDER BY s.id',
        );

        // What name each account holds, updated as renames are decided, so two
        // malformed rows collapsing onto one host become a rename and a fold
        // rather than a duplicate-key error.
        $held = [];
        foreach ($rows as $row) {
            $held[(int) $row['account_id']][strtolower((string) $row['domain'])] = (int) $row['id'];
        }

        $plan = [self::RENAME => [], self::FOLD => [], self::STUCK => []];

        foreach ($rows as $row) {
            $domain    = (string) $row['domain'];
            $account   = (int) $row['account_id'];
            $id        = (int) $row['id'];
            $encoded   = self::isPathEncoded($row);
            $target    = self::targetFor($row);

            if ($target === null) {
                $plan[self::STUCK][] = [...$row, 'why' => self::whyStuck($domain, $encoded)];
                continue;
            }

            if ($target === $domain) {
                continue;
            }

            $item  = [...$row, 'target' => $target, 'encoded' => $encoded];
            $taken = $held[$account][strtolower($target)] ?? null;

            if ($taken !== null && $taken !== $id) {
                $plan[self::FOLD][] = [...$item, 'into' => $taken];
                continue;
            }

            $plan[self::RENAME][] = [...$item, 'elsewhere' => $this->heldElsewhere($account, $target)];
            unset($held[$account][strtolower($domain)]);
            $held[$account][strtolower($target)] = $id;
        }

        return $plan;
    }

    /**
     * Do one item, or say what it would do. The note is the same either way,
     * so a dry run reads exactly like the real thing.
     *
     * @param array<string, mixed> $item
     */
    public function apply(array $item, bool $dryRun = true): string
    {
        return isset($item['into'])
            ? $this->fold($item, $dryRun)
            : $this->rename($item, $dryRun);
    }

    /** @param array<string, mixed> $item */
    private function rename(array $item, bool $dryRun): string
    {
        $id       = (int) $item['id'];
        $domain   = (string) $item['domain'];
        $target   = (string) $item['target'];
        $encoded  = (bool) $item['encoded'];
        $verified = $item['verified_at'] !== null;

        // A path-encoded row was marked verified by 2026-09-15-site-verification.sql
        // because it matched the account's sign-in name. What IndieAuth proved
        // was a path on the host, not the host, so that claim does not survive
        // the rename: tools/verify-sites re-checks it properly.
        $dropVerification = $encoded && $verified;

        $note = sprintf(
            'site #%d %s -> %s%s%s',
            $id,
            $domain,
            $target,
            $dropVerification ? ', no longer verified (IndieAuth proved a path, not the host)' : '',
            ($item['elsewhere'] ?? 0) > 0 ? sprintf(' [%d other account(s) hold %s]', $item['elsewhere'], $target) : '',
        );

        if ($dryRun) {
            return $note;
        }

        $this->transaction(function () use ($id, $target, $dropVerification, $verified): void {
            $columns = ['domain' => $target, 'updated_at' => Database::now()];

            if ($dropVerification) {
                $columns['verified_at'] = null;
            }

            // Any stored check result was against the old string.
            if ($dropVerification || !$verified) {
                $columns['verification_checked_at'] = null;
                $columns['verification_error']      = null;
            }

            $this->db->update('sites', $id, $columns);
        });

        return $note;
    }

    /** @param array<string, mixed> $item */
    private function fold(array $item, bool $dryRun): string
    {
        $id   = (int) $item['id'];
        $into = (int) $item['into'];

        $holds = $this->contents($id);
        if ($holds !== []) {
            return sprintf(
                'site #%d %s would fold into #%d, but it holds %s: left alone',
                $id,
                (string) $item['domain'],
                $into,
                implode(', ', $holds),
            );
        }

        $blocked = (int) $this->db->value('SELECT COUNT(*) FROM blocklists WHERE site_id = ?', [$id]);
        $note    = sprintf(
            'site #%d %s folded into #%d (%s)%s',
            $id,
            (string) $item['domain'],
            $into,
            (string) $item['target'],
            $blocked > 0 ? sprintf(', %d blocked source%s moved', $blocked, $blocked === 1 ? '' : 's') : '',
        );

        if ($dryRun) {
            return $note;
        }

        $this->transaction(function () use ($id, $into): void {
            // A source the survivor already blocks would break the unique
            // pair, so drop it rather than move it.
            $this->db->run(
                'DELETE b FROM blocklists b JOIN blocklists k ON k.site_id = ? AND k.source = b.source WHERE b.site_id = ?',
                [$into, $id],
            );
            $this->db->run('UPDATE blocklists SET site_id = ? WHERE site_id = ?', [$into, $id]);

            // Web hook settings are worth carrying over only if the survivor
            // has none of its own.
            $dropped  = $this->db->one('SELECT callback_url, callback_secret FROM sites WHERE id = ?', [$id]);
            $survivor = $this->db->one('SELECT callback_url FROM sites WHERE id = ?', [$into]);

            if (($dropped['callback_url'] ?? '') !== '' && ($survivor['callback_url'] ?? '') === '') {
                $this->db->update('sites', $into, [
                    'callback_url'    => $dropped['callback_url'],
                    'callback_secret' => $dropped['callback_secret'],
                    'updated_at'      => Database::now(),
                ]);
            }

            $this->db->run('DELETE FROM sites WHERE id = ?', [$id]);
        });

        return $note;
    }

    /**
     * What a row is holding, so a fold never silently drops anything. Empty
     * for every duplicate seen so far, but this runs against a live database.
     *
     * @return list<string>
     */
    private function contents(int $siteId): array
    {
        $holds = [];
        foreach ([
            'links'              => 'webmention',
            'pages'              => 'page',
            'page_aliases'       => 'alias',
            'webhook_deliveries' => 'web hook delivery',
        ] as $table => $noun) {
            $n = (int) $this->db->value("SELECT COUNT(*) FROM `$table` WHERE site_id = ?", [$siteId]);
            if ($n > 0) {
                $holds[] = sprintf('%d %s%s', $n, $noun, $n === 1 ? '' : 's');
            }
        }

        return $holds;
    }

    /** How many other accounts already have a site for this host. */
    private function heldElsewhere(int $accountId, string $domain): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM sites WHERE domain = ? AND account_id <> ?',
            [$domain, $accountId],
        );
    }

    private function transaction(callable $work): void
    {
        $this->db->pdo()->beginTransaction();

        try {
            $work();
            $this->db->pdo()->commit();
        } catch (Throwable $e) {
            $this->db->pdo()->rollBack();

            throw $e;
        }
    }

    /**
     * Whether a row is the auth.rb:75 shape: the account's own name, with the
     * slashes of a profile URL turned into underscores. Requiring the match
     * against the username is what keeps out a host that simply contains an
     * underscore and was typed into the add-a-site form.
     *
     * @param array<string, mixed> $row
     */
    private static function isPathEncoded(array $row): bool
    {
        $domain = (string) $row['domain'];

        return $domain !== ''
            && $domain === (string) $row['username']
            && str_contains($domain, '_')
            && !str_contains($domain, '://');
    }

    /**
     * The hostname a row should hold, or null if nothing can be made of it.
     *
     * @param array<string, mixed> $row
     */
    public static function targetFor(array $row): ?string
    {
        $domain = (string) $row['domain'];

        // Inverting the gsub can only be right when what comes back is a
        // hostname. "brandon_swoop.ngrok.io" would give "brandon", which
        // normalizeDomain() rejects for having no dot, so the underscore
        // there belongs to the host and the value is left as it stands.
        if (self::isPathEncoded($row)) {
            $decoded = SettingsController::normalizeDomain(str_replace('_', '/', $domain));
            if ($decoded !== null) {
                return $decoded;
            }
        }

        return SettingsController::normalizeDomain($domain);
    }

    private static function whyStuck(string $domain, bool $encoded): string
    {
        if (trim($domain) === '') {
            return 'empty';
        }

        if ($encoded) {
            return 'the part before the first underscore is not a hostname';
        }

        // An email address names a mail provider, not the person's site.
        if (isset(parse_url(str_contains($domain, '://') ? $domain : 'http://' . $domain)['user'])) {
            return 'looks like an email address, so its host is not this account\'s site';
        }

        return str_contains($domain, '.') ? 'no usable hostname' : 'not a domain name';
    }
}
