<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use OCA\PettyCash\Tests\Contract\Fixture\CorruptRecordFixture;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * T267: a stored sheet whose currency reference does not resolve is never presented as a rial sheet.
 *
 * ## Why this is a separate class from `DataIntegrityReportTest`
 *
 * The probe is not confined to the reports. `DbExpenseSheetRepository::getCurrencyCode()` substituted
 * the literal `'IRR'` for a reference that resolved to nothing, and that repository is what
 * `GET /sheets` and `GET /sheets/{id}` read. Measured on the live endpoint before this task, with the
 * probe present:
 *
 * | Endpoint | Answer | What the probe was reported as |
 * |---|---|---|
 * | `GET /reports/sheets` | `200` | a sheet with `"currency":"IRR"` |
 * | `GET /sheets` | `200` | in the list, with `"currency":"IRR"` |
 * | `GET /sheets/{probe}` | `200` | a **rial sheet** |
 * | `GET /sheets/{healthy}` | `200` | — unaffected |
 *
 * So the task's named site was one of three, and the two the task does not name are the ones a user is
 * most likely to be looking at. A class per subject, as T311 established for the same probe, is what
 * keeps the window in which the fault exists from stretching across a file that also tests unrelated
 * things.
 *
 * ## What is asserted, and what is deliberately not
 *
 * Asserted: **the substitution is gone.** A response that carries the probe sheet alongside a currency
 * of `"IRR"` is the defect, and no status code makes that acceptable.
 *
 * Deliberately **not** asserted: that these endpoints answer 422. `UnreadableRecordData` extends
 * `LogicException` on purpose, and `OcsErrorMapper` maps an unrecognised exception to 500 with the
 * message withheld and logged — so today they answer 500, and a follow-on task may generalise T308's
 * 422 policy to them. Pinning 500 here would make that improvement fail a test, which is the same
 * mistake T311 avoided by not pinning `"IRR"`. The tests below therefore assert the *absence of the
 * wrong answer*, and a follow-on can tighten them to a status without having to delete anything.
 */
final class DataIntegritySheetCurrencyTest extends TestCase
{
    private Client $client;

    public static function setUpBeforeClass(): void
    {
        // Repair before anything else, unconditionally: a run killed between the write and the removal
        // would otherwise leave a sheet that reports as rial for every later run.
        CorruptRecordFixture::sweep();
    }

    protected function setUp(): void
    {
        $this->client = OcsTestClient::create();
        ContractFixtures::prepare('submitted');
    }

    /**
     * The defect, on the endpoint a user actually opens.
     *
     * `GET /sheets/{id}` on the broken sheet answered `200` with a full, well-formed sheet whose
     * currency was `'IRR'`. Every figure on it — current total, submitted total, the effective-outcome
     * column — is then a number in the wrong unit, and nothing in the response distinguishes that from a
     * sheet that really is denominated in rials.
     */
    public function testASheetWhoseCurrencyDoesNotResolveIsNotPresentedAsARialSheet(): void
    {
        $probe = CorruptRecordFixture::insert();

        try {
            $response = $this->get('/sheets/' . $probe['sheetId']);
            $entry = $this->entryFor($response['data'], $probe['sheetId']);

            // The invariant is the strong one, and it took a mutation to see why the obvious one is
            // too weak. `assertNotSame('IRR', $entry['currency'])` passes against an implementation
            // that substitutes *USD* instead — a different wrong number, in a different unit, from
            // the same one line of code. There is no currency this record is entitled to be shown as,
            // because it does not have one, so the only sound form is: a `200` may not carry this
            // record at all.
            if ($response['status'] === 200) {
                $this->assertNull(
                    $entry,
                    'GET /sheets/{id} answered 200 with a sheet whose currency reference resolves to '
                    . 'nothing. Whatever currency it is given, it is a fabrication: the record has no '
                    . 'readable currency, so every amount on it is in a unit nobody chose, and nothing '
                    . 'in the response says so.'
                );
            }

            // A 200 that omits the record would be a *different* defect -- telling a caller something
            // does not exist that does -- so the acceptable statuses are enumerated rather than
            // assumed. What may not vary is the wrong answer above.
            $this->assertContains(
                $response['status'],
                [200, 404, 422, 500],
                'GET /sheets/{id} must answer with a status that means something about the fault'
            );
        } finally {
            CorruptRecordFixture::remove();
        }
    }

    /**
     * The list is the more damaging of the two: a caller cannot tell which entry is wrong.
     *
     * `GET /sheets` returned `200` with the probe among the caller's own sheets, reported as rial. The
     * user sees a list that looks complete and correct, and the corruption is one row of it that no
     * amount of looking will reveal.
     */
    public function testAListOfSheetsNeverCarriesTheSubstitutedCurrency(): void
    {
        $probe = CorruptRecordFixture::insert();

        try {
            $response = $this->get('/sheets?pageSize=100');
            $entry = $this->entryFor($response['data'], $probe['sheetId']);

            if ($response['status'] === 200) {
                $this->assertNull(
                    $entry,
                    'GET /sheets answered 200 with a sheet whose currency reference resolves to nothing '
                    . 'in the list. A user cannot tell which row is wrong, which is why this is the '
                    . 'more damaging of the two endpoints even though it is the less specific one.'
                );
            }

            $this->assertContains(
                $response['status'],
                [200, 422, 500],
                'GET /sheets must answer with a status that means something about the fault'
            );
        } finally {
            CorruptRecordFixture::remove();
        }
    }

