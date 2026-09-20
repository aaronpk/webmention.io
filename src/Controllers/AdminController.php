<?php

declare(strict_types=1);

namespace Webmention\Controllers;

use Webmention\Admin\AccountReport;
use Webmention\Admin\Admins;
use Webmention\Admin\Lookup;
use Webmention\Admin\ServiceActivity;
use Webmention\Admin\ServiceOverview;
use Webmention\Admin\SourceRadar;
use Webmention\Http\HttpException;
use Webmention\Http\Request;
use Webmention\Http\Response;
use Webmention\Http\Session;
use Webmention\Model\Account;
use Webmention\Storage\AccountRepository;
use Webmention\View\Template;

/**
 * The admin section: how the service as a whole is doing, and the facts
 * behind one person's problem.
 *
 * Read-only, on purpose. Everything that changes data still goes through the
 * owner's own pages or through tools/ on the server, so this section adds
 * nothing that can be misused, only things that can be seen.
 *
 * Signed out, these paths behave like every other page and send you home.
 * Signed in as anyone ADMIN_USERS does not name, they are a 404: the section
 * should leave no trace for people it is not for.
 */
final class AdminController extends Controller
{
    use RequiresLogin;

    /** Accounts listed before anything has been searched for. */
    private const RECENT_ACCOUNTS = 30;

    public function __construct(
        Template $view,
        private readonly Session $session,
        private readonly AccountRepository $accounts,
        private readonly Admins $admins,
        private readonly ServiceOverview $overview,
        private readonly ServiceActivity $activity,
        private readonly SourceRadar $radar,
        private readonly AccountReport $report,
        private readonly Lookup $lookup,
    ) {
        parent::__construct($view);
    }

    protected function session(): Session
    {
        return $this->session;
    }

    protected function accounts(): AccountRepository
    {
        return $this->accounts;
    }

    protected function admins(): Admins
    {
        return $this->admins;
    }

    /** @param array<string, string> $params */
    public function overview(Request $request, array $params): Response
    {
        if (!($user = $this->admin($request)) instanceof Account) {
            return $user;
        }

        return $this->page('admin/overview', 'Admin', $this->overview->now(), $this->nav($user, 'admin'));
    }

    /** @param array<string, string> $params */
    public function activity(Request $request, array $params): Response
    {
        if (!($user = $this->admin($request)) instanceof Account) {
            return $user;
        }

        $months = (int) ($request->query('months') ?? ServiceActivity::MONTHS);

        return $this->page('admin/activity', 'Activity · Admin', $this->activity->report($months), $this->nav($user, 'admin'));
    }

    /** @param array<string, string> $params */
    public function sources(Request $request, array $params): Response
    {
        if (!($user = $this->admin($request)) instanceof Account) {
            return $user;
        }

        $sort = $request->query('sort') === 'deleted' ? 'deleted' : 'total';

        return $this->page('admin/sources', 'Sources · Admin', [
            'sources' => $this->radar->busiest($sort),
            'sort'    => $sort,
            'days'    => SourceRadar::DAYS,
            'limit'   => SourceRadar::LIMIT,
        ], $this->nav($user, 'admin'));
    }

    /** @param array<string, string> $params */
    public function accountList(Request $request, array $params): Response
    {
        if (!($user = $this->admin($request)) instanceof Account) {
            return $user;
        }

        $query = trim((string) ($request->query('q') ?? ''));
        $rows  = $query === ''
            ? $this->accounts->recentRows(self::RECENT_ACCOUNTS)
            : $this->accounts->searchRows($query, self::RECENT_ACCOUNTS);

        return $this->page('admin/accounts', 'Accounts · Admin', [
            'query'    => $query,
            'accounts' => array_map(static fn (array $r): array => [
                'id'         => (int) $r['id'],
                'username'   => $r['username'],
                'domain'     => $r['domain'],
                'email'      => $r['email'],
                'sites'      => (int) $r['sites'],
                'created_at' => $r['created_at'],
                'last_login' => $r['last_login'],
            ], $rows),
            'limit' => self::RECENT_ACCOUNTS,
        ], $this->nav($user, 'admin'));
    }

    /** @param array<string, string> $params */
    public function account(Request $request, array $params): Response
    {
        if (!($user = $this->admin($request)) instanceof Account) {
            return $user;
        }

        $report = $this->report->of((int) ($params['id'] ?? 0));
        if ($report === null) {
            throw HttpException::notFound('No account with that id.');
        }

        $name = $report['account']['domain'] ?? $report['account']['username'] ?? ('Account ' . $report['account']['id']);

        return $this->page('admin/account', "$name · Admin", $report, $this->nav($user, 'admin'));
    }

    /** @param array<string, string> $params */
    public function lookup(Request $request, array $params): Response
    {
        if (!($user = $this->admin($request)) instanceof Account) {
            return $user;
        }

        return $this->page('admin/lookup', 'Lookup · Admin', $this->lookup->find((string) ($request->query('q') ?? '')), $this->nav($user, 'admin'));
    }

    /**
     * The signed-in admin, or the response to send instead: home when nobody
     * is signed in, and a 404 for everyone else, so the section is invisible
     * rather than merely closed.
     */
    private function admin(Request $request): Account|Response
    {
        $user = $this->currentUser($request);
        if ($user === null) {
            return Response::redirect('/');
        }

        if (!$this->admins->has($user)) {
            throw HttpException::notFound();
        }

        return $user;
    }
}
