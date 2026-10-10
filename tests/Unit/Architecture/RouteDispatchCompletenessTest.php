<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * T326/T344: every path the frontend addresses must be a path some route serves.
 *
 * ## The defect this exists to stop recurring
 *
 * `src/services/transactionsApi.ts` called `GET /sheets/{sheetId}/transactions`. No route served it,
 * and `contracts/ocs-api.yaml` declared only `post:` under that path — so the `GET` had never been
 * written, anywhere. The request answered `405` with an **empty body** in every signed-in browser.
 *
 * Nothing failed. The frontend suite stubbed `@nextcloud/axios` and answered the unserved path with a
 * fixture page of transactions, so 243 tests were green while the purchaser's transaction table could
 * never load a row. The defect was found by a human reading a browser network log during T171, not
 * by any test.
 *
 * ## Why a structural assertion and not a request
 *
 * An unserved path has no behaviour to exercise: the honest runtime symptom is an error, and a test
 * that asserts the error is a test that pins the defect in place. What can be asserted is the
 * *correspondence* — the set of paths the client can ask for against the set of routes that answer —
 * and that correspondence is a fact about text, exactly like `LayerBoundaryTest`'s import scan. The
 * failure it produces is a build-time failure naming the file and the path, which is a far cheaper
 * signal than a table that renders empty in a browser nobody is watching.
 *
 * ## What is deliberately not asserted
 *
 * The reverse direction — a route no client method addresses — is **not** required to be empty.
 * `ConfigurationController` (T087) and `EvidenceController::links` / `deactivateLink` (T096) are
 * routed and not yet reachable from the client, and demanding symmetry there would fail on work that
 * is correctly in progress. The failure this file exists to catch is the one where the client asks
 * for something nothing serves, because that one is invisible until a user tries it.
 */
final class RouteDispatchCompletenessTest extends TestCase
{
    private const APP_ROOT = __DIR__ . '/../../..';

    public function testEveryFrontendApiPathIsServedByARoute(): void
    {
        $served = $this->servedRoutes();
        $this->assertNotEmpty($served, 'the route table parsed to nothing, so this scan proves nothing');

        $unserved = [];
        foreach ($this->clientPaths() as $file => $calls) {
            foreach ($calls as $call) {
                $route = $this->normalise($call['path']);
                if (!isset($served[$route][$call['verb']])) {
                    $unserved[] = sprintf('%s %s (%s)', $call['verb'], $call['path'], $file);
                }
            }
        }

        $this->assertSame(
            [],
            $unserved,
            "Every path a frontend service addresses must be served by a route.\n"
            . "Unserved:\n  " . implode("\n  ", $unserved) . "\n\n"
            . 'T326 shipped this failure once already: `GET /sheets/{sheetId}/transactions` was never '
            . 'routed, answered 405 with an empty body in every browser, and 243 frontend tests stayed '
            . 'green because the suite stubbed the HTTP layer and answered the unserved path anyway.'
        );
    }

    public function testTheScanActuallyFindsClientPaths(): void
    {
        // The scan is a regex over source. A refactor that changes how a URL is spelled would leave it
        // matching nothing, and the test above would pass over every path in the client — the failure
        // mode this file shares with `LayerBoundaryTest`, which checks its own rule table for exactly
        // this reason.
        $paths = $this->clientPaths();
        $total = array_sum(array_map('count', $paths));

        $this->assertGreaterThan(
            0,
            $total,
            'no frontend API path was recognised, so the route-dispatch scan is vacuous'
        );
        $this->assertArrayHasKey(
            'src/services/sheetsApi.ts',
            $paths,
            'the scan should reach every service module; missing one means its URL spelling is no longer matched'
        );
    }

