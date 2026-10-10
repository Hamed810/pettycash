<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * T383: a query parameter the contract declares must be a parameter the controller binds.
 *
 * ## The defect this exists to stop recurring
 *
 * `contracts/ocs-api.yaml` gave `GET /accounts` an `active` parameter (`type: boolean`) beside `userId`,
 * `page` and `pageSize`. `AccountController::index()` bound `userId`, `page` and `pageSize`. **Nothing
 * rejected `active` and nothing read it**, so a caller sending `?active=false` received the *active*
 * accounts together with a `total` computed from them — an answer indistinguishable from a correct one.
 *
 * This is FR-064's *"MUST NOT be ignored"* failure with no filter endpoint involved: the contract
 * documents a capability, the client generates a typed parameter from that document, and the server
 * silently does something else. Nothing fails. The generated client compiles, the endpoint answers 200,
 * and the only symptom is a user who asked for deactivated accounts and was handed active ones.
 *
 * Writing T383's own required test — *"a test asserts the two cannot drift, which is the property the
 * current state lacks entirely"* — surfaced a **second** instance on the same pass:
 * `GET /configuration/cost-centers` declared `active` and `ConfigurationController::costCentersIndex()`
 * bound only `page` and `pageSize`.
 *
 * ## Why a structural comparison and not a request
 *
 * An unbound parameter has no behaviour to exercise. The honest runtime symptom is a *successful but
 * wrong* answer, so a test asserting the request would pin the defect in place. What can be asserted is
 * the **correspondence** — the parameters the contract declares against the parameters the AppFramework
 * will actually bind — and that is a fact about two texts, exactly as
 * {@see RouteDispatchCompletenessTest}'s route-to-client-path comparison is. Its failure is a build-time
 * failure naming the endpoint and the parameter, which is a far cheaper signal than a user being shown
 * the wrong accounts.
 *
 * ## Parsed as text, deliberately
 *
 * There is no YAML parser available to a unit test here, so the contract is read as text with
 * indentation-scoped patterns — the precedent `ReportFilterVocabularyTest` and `PageBoundsTest` set, and
 * the reason to follow it rather than add a dependency to assert a list of names.
 *
 * The **controller** side uses reflection rather than a source regex. The controllers live in `lib/`, which
 * `tests/bootstrap.php` autoloads, so `ReflectionMethod::getParameters()` answers the question exactly.
 * A regex would have to parse `?string $userId = null, int $page = 1, int $pageSize = 25` and get the
 * defaults and types right by eye, and a signature the parser mis-reads produces a *false pass* — which
 * is the failure mode this file is about.
 */
final class ContractQueryParameterTest extends TestCase
{
    private const APP_ROOT = __DIR__ . '/../../..';

    private const CONTRACT = '/specs/001-pettycash-v1-core/contracts/ocs-api.yaml';

