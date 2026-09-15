<?php

declare(strict_types=1);

namespace Webmention\Webmention;

use Webmention\Format\Url;
use Webmention\Logging\Log;
use Webmention\Model\Account;
use Webmention\Model\Site;
use Webmention\Storage\AccountRepository;
use Webmention\Storage\Database;
use Webmention\Storage\PageRepository;
use Webmention\Storage\SiteRepository;

/**
 * Bring an old account's sites and mentions into the account someone is
 * signed in with, after they moved their site to a new domain (issue 223).
 *
 * Proof that the old account is theirs: its domain now points at the current
 * account, either by advertising one of its webmention endpoints (the normal
 * site proof) or by redirecting to a site the current account has verified.
 * Whoever controls the old domain today chose where it goes.
 */
final class AccountMerger
{
    private const TIMEOUT  = 5;
    private const MAX_HOPS = 5;

    /** Rows moved per statement, so a large account does not hold locks for long. */
    public const BATCH = 5000;

    public function __construct(
        private readonly Database $db,
        private readonly AccountRepository $accounts,
        private readonly SiteRepository $sites,
        private readonly PageRepository $pages,
        private readonly SiteVerifier $verifier,
        private readonly HttpClient $http,
        private readonly Log $log,
    ) {
    }

    /**
     * The old account, if $oldDomain names one and it now points at $into;
     * otherwise a sentence saying why not.
     */
    public function check(Account $into, string $oldDomain): Account|string
    {
        $domain = self::domain($oldDomain);
        if ($domain === null) {
            return 'Enter the old domain name, like example.com.';
        }

        $old = $this->accounts->findByDomain($domain);
        if ($old === null) {
            return "There is no account named $domain.";
        }
        if ($old->id === $into->id) {
            return 'That is the account you are signed in with.';
        }

        // The old domain advertises this account's endpoint...
        if ($this->verifier->verify($into, $domain) === null) {
            return $old;
        }

        // ...or redirects to one of this account's verified sites.
        $verified = [];
        foreach ($this->sites->listForAccount($into->id) as $site) {
            if ($site->isVerified() && !$site->isArchived()) {
                $verified[] = strtolower((string) $site->domain);
            }
        }

        $http = $this->http->http(self::TIMEOUT);
        $http->set_max_redirects(0);
        $seen = [];

        foreach (["https://$domain/", "http://$domain/"] as $url) {
            for ($hop = 0; $hop <= self::MAX_HOPS; $hop++) {
                $response = $http->get($url, ['Accept: text/html']);
                $code     = (int) ($response['code'] ?? 0);
                if (!empty($response['error']) || $code < 300 || $code >= 400) {
                    break;
                }
                if (preg_match('/^location:\s*(\S.*)$/im', (string) ($response['header'] ?? ''), $m) !== 1) {
                    break;
                }
                $url = \Mf2\resolveUrl($url, trim($m[1]));
                if (!Url::isHttp($url)) {
                    break;
                }
                $host = (string) Url::host($url);
                if (in_array($host, $verified, true)) {
                    return $old;
                }
                $seen[$host] = true;
            }
        }

        $where = $seen === [] ? 'does not redirect anywhere' : 'redirects to ' . implode(', ', array_keys($seen));

        return "$domain $where, and does not advertise this account's webmention endpoint, so it cannot be shown to be yours. "
            . "Point it at one of this account's verified sites, or add the endpoint tag to its home page, then try again.";
    }

    /**
     * What a merge would move, for the confirmation page.
     *
     * @return array{sites: list<string>, mentions: int}
     */
    public function preview(Account $old): array
    {
        return [
            'sites'    => array_map(static fn (Site $s): string => (string) $s->domain, $this->sites->listForAccount($old->id)),
            'mentions' => (int) $this->db->value('SELECT COUNT(*) FROM links WHERE account_id = ?', [$old->id]),
        ];
    }

