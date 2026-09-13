<?php

declare(strict_types=1);

namespace Webmention\Controllers;

use Webmention\Http\HttpException;
use Webmention\Http\Request;
use Webmention\Http\Session;
use Webmention\Model\Account;
use Webmention\Storage\AccountRepository;

/**
 * Shared by every controller behind sign-in.
 */
trait RequiresLogin
{
    abstract protected function session(): Session;

    abstract protected function accounts(): AccountRepository;

    /** The signed-in account, or null (the caller redirects home). */
    protected function currentUser(Request $request): ?Account
    {
        $this->session()->start($request);

        $id = $this->session()->userId();

        return $id === null ? null : $this->accounts()->find($id);
    }

    /** @throws HttpException 403 when the form's CSRF token does not match the session. */
    protected function checkCsrf(Request $request): void
    {
        if (!$this->session()->validCsrf($request->post('csrf'))) {
            throw HttpException::forbidden('Your session expired. Go back, reload the page and try again.');
        }
    }

    /** @return array{domain: string|null, active: string, csrf: string} */
    protected function nav(Account $user, string $active): array
    {
        return ['domain' => $user->domain, 'active' => $active, 'csrf' => $this->session()->csrfToken()];
    }
}
