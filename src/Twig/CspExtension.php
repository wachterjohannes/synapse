<?php

namespace App\Twig;

use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * csp_nonce() is the nonce UiHeaders put into the Content-Security-Policy of this request. A script tag that carries it may run.
 */
final class CspExtension extends AbstractExtension
{
    public function __construct(private readonly RequestStack $requests)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('csp_nonce', fn (): string => (string) $this->requests->getCurrentRequest()?->attributes->get('csp_nonce', ''))];
    }
}
