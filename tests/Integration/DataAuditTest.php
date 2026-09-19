<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use Webmention\Storage\DataAudit;
use Webmention\Storage\Database;
use Webmention\Storage\PageRepository;
use Webmention\Tests\Support\IntegrationTestCase;

/**
 * The consistency report behind tools/audit.
 */
final class DataAuditTest extends IntegrationTestCase
{
    public function testACleanDatabaseReportsNothing(): void
    {
        $alice = $this->createAccount('alice.example');
        $site  = $this->createSite($alice, 'alice.example');
        $this->createLink($site, 'https://alice.example/post', 'https://bob.example/reply');

        foreach ((new DataAudit($this->db))->run() as $result) {
            self::assertSame(0, $result['count'], $result['key']);
            self::assertSame([], $result['samples']);
        }
    }

    public function testEachKindOfBreakageIsCountedAndSampled(): void
    {
        $alice = $this->createAccount('alice.example');
        $bob   = $this->createAccount('bob.example');
        $site  = $this->createSite($alice, 'alice.example');
        $pages = $this->service(PageRepository::class);

        $page = $pages->create($alice->id, $site->id, 'https://alice.example/post');
        $this->insertLink(['page_id' => $page->id, 'site_id' => 0, 'account_id' => null, 'href' => 'https://bob.example/1']);
        $this->insertLink(['page_id' => 999_999, 'site_id' => 0, 'account_id' => null, 'href' => 'https://bob.example/2']);
        $this->insertLink(['page_id' => $page->id, 'site_id' => $site->id, 'account_id' => $bob->id, 'href' => 'https://bob.example/3']);
        $this->insertLink(['page_id' => $page->id, 'site_id' => $site->id, 'account_id' => $alice->id, 'href' => 'https://bob.example/4', 'verified' => 1, 'deleted' => 1]);
        $this->insertLink(['page_id' => $page->id, 'site_id' => $site->id, 'account_id' => $alice->id, 'href' => 'https://bob.example/dup']);
        $this->insertLink(['page_id' => $page->id, 'site_id' => $site->id, 'account_id' => $alice->id, 'href' => 'https://bob.example/dup']);

        $pages->create($alice->id, $site->id, 'https://alice.example/post');          // a second row for the same URL
        $pages->create($alice->id, 999_999, 'https://alice.example/gone');            // site that does not exist
        $pages->create($bob->id, $site->id, 'https://alice.example/wrong-account');   // account that is not the site's
        $pages->create($alice->id, $site->id, 'https://elsewhere.example/off-site');  // host that is not the site's

        // A page whose site is gone, holding a webmention, on a host this account has no site for.
        $stuck = $pages->create($alice->id, 999_998, 'https://gone.example/post');
        $this->insertLink(['page_id' => $stuck->id, 'site_id' => 999_998, 'account_id' => null, 'href' => 'https://bob.example/5']);

        $this->db->insert('sites', ['account_id' => 0, 'domain' => 'nobody.example', 'created_at' => Database::now()]);
        $holding = $this->db->insert('sites', ['account_id' => 0, 'domain' => 'somebody.example', 'created_at' => Database::now()]);
        $pages->create(0, $holding, 'https://somebody.example/post');
        $this->createSite($bob, 'alice.example', ['verified_at' => '2026-01-01 00:00:00']); // alice.example verified on bob's account too
        $this->db->update('sites', $site->id, ['verified_at' => '2026-01-01 00:00:00']);
        $this->db->insert('page_aliases', ['site_id' => $site->id, 'href' => 'https://alice.example/old', 'page_id' => 999_999, 'created_at' => Database::now()]);
        $this->db->insert('blocklists', ['site_id' => 999_999, 'source' => 'https://spam.example/', 'created_at' => Database::now()]);

        $audit   = new DataAudit($this->db);
        $results = [];
        foreach ($audit->run() as $result) {
            $results[$result['key']] = $result;
        }

        $expected = [
            'orphan_links'                 => 1,
            'orphan_links_unplaceable'     => 2,
            'orphan_links_no_page'         => 1,
            'link_site_mismatch'           => 1,
            'link_account_mismatch'        => 1,
            'duplicate_pages'              => 1,
            'page_missing_site'            => 1,
            'page_missing_site_stuck'      => 1,
            'page_account_mismatch'        => 1,
            'site_missing_account'         => 1,
            'site_missing_account_holding' => 1,
            'domain_verified_on_several_accounts' => 1,
            'alias_missing_page'           => 1,
            'blocklist_missing_site'       => 1,
            'duplicate_mentions'           => 1,
            'verified_and_deleted'         => 1,
            'page_host_mismatch'           => 1,
        ];
        foreach ($expected as $key => $count) {
            self::assertSame($count, $results[$key]['count'], $key);
            self::assertSame($count, $audit->count($key), "$key via count()");
            self::assertNotSame([], $results[$key]['samples'], "$key has an example");
        }
        self::assertSame(array_keys($expected), array_keys($results), 'every check is reported, in order');

        // The report says which ones tools/repair can fix.
        self::assertTrue($results['orphan_links']['repairable']);
        self::assertFalse($results['verified_and_deleted']['repairable']);
        self::assertSame(DataAudit::REPAIRABLE, array_keys(array_filter(array_map(static fn (array $r): bool => $r['repairable'], $results))));

        self::assertStringContainsString('site_id 0', $results['orphan_links']['samples'][0]);
        self::assertStringContainsString('https://alice.example/post', $results['duplicate_pages']['samples'][0]);
    }

    public function testAnUnknownCheckIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DataAudit($this->db))->count('no_such_check');
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
