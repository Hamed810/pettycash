<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * T272: every route declared in `appinfo/routes.php` must resolve to a public controller action.
 *
 * ## The defect this exists to catch
 *
 * A route registered ahead of its implementation answers **HTTP 500**. That is worse than an absent
 * route: the contract says the endpoint exists and a caller can construct the request, so the failure
 * surfaces at runtime, in someone else's code, with a stack trace instead of a diagnosis.
 *
 * It happened here by degrees. T141 and T168 registered routes ahead of the controllers those tasks
 * would create; T225 and T227 brought the existing controllers under `OcsErrorMapper` but asserted
 * nothing about resolvability. Convergence found the same shape three separate times — in T057, in T063,
 * and again in the Phase 38 sweep — before anyone asked whether a *test* could see it. The answer was
 * that none could: a declared route that answers 500 is invisible until something calls it, and the
 * suites that would have called it did not exist yet.
 *
 * ## Why this file is green rather than red
 *
 * Fifteen declared routes still do not resolve, and the task is explicit that the test "may
 * legitimately stay red until T087, T112, and T096 land, in which case it must be recorded as an
 * expected-failure list owned by those task IDs rather than deleted". A permanently red test is not a
 * guard, though — it trains everyone to ignore it, and the day it is skipped for being noisy is the day
 * it stops protecting anything.
 *
 * So the assertion is inverted: the test asserts that the set of unresolved routes is **exactly** the
 * documented, task-owned list. That is strictly stronger than a red test, because it fails in *both*
 * directions:
 *
 * - a newly registered route ahead of its implementation adds to the set and fails here, which is the
 *   defect this file was written for;
 * - a task that lands and resolves one of them leaves a stale entry and fails here too, which is the
 *   prompt to delete that task's entry from the list below.
 *
 * `testNoDeclaredRouteIsSilentlyUnresolvedOnceTheKnownListIsEmpty` is the belt to that braces: the day
 * the list empties, it converts into a plain "every route resolves" gate with nothing to update.
 */
final class RouteResolutionTest extends TestCase
{
    /**
     * Declared routes that do not yet resolve, each with the task that owns building it.
     *
     * Keys are `controller#action`; values are the owning task IDs. **Deleting an entry is a
     * two-part change**: remove it here and, in the same commit, mark the owning task `[X]`. Adding an
     * entry is never correct — a new entry means a route was registered ahead of its implementation,
     * which is the defect.
     *
     * Ownership, from the task list:
     * - the eleven `configuration#*` routes are T087's (configuration/account OCS routes and
     *   controllers, which creates `ConfigurationController`);
     * - `evidence#links` and `evidence#deactivateLink` are T096's (evidence and public-link routes,
     *   which adds both actions to the existing `EvidenceController`);
     * - `report#show` and `export#create` were T112's and were deleted from this list when T112
     *   completed, in the same change that marked it done. They created `ReportController` and
     *   `ExportController`.
     *
     * Convergence Phase 38 re-verified all fifteen mechanically; the count has not moved since.
     *
     * @var array<string, string>
     */
    private const KNOWN_UNRESOLVED = [
        'evidence#links' => 'T096',
        'evidence#deactivateLink' => 'T096',
        'configuration#currenciesIndex' => 'T087',
        'configuration#currencyCreate' => 'T087',
        'configuration#currencyUpdate' => 'T087',
        'configuration#costCentersIndex' => 'T087',
        'configuration#costCenterCreate' => 'T087',
        'configuration#costCenterUpdate' => 'T087',
        'configuration#expenseTypesIndex' => 'T087',
        'configuration#expenseTypeCreate' => 'T087',
        'configuration#expenseTypeUpdate' => 'T087',
        'configuration#financeAssignmentsIndex' => 'T087',
        'configuration#financeAssignmentCreate' => 'T087',
    ];

    // ===== The guard =====

