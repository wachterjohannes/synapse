<?php

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The response factory of the test kernel's HTTP client. A test queues what the "network" answers.
 * A request nobody queued fails loudly, so a test can never reach a real provider by accident.
 */
final class MockResponses
{
    /** @var list<MockResponse|\Closure(string, string, array<string, mixed>): MockResponse> */
    private static array $queue = [];

    /** @var list<array{method: string, url: string}> */
    private static array $requests = [];

    public static function queue(MockResponse|\Closure ...$responses): void
    {
        self::$queue = [...self::$queue, ...$responses];
    }

    public static function reset(): void
    {
        self::$queue = [];
        self::$requests = [];
    }

    /**
     * @return list<array{method: string, url: string}>
     */
    public static function requests(): array
    {
        return self::$requests;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        self::$requests[] = ['method' => $method, 'url' => $url];
        $next = array_shift(self::$queue) ?? throw new \LogicException(sprintf('Unexpected HTTP request %s %s. Queue an answer with MockResponses::queue().', $method, $url));

        return $next instanceof \Closure ? $next($method, $url, $options) : $next;
    }
}
