<?php

namespace App\Tests\Functional;

final class LoginTest extends BrowserTestCase
{
    public function testTheIndexRedirectsToTheLoginPage(): void
    {
        $browser = $this->browser();
        $browser->request('GET', '/');

        self::assertResponseRedirects('/login');
    }

    public function testTheLoginPageShowsTheFormWithACsrfToken(): void
    {
        $browser = $this->browser();
        $browser->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form input[name="_username"]');
        self::assertSelectorExists('form input[name="_password"]');
        self::assertSelectorExists('form input[name="_csrf_token"]');
    }

    public function testAWrongPasswordShowsAnError(): void
    {
        $browser = $this->browser();
        $this->logIn($browser, 'wrong');

        self::assertResponseRedirects('/login');
        $browser->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'Wrong user or password.');
        $browser->request('GET', '/');
        self::assertResponseRedirects('/login');
    }

    public function testTheRightPasswordOpensThePage(): void
    {
        $browser = $this->browser();
        $this->logIn($browser);

        self::assertResponseRedirects('/');
        $browser->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form input[name="_password"]');
    }

    public function testALoggedInUserIsSentAwayFromTheLoginPage(): void
    {
        $browser = $this->browser();
        $this->logIn($browser);
        $browser->request('GET', '/login');

        self::assertResponseRedirects('/');
    }

    public function testTheFormNeedsAValidCsrfToken(): void
    {
        $browser = $this->browser();
        $browser->request('GET', '/login');
        $browser->request('POST', '/login', ['_username' => 'synapse', '_password' => self::PASSWORD, '_csrf_token' => 'forged']);

        self::assertResponseRedirects('/login');
        $browser->request('GET', '/');
        self::assertResponseRedirects('/login');
    }

    public function testThePasswordWithoutAnyTokenIsNotEnough(): void
    {
        $browser = $this->browser();
        $browser->request('POST', '/login', ['_username' => 'synapse', '_password' => self::PASSWORD]);

        self::assertResponseRedirects('/login');
        $browser->request('GET', '/');
        self::assertResponseRedirects('/login');
    }

    public function testLogoutClosesThePageAgain(): void
    {
        $browser = $this->browser();
        $this->logIn($browser);
        $browser->request('GET', '/');
        self::assertResponseIsSuccessful();

        $browser->request('GET', '/logout');
        self::assertResponseRedirects('/login');
        $browser->request('GET', '/');
        self::assertResponseRedirects('/login');
    }

    public function testBasicAuthStillWorksForScripts(): void
    {
        $browser = $this->browser(self::basicAuth());
        $browser->request('GET', '/');

        self::assertResponseIsSuccessful();
    }

    public function testBasicAuthWithAWrongPasswordIsRefused(): void
    {
        $browser = $this->browser(self::basicAuth('wrong'));
        $browser->request('GET', '/ui/notes');

        self::assertResponseStatusCodeSame(401);
    }

    public function testTheLoginPageCarriesTheSecurityHeadersAndANonce(): void
    {
        $browser = $this->browser();
        $browser->request('GET', '/login');

        $csp = (string) $browser->getResponse()->headers->get('Content-Security-Policy');
        self::assertSame(1, preg_match("/script-src 'self' 'nonce-([A-Za-z0-9_-]{22})'/", $csp, $m), $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertStringContainsString("form-action 'self'", $csp);
        self::assertResponseHeaderSame('X-Frame-Options', 'DENY');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertResponseHeaderSame('Referrer-Policy', 'same-origin');
        self::assertStringContainsString('nonce="'.$m[1].'"', (string) $browser->getResponse()->getContent(), 'The script tags carry the nonce of the header.');
    }

    public function testEveryRequestGetsAFreshNonce(): void
    {
        $browser = $this->browser();
        $nonces = [];
        foreach ([1, 2] as $_) {
            $browser->request('GET', '/login');
            preg_match("/nonce-([A-Za-z0-9_-]+)'/", (string) $browser->getResponse()->headers->get('Content-Security-Policy'), $m);
            $nonces[] = $m[1];
        }

        self::assertNotSame($nonces[0], $nonces[1]);
    }

    public function testTheRedirectToTheLoginPageAlsoCarriesTheHeaders(): void
    {
        $browser = $this->browser();
        $browser->request('GET', '/');

        self::assertResponseHasHeader('Content-Security-Policy');
    }

    public function testAnEmptyPasswordKeepsTheUiClosed(): void
    {
        $this->withEnv('UI_PASSWORD', '', function (): void {
            $browser = $this->browser();
            $browser->request('GET', '/login');
            self::assertResponseStatusCodeSame(503);
            self::assertStringContainsString('The UI is off', (string) $browser->getResponse()->getContent());

            $browser->request('GET', '/ui/notes', server: self::basicAuth(''));
            self::assertResponseStatusCodeSame(401, 'An empty password is no way in.');

            $browser->request('POST', '/ui/capture', ['text' => 'x'], server: self::fromThePage());
            self::assertResponseRedirects('/login');
        });
    }

    private function withEnv(string $name, string $value, callable $run): void
    {
        $server = $_SERVER[$name] ?? null;
        $env = $_ENV[$name] ?? null;
        $process = getenv($name);
        $_SERVER[$name] = $_ENV[$name] = $value;
        putenv($name.'='.$value);
        self::ensureKernelShutdown();
        try {
            $run();
        } finally {
            self::ensureKernelShutdown();
            null === $server ? $this->forget($_SERVER, $name) : $_SERVER[$name] = $server;
            null === $env ? $this->forget($_ENV, $name) : $_ENV[$name] = $env;
            false === $process ? putenv($name) : putenv($name.'='.$process);
        }
    }

    /**
     * @param array<string, mixed> $bag
     */
    private function forget(array &$bag, string $name): void
    {
        unset($bag[$name]);
    }
}
