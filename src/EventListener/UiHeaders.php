<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The security headers of the browser pages: a Content-Security-Policy that lets only scripts with this request's nonce
 * run, and no framing, so another site cannot put the page in a frame and have you click in it.
 * The MCP endpoint and the metadata are not pages and get none of this.
 */
final class UiHeaders
{
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 64)]
    public function nonce(RequestEvent $event): void
    {
        if ($event->isMainRequest() && $this->isPage($event->getRequest()->getPathInfo())) {
            $event->getRequest()->attributes->set('csp_nonce', rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='));
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function headers(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || null === $nonce = $request->attributes->get('csp_nonce')) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set('Content-Security-Policy', sprintf(
            "default-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; style-src 'self' 'unsafe-inline'; script-src 'self' 'nonce-%s'; img-src 'self' data:; media-src 'self' blob:",
            $nonce,
        ));
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'same-origin');
    }

    private function isPage(string $path): bool
    {
        return '/' === $path || str_starts_with($path, '/ui/');
    }
}
