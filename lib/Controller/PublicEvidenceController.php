<?php

declare(strict_types=1);

namespace OCA\PettyCash\Controller;

use OCA\PettyCash\Application\Evidence\PublicEvidenceLinkService;
use OCA\PettyCash\Application\Evidence\PublicEvidenceRefusal;
use OCA\PettyCash\Infrastructure\Http\OcsErrorMapper;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use RuntimeException;
use Throwable;

/**
 * FR-031: `GET /public/evidence/{token}` — the only unauthenticated data path in V1.
 *
 * ## Why this controller is separate from `EvidenceController`
 *
 * Not for tidiness. The three attributes an action needs are `#[NoAdminRequired]` on every other evidence
 * action, and here they must be **absent** — `#[PublicPage]` and nothing else. Keeping the unauthenticated
 * action in the same class as the authenticated ones would mean one file whose actions have
 * incompatible security postures, and the next action added would have its attribute copied from whichever
 * neighbour the author had open.
 *
 * It is also the only controller with no `CurrentUser`. Reading the session here is not merely discouraged,
 * it is a defect: FR-031 records the viewer as `anonymous`, and a session read would either throw
 * (`NextcloudCurrentUser::uid()` does, for an unauthenticated request) or, on a route that a signed-in
 * visitor may also open, silently record their UID and attribute an anonymous access to a named person.
 */
class PublicEvidenceController extends OCSController
{
    public function __construct(
        IRequest $request,
        private PublicEvidenceLinkService $links,
        private OcsErrorMapper $ocsErrorMapper,
    ) {
        parent::__construct('pettycash', $request);
    }

    /**
     * Open an evidence item through its unguessable link token.
     *
     * @param string $token the token exactly as issued, disclosed once at creation
     * @param string|null $disposition `inline` or `attachment`; FR-060 honours the choice only for an
     *                                 inline-safe stored media type
     *
     * ## `#[PublicPage]` and what it must NOT be joined by
     *
     * `PublicPage` is what makes this route reachable without signing in — Nextcloud's `SecurityMiddleware`
     * throws `NotLoggedInException` for any action without it. Three things are deliberately absent:
     *
     * - **No `#[NoAdminRequired]`.** It is redundant beside `PublicPage` and reads as "any signed-in
     *   non-admin", which is the wrong mental model for a route whose authority is the token.
     * - **No application-level rate limiter, login challenge or CAPTCHA.** FR-031 delegates abuse control
     *   to Nextcloud's existing brute-force limiter and forbids a second mechanism, because a parallel one
     *   is the undeclared authentication system Principle III rules out. Adding `#[BruteForceProtection]`
     *   here would *also* be wrong: it is Nextcloud's own, but it is applied per session and this route has
     *   no session, so it would guard nothing while looking as though it did.
     * - **No `X-PettyCash-Command-ID` requirement.** The contract declares this operation without the
     *   header, and that is right rather than an oversight: consumption mutates nothing business-side, so
     *   it is idempotent by the token itself and a command identifier would imply a replay store it has no
     *   use for.
     *
     * `#[NoCSRFRequired]` below IS required, and its absence answered every anonymous consumption with
     * `412 CSRF check failed`: there is no session to forge a request for, so there is nothing the check
     * could protect, and without the exemption the token — the whole authority on this path — is
     * unreachable to the very holder FR-031 grants it to.
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function show(string $token, ?string $disposition = null): DataResponse|StreamResponse
    {
        try {
            $content = $this->links->consume($token, $disposition, $this->remoteAddress());
        } catch (PublicEvidenceRefusal $refusal) {
            // The single refusal, for all four reasons. `OcsErrorMapper::notFound()` is used rather than
            // a bespoke body because the contract's generic `NotFound` response is the correct shape
            // precisely when it carries no detail: a bespoke body would tempt an implementation into
            // disclosing which of the four applied.
            return $this->ocsErrorMapper->notFound($refusal->getMessage());
        } catch (Throwable $error) {
            // A storage or data fault on an unauthenticated path. Logged and answered with the generic
            // message: this is the route where an internal detail is least safe to publish, because there
            // is no authenticated caller to recognise it as a server fault rather than a refusal.
            return $this->ocsErrorMapper->map($error);
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'pettycash-public-evidence-');
        if ($tempFile === false) {
            return $this->ocsErrorMapper->map(new RuntimeException(
                'Failed to stage a public evidence response'
            ));
        }

        if (file_put_contents($tempFile, $content->bytes) === false) {
            return $this->ocsErrorMapper->map(new RuntimeException(
                'Failed to stage a public evidence response'
            ));
        }

        register_shutdown_function(static function () use ($tempFile): void {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        });

        return new StreamResponse($tempFile, Http::STATUS_OK, [
            // The **resolved** media type, never the stored one: FR-060, and the reason the service owns
            // the decision rather than returning raw evidence for the controller to label.
            'Content-Type' => $content->contentType,
            'Content-Length' => (string)$content->sizeBytes,
            'Content-Disposition' => $content->contentDispositionHeader(),
            // An unauthenticated response is the one place a caching proxy must not keep a copy: the token
            // is the authority, so a shared cache holding the body is a second reader of the evidence.
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * The requester's address, for FR-031's per-access audit.
     *
     * Read through the request rather than `$_SERVER` so the value is the one the platform resolved —
     * `IRequest::getRemoteAddress()` is what Nextcloud's own audit and abuse-control code reads, and using
     * a different source here would make the recorded address disagree with the platform's log of the same
     * request.
     */
    private function remoteAddress(): ?string
    {
        $address = $this->request->getRemoteAddress();

        return $address === '' ? null : $address;
    }
}