    /**
     * The other direction: a controller action no route reaches is unreachable code.
     *
     * **Added by T344, and it is the check that would have caught all three of the actions it removed.**
     * `TransactionController::decide()` duplicated the routed `review#decide` and returned a bare
     * `TransactionDetail` where the contract requires `DecisionResult` — so wiring it in as part of a
     * cleanup would have silently removed `decision` and `accountMutation` from a working endpoint.
     * `QueueController::index()` and `ReturnsController::index()` duplicated routed `ReviewController`
     * methods, and the first would have raised `ValueError` on the kebab-case stage slug the client
     * sends. Two divergent implementations of one queue, none of them reachable, and every test green.
     *
     * Unlike the client-side direction, this one **is** symmetric and must be empty: unlike
     * `ConfigurationController` (T087) and `EvidenceController::links`/`deactivateLink` (T096), which are
     * *routed but not yet reachable from the client*, the failure here is an action that **no route can
     * reach at all** — which nothing in the application can ever call. So a controller action must be
     * routed, and an unrouted one is dead code by definition rather than by neglect.
     *
     * Asserted structurally (a fact about text) for the same reason as the scan above: an unrouted action
     * has no runtime behaviour to exercise, and a test that issued a request to it would be testing the
     * dispatcher's 500.
     */
    public function testEveryControllerActionIsReachableFromARoute(): void
    {
        $routedActions = $this->routedActions();
        $actions = $this->controllerActions();

        $unrouted = [];
        foreach ($actions as $name => $file) {
            if (!in_array($name, $routedActions, true)) {
                $unrouted[] = $name . ' (' . $file . ')';
            }
        }

        $this->assertSame(
            [],
            $unrouted,
            "Every public controller action must be reachable from a route.\n"
            . "Unrouted:\n  " . implode("\n  ", $unrouted) . "\n\n"
            . 'An unrouted action cannot be called by anything. T344 removed three of them, one of which '
            . '(`transaction#decide`) answered a different schema from the route serving the same URL and '
            . 'would have regressed a working endpoint had it ever been wired in.'
        );
    }

    /**
     * The scan must actually see controller actions, or the case above passes vacuously.
     *
     * The same self-check `LayerBoundaryTest` and `testTheScanActuallyFindsClientPaths` exist for, and
     * for the same reason: a regex over source that stops matching reports nothing while proving
     * nothing.
     */
    public function testTheScanActuallyFindsControllerActions(): void
    {
        $actions = $this->controllerActions();

        $this->assertGreaterThan(
            0,
            count($actions),
            'no controller action was recognised, so the unrouted-action scan is vacuous'
        );
        $this->assertContains(
            'review#decide',
            $this->routedActions(),
            'the route table parsed to no usable action names, so the comparison would be empty-vs-everything'
        );
        $this->assertArrayHasKey(
            'sheet#index',
            $actions,
            'the action scan should reach `SheetController::index()`'
        );
    }

    /**
     * Every public method of every controller, keyed `shortControllerName#action`.
     *
     * `__construct` is excluded because it is never an action, and the `#[NoAdminRequired]` attribute
     * is not required: an action without it is still routed, and whether its guard is correct is a
     * different question that a route-to-action correspondence cannot answer.
     *
     * @return array<string, string> action name => relative file
     */
    private function controllerActions(): array
    {
        $actions = [];

        foreach (glob(self::APP_ROOT . '/lib/Controller/*Controller.php') ?: [] as $file) {
            $relative = substr($file, strlen(self::APP_ROOT) + 1);
            $controller = basename($file, '.php');
            $short = strtolower(str_replace('Controller', '', $controller));

            // `public function name(` — the method declaration line. A `private`/`protected` method is
            // excluded by requiring `public` directly before `function`, and a return type is not
            // required because the controllers do not all declare one.
            preg_match_all('/^\s+public function (\w+)\(/m', (string) file_get_contents($file), $matches);

            foreach ($matches[1] as $method) {
                if ($method === '__construct') {
                    continue;
                }
                $actions[$short . '#' . $method] = $relative;
            }
        }

        ksort($actions);

        return $actions;
    }

    /**
     * The `controller#action` names `appinfo/routes.php` dispatches to, across both route blocks.
     *
     * @return list<string>
     */
    private function routedActions(): array
    {
        $routes = (string) file_get_contents(self::APP_ROOT . '/appinfo/routes.php');

        preg_match_all("/'name'\s*=>\s*'([a-zA-Z]+)#(\w+)'/", $routes, $matches, PREG_SET_ORDER);

        $actions = array_map(
            static fn (array $match): string => strtolower($match[1]) . '#' . $match[2],
            $matches
        );
        sort($actions);

        return array_values(array_unique($actions));
    }