    /**
     * Parameters the AppFramework binds from the **query string**, as opposed to a request body.
     *
     * A body parameter is not query-scoped by definition, so a contract declaring one under
     * `in: query` is the anomaly this file is for; conversely a `GET` with a body is not a thing this
     * contract has, so nothing here needs to distinguish one.
     *
     * `page` and `pageSize` are included rather than exempted. Every list endpoint takes them, and the
     * one place they could drift is the same place: a controller that renames a parameter would break
     * paging silently, exactly as an unbound `active` broke filtering silently.
     */
    public function testEveryQueryParameterTheContractDeclaresIsBoundByItsController(): void
    {
        $routes = $this->getRoutes();
        $unbound = [];
        $checked = 0;

        foreach ($this->contractQueryParameters() as $path => $parameters) {
            foreach ($parameters as $parameter) {
                $action = $routes[$path] ?? null;
                if ($action === null) {
                    // A declared GET with no matching route is a different defect, and
                    // `RouteDispatchCompletenessTest` already owns route-to-client correspondence.
                    // Reported rather than skipped silently, because "no route" would otherwise let a
                    // contract path pass this file without a controller ever being consulted.
                    $unbound[] = sprintf('GET %s declares ?%s but no GET route serves it', $path, $parameter);

                    continue;
                }

                $checked++;
                if (!$this->bindsParameter($action, $parameter)) {
                    $unbound[] = sprintf(
                        'GET %s declares ?%s, but %s does not bind it',
                        $path,
                        $parameter,
                        $action
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $unbound,
            "Every query parameter the contract declares must be a parameter its controller binds.\n"
            . "A declared-but-unbound parameter is not refused — it is silently ignored, so the caller "
            . "receives a successful answer to a question the server never answered. T383 shipped this "
            . "on `GET /accounts?active=` and `GET /configuration/cost-centers?active=`, and neither was "
            . "observable: 200 either way.\n"
            . "Unbound:\n  " . implode("\n  ", $unbound)
        );

        $this->assertGreaterThan(
            0,
            $checked,
            'no declared query parameter reached a controller, so this scan is vacuous'
        );
    }

    /**
     * The scan is only evidence if it actually finds parameters to check.
     *
     * A regex over a YAML document is exactly the shape that rots quietly: rename a parameter's
     * indentation, change `$ref` spelling, or reformat the `parameters:` block and every pattern
     * matches nothing — while the test above passes over the whole contract. `RouteDispatchCompletenessTest`
     * carries a guard for the same reason and on the same file, so this is a precedent rather than a
     * preference.
     */
    public function testTheScanFindsTheQueryParametersItIsMeantToCheck(): void
    {
        $parameters = $this->contractQueryParameters();

        $this->assertArrayHasKey(
            '/sheets',
            $parameters,
            "the scan should reach the sheet list, whose filters are the busiest set of query parameters "
            . 'in the contract'
        );
        $this->assertContains(
            'status',
            $parameters['/sheets'],
            '`GET /sheets` declares a `status` filter; missing it means the query-parameter scan no longer matches'
        );
        $this->assertContains(
            'page',
            $parameters['/sheets'] ?? [],
            'a `$ref` to #/components/parameters/Page must resolve to the name it declares'
        );

        $total = array_sum(array_map('count', $parameters));
        $this->assertGreaterThan(0, $total, 'no query parameter was recognised in the contract at all');
    }

    // ===== contract =====

    /**
     * Every `GET` operation's query parameters, keyed by the contract's path.
     *
     * @return array<string, list<string>>
     */
    private function contractQueryParameters(): array
    {
        $contract = $this->contract();
        $components = $this->sharedParameters();

        $found = [];

        // Paths sit at two spaces and HTTP methods at four, so a path is a two-space line starting with
        // `/` and its `get:` block runs to the next line at four spaces that is not `get:`.
        preg_match_all('/^ {2}(\/[^\s:]*):\n((?:(?!^ {2}\S)[\s\S])*)/m', $contract, $blocks, PREG_SET_ORDER);

        foreach ($blocks as [, $path, $body]) {
            if (preg_match('/^ {4}get:\n((?:(?!^ {4}\S)[\s\S])*)/m', $body, $get) !== 1) {
                continue;
            }

            if (preg_match('/^ {6}parameters:\n((?:(?!^ {6}\S)[\s\S])*)/m', $get[1], $list) !== 1) {
                continue;
            }

            $names = [];
            foreach ($this->parameterEntries($list[1]) as $entry) {
                // `- $ref: '#/components/parameters/Page'` names the shared component, and the parameter's
                // own name is inside that component. Resolving it rather than reading the component name is
                // what keeps a `$ref` equal to the name it declares — a rename inside `components:` is then
                // still checked against the controller.
                if (preg_match("/\\\$ref:\s*'#\/components\/parameters\/(\w+)'/", $entry, $ref) === 1) {
                    $declared = $components[$ref[1]] ?? null;
                    if ($declared !== null) {
                        $names[] = $declared;
                    }

                    continue;
                }

                // `- name: userId` followed by `in: query` on the next line. A parameter in the path or a
                // header is not a query parameter, and `X-PettyCash-Command-ID` is a header on every
                // mutating operation.
                if (preg_match('/^ {8}- name: (\w+)\n(?:^ {10}.*\n)*?^ {10}in: query$/m', $entry, $name) === 1) {
                    $names[] = $name[1];
                }
            }

            $names = array_values(array_unique($names));
            if ($names !== []) {
                $found[$path] = $names;
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * The `components/parameters` block's own declared names, keyed by component name.
     *
     * @return array<string, string>
     */
    private function sharedParameters(): array
    {
        $contract = $this->contract();

        if (preg_match('/^  parameters:\n((?:(?!^ {2}\S)[\s\S])*)/m', $contract, $block) !== 1) {
            return [];
        }

        $names = [];
        // A component is `    Name:` at four spaces and its `name:` at six. Anchored on both, so a
        // `name:` belonging to something nested deeper inside a component cannot be read as its own.
        preg_match_all('/^ {4}(\w+):\n(?:(?!^ {4}\S)[\s\S])*?^ {6}name: (\w+)$/m', $block[1], $matches, PREG_SET_ORDER);

        foreach ($matches as [, $component, $name]) {
            $names[$component] = $name;
        }

        return $names;
    }

    /**
     * One `- …` parameter entry, with the lines that follow it at the same indentation.
     *
     * @return list<string>
     */
    private function parameterEntries(string $block): array
    {
        preg_match_all('/^ {8}- (?:name:|\$ref:)(?:.*)\n(?:^ {10}.*\n)*/m', $block, $matches);

        return $matches[0];
    }

    private function contract(): string
    {
        $path = self::APP_ROOT . self::CONTRACT;
        $contract = is_file($path) ? (string) file_get_contents($path) : '';

        $this->assertNotSame('', $contract, 'contracts/ocs-api.yaml must be readable for this to mean anything');

        return $contract;
    }

    // ===== routes and controllers =====

    /**
     * The `controller#method` each `GET` route dispatches to, keyed by the contract's path spelling.
     *
     * The contract declares `/accounts` where the route table says `/api/v1/accounts`, so the prefix is
     * stripped to make the two comparable — otherwise every path would miss and the scan would report the
     * whole contract as unrouted.
     *
     * @return array<string, string>
     */
    private function getRoutes(): array
    {
        $routes = (string) file_get_contents(self::APP_ROOT . '/appinfo/routes.php');

        $found = [];
        preg_match_all(
            "/'name'\s*=>\s*'([a-zA-Z]+)#(\w+)',\s*'url'\s*=>\s*'([^']+)',\s*'verb'\s*=>\s*'GET'/",
            $routes,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as [, $controller, $method, $url]) {
            $found[preg_replace('#^/api/v1#', '', $url)] = $controller . '::' . $method;
        }

        return $found;
    }

    /**
     * Whether the action's signature binds `$parameter`.
     *
     * **Parsed from source, not reflected, and the reason is concrete.** `tests/bootstrap.php` wires a
     * lightweight container of in-memory doubles and does not load Nextcloud's `OCP` classes, so
     * `class_exists()` on a controller throws `Class "OCP\AppFramework\OCSController" not found` the moment
     * it resolves. That is why {@see RouteDispatchCompletenessTest} scans controller source too.
     *
     * Two rules make the parse safe rather than a source of false passes:
     *
     * - **An unresolvable controller or method fails the test.** The first version of this method returned
     *   `true` when it could not find the class — a "defensive" guard that made the whole scan vacuous,
     *   because the namespace it guessed (`OCA\PettyCash\Lib\Controller`) does not exist, so *every* action
     *   resolved to nothing and every parameter "passed". Re-declaring `active` on `GET /accounts` then left
     *   this file fully green. A scan that cannot see its subject must say so; returning the passing answer is
     *   the one response indistinguishable from a correct result in the output.
     * - **An unreadable parameter list answers "not bound".** The failure direction of a bad parse is
     *   therefore a *false failure*, never a false pass.
     *
     * `$[^)]*` is sufficient because these signatures take scalars and `?array` — no default in any
     * controller action calls a function, so no nested parenthesis can end the list early.
     */
    private function bindsParameter(string $action, string $parameter): bool
    {
        [$controller, $method] = explode('::', $action, 2);
        $file = self::APP_ROOT . '/lib/Controller/' . ucfirst($controller) . 'Controller.php';

        $this->assertFileExists(
            $file,
            "a GET route declares a query parameter and dispatches to '{$action}', but {$file} does not exist. "
            . 'A scan that cannot read its subject would otherwise report every parameter as bound.'
        );

        $source = (string) file_get_contents($file);
        $this->assertSame(
            1,
            preg_match('/public function ' . preg_quote($method, '/') . '\s*\(([^)]*)\)/', $source, $matches),
            "the GET route names {$controller}Controller::{$method}(), whose signature this scan could not read. "
            . 'A controller action that stops being parseable must fail here rather than be reported as binding nothing.'
        );

        preg_match_all('/\$(\w+)/', $matches[1], $declared);

        return in_array($parameter, $declared[1], true);
    }
}