<?php

declare(strict_types=1);

namespace Webmention\Webmention;

use Webmention\Model\Account;
use Webmention\Model\Link;
use Webmention\Model\Site;
use Webmention\Storage\LinkRepository;
use Webmention\Storage\MuteRepository;

/**
 * Whether a verified mention is published at once, held for the owner's
 * review, or hidden by a mute rule (issues 160 and 85).
 *
 * The decision is written to links.status, with verified = 1 only for
 * "publish", so every reader that already filters on verified leaves held
 * and hidden mentions out. The sender is told "success" in every case.
 */
final class Moderation
{
    public const PUBLISH = 'publish';
    public const PENDING = 'pending';
    public const HIDDEN  = 'hidden';

    /** Site hold policies: publish at once, hold first-time source domains, hold everything. */
    public const POLICIES = ['off', 'first', 'all'];

    public function __construct(
        private readonly MuteRepository $mutes,
        private readonly LinkRepository $links,
    ) {
    }

    /**
     * @param Link|null $existing The row for this (page, source) before this delivery, if any.
     * @param string    $source
     * @param string    $sourceDomain
     * @param string|null $authorUrl
     */
    public function decide(Account $account, Site $site, ?Link $existing, string $source, string $sourceDomain, ?string $authorUrl): string
    {
        // A mute rule always applies, even to something already published.
        foreach ($this->mutes->forAccount($account->id) as $rule) {
            if ($rule->matches($source, $authorUrl)) {
                return self::HIDDEN;
            }
        }

        // A mention that was already published is never held again on re-send.
        if ($existing !== null && !$existing->deleted && $existing->verified === true && $existing->status === null) {
            return self::PUBLISH;
        }

        return match ($site->moderation) {
            'all'   => self::PENDING,
            'first' => $this->links->hasPublishedFromDomain($account->id, $sourceDomain) ? self::PUBLISH : self::PENDING,
            default => self::PUBLISH,
        };
    }
}
