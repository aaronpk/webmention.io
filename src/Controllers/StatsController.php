<?php

declare(strict_types=1);

namespace Webmention\Controllers;

use Redis;
use Webmention\Http\HttpException;
use Webmention\Http\Request;
use Webmention\Http\Response;
use Webmention\View\Template;
use Webmention\Webmention\Queue;
use Webmention\Webmention\SiteDeleter;
use Webmention\Webmention\Stats;
use Webmention\Webmention\WebhookRetries;
use Webmention\Webmention\WorkerHeartbeat;

/**
 * Munin multigraph output, fetched by docs/munin/webmention.
 *
 * Needs `Authorization: Bearer STATS_API_KEY`. With no key configured the
 * routes do not exist.
 */
final class StatsController extends Controller
{
    public function __construct(
        Template $view,
        private readonly ?string $apiKey,
        private readonly Stats $stats,
        private readonly Queue $queue,
        private readonly WebhookRetries $retries,
        private readonly WorkerHeartbeat $workers,
        private readonly Redis $redis,
    ) {
        parent::__construct($view);
    }

    /** @param array<string, string> $params */
    public function config(Request $request, array $params): Response
    {
        if (($denied = $this->authorize($request)) !== null) {
            return $denied;
        }

        $out = self::graph('webmention_received', 'Webmentions received', 'What the endpoint did with each webmention request', 'per ${graph_period}', 'DERIVE', Stats::RECEIVED)
            . self::graph('webmention_processed', 'Webmentions processed', 'The result of verifying each webmention', 'per ${graph_period}', 'DERIVE', Stats::PROCESSED)
            . self::graph('webmention_backlog', 'Webmention backlog', 'Work waiting for the workers', 'items', 'GAUGE', ['queue', 'webhook_retries', 'site_purges'])
            . self::graph('webmention_workers', 'Webmention workers', 'Workers heard from in the last ' . WorkerHeartbeat::STALE_AFTER . ' seconds, and those that have stopped', 'workers', 'GAUGE', ['alive', 'stopped']);

        return Response::text($out);
    }

    /** @param array<string, string> $params */
    public function data(Request $request, array $params): Response
    {
        if (($denied = $this->authorize($request)) !== null) {
            return $denied;
        }

        $workers = $this->workers->all();
        $alive   = count(array_filter($workers, static fn (array $w): bool => $w['alive']));

        $out = self::values('webmention_received', $this->stats->receivedCounts())
            . self::values('webmention_processed', $this->stats->processedCounts())
            . self::values('webmention_backlog', [
                'queue'           => $this->queue->length(),
                'webhook_retries' => $this->retries->count(),
                'site_purges'     => (int) $this->redis->lLen(SiteDeleter::QUEUE),
            ])
            . self::values('webmention_workers', ['alive' => $alive, 'stopped' => count($workers) - $alive]);

        return Response::text($out);
    }

    private function authorize(Request $request): ?Response
    {
        if ($this->apiKey === null) {
            throw HttpException::notFound();
        }

        $sent = (string) $request->header('authorization');
        if (!str_starts_with($sent, 'Bearer ') || !hash_equals($this->apiKey, substr($sent, 7))) {
            return Response::text("Unauthorized\n", 401)->withHeader('www-authenticate', 'Bearer');
        }

        return null;
    }

    /** @param list<string> $fields */
    private static function graph(string $name, string $title, string $info, string $vlabel, string $type, array $fields): string
    {
        $out = "multigraph $name\n"
            . "graph_title $title\n"
            . "graph_info $info\n"
            . "graph_vlabel $vlabel\n"
            . "graph_category webmention\n"
            . "graph_args --lower-limit 0\n";

        if ($type === 'DERIVE') {
            $out .= "graph_period minute\n";
        }

        foreach ($fields as $field) {
            $out .= "$field.label " . str_replace('_', ' ', $field) . "\n"
                . "$field.type $type\n"
                . "$field.min 0\n";
        }

        return $out;
    }

    /** @param array<string, int> $values */
    private static function values(string $name, array $values): string
    {
        $out = "multigraph $name\n";
        foreach ($values as $field => $value) {
            $out .= "$field.value $value\n";
        }

        return $out;
    }
}
