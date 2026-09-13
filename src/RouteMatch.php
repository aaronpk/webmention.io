<?php

declare(strict_types=1);

namespace Webmention;

final class RouteMatch
{
    /**
     * @param array{0: class-string, 1: string} $handler
     * @param array<string, string>             $params
     */
    public function __construct(
        public readonly array $handler,
        public readonly array $params = [],
    ) {
    }
}