    /**
     * Every path and verb the client can issue, keyed by file for a failure message naming the source.
     *
     * A **list**, not one entry per verb: `evidenceApi.ts` issues four different `POST`s, and a map
     * keyed by verb would keep the first and silently drop the other three — which is the same
     * "reported nothing while proving nothing" failure this file exists to prevent, one level in.
     *
     * @return array<string, list<array{verb: string, path: string}>>
     */
    private function clientPaths(): array
    {
        $found = [];

        foreach (glob(self::APP_ROOT . '/src/services/*.ts') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            $relative = substr($file, strlen(self::APP_ROOT) + 1);

            // `ocs<T>(path)` is a read; `mutate<T>(path, 'VERB', …)` is a write. Both wrap
            // `/apps/pettycash/api/v1${path}`, so the path literal is what has to be matched — and it
            // is a template literal in every case, because these calls interpolate an id.
            //
            // `<[^>]*>` cannot match a nested generic (`ocs<Page<Account>>` is `<Page<Account>`),
            // which is why the read pattern also allows a plain `ocs(` — the common shape, and the one
            // that carries the paths this scan is for.
            $patterns = [
                '/\bmutate(?:<.*?>)?\(\s*`([^`]+)`\s*,\s*\'([A-Z]+)\'/' => 2,
                '/\bocs\(\s*`([^`]+)`/' => 1,
                '/\bocs<[^>]*>\(\s*`([^`]+)`/' => 1,
            ];

            foreach ($patterns as $pattern => $groupCount) {
                if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER) === 0) {
                    continue;
                }

                foreach ($matches as $match) {
                    $found[$relative][] = $groupCount === 2
                        ? ['verb' => $match[2], 'path' => $match[1]]
                        : ['verb' => 'GET', 'path' => $match[1]];
                }
            }
        }

        return $found;
    }

    /**
     * The routes that answer, keyed by normalised path then verb.
     *
     * @return array<string, array<string, true>>
     */
    private function servedRoutes(): array
    {
        $routes = (string) file_get_contents(self::APP_ROOT . '/appinfo/routes.php');

        $served = [];
        if (preg_match_all("/'url'\\s*=>\\s*'([^']+)'\\s*,\\s*'verb'\\s*=>\\s*'([A-Z]+)'/", $routes, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $served[$this->normalise($match[1])][$match[2]] = true;
            }
        }

        return $served;
    }

    /**
     * Strip the query string, the route prefix, and the difference in how the two sides spell a
     * placeholder.
     *
     * Three normalisations, and each exists because the two sides genuinely disagree:
     *
     * - the client spells a path relative to `/apps/pettycash/api/v1` (`ocs()` prepends it) and the
     *   route table spells it from the app root (`/api/v1/...`), so both prefixes are removed;
     * - a placeholder's *name* differs for harmless reasons — the client writes `${params.stage}`
     *   where the route table writes `{stage}` — so both are reduced to a positional token. What
     *   decides whether a route serves a path is the number and position of its parameters, not what
     *   the two files happened to call them; comparing names instead reported a served route as
     *   unserved, which is the same class of false signal this file exists to remove;
     * - a query string is not part of the route, so `/reports/x?a=b` and `/reports/x` are one path.
     */
    private function normalise(string $path): string
    {
        $path = (string) preg_replace('/\?.*$/', '', $path);
        $path = (string) preg_replace('#^/apps/pettycash/api/v1#', '', $path);
        $path = (string) preg_replace('#^/api/v1#', '', $path);
        // Positional: `{evidenceId}`, `${evidenceId}` and `${params.stage}` all become `{}`.
        $path = (string) preg_replace('#\$\{[^}]*\}|\{[^}]*\}#', '{}', $path);

        return $path === '' ? '/' : $path;
    }
}
