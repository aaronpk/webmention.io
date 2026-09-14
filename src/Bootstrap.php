<?php

declare(strict_types=1);

namespace Webmention;

use Redis;
use Webmention\Controllers\ApiController;
use Webmention\Controllers\AuthController;
use Webmention\Controllers\DashboardController;
use Webmention\Controllers\HomeController;
use Webmention\Controllers\SettingsController;
use Webmention\Controllers\WebmentionController;
use Webmention\Http\JsonResponder;
use Webmention\Http\Session;
use Webmention\Logging\Log;
use Webmention\Storage\AccountRepository;
use Webmention\Storage\BlockRepository;
use Webmention\Storage\Database;
use Webmention\Storage\LinkRepository;
use Webmention\Storage\MuteRepository;
use Webmention\Storage\PageRepository;
use Webmention\Storage\SiteRepository;
use Webmention\View\Template;
use Webmention\Webmention\AvatarArchiver;
use Webmention\Webmention\HttpClient;
use Webmention\Webmention\Moderation;
use Webmention\Webmention\Processor;
use Webmention\Webmention\Queue;
use Webmention\Webmention\RateLimiter;
use Webmention\Webmention\SiteVerifier;
use Webmention\Webmention\SourceFetcher;
use Webmention\Webmention\StatusStore;
use Webmention\Webmention\TargetResolver;
use Webmention\Webmention\WebHooks;

/**
 * The wiring. Every service and every route is declared here, explicitly.
 */
final class Bootstrap
{
    public static function root(): string
    {
        return dirname(__DIR__);
    }

    public static function config(): Config
    {
        return Config::load(self::root() . '/.env');
    }

    /**
     * The log file for one process. LOG_DIR holds one file per process, so the
     * web app and each worker can be followed separately: web.log, worker.log,
     * worker-1.log, ...
     */
    public static function logFile(Config $config, string $process): string
    {
        $dir = rtrim($config->get('LOG_DIR') ?? self::root() . '/logs', '/');

        return $dir . '/' . $process . '.log';
    }