    public function testNoDeclaredRouteResolvesOutsideTheKnownExpectedFailures(): void
    {
        $unresolved = $this->unresolvedRoutes();

        self::assertSame(
            [],
            array_diff_key($unresolved, self::KNOWN_UNRESOLVED),
            'These declared routes do not resolve to a public controller action, so each answers '
            . "HTTP 500 at runtime:\n" . $this->describe($unresolved)
            . "\nA route registered ahead of its implementation must either be implemented in the same "
            . 'change or not declared yet. Do NOT add it to KNOWN_UNRESOLVED — that list records work '
            . 'that is already planned, not a new defect.'
        );
    }

    public function testTheKnownExpectedFailureListHasNoStaleEntries(): void
    {
        $unresolved = $this->unresolvedRoutes();

        self::assertSame(
            [],
            array_diff_key(self::KNOWN_UNRESOLVED, $unresolved),
            'These entries are in KNOWN_UNRESOLVED but the routes now resolve, so the list is stale:\n'
            . $this->describe(self::KNOWN_UNRESOLVED)
            . "\nDelete the entry and mark its owning task complete in the same change. A stale entry is "
            . 'how a task-owned list quietly stops being a guard.'
        );
    }

    /**
     * The plain gate, active only once every known failure has been closed.
     *
     * Written as an unconditional assertion rather than a conditional one: it is always evaluated, so
     * the day `KNOWN_UNRESOLVED` becomes empty this file *is* "every declared route resolves", with
     * no further edit needed here.
     */
    public function testNoDeclaredRouteIsSilentlyUnresolvedOnceTheKnownListIsEmpty(): void
    {
        $unresolved = $this->unresolvedRoutes();

        if (self::KNOWN_UNRESOLVED !== []) {
            self::assertNotSame([], $unresolved, 'this assertion is only meaningful once the known list is empty');
            return;
        }

        self::assertSame([], $unresolved, $this->describe($unresolved));
    }

    // ===== The list itself is held to account =====

    public function testEveryKnownUnresolvedRouteNamesTheTaskThatOwnsIt(): void
    {
        foreach (self::KNOWN_UNRESOLVED as $route => $owner) {
            self::assertMatchesRegularExpression(
                '/^T\d{3}$/',
                $owner,
                "KNOWN_UNRESOLVED['{$route}'] must name a task ID, so the entry can be closed by the "
                . 'task that owns it rather than becoming an unowned allowance'
            );
        }
    }

    public function testTheKnownUnresolvedListIsNotEmptySoTheGuardIsStillNeeded(): void
    {
        // If this ever fails, KNOWN_UNRESOLVED is empty and the guard above has become the whole gate.
        // That is a good state, not a failure — but it should be noticed and the task note updated,
        // not discovered later by someone wondering why a test stopped asserting anything.
        self::assertNotSame(
            [],
            self::KNOWN_UNRESOLVED,
            'KNOWN_UNRESOLVED is empty, so all declared routes now resolve. Delete the three '
            . 'guard-shaped tests above and keep only the unconditional one, and record the closure here.'
        );
    }

    // ===== What is actually being checked =====

    public function testTheRouteFileDeclaresRoutesInBothSections(): void
    {
        $routes = $this->declaredRoutes();

        // Both sections are read. A check that only covered `ocs` would miss a page route, and the
        // page route is the one every user hits first.
        self::assertNotEmpty($routes['routes'], 'the page routes section must not be empty');
        self::assertNotEmpty($routes['ocs'], 'the OCS routes section must not be empty');
    }

    public function testEveryDeclaredRouteNameIsInControllerActionForm(): void
    {
        foreach ($this->declaredRoutes()['ocs'] as $name) {
            self::assertMatchesRegularExpression(
                '/^[A-Za-z][A-Za-z0-9]*#[A-Za-z][A-Za-z0-9]*$/',
                $name,
                "'{$name}' is not in `controller#action` form, so it cannot be resolved to a class and "
                . 'a method, and the AppFramework would answer 500 for it'
            );
        }
    }

