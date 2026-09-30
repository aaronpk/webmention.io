<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use Webmention\Controllers\SettingsController;
use Webmention\Model\Account;
use Webmention\Storage\Database;
use Webmention\Storage\SiteDomainCleanup;
use Webmention\Tests\Support\IntegrationTestCase;

/**
 * The data migration behind database/migrations/2026-09-29-clean-site-domains.php.
 *
 * Two shapes get in from the Ruby app: a whole URL, from an add-a-site form
 * that never validated anything, and a profile URL's path encoded with
 * underscores, from the account name it built at sign-in. Neither can ever
 * match a target's host.
 */
final class SiteDomainCleanupTest extends IntegrationTestCase
{
    private SiteDomainCleanup $cleanup;
    private Account $alice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanup = new SiteDomainCleanup($this->db);
        $this->alice   = $this->createAccount('alice.example');
    }

    /** @return list<string> */
    private function clean(bool $apply): array
    {
        $plan  = $this->cleanup->plan();
        $notes = [];

        foreach ([SiteDomainCleanup::RENAME, SiteDomainCleanup::FOLD] as $kind) {
            foreach ($plan[$kind] as $item) {
                $notes[] = $this->cleanup->apply($item, dryRun: !$apply);
            }
        }

        return $notes;
    }

    private function domainOf(int $siteId): ?string
    {
        $value = $this->db->value('SELECT domain FROM sites WHERE id = ?', [$siteId]);

        return $value === null ? null : (string) $value;
    }

    public function testASiteThatIsAlreadyABareHostnameIsLeftAlone(): void
    {
        $site = $this->createSite($this->alice, 'alice.example');
        $plan = $this->cleanup->plan();

        self::assertSame([], $plan[SiteDomainCleanup::RENAME]);
        self::assertSame([], $plan[SiteDomainCleanup::FOLD]);
        self::assertSame([], $plan[SiteDomainCleanup::STUCK]);
        self::assertSame('alice.example', $this->domainOf($site->id));
    }

    public function testAWholeUrlBecomesItsHost(): void
    {
        $site = $this->createSite($this->alice, 'https://alice.example/blog/');

        $dry = $this->clean(apply: false);
        self::assertSame(["site #{$site->id} https://alice.example/blog/ -> alice.example"], $dry);
        self::assertSame('https://alice.example/blog/', $this->domainOf($site->id), 'a dry run writes nothing');

        self::assertSame($dry, $this->clean(apply: true), 'the note reads the same either way');
        self::assertSame('alice.example', $this->domainOf($site->id));
    }

    public function testRenamingAUrlKeepsVerificationBecauseTheHostIsTheSame(): void
    {
        $site = $this->createSite($this->alice, 'HTTPS://alice.example', ['verified_at' => Database::now()]);

        $this->clean(apply: true);

        self::assertSame('alice.example', $this->domainOf($site->id));
        self::assertNotNull($this->db->value('SELECT verified_at FROM sites WHERE id = ?', [$site->id]));
    }

    public function testAnUnderscoreEncodedPathBecomesItsHostAndLosesVerification(): void
    {
        $bob  = $this->createAccount('bob.example_blog_', 'bob.example_blog_');
        $site = $this->createSite($bob, 'bob.example_blog_', [
            'verified_at'             => Database::now(),
            'verification_checked_at' => Database::now(),
            'verification_error'      => 'could not be fetched',
        ]);

        $notes = $this->clean(apply: true);

        self::assertStringContainsString('no longer verified', $notes[0]);
        self::assertSame('bob.example', $this->domainOf($site->id));

        $row = $this->db->one('SELECT * FROM sites WHERE id = ?', [$site->id]);
        self::assertNull($row['verified_at'], 'IndieAuth proved a path, not the host');
        self::assertNull($row['verification_checked_at'], 'the old check was against a different string');
        self::assertNull($row['verification_error']);
    }

    public function testAnUnderscoreInTheHostItselfIsLeftAlone(): void
    {
        $bob  = $this->createAccount('brandon_swoop.example', 'brandon_swoop.example');
        $site = $this->createSite($bob, 'brandon_swoop.example');

        $plan = $this->cleanup->plan();

        self::assertSame([], $plan[SiteDomainCleanup::RENAME], 'decoding would give "brandon", which is not a hostname');
        self::assertSame([], $plan[SiteDomainCleanup::STUCK]);
        self::assertSame('brandon_swoop.example', $this->domainOf($site->id));
    }

    public function testAnUnderscoreSiteThatIsNotTheAccountsOwnNameIsLeftAlone(): void
    {
        // Typed into the add-a-site form by someone else, so the underscore
        // is not the sign-in encoding and must not be read as a path.
        $site = $this->createSite($this->alice, 'eli_oat.example.city');

        self::assertSame([], $this->cleanup->plan()[SiteDomainCleanup::RENAME]);
        self::assertSame('eli_oat.example.city', $this->domainOf($site->id));
    }

    public function testADuplicateIsFoldedIntoTheRowThatAlreadyHasTheName(): void
    {
        $keep = $this->createSite($this->alice, 'alice.example');
        $dupe = $this->createSite($this->alice, 'https://alice.example/');

        $notes = $this->clean(apply: true);

        self::assertSame(["site #{$dupe->id} https://alice.example/ folded into #{$keep->id} (alice.example)"], $notes);
        self::assertNull($this->domainOf($dupe->id), 'the duplicate is gone');
        self::assertSame('alice.example', $this->domainOf($keep->id));
    }

    public function testFoldingMovesBlockedSourcesAndDropsTheOnesAlreadyThere(): void
    {
        $keep = $this->createSite($this->alice, 'alice.example');
        $dupe = $this->createSite($this->alice, 'https://alice.example/');

        $blocks = $this->service(\Webmention\Storage\BlockRepository::class);
        $blocks->blockSource($keep->id, 'https://spam.example/one');
        $blocks->blockSource($dupe->id, 'https://spam.example/one');
        $blocks->blockSource($dupe->id, 'https://spam.example/two');

        $notes = $this->clean(apply: true);

        self::assertStringContainsString('2 blocked sources moved', $notes[0]);
        self::assertTrue($blocks->isSourceBlocked($keep->id, 'https://spam.example/one'));
        self::assertTrue($blocks->isSourceBlocked($keep->id, 'https://spam.example/two'), 'the one it lacked moved over');
        self::assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM blocklists WHERE site_id = ?', [$keep->id]));
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM blocklists WHERE site_id = ?', [$dupe->id]));
    }

    public function testFoldingCarriesTheWebHookOverOnlyWhenTheSurvivorHasNone(): void
    {
        $keep = $this->createSite($this->alice, 'alice.example');
        $this->createSite($this->alice, 'https://alice.example/', ['callback_url' => 'https://hooks.example.net/in', 'callback_secret' => 's3cret']);

        $this->clean(apply: true);

        $row = $this->db->one('SELECT * FROM sites WHERE id = ?', [$keep->id]);
        self::assertSame('https://hooks.example.net/in', $row['callback_url']);
        self::assertSame('s3cret', $row['callback_secret']);
    }

    public function testFoldingLeavesTheSurvivorsOwnWebHookInPlace(): void
    {
        $keep = $this->createSite($this->alice, 'alice.example', ['callback_url' => 'https://hooks.example.net/keep']);
        $this->createSite($this->alice, 'https://alice.example/', ['callback_url' => 'https://hooks.example.net/other']);

        $this->clean(apply: true);

        self::assertSame('https://hooks.example.net/keep', $this->db->value('SELECT callback_url FROM sites WHERE id = ?', [$keep->id]));
    }

    public function testADuplicateHoldingWebmentionsIsReportedAndLeftAlone(): void
    {
        $this->createSite($this->alice, 'alice.example');
        $dupe = $this->createSite($this->alice, 'https://alice.example/');
        $this->createLink($dupe, 'https://alice.example/post', 'https://writer.example/note');

        $notes = $this->clean(apply: true);

        self::assertStringContainsString('but it holds 1 webmention, 1 page: left alone', $notes[0]);
        self::assertSame('https://alice.example/', $this->domainOf($dupe->id), 'nothing was touched');
    }

    public function testTwoMalformedRowsCollapsingOnOneHostBecomeARenameAndAFold(): void
    {
        $first  = $this->createSite($this->alice, 'http://alice.example');
        $second = $this->createSite($this->alice, 'https://alice.example/');

        $this->clean(apply: true);

        self::assertSame('alice.example', $this->domainOf($first->id), 'the older row keeps the name');
        self::assertNull($this->domainOf($second->id));
    }

    public function testAnInternationalisedDomainBecomesItsPunycodeForm(): void
    {
        if (!function_exists('idn_to_ascii')) {
            self::markTestSkipped('ext-intl is not installed.');
        }

        $site = $this->createSite($this->alice, 'tīkōuka.example');

        $this->clean(apply: true);

        self::assertSame('xn--tkuka-m3a3v.example', $this->domainOf($site->id));
    }

    public function testAnEmailAddressIsReportedRatherThanTurnedIntoItsProvider(): void
    {
        $site = $this->createSite($this->alice, 'someone@gmail.com');

        $plan = $this->cleanup->plan();

        self::assertSame([], $plan[SiteDomainCleanup::RENAME]);
        self::assertCount(1, $plan[SiteDomainCleanup::STUCK]);
        self::assertStringContainsString('looks like an email address', $plan[SiteDomainCleanup::STUCK][0]['why']);
        self::assertSame('someone@gmail.com', $this->domainOf($site->id));
    }

    public function testRunningItAgainFindsNothingLeftToDo(): void
    {
        $this->createSite($this->alice, 'alice.example');
        $this->createSite($this->alice, 'https://alice.example/');
        $this->createSite($this->alice, 'https://blog.alice.example/posts/');

        $this->clean(apply: true);
        $plan = $this->cleanup->plan();

        self::assertSame([], $plan[SiteDomainCleanup::RENAME]);
        self::assertSame([], $plan[SiteDomainCleanup::FOLD]);
    }

    public function testARenamedSiteAnswersTheApiItCouldNotAnswerBefore(): void
    {
        $site = $this->createSite($this->alice, 'https://alice.example/blog/');
        $this->createLink($site, 'https://alice.example/post', 'https://writer.example/note');
        $token = $this->service(\Webmention\Storage\AccountRepository::class)->regenerateToken($this->alice->id);

        // A domain the account does not have answers with an empty feed.
        $before = $this->request('GET', '/api/mentions.jf2', ['token' => $token, 'domain' => 'alice.example']);
        self::assertSame([], self::json($before)['children']);

        $this->clean(apply: true);

        $after = $this->request('GET', '/api/mentions.jf2', ['token' => $token, 'domain' => 'alice.example']);
        self::assertSame(['https://writer.example/note'], array_column(self::json($after)['children'], 'wm-source'));
    }

    public function testNormalizeDomainRules(): void
    {
        self::assertSame('example.com', SettingsController::normalizeDomain('https://Example.com/path'));
        self::assertSame('example.com', SettingsController::normalizeDomain(' exam ple.com '), 'a hostname has no spaces');
        self::assertSame('xn--tkuka-m3a3v.dev', SettingsController::normalizeDomain('tīkōuka.dev'));
        self::assertSame('xn--izc.com', SettingsController::normalizeDomain('xn--izc.com'), 'already punycode');
        self::assertSame('brandon_swoop.ngrok.io', SettingsController::normalizeDomain('brandon_swoop.ngrok.io'));
        self::assertNull(SettingsController::normalizeDomain('someone@gmail.com'), 'an email address is not a domain');
        self::assertNull(SettingsController::normalizeDomain('100%.com'), 'a host never contains a percent sign');
        self::assertNull(SettingsController::normalizeDomain('localhost'));
        self::assertNull(SettingsController::normalizeDomain(''));
    }
}
