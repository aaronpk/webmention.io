<?php

declare(strict_types=1);

namespace Webmention\Admin;

use Webmention\Model\Account;
use Webmention\Model\Mute;
use Webmention\Model\Site;
use Webmention\Model\WebhookDelivery;
use Webmention\Storage\AccountRepository;
use Webmention\Storage\BlockRepository;
use Webmention\Storage\LinkRepository;
use Webmention\Storage\MuteRepository;
use Webmention\Storage\SiteRepository;
use Webmention\Storage\WebhookDeliveryRepository;
use Webmention\Webmention\WebhookRetries;

/**
 * Everything the service knows about one account, for answering "it isn't
 * working" without asking the person to describe their own configuration.
 *
 * Nothing here is a secret of the operator's: the API token is reported as
 * present or absent, never shown, and private mentions arrive already
 * redacted by MentionFacts.
 */
final class AccountReport
{
    public const MENTIONS   = 20;
    public const DELIVERIES = 10;

    /**
     * How many of an account's sites are described in full. One account has
     * 1,749 of them, and each costs several queries; the rest are listed by
     * name instead of being counted one by one.
     */
    public const SITES = 50;

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly SiteRepository $sites,
        private readonly LinkRepository $links,
        private readonly BlockRepository $blocks,
        private readonly MuteRepository $mutes,
        private readonly WebhookDeliveryRepository $deliveries,
        private readonly WebhookRetries $retries,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function of(int $accountId): ?array
    {
        $account = $this->accounts->find($accountId);
        $row     = $this->accounts->row($accountId);
        if ($account === null || $row === null) {
            return null;
        }

        $sites   = $this->sites->listForAccount($accountId);
        $shown   = array_slice($sites, 0, self::SITES);
        $retries = $this->retries->bySite();

        return [
            'account'  => $this->facts($account, $row),
            'sites'    => array_map(fn (Site $s): array => $this->site($s, $retries[$s->id] ?? []), $shown),
            'site_total' => count($sites),
            'site_rest'  => array_map(
                static fn (Site $s): string => (string) $s->domain,
                array_slice($sites, self::SITES),
            ),
            'blocks'   => $this->blocks->domainsForAccount($accountId),
            'sources'  => $this->blocks->countSourcesForAccount($accountId),
            'mutes'    => array_map(static fn (Mute $m): array => [
                'kind'    => $m->kind,
                'pattern' => $m->pattern,
                'created' => $m->createdAt,
            ], $this->mutes->forAccount($accountId)),
            'mentions' => MentionFacts::all($this->links->anyForAccount($accountId, self::MENTIONS)),
        ];
    }

    /**
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function facts(Account $account, array $row): array
    {
        return [
            'id'         => $account->id,
            'username'   => $account->username,
            'domain'     => $account->domain,
            'email'      => $row['email'] === null ? null : (string) $row['email'],
            'created_at' => $account->createdAt,
            'last_login' => $row['last_login'] === null ? null : (string) $row['last_login'],
            // Whether it is set, never the value: this page is for debugging,
            // not for signing in as someone.
            'has_token'  => ($row['token'] ?? null) !== null && $row['token'] !== '',
            'pingback'   => (bool) ($row['pingback_enabled'] ?? false),
            'aperture'   => $account->apertureUri !== null && $account->apertureUri !== '',
        ];
    }

    /**
     * @param  array<int, int> $pending delivery_id => due, for this site
     * @return array<string, mixed>
     */
    private function site(Site $site, array $pending): array
    {
        $elsewhere = [];
        if ($site->domain !== null) {
            foreach ($this->sites->verifiedOnOtherAccounts($site->accountId, $site->domain) as $other) {
                $account     = $this->accounts->find($other->accountId);
                $elsewhere[] = [
                    'account_id' => $other->accountId,
                    'name'       => $account?->username ?? $account?->domain ?? ('account ' . $other->accountId),
                    'site_id'    => $other->id,
                ];
            }
        }

        return [
            'id'           => $site->id,
            'domain'       => $site->domain,
            'created_at'   => $site->createdAt,
            'verified_at'  => $site->verifiedAt,
            'checked_at'   => $site->verificationCheckedAt,
            'error'        => $site->verificationError,
            'moderation'   => $site->moderation ?? 'off',
            'archived_at'  => $site->archivedAt,
            'webhook'      => $site->callbackUrl,
            'pages'        => $this->sites->pageCount($site->id),
            'mentions'     => $this->sites->linkCount($site->id),
            'last_mention' => $this->sites->lastMentionAt($site->id),
            'elsewhere'    => $elsewhere,
            'retries'      => count($pending),
            'deliveries'   => array_map(static fn (WebhookDelivery $d): array => [
                'id'       => $d->id,
                'kind'     => $d->kind,
                'url'      => $d->url,
                'code'     => $d->statusCode,
                'error'    => $d->error,
                'ms'       => $d->durationMs,
                'attempt'  => $d->attempt,
                'link_id'  => $d->linkId,
                'created'  => $d->createdAt,
                'due'      => $pending[$d->id] ?? null,
            ], $this->deliveries->recentForSite($site->id, self::DELIVERIES)),
        ];
    }
}
