<?php

namespace App\Tests\Security;

use App\Security\UiGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class UiGuardTest extends TestCase
{
    private function guard(string $password = 'secret', int $limit = 2): UiGuard
    {
        return new UiGuard($password, new RateLimiterFactory(['id' => 'ui_paid', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 minute'], new InMemoryStorage()));
    }

    public function testTheUiIsOffWhileThePasswordIsEmpty(): void
    {
        $response = $this->guard('')->disabled();

        self::assertSame(503, $response?->getStatusCode());
        self::assertStringContainsString('UI_PASSWORD', (string) $response?->getContent());
    }

    public function testTheUiIsOnWithAPassword(): void
    {
        self::assertNull($this->guard()->disabled());
    }

    public function testAnOffUiBeatsAValidOrigin(): void
    {
        $request = new Request();
        $request->headers->set('Sec-Fetch-Site', 'same-origin');

        self::assertSame(503, $this->guard('')->guard($request)?->getStatusCode());
    }

    public function testASameOriginRequestPasses(): void
    {
        $request = new Request();
        $request->headers->set('Sec-Fetch-Site', 'same-origin');

        self::assertNull($this->guard()->guard($request));
    }

    public function testTheHeaderOfThePagePasses(): void
    {
        $request = new Request();
        $request->headers->set('X-Synapse-Ui', '1');

        self::assertNull($this->guard()->guard($request));
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function foreignRequests(): iterable
    {
        yield 'no header' => [[]];
        yield 'cross-site' => [['Sec-Fetch-Site' => 'cross-site']];
        yield 'same-site is not same-origin' => [['Sec-Fetch-Site' => 'same-site']];
        yield 'none' => [['Sec-Fetch-Site' => 'none']];
        yield 'ui header with another value' => [['X-Synapse-Ui' => '0']];
        yield 'ui header true' => [['X-Synapse-Ui' => 'true']];
    }

    /**
     * @param array<string, string> $headers
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('foreignRequests')]
    public function testAForeignRequestGets403(array $headers): void
    {
        $request = new Request();
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        self::assertSame(403, $this->guard()->guard($request)?->getStatusCode());
    }

    public function testTheRateLimitTurnsAwayTheRequestAfterTheLimit(): void
    {
        $guard = $this->guard(limit: 2);
        $request = Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.5']);

        self::assertNull($guard->throttle($request));
        self::assertNull($guard->throttle($request));
        $limited = $guard->throttle($request);

        self::assertSame(429, $limited?->getStatusCode());
        self::assertGreaterThanOrEqual(1, (int) $limited?->headers->get('Retry-After'));
        self::assertStringContainsString('Too many requests', (string) $limited?->getContent());
    }

    public function testEachClientHasItsOwnBudget(): void
    {
        $guard = $this->guard(limit: 1);

        self::assertNull($guard->throttle(Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.5'])));
        self::assertNull($guard->throttle(Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.6'])));
        self::assertSame(429, $guard->throttle(Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.5']))?->getStatusCode());
    }
}
