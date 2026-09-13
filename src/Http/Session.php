<?php

declare(strict_types=1);

namespace Webmention\Http;

use Webmention\Config;

/**
 * The signed-in user and the CSRF token, kept in PHP's own session.
 *
 * indieauth/client stores its flow state in $_SESSION directly, so this uses
 * native sessions rather than a custom store. In production they are saved in
 * Redis (session.save_handler=redis); in tests the session is a plain array
 * and nothing is sent to the browser.
 */
final class Session
{
    public const COOKIE   = 'webmention_session';
    public const LIFETIME = 2592000; // 30 days, as before

    private bool $started = false;

    private function __construct(
        private readonly ?Config $config,
        private readonly bool $native,
    ) {
    }

    public static function native(Config $config): self
    {
        return new self($config, true);
    }

    /** For tests: $_SESSION is used as a plain array. */
    public static function memory(): self
    {
        $_SESSION = [];

        $session = new self(null, false);
        $session->started = true;

        return $session;
    }

    /**
     * Start (or resume) the session. Only pages that need it call this, so API
     * requests never create sessions.
     */
    public function start(Request $request): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;

        if (!$this->native || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $host = $this->config?->get('REDIS_HOST');
        if ($host !== null && extension_loaded('redis')) {
            ini_set('session.save_handler', 'redis');
            ini_set('session.save_path', sprintf(
                'tcp://%s:%s?database=%d&prefix=webmention:session:',
                $host,
                $this->config?->get('REDIS_PORT', '6379'),
                (int) $this->config?->get('REDIS_DB', '0'),
            ));
        }

        ini_set('session.gc_maxlifetime', (string) self::LIFETIME);
        ini_set('session.use_strict_mode', '1');

        session_name(self::COOKIE);
        session_set_cookie_params([
            'lifetime' => self::LIFETIME,
            'path'     => '/',
            'secure'   => $request->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public function userId(): ?int
    {
        $id = $_SESSION['user_id'] ?? null;

        return is_int($id) ? $id : null;
    }

    public function logIn(int $userId): void
    {
        if ($this->native && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION['user_id'] = $userId;
        unset($_SESSION['csrf']);
    }

    public function logOut(): void
    {
        unset($_SESSION['user_id'], $_SESSION['csrf']);

        if ($this->native && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function csrfToken(): string
    {
        if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }

        return $_SESSION['csrf'];
    }

    public function validCsrf(?string $token): bool
    {
        return $token !== null
            && isset($_SESSION['csrf'])
            && is_string($_SESSION['csrf'])
            && hash_equals($_SESSION['csrf'], $token);
    }
}
