<?php

declare(strict_types=1);

namespace Webmention\Controllers;

use Webmention\Http\Response;
use Webmention\View\Template;

abstract class Controller
{
    public function __construct(protected readonly Template $view)
    {
    }

    /**
     * Renders a page template inside the shared layout.
     *
     * @param array<string, mixed> $vars
     * @param array<string, mixed>|null $nav Shown on signed-in pages; see RequiresLogin::nav().
     */
    protected function page(string $template, string $title, array $vars = [], ?array $nav = null, int $status = 200): Response
    {
        return Response::html($this->view->render('layout', [
            'title'   => $title,
            'nav'     => $nav,
            'content' => $this->view->partial($template, $vars),
        ]), $status);
    }
}
