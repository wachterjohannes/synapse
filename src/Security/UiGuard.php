<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two checks every page of the browser UI shares. The password itself is checked by the firewall.
 * A request that changes something must come from this page (Sec-Fetch-Site: same-origin, which a browser sets on
 * its own and a foreign page cannot fake) or carry an X-Synapse-Ui header, which keeps another site from writing
 * to your brain through your logged in browser.
 */
final class UiGuard
{
    public function __construct(
        #[Autowire('%env(UI_PASSWORD)%')]
        private readonly string $password,
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
}
