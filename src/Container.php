<?php

declare(strict_types=1);

namespace Webmention;

use Closure;
use RuntimeException;

/**
 * A deliberately tiny service locator.
 *
 * Every service is registered explicitly in Bootstrap — there is no reflection
 * or autowiring. That costs a line per service but means the dependency graph
 * is readable in one file and a typo fails loudly instead of half-working.
 */
final class Container
{
    /** @var array<string, Closure(Container): object> */
    private array $factories = [];

    /** @var array<string, object> */
    private array $instances = [];

    /**
     * @template T of object
     * @param class-string<T>           $id
     * @param Closure(Container): T     $factory
     */
    public function set(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    /**
     * Resolve a service, constructing it at most once.
     *
     * @template T of object
     * @param  class-string<T> $id
     * @return T
     */
    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            /** @var T */
            return $this->instances[$id];
        }

        if (!isset($this->factories[$id])) {
            throw new RuntimeException(sprintf('Service "%s" is not registered in Bootstrap.', $id));
        }

        $instance = ($this->factories[$id])($this);
        $this->instances[$id] = $instance;

        /** @var T */
        return $instance;
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]);
    }
}
