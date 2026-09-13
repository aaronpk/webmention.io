<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use Webmention\Model\Account;
use Webmention\Storage\BlockRepository;
use Webmention\Storage\LinkRepository;
use Webmention\Storage\SiteDeduplicator;
use Webmention\Storage\SiteRepository;
use Webmention\Tests\Support\IntegrationTestCase;

/**
 * The data migration behind database/migrations/2026-09-14-dedupe-sites.php.
 */
final class SiteDeduplicatorTest extends IntegrationTestCase
{
    private Account $alice;
    private SiteDeduplicator $dedupe;

    private bool $hadIndex = false;

    protected function setUp(): void
    {
        parent::setUp();

        // The migration runs against a database that still allows duplicates.
        $this->hadIndex = $this->db->all('SHOW INDEX FROM sites WHERE Key_name = "account_domain"') !== [];
        if ($this->hadIndex) {
            $this->db->pdo()->exec('ALTER TABLE sites DROP INDEX account_domain');
        }

        $this->alice  = $this->createAccount('alice.example');
        $this->dedupe = new SiteDeduplicator($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->hadIndex) {
            foreach (['sites', 'pages', 'links', 'blocklists'] as $table) {
                $this->db->pdo()->exec("TRUNCATE TABLE `$table`");
            }
            $this->db->pdo()->exec('ALTER TABLE sites ADD UNIQUE INDEX account_domain (account_id, domain)');
        }
    }

    public function testNothingToDoWithoutDuplicates(): void
    {
        $this->createSite($this->alice, 'alice.example');
        $this->createSite($this->alice, 'blog.alice.example');
        $this->createSite($this->createAccount('bob.example'), 'alice.example'); // another account may hold the same domain

        self::assertSame([], $this->dedupe->groups());
    }

    public function testEmptyDuplicateIsDeletedAndItsSettingsCarriedOver(): void
    {
        $keep = $this->createSite($this->alice, 'alice.example');
        $this->createLink($keep, 'https://alice.example/post', 'https://bob.example/reply');
        $this->service(BlockRepository::class)->blockSource($keep->id, 'https://spam.example/1');

        $dup = $this->createSite($this->alice, 'alice.example', ['callback_url' => 'https://alice.example/hook', 'callback_secret' => 'shh']);
        $this->service(BlockRepository::class)->blockSource($dup->id, 'https://spam.example/1'); // already on the survivor
        $this->service(BlockRepository::class)->blockSource($dup->id, 'https://spam.example/2');

        $groups = $this->dedupe->groups();
        self::assertCount(1, $groups);
        self::assertSame($keep->id, (int) $groups[0]['keep']['id']);
        self::assertSame([$dup->id], array_map(static fn (array $s): int => (int) $s['id'], $groups[0]['drop']));

        // A dry run changes nothing.
        $notes = $this->dedupe->merge($groups[0]);
        self::assertSame(['moved 1 blocklist rows (1 already on the survivor)', 'copied web hook https://alice.example/hook', "deleted site #{$dup->id}"], $notes);
        self::assertNotNull($this->service(SiteRepository::class)->find($dup->id));

        self::assertSame($notes, $this->dedupe->merge($groups[0], dryRun: false));

        $sites = $this->service(SiteRepository::class);
        self::assertNull($sites->find($dup->id));
        $survivor = $sites->find($keep->id);
        self::assertSame('https://alice.example/hook', $survivor?->callbackUrl);
        self::assertSame('shh', $survivor?->callbackSecret);

        $blocks = $this->service(BlockRepository::class);
        self::assertTrue($blocks->isSourceBlocked($keep->id, 'https://spam.example/1'));
        self::assertTrue($blocks->isSourceBlocked($keep->id, 'https://spam.example/2'));
        self::assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM blocklists'));
        self::assertSame([], $this->dedupe->groups());
    }

    public function testDataOnBothRowsIsMergedIntoTheOneWithMore(): void
    {
        // The older row has less data here, so the newer one survives.
        $small = $this->createSite($this->alice, 'alice.example');
        $onlyOnSmall = $this->createLink($small, 'https://alice.example/shared', 'https://carol.example/1');
        $this->createLink($small, 'https://alice.example/only-small', 'https://carol.example/2');

        $big = $this->createSite($this->alice, 'alice.example', ['callback_url' => 'https://alice.example/big-hook']);
        $this->createLink($big, 'https://alice.example/shared', 'https://carol.example/3');
        $this->createLink($big, 'https://alice.example/a', 'https://carol.example/4');
        $this->createLink($big, 'https://alice.example/b', 'https://carol.example/5');

        $groups = $this->dedupe->groups();
        self::assertSame($big->id, (int) $groups[0]['keep']['id']);

        $notes = $this->dedupe->merge($groups[0], dryRun: false);
        self::assertSame(['merged 2 pages (1 folded into existing pages) and 2 links', "deleted site #{$small->id}"], $notes);

        self::assertNull($this->service(SiteRepository::class)->find($small->id));
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM pages WHERE site_id = ?', [$small->id]));
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM links WHERE site_id = ?', [$small->id]));
        // One page per href on the survivor, and every link still reachable.
        self::assertSame(4, (int) $this->db->value('SELECT COUNT(*) FROM pages WHERE site_id = ?', [$big->id]));
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM pages WHERE site_id = ? AND href = ?', [$big->id, 'https://alice.example/shared']));
        self::assertSame(5, (int) $this->db->value('SELECT COUNT(*) FROM links WHERE site_id = ?', [$big->id]));

        $moved = $this->service(LinkRepository::class)->find($onlyOnSmall);
        self::assertSame($big->id, $moved?->siteId);
        self::assertSame('https://alice.example/shared', $moved?->targetHref);

        // The survivor's own web hook is kept.
        self::assertSame('https://alice.example/big-hook', $this->service(SiteRepository::class)->find($big->id)?->callbackUrl);

        $api = self::json($this->request('GET', '/api/mentions.jf2', ['target' => 'https://alice.example/shared']));
        self::assertEqualsCanonicalizing(['https://carol.example/1', 'https://carol.example/3'], array_column($api['children'], 'wm-source'));
    }

    public function testOldestSurvivesATie(): void
    {
        $first  = $this->createSite($this->alice, 'alice.example');
        $second = $this->createSite($this->alice, 'alice.example');
        $third  = $this->createSite($this->alice, 'alice.example');

        $groups = $this->dedupe->groups();
        self::assertSame($first->id, (int) $groups[0]['keep']['id']);
        self::assertSame([$second->id, $third->id], array_map(static fn (array $s): int => (int) $s['id'], $groups[0]['drop']));

        $this->dedupe->merge($groups[0], dryRun: false);

        self::assertSame([$first->id], array_map(static fn ($s): int => $s->id, $this->service(SiteRepository::class)->listForAccount($this->alice->id)));
    }
}
