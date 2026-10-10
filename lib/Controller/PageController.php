<?php

declare(strict_types=1);

namespace OCA\PettyCash\Controller;

use OCA\PettyCash\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

final class PageController extends Controller
{
    public function __construct(IRequest $request)
    {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * The single entry point to the whole browser application, for all five business roles.
     *
     * ## Both attributes are load-bearing, and each was found by measurement
     *
     * **`#[NoAdminRequired]`** is not optional. Nextcloud treats an app *page* controller method as
     * admin-only unless it says otherwise, so without it this method answered **403
     * `NotAdminException`** to every non-administrator -- which is every purchaser, cost-centre manager,
     * senior manager and finance officer the application exists for. Measured: `employee1` answered 403
     * here and 200 on `GET /ocs/.../me`, so the OCS API was reachable while the UI was not.
     *
     * **`#[NoCSRFRequired]`** is not optional either, and the two defects are sequential: with only the
     * admin attribute the response became **412 "CSRF check failed"**. `Request::passesCSRFCheck()`
     * requires a `requesttoken` in the query, the body or the `HTTP_REQUESTTOKEN` header, and
     * `SecurityMiddleware` runs that check for a page load as well as for a state change -- a bare
     * `page.goto('/apps/pettycash/')` carries no token, so a browser could never open the app either.
     * `apps/files`' `ViewController::showFile()` carries the same pair, which is the shape this now
     * follows.
     *
     * ## Why every other layer was green while the application was unusable
     *
     * The OCS contract suite authenticates as a non-admin and never loads this route. The Vue suites
     * mount components in jsdom, with no page and therefore no middleware. `npm run check` runs neither.
     * The Playwright suite is the only thing that loads a real page, and it could not reach one at all
     * (T171) -- so the 401 and this 403 masked each other, each hiding the other.
     *
     * ## What this does not grant
     *
     * A signed-in user may load the *shell*. Every action inside it is authorised server-side by the OCS
     * controllers, each of which already carries `#[NoAdminRequired]` and its own policy, and whose role
     * and scope checks are unchanged. Marking the page is not a way around them: the page renders an empty
     * frame and every request it makes is checked on its own.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse
    {
        return new TemplateResponse(Application::APP_ID, 'main');
    }
}
