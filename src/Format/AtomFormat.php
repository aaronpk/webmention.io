<?php

declare(strict_types=1);

namespace Webmention\Format;

use DateTimeImmutable;
use DateTimeZone;
use Webmention\Model\Link;
use XMLWriter;

/**
 * The Atom feed for /api/mentions.atom. Port of Formats.links_to_atom.
 */
final class AtomFormat
{
    /** @param list<Link> $links */
    public static function feed(array $links, string $baseUrl): string
    {
        $atomUrl = $baseUrl . '/api/mentions.atom';

        $updated = null;
        foreach ($links as $link) {
            $date = $link->updatedDate();
            if ($date !== null && ($updated === null || $date > $updated)) {
                $updated = $date;
            }
        }
        $updated ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'UTF-8');

        $xml->startElement('feed');
        $xml->writeAttribute('xmlns', 'http://www.w3.org/2005/Atom');
        $xml->writeElement('id', $atomUrl);
        $xml->writeElement('title', 'Mentions');
        $xml->writeElement('updated', $updated->format(DATE_ATOM));
        $xml->startElement('link');
        $xml->writeAttribute('href', $atomUrl);
        $xml->endElement();
        $xml->startElement('author');
        $xml->writeElement('name', 'webmention.io');
        $xml->endElement();

        foreach ($links as $link) {
            self::entry($xml, $link, $baseUrl);
        }

        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    private static function entry(XMLWriter $xml, Link $link, string $baseUrl): void
    {
        $source = self::clean((string) $link->href);
        $target = self::clean((string) $link->targetHref);

        // A bare domain target reads as "/" in the title.
        $targetPath = parse_url(Url::escape($target), PHP_URL_PATH);
        if (!is_string($targetPath) || $targetPath === '') {
            $targetPath = '/';
            if (preg_match('#^[a-z][a-z0-9+.\-]*://[^/?\#]+$#i', $target) === 1) {
                $target .= '/';
            }
        }

        $verb = self::verb($link->type);
        $host = Url::host($source) ?? $source;

        $xml->startElement('entry');
        $xml->writeElement('title', "$host $verb $targetPath");
        $xml->writeElement('id', $baseUrl . '/api/mention/' . $link->id);
        $xml->writeElement('updated', ($link->updatedDate() ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM));
        $xml->writeElement('summary', "$source $verb $target");

        $xml->startElement('content');
        $xml->writeAttribute('type', 'xhtml');
        $xml->writeAttribute('xml:lang', 'en');
        // Indenting mixed content would change the text, so the xhtml is written flat.
        $xml->setIndent(false);
        $xml->startElement('div');
        $xml->writeAttribute('xmlns', 'http://www.w3.org/1999/xhtml');
        $xml->startElement('p');
        $xml->startElement('a');
        $xml->writeAttribute('href', $source);
        $xml->text($source);
        $xml->endElement();
        $xml->text(" $verb ");
        $xml->startElement('a');
        $xml->writeAttribute('href', $target);
        $xml->text($target);
        $xml->endElement();
        $xml->endElement(); // p
        $xml->endElement(); // div
        $xml->endElement(); // content
        $xml->setIndent(true);

        $xml->endElement(); // entry
    }

    public static function verb(?string $type): string
    {
        return match ($type) {
            'like'            => 'liked',
            'repost'          => 'reposted',
            'reply'           => 'replied to',
            'bookmark'        => 'bookmarked',
            'rsvp-yes'        => 'RSVPed “yes” to',
            'rsvp-no'         => 'RSVPed “no” to',
            'rsvp-maybe'      => 'RSVPed “maybe” to',
            'rsvp-interested' => 'RSVPed “interested” to',
            default           => 'mentioned',
        };
    }

    /** XML cannot carry invalid UTF-8 or most control characters. */
    private static function clean(string $value): string
    {
        return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', mb_scrub($value, 'UTF-8'));
    }
}
