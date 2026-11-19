<?php

namespace App\Tests\Functional;

use App\Tests\Support\MockResponses;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base of the tests that go through the whole kernel. Rate limits and login throttling live in the cache of the
 * test kernel, which outlives a run, so every test comes from an address of its own.
 */
abstract class BrowserTestCase extends WebTestCase
{
    protected const PASSWORD = 'test-password';

    private static int $client = 0;

    protected function setUp(): void
    {
        MockResponses::reset();
    }

    protected function tearDown(): void
    {
        MockResponses::reset();
        parent::tearDown();
    }

    /**
     * @param array<string, string> $server
     */
    protected function browser(array $server = []): KernelBrowser
    {
        return static::createClient([], $server + ['REMOTE_ADDR' => sprintf('10.%d.%d.%d', random_int(1, 250), random_int(1, 250), ++self::$client % 250 + 1)]);
    }

    protected function logIn(KernelBrowser $browser, string $password = self::PASSWORD): void
    {
        $crawler = $browser->request('GET', '/login');
        $browser->submit($crawler->selectButton('Sign in')->form(['_username' => 'synapse', '_password' => $password]));
    }

    /**
     * @return array<string, string>
     */
    protected static function basicAuth(string $password = self::PASSWORD, string $user = 'synapse'): array
    {
        return ['PHP_AUTH_USER' => $user, 'PHP_AUTH_PW' => $password];
    }

    /**
     * @return array<string, string> what the page sends on its own
     */
    protected static function fromThePage(): array
    {
        return ['HTTP_SEC_FETCH_SITE' => 'same-origin'];
    }
}