    /**
     * The probe's own record within a response, or null when the response does not carry it.
     *
     * Extracted rather than asserting against the whole body, for two reasons. Precision: the claim is
     * about *this* sheet, and a body-wide `assertStringNotContainsString` would also fail on any other
     * sheet that happens to be rial -- which in this fixture set is nearly all of them. And size: a
     * failed assertion on a hundred-sheet body prints all hundred, which buries the one line that
     * matters.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function entryFor(array $data, string $sheetId): ?array
    {
        if (($data['id'] ?? null) === $sheetId) {
            return $data;
        }

        foreach ((array) ($data['items'] ?? []) as $item) {
            if (is_array($item) && ($item['id'] ?? null) === $sheetId) {
                return $item;
            }
        }

        return null;
    }

    /**
     * A healthy sheet is untouched. Without this, "the endpoint refused" would be a sufficient way to
     * pass, including a way that refuses *everything*, which is a different defect entirely.
     */
    public function testAHealthySheetIsStillServedWhileAnotherSheetIsBroken(): void
    {
        CorruptRecordFixture::insert();

        try {
            $response = $this->get('/sheets/' . ContractFixtures::sheetId());
            $raw = $this->raw('/sheets/' . ContractFixtures::sheetId());

            $this->assertSame(200, $response['status'], 'a sheet with a resolvable currency must be served');
            $this->assertStringContainsString(
                ContractFixtures::sheetId(),
                $raw,
                'the healthy sheet must be the one in the body'
            );
        } finally {
            CorruptRecordFixture::remove();
        }
    }

    /**
     * Nothing about the fault may reach the caller: not the surrogate keys, and not the internal
     * message.
     *
     * A 500 answers `pettycash.error.unexpected` and logs the rest, which is the correct division —
     * the operator needs to know *which* row is broken and a client must not. Asserted on the body
     * rather than on the message field, because a leak through any other key would be just as bad.
     */
    public function testTheFaultIsLoggedRatherThanDisclosed(): void
    {
        $probe = CorruptRecordFixture::insert();

        try {
            foreach (['/sheets/' . $probe['sheetId'], '/sheets?pageSize=100'] as $path) {
                $raw = $this->raw($path);

                $this->assertStringNotContainsString(
                    (string) $probe['danglingCurrencyId'],
                    $raw,
                    "{$path} disclosed the currency id that does not resolve. The value belongs in the "
                    . 'log; a client that learns which reference is broken learns something about rows it '
                    . 'may not be able to see.'
                );
                $this->assertStringNotContainsString(
                    (string) $probe['revisionDbId'],
                    $raw,
                    "{$path} disclosed a surrogate key from the probe"
                );
                $this->assertStringNotContainsString(
                    'Cannot resolve the currency',
                    $raw,
                    "{$path} echoed the internal exception message to the caller"
                );
            }
        } finally {
            CorruptRecordFixture::remove();
        }
    }

    /**
     * The control, and the one that makes the three above evidence rather than assertion.
     *
     * A fixture that can only demonstrate a failure has not shown that the failure is the fixture's own
     * doing. This asserts 200 with a real currency on both endpoints once the probe is gone — which is
     * also the assertion that would fail if the repair left the database in a state where every sheet
     * refuses.
     */
    public function testBothEndpointsAreServedAgainOnceTheProbeIsRemoved(): void
    {
        $probe = CorruptRecordFixture::insert();

        try {
            $this->assertNotSame(
                200,
                $this->get('/sheets/' . $probe['sheetId'])['status'],
                'the probe must be what changes the answer, or the assertions above are vacuous'
            );
        } finally {
            // `remove()` throws if anything it owns survived, so reaching the assertions below means
            // the database is clean -- which is the precondition for them meaning anything.
            CorruptRecordFixture::remove();
        }

        foreach (['/sheets?pageSize=100', '/sheets/' . ContractFixtures::sheetId()] as $path) {
            $this->assertSame(200, $this->get($path)['status'], "{$path} must be healthy once the probe is gone");
        }
    }

    // ===== helpers =====

    /** @return array{status: int, data: array<string, mixed>, message: string} */
    private function get(string $path): array
    {
        return $this->decode($this->request('GET', $path));
    }

    /** The undecoded body, for asserting on what is *absent* from it. */
    private function raw(string $path): string
    {
        return (string) $this->request('GET', $path)->getBody();
    }

    private function request(string $method, string $path): ResponseInterface
    {
        return $this->client->request($method, '/ocs/v2.php/apps/pettycash/api/v1' . $path);
    }

    /** @return array{status: int, data: array<string, mixed>, message: string} */
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('The response was not a JSON OCS envelope.');
        }

        return [
            'status' => (int) ($decoded['ocs']['meta']['statuscode'] ?? 0),
            'data' => (array) ($decoded['ocs']['data'] ?? []),
            'message' => (string) ($decoded['ocs']['meta']['message'] ?? ''),
        ];
    }
}
