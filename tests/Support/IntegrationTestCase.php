<?php

declare(strict_types=1);

namespace Webmention\Tests\Support;

use PHPUnit\Framework\TestCase;
use Redis;
use Webmention\Bootstrap;
use Webmention\Config;
use Webmention\Container;
use Webmention\Http\Request;
use Webmention\Http\Response;
use Webmention\Http\Session;
use Webmention\Logging\Log;
use Webmention\Model\Account;
use Webmention\Model\Site;
use Webmention\Storage\AccountRepository;
use Webmention\Storage\Database;
use Webmention\Storage\LinkRepository;
use Webmention\Storage\PageRepository;
use Webmention\Storage\SiteRepository;
use Webmention\Webmention\HttpClient;

/**
 * Runs against the database and Redis named in .env.testing. Both are wiped
 * before every test, so the database name must end in "_test" and Redis must
 * be a non-default DB.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected Container $container;
    protected FakeTransport $http;
    protected Database $db;
    protected Redis $redis;

    /**
     * The IndieAuth client caches fetched pages and metadata in statics for
     * the life of the process, which is one request in production and the
     * whole run here. Called before every test; a test that starts sign-in
     * more than once calls it between attempts, as a new request would.
     */
    protected static function resetIndieAuthClient(): void
    {
        $client = new \ReflectionClass(\IndieAuth\Client::class);
        foreach ($client->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
            if (str_starts_with($property->getName(), '_')) {
                $property->setValue(null, $property->getDefaultValue());
            }
        }
    }

    protected function setUp(): void
    {
        self::resetIndieAuthClient();

        $root    = Bootstrap::root();
        $envFile = $root . '/.env.testing';

        if (!is_file($envFile)) {
            self::markTestSkipped('No .env.testing; see the README for setting up the test database.');
        }

        $config = Config::load($envFile)->with(['CA3DB_API_ENDPOINT' => '']);

        if (!str_ends_with((string) $config->get('DB_NAME'), '_test') || (int) $config->get('REDIS_DB', '0') === 0) {
            self::fail('Refusing to wipe a database not named *_test or Redis DB 0.');
        }

        $this->http      = new FakeTransport($root . '/tests/fixtures');
        $this->container = Bootstrap::container($config);
        $this->container->set(Log::class, static fn (): Log => new Log(sys_get_temp_dir() . '/webmention-test.log'));
        $this->container->set(HttpClient::class, fn (): HttpClient => new HttpClient($config->baseUrl(), $this->http));
        $this->container->set(Session::class, static fn (): Session => Session::memory());

        $this->db    = $this->container->get(Database::class);
        $this->redis = $this->container->get(Redis::class);

        foreach (['accounts', 'sites', 'pages', 'page_aliases', 'links', 'blocks', 'blocklists', 'mutes', 'webhook_deliveries'] as $table) {
            $this->db->pdo()->exec("TRUNCATE TABLE `$table`");
        }
        $this->redis->flushDb();
    }

    protected function service(string $class): object
    {
        return $this->container->get($class);
    }

    protected function createAccount(string $domain, ?string $username = null): Account
    {
        $accounts = $this->service(AccountRepository::class);
        $account  = $accounts->create($domain);

        if ($username !== null) {
            $this->db->run('UPDATE accounts SET username = ? WHERE id = ?', [$username, $account->id]);
        }

        return $accounts->find($account->id);
    }

    /** @param array<string, mixed> $columns */
    protected function createSite(Account $account, string $domain, array $columns = []): Site
    {
        $site = $this->service(SiteRepository::class)->create($account->id, $domain);
        if ($columns !== []) {
            $this->db->update('sites', $site->id, $columns);
        }

        return $this->service(SiteRepository::class)->find($site->id);
    }

    /**
     * Insert a verified link straight into the database.
     *
     * @param array<string, mixed> $columns
     */
    protected function createLink(Site $site, string $target, string $source, array $columns = []): int
    {
        $pages = $this->service(PageRepository::class);
        $page  = $pages->findBySiteAndHref($site->id, $target) ?? $pages->create($site->accountId, $site->id, $target);

        // The repository stamps created_at/updated_at itself; fixtures that need
        // specific times get them afterwards.
        $timestamps = array_intersect_key($columns, ['created_at' => true, 'updated_at' => true]);

        $id = $this->service(LinkRepository::class)->create([
            'page_id'    => $page->id,
            'site_id'    => $site->id,
            'account_id' => $site->accountId,
            'href'       => $source,
            'domain'     => parse_url($source, PHP_URL_HOST),
            'verified'   => 1,
            'protocol'   => 'webmention',
            'type'       => 'link',
            ...array_diff_key($columns, $timestamps),
        ]);

        $this->db->update('links', $id, $timestamps);

        return $id;
    }

    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $post
     * @param array<string, string> $headers
     */
    protected function request(string $method, string $path, array $query = [], array $post = [], array $headers = []): Response
    {
        return Bootstrap::kernel($this->container)->handle(new Request(
            method:  $method,
            path:    $path,
            query:   $query,
            post:    $post,
            headers: $headers,
            ip:      '192.0.2.1',
        ));
    }

    protected function signIn(Account $account): string
    {
        $session = $this->service(Session::class);
        $session->logIn($account->id);

        return $session->csrfToken();
    }

    /** @return array<string, mixed> */
    protected static function json(Response $response): array
    {
        $data = json_decode($response->body, true);
        self::assertIsArray($data, 'Response was not JSON: ' . substr($response->body, 0, 300));

        return $data;
    }
}
