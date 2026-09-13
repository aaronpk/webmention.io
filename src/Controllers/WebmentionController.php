<?php

declare(strict_types=1);

namespace Webmention\Controllers;

use Redis;
use stdClass;
use Throwable;
use Webmention\Config;
use Webmention\Format\Url;
use Webmention\Http\JsonResponder;
use Webmention\Http\Request;
use Webmention\Http\Response;
use Webmention\Logging\Log;
use Webmention\Model\Account;
use Webmention\Storage\AccountRepository;
use Webmention\Storage\SiteRepository;
use Webmention\View\Template;
use Webmention\Webmention\Job;
use Webmention\Webmention\Processor;
use Webmention\Webmention\Queue;
use Webmention\Webmention\StatusStore;

/**
 * The webmention endpoints. Port of controllers/webmention.rb, minus pingback.
 */
final class WebmentionController extends Controller
{
    /** Error descriptions returned when processing synchronously with `debug`. */
    private const DEBUG_ERRORS = [
        'source_not_found'     => 'The source URI does not exist',
        'invalid_target'       => 'The target is not a valid URI',
        'target_not_found'     => 'The target URI does not exist',
        'target_not_supported' => 'The specified target URI is not a Webmention-enabled resource',
        'no_link_found'        => 'The source URI does not contain a link to the target URI',
    ];

    public function __construct(
        Template $view,
        private readonly JsonResponder $json,
        private readonly AccountRepository $accounts,
        private readonly SiteRepository $sites,
        private readonly StatusStore $statuses,
        private readonly Queue $queue,
        private readonly Processor $processor,
        private readonly Redis $redis,
        private readonly Log $log,
        private readonly Config $config,
    ) {
        parent::__construct($view);
    }

    /**
     * People click the endpoint link in page source, so it explains itself and
     * offers a form.
     *
     * @param array<string, string> $params
     */
    public function form(Request $request, array $params): Response
    {
        return $this->page('endpoint', 'Hosted Webmention Service', ['username' => $params['username']]);
    }

    /** @param array<string, string> $params */
    public function status(Request $request, array $params): Response
    {
        $status = $this->statuses->get($params['token']);

        if ($status === null) {
            return $this->json->respond($request, 404, ['error' => 'not_found']);
        }

        return $this->json->respond($request, 200, $status);
    }

    /** @param array<string, string> $params */
    public function receive(Request $request, array $params): Response
    {
        if (($invalid = $this->validate($request)) !== null) {
            return $invalid;
        }

        $username = $params['username'];
        $account  = $this->accounts->findByName($username);

        if ($account === null) {
            return $this->json->respond($request, 404, [
                'error'             => 'not_found',
                'error_description' => "account $username not found",
            ]);
        }

        $targetDomain = Url::host((string) $request->input('target'));

        if ($targetDomain === null || $this->sites->findByAccountAndDomain($account->id, $targetDomain) === null) {
            return $this->json->respond($request, 404, [
                'error'             => 'invalid_target',
                'error_description' => 'target domain not found on this account',
            ]);
        }

        return $this->accept($request, $account, $username, 'account');
    }

    /** @param array<string, string> $params */
    public function receiveForSite(Request $request, array $params): Response
    {
        if (($invalid = $this->validate($request)) !== null) {
            return $invalid;
        }

        $domain  = $params['domain'];
        $site    = $this->sites->findByDomain($domain);
        $account = $site === null ? null : $this->accounts->find($site->accountId);

        if ($site === null || $account === null) {
            return $this->json->respond($request, 404, [
                'error'             => 'not_found',
                'error_description' => "site $domain not found",
            ]);
        }

        $targetDomain = Url::host((string) $request->input('target'));

        if ($targetDomain !== strtolower((string) $site->domain)) {
            return $this->json->respond($request, 400, [
                'error'             => 'invalid_target',
                'error_description' => "Target domain ($targetDomain) does not match the domain of this webmention endpoint ($domain)",
            ]);
        }

        return $this->accept($request, $account, (string) $account->username, 'site');
    }