    public static function container(Config $config): Container
    {
        $c = new Container();

        $c->set(Config::class, static fn (): Config => $config);

        $c->set(Log::class, static fn (): Log => new Log(self::logFile($config, 'web')));

        $c->set(Database::class, static fn (): Database => Database::connect($config));

        $c->set(Redis::class, static function () use ($config): Redis {
            $redis = new Redis();
            $redis->connect($config->get('REDIS_HOST', '127.0.0.1') ?? '127.0.0.1', (int) $config->get('REDIS_PORT', '6379'), 2.0);
            if (($db = (int) $config->get('REDIS_DB', '0')) !== 0) {
                $redis->select($db);
            }

            return $redis;
        });

        $c->set(AccountRepository::class, static fn (Container $c): AccountRepository => new AccountRepository($c->get(Database::class)));
        $c->set(SiteRepository::class, static fn (Container $c): SiteRepository => new SiteRepository($c->get(Database::class)));
        $c->set(PageRepository::class, static fn (Container $c): PageRepository => new PageRepository($c->get(Database::class)));
        $c->set(LinkRepository::class, static fn (Container $c): LinkRepository => new LinkRepository($c->get(Database::class)));
        $c->set(BlockRepository::class, static fn (Container $c): BlockRepository => new BlockRepository($c->get(Database::class)));
        $c->set(MuteRepository::class, static fn (Container $c): MuteRepository => new MuteRepository($c->get(Database::class)));
        $c->set(Moderation::class, static fn (Container $c): Moderation => new Moderation($c->get(MuteRepository::class), $c->get(LinkRepository::class)));

        $c->set(Template::class, static fn (): Template => new Template(self::root() . '/templates'));
        $c->set(JsonResponder::class, static fn (Container $c): JsonResponder => new JsonResponder($c->get(Template::class)));
        $c->set(Session::class, static fn (): Session => Session::native($config));

        $c->set(StatusStore::class, static fn (Container $c): StatusStore => new StatusStore($c->get(Redis::class)));
        $c->set(Queue::class, static fn (Container $c): Queue => new Queue($c->get(Redis::class)));
        $c->set(RateLimiter::class, static fn (Container $c): RateLimiter => new RateLimiter($c->get(Redis::class), $c->get(Log::class)));
        $c->set(HttpClient::class, static fn (): HttpClient => new HttpClient(
            $config->baseUrl(),
            allowPrivateNetwork: $config->get('ALLOW_PRIVATE_NETWORK') === '1',
        ));
        $c->set(SourceFetcher::class, static fn (Container $c): SourceFetcher => new SourceFetcher($c->get(HttpClient::class)));
        $c->set(TargetResolver::class, static fn (Container $c): TargetResolver => new TargetResolver(
            $c->get(PageRepository::class),
            $c->get(SiteRepository::class),
            $c->get(SourceFetcher::class),
            $c->get(Log::class),
        ));
        $c->set(SiteVerifier::class, static fn (Container $c): SiteVerifier => new SiteVerifier($c->get(HttpClient::class), $config));
        $c->set(AvatarArchiver::class, static fn (Container $c): AvatarArchiver => new AvatarArchiver(
            $config,
            $c->get(HttpClient::class),
            $c->get(Log::class),
        ));
        $c->set(WebHooks::class, static fn (Container $c): WebHooks => new WebHooks($c->get(HttpClient::class), $c->get(Log::class)));

        $c->set(Processor::class, static fn (Container $c): Processor => new Processor(
            $c->get(AccountRepository::class),
            $c->get(SiteRepository::class),
            $c->get(TargetResolver::class),
            $c->get(Moderation::class),
            $c->get(LinkRepository::class),
            $c->get(BlockRepository::class),
            $c->get(SourceFetcher::class),
            $c->get(StatusStore::class),
            $c->get(WebHooks::class),
            $c->get(AvatarArchiver::class),
            $c->get(HttpClient::class),
            $c->get(Log::class),
        ));

        $c->set(HomeController::class, static fn (Container $c): HomeController => new HomeController(
            $c->get(Template::class),
            $c->get(Session::class),
            $c->get(AccountRepository::class),
            $c->get(JsonResponder::class),
            $config,
        ));

        $c->set(ApiController::class, static fn (Container $c): ApiController => new ApiController(
            $c->get(Template::class),
            $c->get(JsonResponder::class),
            $c->get(Database::class),
            $c->get(AccountRepository::class),
            $c->get(SiteRepository::class),
            $c->get(PageRepository::class),
            $c->get(LinkRepository::class),
            $config,
            $c->get(RateLimiter::class),
        ));

        $c->set(WebmentionController::class, static fn (Container $c): WebmentionController => new WebmentionController(
            $c->get(Template::class),
            $c->get(JsonResponder::class),
            $c->get(AccountRepository::class),
            $c->get(SiteRepository::class),
            $c->get(StatusStore::class),
            $c->get(Queue::class),
            $c->get(Processor::class),
            $c->get(Redis::class),
            $c->get(RateLimiter::class),
            $c->get(Log::class),
            $config,
        ));

        $c->set(AuthController::class, static fn (Container $c): AuthController => new AuthController(
            $c->get(Template::class),
            $c->get(Session::class),
            $c->get(AccountRepository::class),
            $c->get(HttpClient::class),
            $c->get(RateLimiter::class),
            $config,
        ));

        $c->set(DashboardController::class, static fn (Container $c): DashboardController => new DashboardController(
            $c->get(Template::class),
            $c->get(Session::class),
            $c->get(AccountRepository::class),
            $c->get(SiteRepository::class),
            $c->get(LinkRepository::class),
            $c->get(BlockRepository::class),
            $c->get(WebHooks::class),
        ));

        $c->set(SettingsController::class, static fn (Container $c): SettingsController => new SettingsController(
            $c->get(Template::class),
            $c->get(Session::class),
            $c->get(AccountRepository::class),
            $c->get(SiteRepository::class),
            $c->get(BlockRepository::class),
            $c->get(SiteVerifier::class),
            $c->get(HttpClient::class),
            $c->get(TargetResolver::class),
            $c->get(PageRepository::class),
            $c->get(RateLimiter::class),
            $c->get(LinkRepository::class),
            $c->get(MuteRepository::class),
            $config,
        ));

        return $c;
    }

