<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use Webmention\Admin\Admins;
use Webmention\Bootstrap;
use Webmention\Model\Account;
use Webmention\Model\Site;
use Webmention\Storage\Database;
use Webmention\Tests\Support\IntegrationTestCase;
use Webmention\Webmention\Queue;
use Webmention\Webmention\StatusStore;
use Webmention\Webmention\WorkerHeartbeat;

/**
 * The admin section: who can reach it, what it shows about the service as a
 * whole, and what it shows about one account's problem.
 *
 * The harness sets ADMIN_USERS to admin.example, so an admin is an account on
 * that domain and everyone else is not.
 */
final class AdminTest extends IntegrationTestCase
{
    private Account $admin;
    private Account $alice;
    private Site $blog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAccount('admin.example');
        $this->alice = $this->createAccount('alice.example');
        $this->blog  = $this->createSite($this->alice, 'alice.example');
    }

    /** @return list<string> Every admin path in the route table, ids filled in. */
    private static function adminPaths(): array
    {
        $paths = [];
        foreach (Bootstrap::router()->routes() as $route) {
            if (str_starts_with($route['pattern'], '/admin')) {
                $paths[] = str_replace('{id}', '1', $route['pattern']);
            }
        }

        self::assertNotEmpty($paths);

        return $paths;
    }

    public function testEveryAdminPageSendsAVisitorHomeWhenSignedOut(): void
    {
        foreach (self::adminPaths() as $path) {
            $response = $this->request('GET', $path);
            self::assertSame(302, $response->status, $path);
            self::assertSame('/', $response->header('location'), $path);
        }
    }

    public function testEveryAdminPageIsAbsentForAnAccountThatIsNotAnAdmin(): void
    {
        $this->signIn($this->alice);

        foreach (self::adminPaths() as $path) {
            self::assertSame(404, $this->request('GET', $path)->status, $path);
        }
    }

    public function testTheNavShowsAdminOnlyToAnAdmin(): void
    {
        $this->signIn($this->alice);
        self::assertStringNotContainsString('href="/admin"', $this->request('GET', '/dashboard')->body);

        $this->signIn($this->admin);
        self::assertStringContainsString('href="/admin"', $this->request('GET', '/dashboard')->body, 'the admin sees the tab');
    }

    public function testAnAccountNamedByUsernameRatherThanDomainCanBeAnAdmin(): void
    {
        $admins = Admins::of('operator, Example.COM');

        self::assertTrue($admins->has($this->createAccount('somewhere.example', 'operator')), 'matched on username');
        self::assertTrue($admins->has($this->createAccount('example.com')), 'matched on domain, case-insensitively');
        self::assertFalse($admins->has($this->alice));
        self::assertFalse($admins->has(null));
        self::assertFalse(Admins::none()->has($this->alice));
        self::assertFalse(Admins::of('  ,  ')->any(), 'a list of nothing is nobody');
    }

    public function testTheOverviewShowsTheQueueAndWhatHasArrived(): void
    {
        $this->createLink($this->blog, 'https://alice.example/post', 'https://a.example/one');
        $this->createLink($this->blog, 'https://alice.example/post', 'https://b.example/two', ['verified' => 0, 'status' => 'pending']);
        $this->createLink($this->blog, 'https://alice.example/post', 'https://c.example/three', ['deleted' => 1]);
        $this->redis->lPush(Queue::KEY, '{}');

        $this->signIn($this->admin);
        $body = $this->request('GET', '/admin')->body;

        self::assertStringContainsString('In the queue', $body);
        $today = substr($body, (int) strpos($body, '<td>Today</td>'));
        $today = substr($today, 0, (int) strpos($today, '</tr>'));
        self::assertStringContainsString('<td class="num">3</td>', $today, 'three received today');
        self::assertStringContainsString('<td class="num">1</td>', $today, 'one published, one held, one deleted');

        $queue = substr($body, (int) strpos($body, 'In the queue'));
        self::assertStringContainsString('refused above 10,000', $queue);
    }

    public function testTheOverviewSeparatesRunningWorkersFromStoppedOnes(): void
    {
        $this->service(WorkerHeartbeat::class)->beat('1', 12);
        $this->redis->hSet(WorkerHeartbeat::KEY, '2', (string) json_encode(['at' => time() - 7200, 'pid' => 99, 'jobs' => 3]));

        $this->signIn($this->admin);
        $body = $this->request('GET', '/admin')->body;

        $running = substr($body, (int) strpos($body, '<td>1</td>'));
        $running = substr($running, 0, (int) strpos($running, '</tr>'));
        self::assertStringContainsString('>running<', $running);

        $stopped = substr($body, (int) strpos($body, '<td>2</td>'));
        $stopped = substr($stopped, 0, (int) strpos($stopped, '</tr>'));
        self::assertStringContainsString('>not running<', $stopped);
        self::assertStringContainsString('2 hours ago', $stopped);
    }

    public function testTheActivityPageCountsWebmentionsAndSignupsByMonth(): void
    {
        $lastMonth = gmdate('Y-m-d H:i:s', strtotime('-1 month'));
        $this->createLink($this->blog, 'https://alice.example/post', 'https://a.example/old', ['created_at' => $lastMonth]);
        $this->createLink($this->blog, 'https://alice.example/post', 'https://a.example/new');

        $this->signIn($this->admin);
        $body = $this->request('GET', '/admin/activity')->body;

        self::assertStringContainsString('Webmentions per month', $body);
        self::assertStringContainsString(gmdate('M Y') . ': 1 webmention', $body, 'this month');
        self::assertStringContainsString(gmdate('M Y', strtotime('-1 month')) . ': 1 webmention', $body, 'last month');
        self::assertStringContainsString('New accounts and sites', $body);
    }

    public function testTheSourceRadarRanksBusiestFirstAndCountsAccountsReached(): void
    {
        $bob     = $this->createAccount('bob.example');
        $bobBlog = $this->createSite($bob, 'bob.example');

        foreach (range(1, 4) as $n) {
            $this->createLink($this->blog, 'https://alice.example/post', "https://busy.example/$n");
        }
        $this->createLink($bobBlog, 'https://bob.example/post', 'https://busy.example/5');
        $this->createLink($this->blog, 'https://alice.example/post', 'https://quiet.example/1');

        $this->signIn($this->admin);
        $body = $this->request('GET', '/admin/sources')->body;

        self::assertLessThan(
            strpos($body, 'quiet.example'),
            strpos($body, 'busy.example'),
            'busiest first',
        );

        $busy = substr($body, (int) strpos($body, '>busy.example</a>'));
        $busy = substr($busy, 0, (int) strpos($busy, '</tr>'));
        self::assertStringContainsString('<td class="num">5</td>', $busy, 'five webmentions');
        self::assertStringContainsString('<td class="num">2</td>', $busy, 'two accounts reached');
    }

    public function testTheSourceRadarCanRankByWhatWasDeleted(): void
    {
        foreach (range(1, 5) as $n) {
            $this->createLink($this->blog, 'https://alice.example/post', "https://busy.example/$n");
        }
        foreach (range(1, 3) as $n) {
            $this->createLink($this->blog, 'https://alice.example/post', "https://spam.example/$n", ['deleted' => 1]);
        }

        $this->signIn($this->admin);

        $busiest = $this->request('GET', '/admin/sources')->body;
        self::assertLessThan(strpos($busiest, 'spam.example'), strpos($busiest, 'busy.example'));

        $deleted = $this->request('GET', '/admin/sources', ['sort' => 'deleted'])->body;
        self::assertLessThan(strpos($deleted, 'busy.example'), strpos($deleted, 'spam.example'), 'most deleted first');
    }

    public function testAccountSearchFindsByDomainUsernameEmailAndId(): void
    {
        $account = $this->createAccount('searchable.example', 'thefinder');
        $this->db->run('UPDATE accounts SET email = ? WHERE id = ?', ['someone@example.net', $account->id]);

        $this->signIn($this->admin);

        foreach (['searchable', 'thefinder', 'someone@example.net', (string) $account->id] as $query) {
            $body = $this->request('GET', '/admin/accounts', ['q' => $query])->body;
            self::assertStringContainsString('/admin/accounts/' . $account->id . '"', $body, "found by $query");
        }

        self::assertStringContainsString(
            'No account matches',
            $this->request('GET', '/admin/accounts', ['q' => 'nobody-at-all'])->body,
        );
    }

    public function testAccountSearchIsNotConfusedByWildcardsInTheQuery(): void
    {
        $this->signIn($this->admin);

        self::assertStringContainsString(
            'No account matches',
            $this->request('GET', '/admin/accounts', ['q' => '%'])->body,
            'a percent sign is a character, not "everything"',
        );
    }

    public function testTheAccountPageShowsAFailedVerificationAndTheSiteFacts(): void
    {
        $this->db->update('sites', $this->blog->id, [
            'verification_error'      => 'No webmention endpoint was advertised',
            'verification_checked_at' => Database::now(),
        ]);
        $this->createLink($this->blog, 'https://alice.example/post', 'https://a.example/one');

        $this->signIn($this->admin);
        $body = $this->request('GET', '/admin/accounts/' . $this->alice->id)->body;

        self::assertStringContainsString('No webmention endpoint was advertised', $body);
        self::assertStringContainsString('>unverified<', $body);
        self::assertStringContainsString('Recent webmentions', $body);
        self::assertStringContainsString('https://a.example/one', $body);
    }

    public function testTheAccountPageNamesTheOtherAccountHoldingTheSameDomain(): void
    {
        $other     = $this->createAccount('elsewhere.example', 'oldaccount');
        $otherSite = $this->createSite($other, 'alice.example', ['verified_at' => Database::now()]);
        $this->db->update('sites', $this->blog->id, ['verified_at' => Database::now()]);

        $this->signIn($this->admin);
        $body = $this->request('GET', '/admin/accounts/' . $this->alice->id)->body;

        self::assertStringContainsString('also verified on', $body);
        self::assertStringContainsString('oldaccount', $body, 'the other account is named');
        self::assertStringContainsString('/admin/accounts/' . $other->id, $body);
        self::assertStringContainsString('site ' . $otherSite->id, $body);
    }

    public function testTheAccountPageShowsWebHookDeliveries(): void
    {
        $site = $this->createSite($this->alice, 'hooked.example', ['callback_url' => 'https://hooks.example.net/in']);
        $this->db->insert('webhook_deliveries', [
            'site_id'       => $site->id,
            'link_id'       => null,
            'kind'          => 'mention',
            'url'           => 'https://hooks.example.net/in',
            'status_code'   => 500,
            'error'         => null,
            'duration_ms'   => 42,
            'request_body'  => '{}',
            'response_body' => 'boom',
            'created_at'    => Database::now(),
            'attempt'       => 2,
        ]);

        $this->signIn($this->admin);
        $body = $this->request('GET', '/admin/accounts/' . $this->alice->id)->body;

        self::assertStringContainsString('Web hook deliveries', $body);
        self::assertStringContainsString('>500<', $body);
        self::assertStringContainsString('42 ms', $body);
    }

    public function testAnAccountThatDoesNotExistIsNotFound(): void
    {
        $this->signIn($this->admin);

        self::assertSame(404, $this->request('GET', '/admin/accounts/999999')->status);
    }

    public function testLookupFindsAWebmentionByItsStatusReceipt(): void
    {
        $id = $this->createLink($this->blog, 'https://alice.example/post', 'https://a.example/one', ['token' => 'abcd1234abcd1234abcd']);
        $this->service(StatusStore::class)->error('abcd1234abcd1234abcd', 'https://a.example/one', 'https://alice.example/post', 'internal_error', 'It broke');

        $this->signIn($this->admin);
        $body = $this->request('GET', '/admin/lookup', ['q' => 'abcd1234abcd1234abcd'])->body;

        self::assertStringContainsString('internal_error', $body, 'the status document');
        self::assertStringContainsString('It broke', $body);
        self::assertStringContainsString('/admin/lookup?q=' . $id, $body, 'and the mention it was issued for');
    }

    public function testLookupSaysWhenAReceiptHasExpired(): void
    {
        $this->signIn($this->admin);

        self::assertStringContainsString(
            'No status for receipt',
            $this->request('GET', '/admin/lookup', ['q' => 'zzzz0000zzzz0000zzzz'])->body,
        );
    }

    public function testLookupFindsEverySendOfOneSourceUrlAcrossAccounts(): void
    {
        $bob     = $this->createAccount('bob.example');
        $bobBlog = $this->createSite($bob, 'bob.example');
        $this->createLink($this->blog, 'https://alice.example/post', 'https://writer.example/note');
        $this->createLink($bobBlog, 'https://bob.example/post', 'https://writer.example/note');

        $this->signIn($this->admin);
        $body = $this->request('GET', '/admin/lookup', ['q' => 'https://writer.example/note'])->body;

        self::assertStringContainsString('Sent from this URL', $body);
        self::assertStringContainsString('https://alice.example/post', $body);
        self::assertStringContainsString('https://bob.example/post', $body);
    }

    public function testLookupResolvesATargetUrlThroughItsAlias(): void
    {
        $this->createLink($this->blog, 'https://alice.example/post', 'https://a.example/one');
        $page = $this->service(\Webmention\Storage\PageRepository::class)->findBySiteAndHref($this->blog->id, 'https://alice.example/post');
        $this->service(\Webmention\Storage\PageRepository::class)->addAlias($this->blog->id, 'https://alice.example/old-url', $page->id);

        $this->signIn($this->admin);
        $body = $this->request('GET', '/admin/lookup', ['q' => 'https://alice.example/old-url'])->body;

        self::assertStringContainsString('Received at this URL', $body);
        self::assertStringContainsString('is an alias', $body);
        self::assertStringContainsString('https://a.example/one', $body);
        self::assertStringContainsString('/admin/accounts/' . $this->alice->id, $body);
    }

    public function testLookupOnADomainShowsEveryAccountHoldingItAndWhatItSends(): void
    {
        $other = $this->createAccount('elsewhere.example');
        $this->createSite($other, 'alice.example', ['verified_at' => Database::now()]);
        $this->createLink($this->blog, 'https://alice.example/post', 'https://alice.example/self-link');

        $this->signIn($this->admin);
        $body = $this->request('GET', '/admin/lookup', ['q' => 'alice.example'])->body;

        self::assertStringContainsString('2 sites for alice.example', $body);
        self::assertStringContainsString('More than one account holds this domain', $body);
        self::assertStringContainsString('has sent in the last 30 days', $body);
    }

    public function testLookupOnANumberTriesEveryKindOfId(): void
    {
        $id = $this->createLink($this->blog, 'https://alice.example/post', 'https://a.example/one');

        $this->signIn($this->admin);
        $body = $this->request('GET', '/admin/lookup', ['q' => (string) $id])->body;

        self::assertStringContainsString("Webmention $id", $body);
    }

    public function testLookupSaysSoWhenNothingMatches(): void
    {
        $this->signIn($this->admin);

        self::assertStringContainsString(
            'Nothing in the database matches',
            $this->request('GET', '/admin/lookup', ['q' => 'https://nowhere.example/nothing'])->body,
        );
    }

    public function testAnOrdinaryWebmentionShowsItsAuthorAndContent(): void
    {
        $this->createLink($this->blog, 'https://alice.example/post', 'https://writer.example/note', [
            'author_name'  => 'A Public Writer',
            'content_text' => 'Something anyone can already read',
        ]);

        $this->signIn($this->admin);
        $body = $this->request('GET', '/admin/accounts/' . $this->alice->id)->body;

        self::assertStringContainsString('A Public Writer', $body);
        self::assertStringContainsString('Something anyone can already read', $body);
    }

    public function testAPrivateWebmentionKeepsItsContentOutOfTheAdminPages(): void
    {
        $this->createLink($this->blog, 'https://alice.example/post', 'https://friend.example/secret', [
            'is_private'   => 1,
            'author_name'  => 'A Close Friend',
            'content_text' => 'Meet me at the usual place',
            'name'         => 'A private note',
        ]);

        $this->signIn($this->admin);

        foreach (['/admin/accounts/' . $this->alice->id, '/admin/lookup?q=https://friend.example/secret'] as $path) {
            [$path, $query] = array_pad(explode('?', $path, 2), 2, '');
            parse_str($query, $params);
            $body = $this->request('GET', $path, $params)->body;

            self::assertStringContainsString('https://friend.example/secret', $body, "$path shows the source");
            self::assertStringContainsString('>private<', $body, "$path marks it private");
            self::assertStringNotContainsString('A Close Friend', $body, "$path hides the author");
            self::assertStringNotContainsString('Meet me at the usual place', $body, "$path hides the content");
            self::assertStringNotContainsString('A private note', $body, "$path hides the title");
        }
    }
}