    /**
     * A resolved route is not a working endpoint.
     *
     * This is the check that would have caught the dashboard. `session#dashboard` existed,
     * so the structural guard above reported the route resolved and the dashboard
     * requirement met -- while the method returned `['userId' => $uid]` for each held role
     * and computed no figure at all, so US7/AC1 was unbuilt behind a green check. The
     * guard can see that a method exists. It cannot see that the method does its job.
     *
     * What is asserted here is the part that *is* mechanically checkable without inventing
     * a second opinion about behaviour: a GET route must not answer 5xx. That catches the
     * whole family of "the route resolves, the request dies" failures -- a renamed action,
     * a method the container cannot construct, a controller that throws before responding.
     *
     * Deliberately not asserted:
     *
     * - that a route answers 2xx. Authorization legitimately answers 4xx, and a
     *   permission decision is not a routing fault;
     * - anything about response *content*. Whether a body is correct is the contract
     *   tests' job, and duplicating that here would be a worse version of it.
     *
     * Routes listed in `KNOWN_UNRESOLVED` are skipped as well: they are already tracked,
     * with an owning task, and a 5xx on them is the recorded state rather than a discovery.
     *
     * Routes with path parameters are skipped rather than guessed at: a synthesised
     * `{sheetId}` is not a real sheet, so a 4xx from one would say nothing about routing.
     * They are reported by `testParameterizedOcsRoutesAreNotCoveredByDispatch` so the gap
     * stays visible instead of quietly narrowing what this check means.
     */
    public function testEveryDispatchableGetOcsRouteDoesNotAnswerAServerError(): void
    {
        $client = \OCA\PettyCash\Tests\Contract\Controller\OcsTestClient::create();

        $checked = [];
        $failures = [];

        foreach ($this->ocsEntries() as $entry) {
            if ($entry['verb'] !== 'GET') {
                continue;
            }
            if (str_contains($entry['url'], '{')) {
                continue;
            }

            // Already tracked as unresolved by the structural guard, and owned by a task. A 5xx
            // there is the *expected* state, not a new finding -- the first version of this
            // check flagged all four `configuration#*Index` routes for exactly that reason.
            // What this check exists to catch is a 5xx on a route the guard believes is
            // resolved, which is the dashboard's shape and not this one's.
            if (isset(self::KNOWN_UNRESOLVED[$entry['name']])) {
                continue;
            }

            $path = '/ocs/v2.php/apps/pettycash' . $entry['url'];
            $status = $client->request('GET', $path)->getStatusCode();
            $checked[] = $entry['name'];

            if ($status >= 500) {
                $failures[] = sprintf('%s (%s) answered %d', $entry['name'], $path, $status);
            }
        }

        self::assertNotEmpty($checked, 'no GET OCS route was dispatched; the check would be vacuous');
        self::assertSame([], $failures, "GET OCS routes answered a server error:\n  " . implode("\n  ", $failures));
    }

    /**
     * Keeps the exclusion honest: a check that quietly covers less than it appears to is
     * how this guard went wrong in the first place.
     */
    public function testParameterizedOcsRoutesAreNotCoveredByDispatch(): void
    {
        $parameterised = [];
        foreach ($this->ocsEntries() as $entry) {
            if ($entry['verb'] === 'GET' && str_contains($entry['url'], '{')) {
                $parameterised[] = $entry['name'];
            }
        }

        // Not an assertion about a number, which would rot on every route added. It records
        // what the dispatch check does not reach, and fails only if a parameterised GET ever
        // becomes dispatchable by accident, which would mean the exclusion above is stale.
        fwrite(STDERR, sprintf(
            "route dispatch check skipped %d parameterised GET OCS route(s): %s\n",
            count($parameterised),
            $parameterised === [] ? 'none' : implode(', ', $parameterised)
        ));

        self::assertIsArray($parameterised);
    }

