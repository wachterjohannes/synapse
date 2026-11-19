<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The checks every page of the browser UI shares. The password itself is checked by the firewall.
 * A request that changes something must come from this page (Sec-Fetch-Site: same-origin, which a browser sets on
 * its own and a foreign page cannot fake) or carry an X-Synapse-Ui header, which keeps another site from writing
 * to your brain through your logged in browser. A call that costs money also counts against a rate limit (throttle).
 */
final class UiGuard
{
    public function __construct(
        #[Autowire('%env(UI_PASSWORD)%')]
        private readonly string $password,
        private readonly RateLimiterFactoryInterface $uiPaidLimiter,
    ) {
    }

    /** The response to send instead of the page while the UI is off, or null when it is on. */
    public function disabled(): ?Response
    {
        return '' === $this->password ? new Response('The UI is off. Set UI_PASSWORD in .env.local to turn it on.', 503) : null;
    }

    /** Like disabled(), plus the check that the request comes from this page. */
    public function guard(Request $request): ?Response
    {
        if (null !== $off = $this->disabled()) {
            return $off;
        }
        $fromThisPage = 'same-origin' === $request->headers->get('Sec-Fetch-Site') || '1' === $request->headers->get('X-Synapse-Ui');

        return $fromThisPage ? null : new JsonResponse(['error' => 'Not from this page.'], 403);
    }

    /**
     * Uses up one token of the rate limit. Call it right before a call that costs money, after the input is known to be
     * good, so a request that is turned away for its input does not count.
     */
    public function throttle(Request $request): ?Response
    {
        $limit = $this->uiPaidLimiter->create($request->getClientIp() ?? 'ui')->consume();
        if ($limit->isAccepted()) {
            return null;
        }
        $wait = max(1, $limit->getRetryAfter()->getTimestamp() - time());

        return new JsonResponse(['error' => sprintf('Too many requests. Try again in %d seconds.', $wait)], 429, ['Retry-After' => (string) $wait]);
    }
}