    private function validate(Request $request): ?Response
    {
        $source = $request->input('source');
        $target = $request->input('target');

        if ($source === null || $source === '' || $target === null || $target === '') {
            return $this->json->respond($request, 400, [
                'error'             => 'invalid_request',
                'error_description' => 'source or target were missing',
            ]);
        }

        foreach ([$source, $target] as $url) {
            $details = null;
            if (Url::host($url) === null) {
                $details = 'missing host';
            } elseif (!Url::isHttp($url)) {
                $details = 'invalid protocol';
            }

            if ($details !== null) {
                return $this->json->respond($request, 400, [
                    'error'             => 'invalid_request',
                    'error_description' => 'source or target were invalid',
                    'error_details'     => $details,
                ]);
            }
        }

        return null;
    }

    private function accept(Request $request, Account $account, string $username, string $endpointType): Response
    {
        $source = (string) $request->input('source');
        $target = (string) $request->input('target');

        $rateKey = 'webmention:ratelimit:' . md5("s=$source;t=$target");
        if (!$this->redis->set($rateKey, '1', ['nx', 'ex' => 30])) {
            return $this->json->respond($request, 429, [
                'error'             => 'rate_limit_exceeded',
                'error_description' => 'Only one request per source and target combination is allowed every 30 seconds',
            ]);
        }

        $token     = rtrim(strtr(base64_encode(random_bytes(15)), '+/', '-_'), '=');
        $statusUrl = $this->config->baseUrl() . '/' . rawurlencode($username) . '/webmention/' . $token;

        // Twitter URLs can't be fetched; Bridgy serves the same post with microformats.
        if (preg_match('#https?://(?:www\.)?twitter\.com/(.+)/status(?:es)?/([0-9]+)#', $source, $m) === 1) {
            $source = "https://brid.gy/post/twitter/{$m[1]}/{$m[2]}";
        }

        $code = $request->input('code');
        $code = $code === '' ? null : $code;

        $this->log->info(sprintf(
            'WM: source=%s target=%s%s ip=%s status=%s',
            $source,
            $target,
            $code !== null ? ' private' : '',
            $request->ip,
            $statusUrl,
        ));

        $this->statuses->set($token, [
            'status'  => 'pending',
            'source'  => $source,
            'target'  => $target,
            'private' => $code !== null,
            'summary' => 'The webmention is currently being processed',
            'data'    => new stdClass(),
        ]);

        $job = new Job(
            accountId:    $account->id,
            source:       $source,
            target:       $target,
            token:        $token,
            code:         $code,
            endpointType: $endpointType,
        );

        if ($request->has('debug')) {
            return $this->processNow($request, $job);
        }

        $this->queue->push($job);

        // Browsers are sent on to the status page.
        return $this->json->respond($request, $request->acceptsHtml() ? 303 : 201, [
            'status'   => 'queued',
            'summary'  => 'Webmention was queued for processing',
            'location' => $statusUrl,
            'source'   => $source,
            'target'   => $target,
        ], ['location' => $statusUrl]);
    }

    private function processNow(Request $request, Job $job): Response
    {
        try {
            $result = $this->processor->process($job);
        } catch (Throwable $e) {
            $this->log->exception($e, 'Processing ' . $job->source);

            return $this->json->respond($request, 500, [
                'error'             => 'internal_server_error',
                'error_description' => $e->getMessage(),
            ]);
        }

        if ($result === 'success') {
            return $this->json->respond($request, 200, [
                'status'  => 'success',
                'summary' => 'Webmention was successful',
            ]);
        }

        $summary = $this->statuses->get($job->token)->summary ?? null;

        return $this->json->respond($request, 400, [
            'error'             => $result,
            'error_description' => self::DEBUG_ERRORS[$result] ?? (is_string($summary) ? $summary : $result),
        ]);
    }
}
