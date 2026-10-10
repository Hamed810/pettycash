<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Domain\Shared;

use InvalidArgumentException;
use OCA\PettyCash\Application\Reporting\ReportFilter;
use OCA\PettyCash\Domain\Shared\PageBounds;
use PHPUnit\Framework\TestCase;

/**
 * T319: the shared `page`/`pageSize` bounds every paged OCS endpoint declares.
 *
 * ## Why a test on a value object this small
 *
 * Because the defect it prevents was not a coding error — it was a decision made twice, in two
 * places, differently. T106 records the report surface returning `items`/`offset` where the contract
 * says `PageMeta`; T319 records the audit page declaring `Page`/`pageSize` and answering with a bare
 * array that used neither. Both were invisible because each endpoint's own test asserted only what
 * that endpoint returned, never what the contract's shared parameters require.
 *
 * `PageBounds` exists so the bounds live in one place. These tests pin two things: that it obeys
 * `contracts/ocs-api.yaml`, and — the part that actually prevents a second drift — that it agrees
 * with the `ReportFilter` it was extracted alongside.
 */
final class PageBoundsTest extends TestCase
{
    /**
     * The contract's own numbers, asserted as data rather than restated in prose.
     *
     * If `ocs-api.yaml` changes `maximum` or `default`, this fails and says so, rather than the code
     * and the artifact drifting apart unnoticed — which is the T106 failure mode.
     */
    public function testTheBoundsAreTheContractsOwn(): void
    {
        $contract = (string) file_get_contents(
            dirname(__DIR__, 4) . '/specs/001-pettycash-v1-core/contracts/ocs-api.yaml'
        );
        self::assertNotSame('', $contract, 'contracts/ocs-api.yaml must be readable for this to mean anything');

        self::assertMatchesRegularExpression(
            '/^ {4}PageSize:\n(?: {6}.*\n)*? {6}schema: \{ type: integer, minimum: 1, maximum: '
            . PageBounds::MAX_PAGE_SIZE . ', default: ' . PageBounds::DEFAULT_PAGE_SIZE . ' \}$/m',
            $contract,
            'PageBounds::MAX_PAGE_SIZE and DEFAULT_PAGE_SIZE must be the contract\'s PageSize parameter, '
            . 'verbatim. An endpoint bounded by its own idea of the maximum is a client refusing a '
            . 'request the contract permits'
        );

        self::assertSame(
            1,
            PageBounds::DEFAULT_PAGE,
            'Page.default is 1; a different default would silently return a different window than the '
            . 'contract describes for a request that names no page'
        );
    }

    public function testAbsentParametersFallBackToTheContractDefaults(): void
    {
        $bounds = new PageBounds();

        self::assertSame(PageBounds::DEFAULT_PAGE, $bounds->page);
        self::assertSame(PageBounds::DEFAULT_PAGE_SIZE, $bounds->pageSize);
        self::assertSame(0, $bounds->offset, 'the first page starts at the beginning');
    }

    /**
     * Clamping, not refusing, above the maximum.
     *
     * Asserted separately from the refusal cases because the two directions are different decisions.
     * Refusing a large page size would look defensible and would break a client legitimately asking
     * for the contract's own maximum.
     */
    public function testAPageSizeBeyondTheMaximumIsClampedDown(): void
    {
        $bounds = new PageBounds(1, 5000);

        self::assertSame(
            PageBounds::MAX_PAGE_SIZE,
            $bounds->pageSize,
            'the contract caps the page size at 100, so a request for 5000 is bounded down to it'
        );
    }

    public function testExactlyTheMaximumIsAcceptedRatherThanClamped(): void
    {
        $bounds = new PageBounds(1, PageBounds::MAX_PAGE_SIZE);

        self::assertSame(PageBounds::MAX_PAGE_SIZE, $bounds->pageSize);
    }

    public function testAPageSizeBelowTheMinimumIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PageBounds(1, 0);
    }

    public function testAPageBelowTheMinimumIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PageBounds(0, 25);
    }

    public function testANegativePageIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PageBounds(-1, 25);
    }

    /**
     * The offset has to follow the *clamped* page size, not the requested one.
     *
     * This is the subtle one. `page=2&pageSize=5000` clamps to 100, so the second page must start at
     * offset 100. Computing the offset from the requested size instead puts it at 5000 — past the end
     * of almost every result set, so the endpoint answers 200 with an empty page and a `total` that
     * says there is more. The client concludes the history ended.
     */
    public function testTheOffsetFollowsTheClampedPageSizeRatherThanTheRequestedOne(): void
    {
        $bounds = new PageBounds(2, 5000);

        self::assertSame(
            PageBounds::MAX_PAGE_SIZE,
            $bounds->offset,
            'a clamped page size must also drive the offset, or page 2 of a large-page request starts '
            . 'past the end of the data and reads as an empty history'
        );
    }

    public function testTheOffsetAdvancesByOnePage(): void
    {
        self::assertSame(0, (new PageBounds(1, 25))->offset);
        self::assertSame(25, (new PageBounds(2, 25))->offset);
        self::assertSame(75, (new PageBounds(4, 25))->offset);
    }

    /**
     * `PageMeta`'s three members, with `total` passed through rather than derived.
     *
     * `total` is a parameter because it counts the whole scoped set, which only the caller knows.
     * Deriving it from the page is the trap: the response still carries all four required keys and
     * still answers 200, while telling a paginated client the history ends at the end of page 1.
     */
    public function testPageMetaCarriesTheThreeRequiredMembers(): void
    {
        $meta = (new PageBounds(3, 10))->pageMeta(57);

        self::assertSame(
            ['page' => 3, 'pageSize' => 10, 'total' => 57],
            $meta,
            'PageMeta requires page, pageSize and total; `total` must be the count the caller supplied, '
            . 'not the length of the page'
        );
    }

    /**
     * The two bounds implementations must not drift.
     *
     * `ReportFilter` was left in place because it carries a dozen report-specific filters that mean
     * nothing elsewhere, so the audit endpoint cannot reach into it — but its paging numbers are the
     * same contract numbers. These assertions are what stop the extraction from becoming a fork: if
     * one side's maximum moves, this fails and names the disagreement, instead of two endpoints
     * quietly answering with different limits.
     */
    public function testTheSharedBoundsAgreeWithTheReportFiltersOwn(): void
    {
        self::assertSame(
            ReportFilter::MAX_PAGE_SIZE,
            PageBounds::MAX_PAGE_SIZE,
            'ReportFilter and PageBounds are two implementations of the same contract parameter. If '
            . 'they disagree, the report and audit endpoints bound the same request differently'
        );
        self::assertSame(ReportFilter::DEFAULT_PAGE_SIZE, PageBounds::DEFAULT_PAGE_SIZE);
    }

    /**
     * Both must refuse a sub-minimum request for the same reason.
     *
     * Checked behaviourally through both types rather than by comparing the constants, so a change
     * that made one refuse and the other clamp fails here even though every numeric bound still
     * matched.
     */
    public function testBothImplementationsRefuseTheSameOutOfRangeRequests(): void
    {
        foreach ([[0, 25], [1, 0], [-1, 25]] as [$page, $pageSize]) {
            $boundsRefused = false;
            try {
                new PageBounds($page, $pageSize);
            } catch (InvalidArgumentException) {
                $boundsRefused = true;
            }

            $filterRefused = false;
            try {
                new ReportFilter($page, $pageSize);
            } catch (InvalidArgumentException) {
                $filterRefused = true;
            }

            self::assertTrue(
                $boundsRefused,
                "PageBounds must refuse page={$page} pageSize={$pageSize}"
            );
            self::assertSame(
                $boundsRefused,
                $filterRefused,
                "PageBounds and ReportFilter disagree about page={$page} pageSize={$pageSize}. One "
                . 'endpoint would answer 200 and the other 422 for the identical request'
            );
        }
    }

    /**
     * A negative page size is refused, not silently treated as the default.
     *
     * Worth its own assertion because `?? self::DEFAULT_PAGE_SIZE` would catch `null` and `0` alike
     * to anyone who read the operator quickly — and a negative value reaching `setMaxResults()` is a
     * database error rather than a client error.
     */
    public function testANegativePageSizeIsRefusedRatherThanDefaulted(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PageBounds(1, -5);
    }
}
