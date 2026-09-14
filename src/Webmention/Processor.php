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
    public const REMOVAL_ERRORS = ['no_link_found', 'not_found', 'gone'];

    private const RSVP_VALUES = ['yes', 'no', 'maybe', 'interested'];

    /**
     * Column sizes. The connection runs without strict mode (the legacy data
     * needs it), so anything longer would be cut silently by MySQL, possibly
     * in the middle of a tag or a multibyte character.
     */
    private const BLOB_BYTES = 65535;
    private const URL_BYTES  = 256;
    private const PHOTO_BYTES = 512;
    private const CANONICAL_BYTES = 255;

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

        // Redirects are followed while fetching, so the page that was actually
        // read may live somewhere the account has blocked.
        $finalUrl = $parsed['final_url'] ?? $source;
        if ($finalUrl !== $source) {
            $finalDomain = Url::host($finalUrl);
            if (($finalDomain !== null && $this->blocks->isDomainBlocked($account->id, $finalDomain))
                || $this->blocks->isSourceBlocked($site->id, $finalUrl)) {
                return $fail('blocked', 'source redirects to a blocked URL');
            }
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
            // A mention deleted from the dashboard was also blocked; getting
            // here again means it was unblocked, so it comes back.
            'deleted'       => 0,
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

            $fields['author_name']  = (string) self::fit($fields['author_name'], self::BLOB_BYTES);
            $fields['author_url']   = self::fitUrl($fields['author_url'], self::URL_BYTES) ?? '';
            $fields['author_photo'] = self::fitUrl($fields['author_photo'], self::PHOTO_BYTES) ?? '';

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
            'url'  => self::fitUrl(Url::blank($url) ? $url : Url::absolutize($url, $source), self::URL_BYTES),
            'name' => self::fit($string($entry['name'] ?? null), self::BLOB_BYTES),
        ];

        if (isset($entry['summary'])) {
            $fields['summary'] = self::fit($string($entry['summary']), self::BLOB_BYTES);
        }

        if (isset($entry['content']) && is_array($entry['content'])) {
            if (isset($entry['content']['html'])) {
                $fields['content'] = self::fitHtml($string($entry['content']['html']), self::BLOB_BYTES);
            }
            $fields['content_text'] = self::fit($string($entry['content']['text'] ?? null), self::BLOB_BYTES);
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
        if (is_string($canonical) && ($canonical = self::fitUrl($canonical, self::CANONICAL_BYTES)) !== null) {
            $fields['relcanonical'] = $canonical;
        }

        return $fields;
    }

    /** Cut to a column's size without splitting a multibyte character. */
    public static function fit(?string $value, int $bytes): ?string
    {
        if ($value === null || strlen($value) <= $bytes) {
            return $value;
        }

        return mb_strcut($value, 0, $bytes, 'UTF-8');
    }

    /** A URL cut short is a different URL, so an over-long one is dropped instead. */
    public static function fitUrl(?string $url, int $bytes): ?string
    {
        return $url === null || strlen($url) > $bytes ? null : $url;
    }

    /** Cut HTML at a tag boundary, so the stored fragment never ends inside a tag. */
    public static function fitHtml(?string $html, int $bytes): ?string
    {
        if ($html === null || strlen($html) <= $bytes) {
            return $html;
        }

        $cut  = (string) mb_strcut($html, 0, $bytes, 'UTF-8');
        $open = strrpos($cut, '<');

        if ($open !== false && strrpos($cut, '>') < $open) {
            $cut = substr($cut, 0, $open);
        }

        return $cut;
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