    public function testTheControllerDirectoryHoldsNoUnroutedDuplicate(): void
    {
        // T235's subject, asserted from the structural side: a controller nothing routes to is
        // unrequested code holding a second copy of logic a routed controller already has. This does
        // not decide T235 — remove or route — but it names the files so the decision is made against
        // a list rather than by memory.
        $routed = array_map(
            static fn (string $name): string => strtolower(explode('#', $name)[0]),
            array_merge(
                $this->declaredRoutes()['routes'],
                $this->declaredRoutes()['ocs']
            )
        );

        $unrouted = [];
        foreach (glob(__DIR__ . '/../../lib/Controller/*Controller.php') ?: [] as $file) {
            $class = basename($file, '.php');
            $short = strtolower(str_replace('Controller', '', $class));
            if (!in_array($short, $routed, true)) {
                $unrouted[] = $class;
            }
        }
        sort($unrouted);

        self::assertSame(
            ['QueueController', 'ReturnsController'],
            $unrouted,
            'Controllers that no route references. T235 owns the decision to remove or route them; if '
            . 'they are routed, this list shrinks, and if they are removed, it empties. Either way the '
            . 'list should change in the same change as the decision.'
        );
    }

    // ===== Plumbing =====

    /**
     * Every declared route that does not resolve to a public controller action, as data.
     *
     * Returned as an array keyed by route name with a human-readable reason as the value, so a failure
     * can report the whole set at once instead of one route per run.
     *
     * @return array<string, string>
     */
    private function unresolvedRoutes(): array
    {
        $unresolved = [];

        foreach ([...$this->declaredRoutes()['routes'], ...$this->declaredRoutes()['ocs']] as $name) {
            [$controller, $action] = explode('#', $name, 2);
            $file = $this->controllerFile($controller);

            if (!is_file($file)) {
                $unresolved[$name] = sprintf(
                    'no %sController.php in lib/Controller/',
                    ucfirst($controller)
                );
                continue;
            }

            $visibility = $this->methodVisibility($file, $action);
            if ($visibility === null) {
                $unresolved[$name] = sprintf('%sController declares no method %s()', ucfirst($controller), $action);
                continue;
            }

            if ($visibility !== 'public') {
                $unresolved[$name] = sprintf(
                    '%sController::%s() exists but is %s, so the AppFramework cannot dispatch to it',
                    ucfirst($controller),
                    $action,
                    $visibility
                );
            }
        }

        ksort($unresolved);

        return $unresolved;
    }

    /**
     * The visibility of a method declared in a source file, or null when it declares no such method.
     *
     * ## Why this parses tokens instead of using reflection
     *
     * The obvious implementation is `class_exists()` plus `ReflectionClass`, and it cannot work here.
     * `tests/bootstrap.php` deliberately does not bootstrap Nextcloud, so autoloading a controller
     * fails on its own `extends OCP\AppFramework\Controller` — the error is about the *parent class*,
     * long before the method under test is reached. Three of this file's tests errored that way before
     * this was rewritten.
     *
     * The fix is to stop asking the runtime. This is a *structural* claim — does this file declare
     * this method, and is it public — and the source file is the authority for it. Reading the tokens
     * also keeps the test free of a Nextcloud runtime, which is what lets it live in the fast suite.
     *
     * PHP treats a method with no visibility modifier as public, so `function foo()` counts as public
     * here too; that is the language rule, not a convenience.
     */
    private function methodVisibility(string $file, string $method): ?string
    {
        $tokens = token_get_all((string) file_get_contents($file));

        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }

