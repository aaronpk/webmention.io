<?php

declare(strict_types=1);

namespace Webmention\View;

use Stringable;

/**
 * Marks a string as already-safe HTML so Template leaves it alone.
 *
 * Every other value handed to a template is escaped, so reaching for Raw is a
 * visible, greppable decision rather than the default.
 */
final class Raw implements Stringable
{
    public function __construct(public readonly string $html)
    {
    }

    public function __toString(): string
    {
        return $this->html;
    }
}