    /**
     * Move everything from $old to $into and delete $old. A site whose domain
     * $into already has is folded into that site, page by page.
     *
     * @return array{sites: int, mentions: int}
     */
    public function merge(Account $into, Account $old): array
    {
        $movedSites    = 0;
        $movedMentions = 0;

        foreach ($this->sites->listForAccount($old->id) as $site) {
            $existing = $this->sites->findByAccountAndDomain($into->id, (string) $site->domain);

            if ($existing === null) {
                $this->db->run('UPDATE sites SET account_id = ?, updated_at = ? WHERE id = ?', [$into->id, Database::now(), $site->id]);
                $this->db->run('UPDATE pages SET account_id = ? WHERE site_id = ?', [$into->id, $site->id]);
                $movedMentions += $this->batched('UPDATE links SET account_id = ? WHERE site_id = ? AND (account_id IS NULL OR account_id <> ?) LIMIT ' . self::BATCH, [$into->id, $site->id, $into->id]);
            } else {
                $movedMentions += $this->fold($site, $existing);
            }
            $movedSites++;
        }

        // Account-level lists: skip entries the target already has.
        $this->db->run('DELETE b FROM blocks b JOIN blocks k ON k.account_id = ? AND k.domain = b.domain WHERE b.account_id = ?', [$into->id, $old->id]);
        $this->db->run('UPDATE blocks SET account_id = ? WHERE account_id = ?', [$into->id, $old->id]);
        $this->db->run('DELETE m FROM mutes m JOIN mutes k ON k.account_id = ? AND k.kind = m.kind AND k.pattern = m.pattern WHERE m.account_id = ?', [$into->id, $old->id]);
        $this->db->run('UPDATE mutes SET account_id = ? WHERE account_id = ?', [$into->id, $old->id]);

        // Anything still pointing at the old account (rows with no site).
        $this->db->run('UPDATE pages SET account_id = ? WHERE account_id = ?', [$into->id, $old->id]);
        $movedMentions += $this->batched('UPDATE links SET account_id = ? WHERE account_id = ? LIMIT ' . self::BATCH, [$into->id, $old->id]);

        $this->db->run('DELETE FROM accounts WHERE id = ?', [$old->id]);

        $this->log->info(sprintf('Merged account %d (%s) into %d (%s): %d sites, %d mentions', $old->id, $old->domain, $into->id, $into->domain, $movedSites, $movedMentions));

        return ['sites' => $movedSites, 'mentions' => $movedMentions];
    }

    /** Move an old site's contents into the target account's site of the same domain, then drop it. */
    private function fold(Site $from, Site $into): int
    {
        $moved = 0;

        foreach ($this->db->all('SELECT id FROM pages WHERE site_id = ?', [$from->id]) as $row) {
            $page = $this->pages->find((int) $row['id']);
            if ($page === null) {
                continue;
            }
            $twin = $this->pages->findBySiteAndHref($into->id, (string) $page->href);
            if ($twin !== null) {
                $moved += $this->pages->merge($page, $twin);
            } else {
                $this->db->run('UPDATE pages SET site_id = ?, account_id = ? WHERE id = ?', [$into->id, $into->accountId, $page->id]);
                $moved += $this->batched('UPDATE links SET site_id = ?, account_id = ? WHERE page_id = ? AND site_id <> ? LIMIT ' . self::BATCH, [$into->id, $into->accountId, $page->id, $into->id]);
            }
        }

        $this->db->run('DELETE a FROM page_aliases a JOIN page_aliases k ON k.site_id = ? AND k.href = a.href WHERE a.site_id = ?', [$into->id, $from->id]);
        $this->db->run('UPDATE page_aliases SET site_id = ? WHERE site_id = ?', [$into->id, $from->id]);
        $this->db->run('DELETE b FROM blocklists b JOIN blocklists k ON k.site_id = ? AND k.source = b.source WHERE b.site_id = ?', [$into->id, $from->id]);
        $this->db->run('UPDATE blocklists SET site_id = ? WHERE site_id = ?', [$into->id, $from->id]);
        $this->db->run('UPDATE webhook_deliveries SET site_id = ? WHERE site_id = ?', [$into->id, $from->id]);
        // Stragglers with no page row.
        $moved += $this->batched('UPDATE links SET site_id = ?, account_id = ? WHERE site_id = ? LIMIT ' . self::BATCH, [$into->id, $into->accountId, $from->id]);
        $this->db->run('DELETE FROM sites WHERE id = ?', [$from->id]);

        return $moved;
    }

    /**
     * Run an UPDATE ... LIMIT until it changes nothing more.
     *
     * @param list<mixed> $params
     */
    private function batched(string $sql, array $params): int
    {
        $total = 0;
        do {
            $n = $this->db->run($sql, $params)->rowCount();
            $total += $n;
        } while ($n === self::BATCH);

        return $total;
    }

    /** A domain as typed, cleaned up: lowercased, no scheme or path. */
    public static function domain(string $input): ?string
    {
        $input = strtolower(trim($input));
        $input = (string) preg_replace('#^https?://#', '', $input);
        $host  = explode('/', $input)[0];

        return preg_match('/^[a-z0-9.\-]+$/', $host) === 1 && str_contains($host, '.') ? $host : null;
    }
}
