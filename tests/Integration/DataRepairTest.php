<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use Webmention\Model\Account;
use Webmention\Model\Site;
use Webmention\Storage\DataAudit;
use Webmention\Storage\DataRepair;
use Webmention\Storage\Database;
use Webmention\Storage\PageRepository;
use Webmention\Storage\SiteRepository;
use Webmention\Tests\Support\IntegrationTestCase;

/**
 * The repairs behind tools/repair: computed from the data, batched, and safe
 * to run twice.
 */
final class DataRepairTest extends IntegrationTestCase
{
    private Account $alice;
    private Site $site;
    private DataRepair $repair;
    private DataAudit $audit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice  = $this->createAccount('alice.example');
        $this->site   = $this->createSite($this->alice, 'alice.example');
        $this->repair = new DataRepair($this->db, $this->service(PageRepository::class), $this->service(SiteRepository::class));
        $this->audit  = new DataAudit($this->db);
    }

    public function testOrphanedWebmentionsGetTheirSiteAndAccountBack(): void
    {
        $page = $this->service(PageRepository::class)->create($this->alice->id, $this->site->id, 'https://alice.example/post');
        $a = $this->insertLink(['page_id' => $page->id, 'site_id' => 0, 'account_id' => null, 'href' => 'https://bob.example/1']);
        $b = $this->insertLink(['page_id' => $page->id, 'site_id' => 987, 'account_id' => null, 'href' => 'https://bob.example/2']);
        $stranded = $this->insertLink(['page_id' => 999_999, 'site_id' => 0, 'account_id' => null, 'href' => 'https://bob.example/3']);

        // A dry run counts them and writes nothing.
        $dry = $this->only('orphan_links', apply: false);
        self::assertSame(2, $dry['fixed']);
        self::assertSame(['1 left alone: their page is gone too, so nothing can say where they belong'], $dry['notes']);
        self::assertSame(0, (int) $this->db->value('SELECT site_id FROM links WHERE id = ?', [$a]));

        $done = $this->only('orphan_links', apply: true);
        self::assertSame(2, $done['fixed']);
        foreach ([$a, $b] as $id) {
            $row = $this->db->one('SELECT site_id, account_id FROM links WHERE id = ?', [$id]);
            self::assertSame($this->site->id, (int) $row['site_id']);
            self::assertSame($this->alice->id, (int) $row['account_id']);
        }
        self::assertSame(0, (int) $this->db->value('SELECT site_id FROM links WHERE id = ?', [$stranded]), 'the one with no page is left alone');
        self::assertSame(0, $this->audit->count('orphan_links'), 'everything placeable is placed');
        self::assertSame(1, $this->audit->count('orphan_links_unplaceable'), 'and the rest is reported, not repairable');
        self::assertSame(0, $this->only('orphan_links', apply: true)['fixed'], 'nothing left to do');
    }

    public function testDuplicatePageRowsAreFoldedIntoOne(): void
    {
        $pages = $this->service(PageRepository::class);
        $url   = 'https://alice.example/post';
        $keep  = $pages->create($this->alice->id, $this->site->id, $url);
        $extra = $pages->create($this->alice->id, $this->site->id, $url);
        $third = $pages->create($this->alice->id, $this->site->id, $url);
        $other = $pages->create($this->alice->id, $this->site->id, 'https://alice.example/other');

        $onKeep  = $this->insertLink(['page_id' => $keep->id, 'site_id' => $this->site->id, 'account_id' => $this->alice->id, 'href' => 'https://bob.example/1']);
        $this->insertLink(['page_id' => $keep->id, 'site_id' => $this->site->id, 'account_id' => $this->alice->id, 'href' => 'https://bob.example/2']);
        $moved   = $this->insertLink(['page_id' => $extra->id, 'site_id' => $this->site->id, 'account_id' => $this->alice->id, 'href' => 'https://bob.example/3']);
        $dup     = $this->insertLink(['page_id' => $third->id, 'site_id' => $this->site->id, 'account_id' => $this->alice->id, 'href' => 'https://bob.example/1']);
        $elsewhere = $this->insertLink(['page_id' => $other->id, 'site_id' => $this->site->id, 'account_id' => $this->alice->id, 'href' => 'https://bob.example/4']);
        $pages->addAlias($this->site->id, 'https://alice.example/old', $extra->id);

        self::assertSame(1, $this->only('duplicate_pages', apply: false)['fixed'], 'one group, counted without writing');
        self::assertSame(4, (int) $this->db->value('SELECT COUNT(*) FROM pages'));

        self::assertSame(1, $this->only('duplicate_pages', apply: true)['fixed']);

        // One page left for that URL, holding everything.
        self::assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM pages'));
        self::assertNotNull($pages->find($keep->id));
        self::assertNull($pages->find($extra->id));
        self::assertSame($keep->id, (int) $this->db->value('SELECT page_id FROM links WHERE id = ?', [$moved]));
        self::assertNotNull($this->db->one('SELECT id FROM links WHERE id = ?', [$onKeep]));
        self::assertNull($this->db->one('SELECT id FROM links WHERE id = ?', [$dup]), 'the same source twice on one page is dropped');
        self::assertSame($other->id, (int) $this->db->value('SELECT page_id FROM links WHERE id = ?', [$elsewhere]), 'another URL is untouched');

        // The old page's alias follows, and no alias for the page's own URL is left behind.
        self::assertSame($keep->id, (int) $this->db->value('SELECT page_id FROM page_aliases WHERE href = ?', ['https://alice.example/old']));
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM page_aliases WHERE href = ?', [$url]));

        self::assertSame(0, $this->audit->count('duplicate_pages'));
        self::assertSame(0, $this->only('duplicate_pages', apply: true)['fixed']);
    }

    public function testPagesWhoseSiteIsGoneAreRefiledOrReported(): void
    {
        $pages = $this->service(PageRepository::class);
        $lost  = $pages->create($this->alice->id, 999_999, 'https://alice.example/lost');
        $link  = $this->insertLink(['page_id' => $lost->id, 'site_id' => 999_999, 'account_id' => null, 'href' => 'https://bob.example/1']);
        $nowhere = $pages->create($this->alice->id, 999_998, 'https://nowhere.example/page');
        $stuck   = $pages->create($this->alice->id, 999_996, 'https://nowhere.example/stuck');
        $stuckLink = $this->insertLink(['page_id' => $stuck->id, 'site_id' => 999_996, 'account_id' => null, 'href' => 'https://bob.example/3']);
        $twin  = $pages->create($this->alice->id, $this->site->id, 'https://alice.example/twin');
        $lostTwin = $pages->create($this->alice->id, 999_997, 'https://alice.example/twin');
        $twinLink = $this->insertLink(['page_id' => $lostTwin->id, 'site_id' => 999_997, 'account_id' => null, 'href' => 'https://bob.example/2']);

        $result = $this->only('page_missing_site', apply: true);

        self::assertSame(3, $result['fixed'], 'two re-filed and one empty page removed');
        self::assertCount(1, $result['notes']);
        self::assertStringContainsString('https://nowhere.example/stuck', $result['notes'][0]);
        self::assertStringContainsString('holds 1 webmentions', $result['notes'][0]);
        self::assertNull($pages->find($nowhere->id), 'an empty page with no site is litter');
        self::assertNotNull($pages->find($stuck->id), 'one that still holds webmentions is kept');
        self::assertNotNull($this->db->one('SELECT id FROM links WHERE id = ?', [$stuckLink]));

        // Re-filed onto the account's site for that host, links and all.
        self::assertSame($this->site->id, $pages->find($lost->id)?->siteId);
        $row = $this->db->one('SELECT site_id, account_id FROM links WHERE id = ?', [$link]);
        self::assertSame($this->site->id, (int) $row['site_id']);
        self::assertSame($this->alice->id, (int) $row['account_id']);

        // A page whose URL already exists on the real site is folded into it.
        self::assertNull($pages->find($lostTwin->id));
        self::assertSame($twin->id, (int) $this->db->value('SELECT page_id FROM links WHERE id = ?', [$twinLink]));

        self::assertSame(0, $this->audit->count('page_missing_site'), 'nothing repairable is left');
        self::assertSame(1, $this->audit->count('page_missing_site_stuck'), 'the one that cannot be placed is reported instead');
        self::assertSame(1, $this->audit->count('orphan_links_unplaceable'), 'and so is its webmention');
    }

    public function testTheSmallerRepairs(): void
    {
        $pages = $this->service(PageRepository::class);
        $bob   = $this->createAccount('bob.example');

        $wrong = $pages->create($bob->id, $this->site->id, 'https://alice.example/wrong-account');
        $link  = $this->insertLink(['page_id' => $wrong->id, 'site_id' => $this->site->id, 'account_id' => $bob->id, 'href' => 'https://carol.example/1']);
        $empty = $this->db->insert('sites', ['account_id' => 0, 'domain' => 'nobody.example', 'created_at' => Database::now()]);
        $held  = $this->db->insert('sites', ['account_id' => 0, 'domain' => 'somebody.example', 'created_at' => Database::now()]);
        // Its account matches its (accountless) site, so it is not a mismatch too.
        $heldPage = $pages->create(0, $held, 'https://somebody.example/post');
        $this->db->insert('page_aliases', ['site_id' => $this->site->id, 'href' => 'https://alice.example/old', 'page_id' => 999_999, 'created_at' => Database::now()]);
        $this->db->insert('blocklists', ['site_id' => 999_999, 'source' => 'https://spam.example/', 'created_at' => Database::now()]);

        $results = [];
        foreach ($this->repair->run(apply: true, only: ['page_account_mismatch', 'site_missing_account', 'alias_missing_page', 'blocklist_missing_site']) as $result) {
            $results[$result['key']] = $result;
        }

        self::assertSame(1, $results['page_account_mismatch']['fixed']);
        self::assertSame($this->alice->id, $pages->find($wrong->id)?->accountId);
        self::assertSame($this->alice->id, (int) $this->db->value('SELECT account_id FROM links WHERE id = ?', [$link]));

        self::assertSame(1, $results['site_missing_account']['fixed']);
        self::assertNull($this->db->one('SELECT id FROM sites WHERE id = ?', [$empty]));
        self::assertNotNull($this->db->one('SELECT id FROM sites WHERE id = ?', [$held]), 'a site that still holds pages is left alone');
        self::assertCount(1, $results['site_missing_account']['notes']);
        self::assertStringContainsString('somebody.example', $results['site_missing_account']['notes'][0]);
        self::assertNotNull($pages->find($heldPage->id));

        self::assertSame(1, $results['alias_missing_page']['fixed']);
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM page_aliases'));
        self::assertSame(1, $results['blocklist_missing_site']['fixed']);
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM blocklists'));
    }

    public function testOnlyAndLimitNarrowTheWork(): void
    {
        $page = $this->service(PageRepository::class)->create($this->alice->id, $this->site->id, 'https://alice.example/post');
        for ($i = 0; $i < 4; $i++) {
            $this->insertLink(['page_id' => $page->id, 'site_id' => 0, 'account_id' => null, 'href' => "https://bob.example/$i"]);
        }
        $this->db->insert('blocklists', ['site_id' => 999_999, 'source' => 'https://spam.example/', 'created_at' => Database::now()]);

        $keys = array_column($this->repair->run(apply: true, only: ['orphan_links'], limit: 2), 'key');
        self::assertSame(['orphan_links'], $keys, 'no other repair runs');
        self::assertSame(2, $this->audit->count('orphan_links'), 'the limit caps the batch');
        self::assertSame(1, $this->audit->count('blocklist_missing_site'), 'the other breakage is untouched');

        self::assertSame(7, count($this->repair->run(apply: false)), 'every repair reports by default');
    }

    /** @return array{key: string, fixed: int, notes: list<string>} */
    private function only(string $key, bool $apply): array
    {
        $results = $this->repair->run($apply, [$key]);
        self::assertCount(1, $results);

        return $results[0];
    }

    /** @param array<string, mixed> $columns */
    private function insertLink(array $columns): int
    {
        return $this->db->insert('links', [
            'domain'     => 'bob.example',
            'verified'   => 1,
            'deleted'    => 0,
            'protocol'   => 'webmention',
            'created_at' => Database::now(),
            'updated_at' => Database::now(),
            ...$columns,
        ]);
    }
}