    public static function router(): Router
    {
        $r = new Router();

        $r->get('/', [HomeController::class, 'index']);
        $r->get('/id', [HomeController::class, 'clientMetadata']);
        $r->get('/api', [HomeController::class, 'api']);

        // Starting a sign-in and signing out change the session, so both are POSTs.
        $r->get('/auth/start', [AuthController::class, 'startForm']);
        $r->post('/auth/start', [AuthController::class, 'start']);
        $r->get('/auth/callback', [AuthController::class, 'callback']);
        $r->get('/logout', [HomeController::class, 'index']);
        $r->post('/logout', [AuthController::class, 'logout']);

        // Literal /api routes first, so {kind} never swallows count.
        $r->get('/api/count', [ApiController::class, 'count']);
        $r->get('/api/count.json', [ApiController::class, 'count']);
        $r->get('/api/example/mentions.jf2', [ApiController::class, 'exampleMentions']);
        $r->get('/api/example/count', [ApiController::class, 'exampleCount']);
        $r->get('/api/export', [ApiController::class, 'export']);
        $r->get('/api/export.jf2', [ApiController::class, 'export']);
        $r->get('/api/deleted', [ApiController::class, 'deleted']);
        $r->get('/api/deleted.jf2', [ApiController::class, 'deleted']);
        $r->get('/api/{kind}', [ApiController::class, 'mentions']);

        $r->get('/dashboard', [DashboardController::class, 'index']);
        $r->get('/delete', [DashboardController::class, 'confirmDelete']);
        $r->post('/delete', [DashboardController::class, 'delete']);
        $r->post('/unblock', [DashboardController::class, 'unblock']);
        $r->post('/unblock-source', [DashboardController::class, 'unblockSource']);
        $r->get('/moderation', [DashboardController::class, 'moderation']);
        $r->post('/approve', [DashboardController::class, 'approve']);
        $r->post('/reject', [DashboardController::class, 'reject']);
        $r->post('/mute', [SettingsController::class, 'mute']);
        $r->post('/unmute-rule', [SettingsController::class, 'unmute']);

        $r->get('/settings', [SettingsController::class, 'index']);
        $r->post('/settings/change_token', [SettingsController::class, 'changeToken']);
        $r->get('/settings/sites', [SettingsController::class, 'sites']);
        $r->post('/settings/sites/new', [SettingsController::class, 'createSite']);
        $r->post('/settings/sites/merge', [SettingsController::class, 'mergePage']);
        $r->post('/settings/sites/verify', [SettingsController::class, 'verifySite']);
        $r->get('/settings/sites/{id}', [SettingsController::class, 'site']);
        $r->get('/settings/webhooks', [SettingsController::class, 'webhooks']);
        $r->post('/webhook/configure', [SettingsController::class, 'configureWebhook']);
        $r->get('/settings/blocks', [SettingsController::class, 'blocks']);

        $r->post('/d/{domain}/webmention', [WebmentionController::class, 'receiveForSite']);
        $r->get('/{username}/webmention', [WebmentionController::class, 'form']);
        $r->post('/{username}/webmention', [WebmentionController::class, 'receive']);
        $r->get('/{username}/webmention/{token}', [WebmentionController::class, 'status']);

        return $r;
    }

    public static function kernel(Container $container): Kernel
    {
        return new Kernel(
            $container,
            self::router(),
            $container->get(Log::class),
            debug: $container->get(Config::class)->debug(),
        );
    }
}
