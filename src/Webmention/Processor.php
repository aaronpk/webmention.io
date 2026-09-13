<?php

declare(strict_types=1);

namespace Webmention\Webmention;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Throwable;
use Webmention\Format\Jf2Format;
use Webmention\Format\Url;
use Webmention\Logging\Log;
use Webmention\Model\Link;
use Webmention\Model\Page;
use Webmention\Model\Site;
use Webmention\Storage\AccountRepository;
use Webmention\Storage\BlockRepository;
use Webmention\Storage\Database;
use Webmention\Storage\LinkRepository;
use Webmention\Storage\PageRepository;
use Webmention\Storage\SiteRepository;

/**
 * Verifies a webmention and stores it. Port of WebmentionProcessor.
 */
final class Processor
{
    /**
     * Errors that mean the source no longer links to the target. Only these
     * remove a previously received mention; a timeout or a DNS failure while
     * re-checking leaves it alone.
     */
    public const REMOVAL_ERRORS = ['no_link_found', 'not_found'];

    private const RSVP_VALUES = ['yes', 'no', 'maybe', 'interested'];

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly SiteRepository $sites,
        private readonly PageRepository $pages,
        private readonly LinkRepository $links,
        private readonly BlockRepository $blocks,
        private readonly SourceFetcher $fetcher,
        private readonly StatusStore $statuses,
        private readonly WebHooks $webHooks,
        private readonly AvatarArchiver $avatars,
        private readonly HttpClient $http,
        private readonly Log $log,
    ) {
    }

    /** @return string "success", "deleted", or the error code written to the status. */
    public function process(Job $job): string
    {
        $source = $job->source;
        $target = $job->target;

        $fail = function (string $error, ?string $description = null) use ($job, $source, $target): string {
            $this->statuses->error($job->token, $source, $target, $error, $description);

            return $error;
        };

        $account = $this->accounts->find($job->accountId);
        if ($account === null) {
            return $fail('target_not_found');
        }

        if ($source === $target) {
            return $fail('invalid_target');
        }

        $targetDomain = Url::host($target);
        if ($targetDomain === null) {
            return $fail('invalid_target', 'target domain was empty');
        }

        $sourceDomain = Url::host($source);
        if ($sourceDomain === null) {
            return $fail('invalid_source', 'source could not be parsed as a URL');
        }

        if ($this->blocks->isDomainBlocked($account->id, $sourceDomain)) {
            return $fail('blocked', 'source domain is blocked');
        }

        $site = $this->sites->findByAccountAndDomain($account->id, $targetDomain);
        if ($site === null) {
            return $fail('invalid_target', 'target domain not found on this account');
        }

        if ($this->blocks->isSourceBlocked($site->id, $source)) {
            return $fail('blocked', 'source URL is blocked');
        }

        $accessToken = null;
        if ($job->code !== null) {
            $token = $this->fetcher->accessToken($source, $job->code);
            if ($token === null) {
                return $fail('access_token_error', 'Error obtaining an access token, no access token returned.');
            }
            if (isset($token['error'])) {
                return $fail($token['error'], $token['error_description'] ?? null);
            }
            $accessToken = $token['access_token'] ?? null;
        }

        $parsed = $this->fetcher->parse($source, $target, $accessToken);

        if (isset($parsed['error'])) {
            if (in_array($parsed['error'], self::REMOVAL_ERRORS, true) && $this->removeExisting($job, $site)) {
                return 'deleted';
            }

            if ($parsed['error'] !== 'no_link_found') {
                $this->log->info("Error retrieving source $source: {$parsed['error']}");
            }

            return $fail($parsed['error'], $parsed['error_description'] ?? null);
        }

        $entry = $parsed['data'] ?? [];

        $this->log->info("Processing s=$source t=$target");

        $page = $this->createPageInSite($site, $target);
        $link = $this->links->findByPageAndHref($page->id, $source);

        $linkId = $link?->id ?? $this->links->create([
            'page_id'    => $page->id,
            'href'       => $source,
            'site_id'    => $site->id,
            'account_id' => $site->accountId,
            'domain'     => $sourceDomain,
        ]);

        $row = [
            'protocol'      => $job->protocol,
            'endpoint_type' => $job->endpointType,
            'is_private'    => $job->isPrivate(),
        ];

        try {
            $row = [
                ...$row,
                ...$this->authorFields($entry, $site),
                ...$this->mf2Fields($entry, $source),
                ...self::typeFields($entry, $target),
            ];
        } catch (Throwable $e) {
            // A malformed field should not lose the whole mention.
            $this->log->exception($e, "Error while reading microformats from $source");
        }

        $this->links->update($linkId, $row);

        $link = $this->links->find($linkId) ?? throw new \RuntimeException("Link $linkId vanished.");

        $this->webHooks->notify($site, $link, $source, $target, $job->isPrivate());
        $this->forwardToAperture($account->apertureUri, $account->apertureToken, $entry, $source, $target);

        $this->links->update($linkId, ['token' => $job->token, 'verified' => 1]);
        $link = $this->links->find($linkId) ?? $link;

        $this->statuses->set($job->token, [
            'status'  => 'success',
            'source'  => $source,
            'target'  => $target,
            'private' => $link->isPrivate,
            'data'    => Jf2Format::entry($link),
        ]);

        $this->log->info("Finished {$job->token}");

        return 'success';
    }

    /** A mention the source no longer links to is removed, and the callback told. */
    private function removeExisting(Job $job, Site $site): bool
    {
        $page = $this->pages->findBySiteAndHref($site->id, $job->target);
        $link = $page === null ? null : $this->links->findByPageAndHref($page->id, $job->source);

        if ($link === null) {
            return false;
        }

        $this->links->destroy($link->id);

        $this->statuses->set($job->token, [
            'status'  => 'deleted',
            'source'  => $job->source,
            'target'  => $job->target,
            'private' => $link->isPrivate,
        ]);

        $this->webHooks->deleted($site, $job->source, $job->target, $job->isPrivate());

        return true;
    }

    /**
     * The target page, created on first mention. XRay also tells us what kind
     * of post it is (entry, photo, event, …).
     */
    public function createPageInSite(Site $site, string $target): Page
    {
        $page = $this->pages->findBySiteAndHref($site->id, $target);
        if ($page !== null) {
            return $page;
        }

        // Saved before parsing, since XRay may take a while.
        $page = $this->pages->create($site->accountId, $site->id, $target);

        $parsed = $this->fetcher->parse($target);

        if (isset($parsed['error'])) {
            $this->log->info("Error retrieving page $target: {$parsed['error']}");

            return $page;
        }

        $data = $parsed['data'] ?? [];
        $type = null;

        if (($data['type'] ?? null) === 'entry') {
            $type = match (true) {
                !empty($data['photo']) => 'photo',
                !empty($data['video']) => 'video',
                !empty($data['audio']) => 'audio',
                default                => 'entry',
            };
        } elseif (($data['type'] ?? null) === 'event') {
            $type = 'event';
        }

        $name = isset($data['name']) && is_string($data['name']) ? $data['name'] : null;

        $this->pages->describe($page->id, $type, $name);

        return $this->pages->find($page->id) ?? $page;
    }

    /**
     * @param  array<string, mixed> $entry
     * @return array<string, string>
     */
    public function authorFields(array $entry, Site $site): array
    {
        $fields = ['author_url' => '', 'author_name' => '', 'author_photo' => ''];

        // Bridgy doesn't know who sent an invite, so it names the invitee instead.
        if (!empty($entry['invitee']) && is_array($entry['invitee'])) {
            $invitee = $entry['invitee'][0];
            $fields['author_url'] = is_string($invitee) ? $invitee : (string) ($invitee['url'] ?? '');
        }

        $author = $entry['author'] ?? null;

        if (is_array($author) && ($author['type'] ?? null) === 'card') {
            foreach (['url' => 'author_url', 'name' => 'author_name', 'photo' => 'author_photo'] as $key => $column) {
                if (isset($author[$key]) && is_string($author[$key])) {
                    $fields[$column] = $author[$key];
                }
            }

            if ($site->archiveAvatars && $fields['author_photo'] !== '') {
                $fields['author_photo'] = $this->avatars->archive($fields['author_photo']);
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed> $entry
     * @return array<string, mixed>
     */
    public function mf2Fields(array $entry, string $source): array
    {
        $string = static fn (mixed $v): ?string => is_string($v) ? $v : null;
        $json   = static fn (mixed $v): string => (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        $url = $string($entry['url'] ?? null);

        $fields = [
            'url'  => Url::blank($url) ? $url : Url::absolutize($url, $source),
            'name' => $string($entry['name'] ?? null),
        ];

        if (isset($entry['summary'])) {
            $fields['summary'] = $string($entry['summary']);
        }

        if (isset($entry['content']) && is_array($entry['content'])) {
            if (isset($entry['content']['html'])) {
                $fields['content'] = $string($entry['content']['html']);
            }
            $fields['content_text'] = $string($entry['content']['text'] ?? null);
        }

        foreach (['photo', 'video', 'audio'] as $media) {
            if (isset($entry[$media])) {
                $fields[$media] = $json($entry[$media]);
            }
        }

        $published = $string($entry['published'] ?? null);
        if (!Url::blank($published)) {
            try {
                $date = new DateTimeImmutable((string) $published, new DateTimeZone('UTC'));

                $fields['published']    = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                $fields['published_ts'] = $date->getTimestamp();
                // Only keep an offset the source actually gave.
                if (preg_match('/[+-]\d{2}:?\d{2}/', (string) $published) === 1) {
                    $fields['published_offset'] = $date->getOffset();
                }
            } catch (Exception) {
                // An unparseable date is left out rather than guessed.
            }
        }

        if (!Url::blank($entry['syndication'] ?? null)) {
            $fields['syndication'] = $json($entry['syndication']);
        }

        if (isset($entry['swarm-coins'])) {
            $fields['swarm_coins'] = (int) $entry['swarm-coins'];
        }

        $canonical = $entry['rels']['canonical'] ?? null;
        if (is_array($canonical)) {
            $canonical = $canonical[0] ?? null;
        }
        if (is_string($canonical) && $canonical !== '') {
            $fields['relcanonical'] = $canonical;
        }

        return $fields;
    }

    /**
     * The kind of mention: an RSVP, invite, repost, like, bookmark, reply, or a
     * plain link. is_direct is cleared when the post names a different URL
     * (common with Bridgy), and never set back.
     *
     * @param  array<string, mixed> $entry
     * @return array{type: string, is_direct?: int}
     */
    public static function typeFields(array $entry, string $target): array
    {
        $rsvp = $entry['rsvp'] ?? null;
        if (is_string($rsvp) && in_array(strtolower(trim($rsvp)), self::RSVP_VALUES, true)) {
            return ['type' => 'rsvp-' . strtolower(trim($rsvp))];
        }

        if (!empty($entry['invitee'])) {
            return ['type' => 'invite'];
        }

        foreach (['repost-of' => 'repost', 'like-of' => 'like', 'bookmark-of' => 'bookmark', 'in-reply-to' => 'reply'] as $property => $type) {
            if (!empty($entry[$property])) {
                $urls   = (array) $entry[$property];
                $fields = ['type' => $type];
                if (!in_array($target, $urls, true)) {
                    $fields['is_direct'] = 0;
                }

                return $fields;
            }
        }

        return ['type' => 'link'];
    }

    /**
     * Readers subscribed via Aperture get each mention as a jf2 post.
     *
     * @param array<string, mixed> $entry
     */
    private function forwardToAperture(?string $uri, ?string $token, array $entry, string $source, string $target): void
    {
        if (Url::blank($uri)) {
            return;
        }

        if (Url::blank($entry['url'] ?? null)) {
            $entry['url'] = $source;
        }
        if (Url::blank($entry['published'] ?? null)) {
            $entry['published'] = gmdate('Y-m-d\TH:i:sP');
        }
        if (Url::blank($entry['in-reply-to'] ?? null)) {
            $entry['in-reply-to'] = [$target];
        }

        $response = $this->http->postJson(
            (string) $uri,
            $entry,
            ['Authorization: Bearer ' . $token],
            'application/jf2+json',
        );

        if (!HttpClient::succeeded($response)) {
            $this->log->warning("Aperture post to $uri failed: " . ($response['error'] ?: 'HTTP ' . ($response['code'] ?? '?')));
        }
    }
}
