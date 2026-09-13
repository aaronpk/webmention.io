<?php

declare(strict_types=1);

namespace Webmention;

/**
 * Settings from a `.env` file, overridable by real environment variables.
 *
 * The file format is deliberately minimal: KEY=VALUE per line, `#` comments,
 * optional surrounding quotes. No interpolation.
 */
final class Config
{
    /** @param array<string, string> $values */
    public function __construct(private readonly array $values)
    {
    }

    public static function load(string $envFile): self
    {
        $values = is_file($envFile) ? self::parse((string) file_get_contents($envFile)) : [];

        foreach (array_keys($values) as $key) {
            $env = getenv($key);
            if (is_string($env)) {
                $values[$key] = $env;
            }
        }

        return new self($values);
    }

    /** @return array<string, string> */
    public static function parse(string $contents): array
    {
        $values = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }

            $values[$key] = $value;
        }

        return $values;
    }

    /** @param array<string, string> $overrides */
    public function with(array $overrides): self
    {
        return new self(array_merge($this->values, $overrides));
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $value = $this->values[$key] ?? getenv($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /** The public base URL with no trailing slash, e.g. https://webmention.io */
    public function baseUrl(): string
    {
        return rtrim($this->get('BASE_URL', 'http://127.0.0.1:8080') ?? '', '/');
    }

    public function debug(): bool
    {
        return $this->get('APP_DEBUG') === '1';
    }

    public function trustProxy(): bool
    {
        return $this->get('TRUST_PROXY') === '1';
    }
}
