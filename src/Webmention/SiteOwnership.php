<?php

declare(strict_types=1);

namespace Webmention\Webmention;

use Webmention\Logging\Log;
use Webmention\Model\Account;
use Webmention\Model\Site;
use Webmention\Storage\AccountRepository;
use Webmention\Storage\SiteRepository;

/**
 * Records what a verification check proved, for the nightly recheck, the
 * Check now button, and a new account's first visit to its Sites page.
 *
 * A site that advertises its account's endpoint is verified. One that does
 * not is normally left as it was: a site that is down for a day, or mid
 * redesign, should not lose its standing. The one exception is a domain
 * whose pages now name a different account of this service: that is not an
 * outage but the domain moving, so the old row loses its verification and
 * says where the domain went. Only the /{account}/webmention form counts as
 * naming an account; /d/{domain}/webmention can be claimed by any account.
 */
final class SiteOwnership
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly AccountRepository $accounts,
        private readonly SiteVerifier $verifier,
        private readonly Log $log,
    ) {
    }

    /**
     * Run the check for $site and record the outcome. Returns the verifier's
     * problem sentence, or null when the site is verified. A dry run checks
     * and returns without writing anything.
     *
     * @param list<string> $alsoTry Pages to try after the home page, see SiteVerifier::verify().
     */
    public function check(Site $site, Account $account, array $alsoTry = [], bool $dryRun = false): ?string
    {
        $domain  = strtolower((string) $site->domain);
        $problem = $this->verifier->verify($account, $domain, $alsoTry);

        if ($dryRun) {
            return $problem;
        }

        if ($problem === null) {
            $this->sites->markVerified($site->id);

            // The domain names this account in particular: any other account
            // still holding it verified has been superseded.
            $matched = $this->verifier->matchedEndpoint();
            if ($matched !== null && $this->verifier->accountNamedBy($matched) !== null) {
                foreach ($this->sites->verifiedOnOtherAccounts($account->id, $domain) as $other) {
                    $this->moved($other, $domain, $account);
                }
            }

            return null;
        }

        $successor = $this->namedOtherAccount($account);
        if ($successor !== null && $site->isVerified()) {
            $this->moved($site, $domain, $successor);

            return $problem;
        }

        $this->sites->markChecked($site->id, $problem);

        return $problem;
    }

    /** The message a superseded row carries. */
    public static function movedMessage(string $domain, Account $to): string
    {
        return "$domain now advertises the endpoint of the account " . ($to->domain ?? $to->username) . ', so its webmentions go there.';
    }

    private function moved(Site $site, string $domain, Account $to): void
    {
        $this->sites->unverify($site->id, self::movedMessage($domain, $to));
        $this->log->info(sprintf('Site #%d %s (account %d) unverified: it now advertises the endpoint of account %d', $site->id, $domain, $site->accountId, $to->id));
    }

    /**
     * Among the endpoints the last check saw, the first that names an
     * existing account other than $account.
     */
    private function namedOtherAccount(Account $account): ?Account
    {
        foreach ($this->verifier->foundEndpoints() as $endpoint) {
            $name = $this->verifier->accountNamedBy($endpoint);
            if ($name === null) {
                continue;
            }
            $named = $this->accounts->findByName($name);
            if ($named !== null && $named->id !== $account->id) {
                return $named;
            }
        }

        return null;
    }
}