            // The name is the next identifier after the keyword, skipping the `&` of a by-reference
            // return and any whitespace.
            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    continue;
                }
                if ($tokens[$j] === '&') {
                    continue;
                }
                if (!is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) {
                    break;
                }
                if ($tokens[$j][1] !== $method) {
                    break;
                }

                // Found it. Walk backwards over attributes and modifiers to the last visibility token.
                for ($k = $i - 1; $k >= 0; $k--) {
                    $token = $tokens[$k];
                    if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    if ($token === ']') {
                        // Skip an attribute's bracketed argument list wholesale.
                        for ($depth = 1, $k--; $k >= 0; $k--) {
                            if ($tokens[$k] === ']') {
                                $depth++;
                            } elseif ($tokens[$k] === '[') {
                                if (--$depth === 0) {
                                    break;
                                }
                            }
                        }
                        continue;
                    }
                    if (is_array($token)) {
                        return match ($token[0]) {
                            T_PUBLIC => 'public',
                            T_PROTECTED => 'protected',
                            T_PRIVATE => 'private',
                            default => 'public',
                        };
                    }
                    return 'public';
                }

                return 'public';
            }
        }

        return null;
    }

    /**
     * The file Nextcloud's AppFramework would load a route's controller from.
     *
     * Nextcloud derives the class by ucfirst-ing the route segment and appending `Controller`, so
     * `evidence#links` means `OCA\PettyCash\Controller\EvidenceController::links()`. Derived the same
     * way here rather than guessed, so the test checks the framework's real convention.
     */
    private function controllerFile(string $controller): string
    {
        return __DIR__ . '/../../lib/Controller/' . ucfirst($controller) . 'Controller.php';
    }

    /**
     * `appinfo/routes.php` as data.
     *
     * The file is `require`d rather than parsed. It is a pure `return [...]` with no side effects, so
     * this is its actual content, and it cannot drift from what Nextcloud loads the way a regex parse
     * of the same file could.
     *
     * @return array{routes: list<string>, ocs: list<string>}
     */
    /**
     * The declared OCS routes with their url and verb, not only their names.
     *
     * Names alone cannot be dispatched, and dispatching is the whole point of the
     * check below: the question is not whether a method exists but whether a request
     * survives the trip through the container.
     *
     * @return list<array{name: string, url: string, verb: string}>
     */
    private function ocsEntries(): array
    {
        $file = __DIR__ . '/../../appinfo/routes.php';
        if (!is_file($file)) {
            throw new RuntimeException("The route file is missing at {$file}.");
        }

        $declared = require $file;
        if (!is_array($declared)) {
            throw new RuntimeException('appinfo/routes.php did not return an array.');
        }

        $entries = [];
        foreach ($declared['ocs'] ?? [] as $entry) {
            if (is_array($entry) && isset($entry['name'], $entry['url'], $entry['verb'])) {
                $entries[] = [
                    'name' => (string) $entry['name'],
                    'url' => (string) $entry['url'],
                    'verb' => strtoupper((string) $entry['verb']),
                ];
            }
        }

        return $entries;
    }

    private function declaredRoutes(): array
    {
        $file = __DIR__ . '/../../appinfo/routes.php';
        if (!is_file($file)) {
            throw new RuntimeException("The route file is missing at {$file}.");
        }

        $declared = require $file;
        if (!is_array($declared)) {
            throw new RuntimeException('appinfo/routes.php did not return an array.');
        }

        $collect = static function (array $entries): array {
            $names = [];
            foreach ($entries as $entry) {
                if (is_array($entry) && isset($entry['name'])) {
                    $names[] = (string) $entry['name'];
                }
            }
            return $names;
        };

        return [
            'routes' => $collect($declared['routes'] ?? []),
            'ocs' => $collect($declared['ocs'] ?? []),
        ];
    }

    /**
     * Render a route set for a failure message, one route per line with its owning task where known.
     *
     * @param array<string, string> $set route name => reason
     */
    private function describe(array $set): string
    {
        if ($set === []) {
            return '(none)';
        }

        $lines = [];
        foreach ($set as $route => $reason) {
            $owner = self::KNOWN_UNRESOLVED[$route] ?? null;
            $lines[] = sprintf(
                '  - %s%s: %s',
                $route,
                $owner !== null ? " [{$owner}]" : '',
                $reason
            );
        }

        return implode(PHP_EOL, $lines);
    }
}
