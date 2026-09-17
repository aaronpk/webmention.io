<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use Webmention\Model\Account;
use Webmention\Model\Site;
use Webmention\Storage\Database;
use Webmention\Storage\FragmentRecovery;
use Webmention\Storage\PageRepository;
use Webmention\Tests\Support\IntegrationTestCase;

/**
 * Recovering which fragment old webmentions were sent to, from a map taken
 * out of a pre-fold backup.
 */
final class RecoverFragmentsTest extends IntegrationTestCase
{
    private const GALLERY = 'https://alice.example/gallery';

    private Account $alice;
    private Site $site;
    private FragmentRecovery $recovery;
    private int $page;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice    = $this->createAccount('alice.example');
        $this->site     = $this->createSite($this->alice, 'alice.example');
        $this->recovery = new FragmentRecovery($this->db);

        // The state the fold left behind: one page, the fragment URLs as aliases.
        $pages      = $this->service(PageRepository::class);
        $this->page = $pages->create($this->alice->id, $this->site->id, self::GALLERY)->id;
        $pages->addAlias($this->site->id, self::GALLERY . '#photo-1', $this->page);
        $pages->addAlias($this->site->id, self::GALLERY . '#photo-2', $this->page);
    }

    public function testFillsInTheFragmentOnlyWhereTheMapStillFits(): void
    {
        $first  = $this->insertLink('https://bob.example/1');
        $second = $this->insertLink('https://bob.example/2');
        $third  = $this->insertLink('https://carol.example/1');
        $set    = $this->insertLink('https://dave.example/1', 'photo-2');
        $other  = $this->insertLink('https://erin.example/1');

        $map = [
            ['id' => $first,  'fragment' => 'photo-1', 'url' => self::GALLERY . '#photo-1'],
            ['id' => $second, 'fragment' => 'photo-1', 'url' => self::GALLERY . '#photo-1'],
            ['id' => $third,  'fragment' => 'photo-2', 'url' => self::GALLERY . '#photo-2'],
            ['id' => $set,    'fragment' => 'photo-1', 'url' => self::GALLERY . '#photo-1'],
            ['id' => $other,  'fragment' => 'gone',    'url' => self::GALLERY . '#gone'],
            ['id' => 999_999, 'fragment' => 'photo-1', 'url' => self::GALLERY . '#photo-1'],
        ];

        // A dry run counts and writes nothing.
        $dry = $this->recovery->apply($map, apply: false);
        self::assertSame(3, $dry['filled']);
        self::assertSame(1, $dry['already'], 'one already had its fragment');
        self::assertSame(1, $dry['gone'], 'one id is no longer in the database');
        self::assertSame(1, $dry['mismatch'], 'one fragment URL is not an alias of that page');
        self::assertStringContainsString('#gone is not an alias', $dry['notes'][0]);
        self::assertNull($this->db->value('SELECT target_fragment FROM links WHERE id = ?', [$first]));

        $done = $this->recovery->apply($map, apply: true);
        self::assertSame(3, $done['filled']);
        self::assertSame('photo-1', $this->db->value('SELECT target_fragment FROM links WHERE id = ?', [$first]));
        self::assertSame('photo-1', $this->db->value('SELECT target_fragment FROM links WHERE id = ?', [$second]));
        self::assertSame('photo-2', $this->db->value('SELECT target_fragment FROM links WHERE id = ?', [$third]));
        self::assertSame('photo-2', $this->db->value('SELECT target_fragment FROM links WHERE id = ?', [$set]), 'an existing value is never overwritten');
        self::assertNull($this->db->value('SELECT target_fragment FROM links WHERE id = ?', [$other]));

        // The API answers per fragment now.
        self::assertSame(2, self::json($this->request('GET', '/api/count', ['target' => self::GALLERY . '#photo-1']))['count']);
        self::assertSame(2, self::json($this->request('GET', '/api/count', ['target' => self::GALLERY . '#photo-2']))['count']);
        self::assertSame(5, self::json($this->request('GET', '/api/count', ['target' => self::GALLERY]))['count']);

        // Running it again fills nothing.
        $again = $this->recovery->apply($map, apply: true);
        self::assertSame(0, $again['filled']);
        self::assertSame(4, $again['already']);
    }

    public function testReadsTheMapOutOfThePrefoldTables(): void
    {
        $link = $this->insertLink('https://bob.example/1');
        $this->db->run('CREATE TEMPORARY TABLE prefold_pages (id int unsigned PRIMARY KEY, href varchar(512))');
        $this->db->run('CREATE TEMPORARY TABLE prefold_links (id int unsigned PRIMARY KEY, page_id int unsigned)');
        $this->db->run('INSERT INTO prefold_pages VALUES (10, ?), (11, ?)', [self::GALLERY . '#photo-1', self::GALLERY]);
        $this->db->run('INSERT INTO prefold_links VALUES (?, 10), (?, 11)', [$link, $link + 1]);

        $rows = $this->recovery->fromPrefoldTables();

        self::assertSame([['id' => $link, 'fragment' => 'photo-1', 'url' => self::GALLERY . '#photo-1']], $rows, 'only the ones sent to a fragment');
    }

    private function insertLink(string $source, ?string $fragment = null): int
    {
        return $this->db->insert('links', [
            'page_id'         => $this->page,
            'site_id'         => $this->site->id,
            'account_id'      => $this->alice->id,
            'href'            => $source,
            'domain'          => (string) parse_url($source, PHP_URL_HOST),
            'verified'        => 1,
            'deleted'         => 0,
            'protocol'        => 'webmention',
            'target_fragment' => $fragment,
            'created_at'      => Database::now(),
            'updated_at'      => Database::now(),
        ]);
    }
}
