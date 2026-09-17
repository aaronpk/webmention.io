<?php

declare(strict_types=1);

namespace Webmention\Model;

use DateTimeImmutable;
use DateTimeZone;
use Webmention\Format\Url;

/**
 * A received webmention: one source URL linking to one target page.
 *
 * Loaded together with its page's href and its site's created_at, which every
 * output format needs.
 */
final class Link
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $href,
        public readonly string $domain,
        public readonly ?bool $verified,
        public readonly ?string $protocol,
        public readonly string $endpointType,
        public readonly bool $isPrivate,
        public readonly ?string $summary,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
        public readonly int $pageId,
        public readonly ?string $authorUrl,
        public readonly ?string $authorName,
        public readonly ?string $authorPhoto,
        public readonly ?string $name,
        public readonly ?string $content,
        public readonly ?string $contentText,
        public readonly ?string $published,
        public readonly ?int $publishedTs,
        public readonly ?int $publishedOffset,
        public readonly ?string $url,
        public readonly ?string $relcanonical,
        public readonly ?string $type,
        public readonly bool $isDirect,
        public readonly int $siteId,
        public readonly ?int $accountId,
        public readonly ?string $syndication,
        public readonly ?string $token,
        public readonly ?int $swarmCoins,
        public readonly bool $deleted,
        public readonly ?string $photo,
        public readonly ?string $video,
        public readonly ?string $audio,
        public readonly ?string $targetHref,
        public readonly ?string $siteCreatedAt,
        /** NULL when published; "pending" while held for review; "hidden" while a mute rule matches. */
        public readonly ?string $status = null,
        /** The "#fragment" of the target as sent, without the hash; NULL for rows received before it was recorded. */
        public readonly ?string $targetFragment = null,
    ) {
    }

    /** @param array<string, mixed> $row A links row joined with page_href and site_created_at. */
    public static function fromRow(array $row): self
    {
        $int  = static fn (mixed $v): ?int => $v === null ? null : (int) $v;
        $str  = static fn (mixed $v): ?string => $v === null ? null : (string) $v;

        return new self(
            id:              (int) $row['id'],
            href:            $str($row['href']),
            domain:          (string) ($row['domain'] ?? ''),
            verified:        $row['verified'] === null ? null : (bool) $row['verified'],
            protocol:        $str($row['protocol']),
            endpointType:    (string) ($row['endpoint_type'] ?? 'account'),
            isPrivate:       (bool) $row['is_private'],
            summary:         $str($row['summary']),
            createdAt:       $str($row['created_at']),
            updatedAt:       $str($row['updated_at']),
            pageId:          (int) $row['page_id'],
            authorUrl:       $str($row['author_url']),
            authorName:      $str($row['author_name']),
            authorPhoto:     $str($row['author_photo']),
            name:            $str($row['name']),
            content:         $str($row['content']),
            contentText:     $str($row['content_text']),
            published:       $str($row['published']),
            publishedTs:     $int($row['published_ts']),
            publishedOffset: $int($row['published_offset']),
            url:             $str($row['url']),
            relcanonical:    $str($row['relcanonical']),
            type:            $str($row['type']),
            isDirect:        $row['is_direct'] === null ? true : (bool) $row['is_direct'],
            siteId:          (int) $row['site_id'],
            accountId:       $int($row['account_id']),
            syndication:     $str($row['syndication']),
            token:           $str($row['token']),
            swarmCoins:      $int($row['swarm_coins']),
            deleted:         (bool) $row['deleted'],
            photo:           $str($row['photo']),
            video:           $str($row['video']),
            audio:           $str($row['audio']),
            targetHref:      $str($row['page_href'] ?? null),
            siteCreatedAt:   $str($row['site_created_at'] ?? null),
            status:          $str($row['status'] ?? null),
            targetFragment:  $str($row['target_fragment'] ?? null),
        );
    }

    public function hasAuthorInfo(): bool
    {
        return !Url::blank($this->authorName) || !Url::blank($this->authorUrl) || !Url::blank($this->authorPhoto);
    }

    /** @return list<mixed>|null */
    public function syndications(): ?array
    {
        if (Url::blank($this->syndication)) {
            return null;
        }

        $decoded = json_decode((string) $this->syndication, true);

        return is_array($decoded) ? array_values($decoded) : null;
    }

    /**
     * The published date in the timezone the source reported. Stays in UTC
     * when the source gave no timezone.
     */
    public function publishedDate(): ?DateTimeImmutable
    {
        if (Url::blank($this->published)) {
            return null;
        }

        $date = new DateTimeImmutable((string) $this->published, new DateTimeZone('UTC'));

        if ($this->publishedOffset !== null) {
            $date = $date->setTimezone(new DateTimeZone(self::offsetString($this->publishedOffset)));
        }

        return $date;
    }

    public function createdDate(): ?DateTimeImmutable
    {
        return $this->createdAt === null ? null : new DateTimeImmutable($this->createdAt, new DateTimeZone('UTC'));
    }

    public function updatedDate(): ?DateTimeImmutable
    {
        return $this->updatedAt === null ? null : new DateTimeImmutable($this->updatedAt, new DateTimeZone('UTC'));
    }

    public function absoluteUrl(): ?string
    {
        if (Url::blank($this->url)) {
            return $this->href;
        }

        return Url::absolutize($this->url, $this->href);
    }

    public function mf2RelationClass(): string
    {
        return match ($this->type) {
            'repost'   => 'u-repost-of',
            'like'     => 'u-like-of',
            'reply'    => 'u-in-reply-to',
            'bookmark' => 'u-bookmark-of',
            default    => 'u-mention-of',
        };
    }

    /** An offset in seconds as "+HH:MM". */
    public static function offsetString(int $seconds): string
    {
        $sign    = $seconds < 0 ? '-' : '+';
        $seconds = abs($seconds);

        return sprintf('%s%02d:%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
}
