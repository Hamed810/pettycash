<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Application\Support;

use OCA\PettyCash\Application\Support\FaultContextFactory;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * FR-087: what a technical fault records.
 *
 * ## Why the rule lives in its own class
 *
 * FR-087 (clarified 2026-10-08) requires five details on every `5xx`. Before T418, `TechnicalLogger`
 * recorded two — `commandId` and the throwable — and nothing owned the other three, so an implementation
 * could satisfy every requirement while logging nothing an operator could act on. FR-073 then requires that
 * `5xx` rate to be *measured* at release readiness, and a figure nobody can diagnose is not a readiness
 * signal.
 *
 * The gathering was first written inside `OcsErrorMapper`. **That class cannot be tested at all** — not
 * because it is hard, but because `tests/bootstrap.php` does not bootstrap Nextcloud, so *no* `OCP\*`
 * class is loadable in a PHPUnit run (verified with a class-existence probe, not assumed). Any test that
 * tried to construct the mapper failed with `UnknownTypeException: Class or interface "OCP\IRequest" does
 * not exist`, and the 4xx half — "a correct refusal writes no technical entry" — is a negative assertion
 * about a side effect that no HTTP test can make either.
 *
 * So the decision moved to `FaultContextFactory`, which has no framework dependency, and the platform
 * lookup moved behind the `RequestRoute` port. That is the same shape `RequestTimeZone` already uses for
 * the instance time zone, and it is what turns FR-087 into something with executable assertions.
 *
 * @see \OCA\PettyCash\Tests\Unit\Infrastructure\TechnicalLoggerTest for the logger's own surface
 */
final class FaultContextFactoryTest extends TestCase
{
    // ===== the details FR-087 requires =====

    /**
     * Actor and route, the two that are knowable on **every** fault path.
     *
     * Asserted as exact values rather than as "the keys exist", because a factory that emitted
     * `'actor' => ''` would satisfy a key-existence check while telling an operator nothing.
     */
    public function testItReportsTheActorAndTheRoute(): void
    {
        $context = $this->factory('purchaser-1', 'GET /apps/pettycash/api/v1/sheets/abc')->build();

        self::assertSame('purchaser-1', $context['actor'], 'FR-087: who hit it');
        self::assertSame(
            'GET /apps/pettycash/api/v1/sheets/abc',
            $context['route'],
            'FR-087: where it happened'
        );
    }

    /**
     * FR-087's "affected public identifier **where one is known**" — the channel works.
     */
    public function testItForwardsAPublicIdentifierWhenTheCallerKnowsOne(): void
    {
        $context = $this->factory('purchaser-1', 'DELETE /x')->build(['publicId' => 'sheet-abc']);

        self::assertSame('sheet-abc', $context['publicId']);
    }

    /**
     * …and the same assertion in the negative, which is the one that matters.
     *
     * Most fault paths genuinely do not know which record was addressed. A factory that invented a value,
     * or scraped one out of an exception message, would send an operator to a record that was never
     * involved — a wrong answer is worse than a missing one, and FR-087's qualifier exists for that reason.
     */
    public function testItInventsNoPublicIdentifierWhenNoneIsKnown(): void
    {
        $context = $this->factory('purchaser-1', 'GET /x')->build();

        self::assertArrayNotHasKey(
            'publicId',
            $context,
            'an unknown public identifier must be absent — a placeholder would be a fabrication'
        );
    }

    // ===== the three ways a detail can be unavailable, and why each is handled =====

    /**
     * A background or CLI fault has no actor. Absent, not blank.
     */
    public function testAnAbsentActorIsOmittedRatherThanRecordedAsEmpty(): void
    {
        $context = $this->factory('', 'POST /background')->build();

        self::assertArrayNotHasKey(
            'actor',
            $context,
            'an empty uid is "no actor", not "an actor whose name is blank"'
        );
        self::assertSame('POST /background', $context['route'], 'the route is unaffected');
    }

    /**
     * A collaborator that throws must not turn a diagnosable fault into an undiagnosed one.
     *
     * This runs on the failure path, while a `500` is already being recorded. If the current user were
     * unavailable and the factory propagated, the caller would receive a *second*, less diagnosable
     * exception in place of the first — and the original fault would never be logged at all.
     */
    public function testAnUnavailableActorOrRouteIsSwallowedRatherThanRaised(): void
    {
        $explodingRoute = new class implements \OCA\PettyCash\Application\Port\RequestRoute {
            public function describe(): string
            {
                throw new RuntimeException('no request in scope');
            }
        };

        $explodingUser = new class implements \OCA\PettyCash\Application\Port\CurrentUser {
            public function uid(): string
            {
                throw new RuntimeException('no user session');
            }
        };

        $context = (new FaultContextFactory($explodingUser, $explodingRoute))->build();

        self::assertArrayNotHasKey('actor', $context, 'an unavailable actor is omitted, not raised');
        self::assertArrayNotHasKey('route', $context, 'an unavailable route is omitted, not raised');
    }

    /**
     * A route provider that legitimately has nothing to say returns `''`, not a throw.
     *
     * Asserted separately from the throwing case because they are different contracts: one is "no request
     * here", the other is "this collaborator broke". Both end as an omitted key, and the distinction is
     * recorded here so the `''` branch is not mistaken for dead code.
     */
    public function testAnEmptyRouteIsTreatedAsUnknown(): void
    {
        $context = $this->factory('purchaser-1', '')->build();

        self::assertArrayNotHasKey('route', $context);
        self::assertSame('purchaser-1', $context['actor']);
    }

    // ===== merge precedence =====

    /**
     * The gathered actor and route are facts about the request; a caller's value cannot overwrite them.
     *
     * PHP's `+` keeps the left operand, so putting the gathered values first makes them win. This test
     * exists because that is a one-character-ordering decision with a real consequence: reversed, any
     * caller could attribute a fault to a different user in the log.
     */
    public function testGatheredValuesWinOverCallerSuppliedOnes(): void
    {
        $context = $this->factory('purchaser-1', 'GET /real')->build([
            'actor' => 'someone-else',
            'route' => 'POST /fabricated',
        ]);

        self::assertSame('purchaser-1', $context['actor'], 'the acting user comes from the request');
        self::assertSame('GET /real', $context['route'], 'the route comes from the request');
    }

    /**
     * An unrelated caller key still gets through — the factory must not be a closed vocabulary.
     */
    public function testAnUnrelatedCallerKeySurvives(): void
    {
        $context = $this->factory('purchaser-1', 'GET /x')->build(['accountDbId' => 7]);

        self::assertSame(7, $context['accountDbId']);
    }

    private function factory(string $uid, string $route): FaultContextFactory
    {
        return new FaultContextFactory(
            new InMemoryCurrentUser($uid),
            new class ($route) implements \OCA\PettyCash\Application\Port\RequestRoute {
                public function __construct(private readonly string $route) {}

                public function describe(): string
                {
                    return $this->route;
                }
            },
        );
    }
}