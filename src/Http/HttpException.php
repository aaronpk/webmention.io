<?php

declare(strict_types=1);

namespace Webmention\Http;

use RuntimeException;
use Throwable;

/**
 * Thrown to abort a request with a specific status code and a message that is
 * safe to show a user.
 */
class HttpException extends RuntimeException
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        string $message = '',
        public readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : self::defaultMessage($status), $status, $previous);
    }

    public static function notFound(string $message = ''): self
    {
        return new self(404, $message);
    }

    public static function badRequest(string $message = ''): self
    {
        return new self(400, $message);
    }

    public static function forbidden(string $message = ''): self
    {
        return new self(403, $message);
    }

    /** @param list<string> $allowed */
    public static function methodNotAllowed(array $allowed): self
    {
        return new self(405, 'That method is not allowed here.', ['allow' => implode(', ', $allowed)]);
    }

    private static function defaultMessage(int $status): string
    {
        return match ($status) {
            400     => 'The request could not be understood.',
            401     => 'Authentication is required.',
            403     => 'You do not have access to that.',
            404     => 'That page does not exist.',
            405     => 'That method is not allowed here.',
            429     => 'Too many requests. Try again shortly.',
            default => 'Something went wrong.',
        };
    }
}
