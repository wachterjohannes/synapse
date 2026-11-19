<?php

namespace App\EventListener;

use App\Security\UiGuard;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The live components answer below /ui/_components/, so the login of the firewall already covers them. A call
 * that is not a plain read (an action, or a re-render, which the browser sends as POST) must also come from this
 * page, the same check the other endpoints of the page make (see UiGuard::guard). Without it another site could
 * have your logged in browser change the status of a note.
 */
final class GuardLiveComponents
{
    public function __construct(private readonly UiGuard $ui)
    {
    }

    // just after the firewall (priority 8), so a visitor without a login is sent to the login page first
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/ui/_components/') || $request->isMethodSafe()) {
            return;
        }

        if (null !== $response = $this->ui->guard($request)) {
            $event->setResponse($response);
        }
    }
}
